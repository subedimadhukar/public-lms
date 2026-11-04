<?php
/**
 * Progress repository.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

use PublicLMS\Core\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Rows in plms_progress (per item) and plms_course_progress (per course).
 *
 * Writes are idempotent upserts on the unique keys (ADR-002): a repeated
 * "mark complete" never duplicates a row or moves its completion time, and a
 * later "in progress" never undoes a completion.
 *
 * Cache keys (group 'publiclms'): `progress_items:{user_id}:{course_id}`
 * holds a user's item rows in a course keyed by item ID;
 * `course_progress:user:{user_id}` holds every aggregate of a user keyed by
 * course ID, which serves the dashboard and single-course lookups alike.
 */
class ProgressRepository implements ProgressRepositoryInterface {

	const STATUS_IN_PROGRESS = 'in_progress';
	const STATUS_COMPLETED   = 'completed';

	const ITEM_TYPES = array( 'lesson', 'quiz', 'assignment' );

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database connection.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Returns a completion percentage: rounded to the nearest whole number,
	 * but 100 only when every item is done and at least 1 once any item is.
	 *
	 * The progress bar, the dashboard, the live region and REST all show this
	 * number, so 1 of 6 reads 17% everywhere and 199 of 200 never reads 100%.
	 *
	 * @param int $done  Items completed.
	 * @param int $total Items in the course.
	 * @return int 0 to 100.
	 */
	public static function percent( int $done, int $total ): int {
		if ( $total <= 0 || $done <= 0 ) {
			return 0;
		}
		if ( $done >= $total ) {
			return 100;
		}
		return max( 1, min( 99, (int) round( $done * 100 / $total ) ) );
	}

	/**
	 * Returns the per-item table name.
	 *
	 * @return string
	 */
	public function items_table(): string {
		return $this->db->prefix . 'plms_progress';
	}

	/**
	 * Returns the per-course aggregate table name.
	 *
	 * @return string
	 */
	public function courses_table(): string {
		return $this->db->prefix . 'plms_course_progress';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $course_id Course ID.
	 * @param int    $item_id   Lesson, quiz or assignment ID.
	 * @param string $item_type 'lesson', 'quiz' or 'assignment'.
	 * @param string $status    'in_progress' or 'completed'.
	 * @return bool
	 */
	public function upsert_item( int $user_id, int $course_id, int $item_id, string $item_type, string $status ): bool {
		if ( $user_id <= 0 || $course_id <= 0 || $item_id <= 0
			|| ! in_array( $item_type, self::ITEM_TYPES, true )
			|| ! in_array( $status, array( self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED ), true ) ) {
			return false;
		}
		$now       = gmdate( 'Y-m-d H:i:s' );
		$completed = self::STATUS_COMPLETED === $status;

		// A completed row stays completed with its first completion time.
		$sql = 'INSERT INTO %i (user_id, course_id, item_id, item_type, status, started_at, completed_at, updated_at)
			VALUES (%d, %d, %d, %s, %s, %s, ' . ( $completed ? '%s' : 'NULL' ) . ', %s)
			ON DUPLICATE KEY UPDATE
				completed_at = IF(status = %s, completed_at, VALUES(completed_at)),
				status = IF(status = %s, status, VALUES(status)),
				course_id = VALUES(course_id),
				item_type = VALUES(item_type),
				updated_at = VALUES(updated_at)';

		$args = array( $this->items_table(), $user_id, $course_id, $item_id, $item_type, $status, $now );
		if ( $completed ) {
			$args[] = $now;
		}
		array_push( $args, $now, self::STATUS_COMPLETED, self::STATUS_COMPLETED );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; built from placeholders only; cache invalidated below.
		$result = $this->db->query( $this->db->prepare( $sql, $args ) );
		Cache::delete( $this->items_key( $user_id, $course_id ) );
		return false !== $result;
	}

	/**
	 * Removes a user's progress on one item ("mark incomplete").
	 *
	 * @param int $user_id User ID.
	 * @param int $item_id Item ID.
	 * @return bool Whether a row was deleted.
	 */
	public function reset_item( int $user_id, int $item_id ): bool {
		$row = $this->find_item( $user_id, $item_id );
		if ( null === $row ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
		$result = $this->db->delete(
			$this->items_table(),
			array(
				'user_id' => $user_id,
				'item_id' => $item_id,
			),
			array( '%d', '%d' )
		);
		Cache::delete( $this->items_key( $user_id, (int) $row['course_id'] ) );
		return is_int( $result ) && $result > 0;
	}

	/**
	 * Returns a user's row for one item, uncached.
	 *
	 * @param int $user_id User ID.
	 * @param int $item_id Item ID.
	 * @return array<string, mixed>|null
	 */
	public function find_item( int $user_id, int $item_id ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; single-row lookup by unique key.
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d AND item_id = %d', $this->items_table(), $user_id, $item_id ), ARRAY_A );
		return is_array( $row ) ? $this->normalize_item( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function items_for_course( int $user_id, int $course_id ): array {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return array();
		}
		$key    = $this->items_key( $user_id, $course_id );
		$cached = Cache::get( $key, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cached below.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d AND course_id = %d', $this->items_table(), $user_id, $course_id ), ARRAY_A );

		$by_item = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row                              = $this->normalize_item( $row );
			$by_item[ (int) $row['item_id'] ] = $row;
		}
		Cache::set( $key, $by_item );
		return $by_item;
	}

	/**
	 * Returns the IDs of the items a user completed in a course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array<int, int>
	 */
	public function completed_item_ids( int $user_id, int $course_id ): array {
		$ids = array();
		foreach ( $this->items_for_course( $user_id, $course_id ) as $item_id => $row ) {
			if ( self::STATUS_COMPLETED === $row['status'] ) {
				$ids[] = (int) $item_id;
			}
		}
		return $ids;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array<string, mixed>|null
	 */
	public function course_progress( int $user_id, int $course_id ): ?array {
		$rows = $this->course_progress_for_user( $user_id );
		return $rows[ $course_id ] ?? null;
	}

	/**
	 * Returns every course aggregate of a user keyed by course ID, cached.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function course_progress_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$key    = 'course_progress:user:' . $user_id;
		$cached = Cache::get( $key, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cached below.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY updated_at DESC, id DESC', $this->courses_table(), $user_id ), ARRAY_A );

		$by_course = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row                                  = $this->normalize_course( $row );
			$by_course[ (int) $row['course_id'] ] = $row;
		}
		Cache::set( $key, $by_course );
		return $by_course;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int             $user_id       User ID.
	 * @param int             $course_id     Course ID.
	 * @param array<int, int> $item_ids      The course's current completable items.
	 * @param int             $last_item_id  The item just worked on, or 0 to keep the stored one.
	 * @param int             $enrollment_id The enrolment the aggregate belongs to, or 0.
	 * @return array<string, mixed>
	 */
	public function refresh_course_progress( int $user_id, int $course_id, array $item_ids, int $last_item_id = 0, int $enrollment_id = 0 ): array {
		$item_ids  = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ) ) ) );
		$total     = count( $item_ids );
		$completed = 0;
		if ( $total > 0 ) {
			$completed = count( array_intersect( $item_ids, $this->completed_item_ids( $user_id, $course_id ) ) );
		}
		$percent = self::percent( $completed, $total );
		$now     = gmdate( 'Y-m-d H:i:s' );

		// completed_at is set the first time the course reaches 100% and cleared
		// if new items later bring it below; last_item_id and enrollment_id keep
		// their stored values when the caller passes 0.
		$sql = 'INSERT INTO %i (user_id, course_id, enrollment_id, items_completed, items_total, percent, last_item_id, started_at, completed_at, updated_at)
			VALUES (%d, %d, ' . ( $enrollment_id > 0 ? '%d' : 'NULL' ) . ', %d, %d, %d, ' . ( $last_item_id > 0 ? '%d' : 'NULL' ) . ', %s, ' . ( 100 === $percent ? '%s' : 'NULL' ) . ', %s)
			ON DUPLICATE KEY UPDATE
				enrollment_id = COALESCE(VALUES(enrollment_id), enrollment_id),
				items_completed = VALUES(items_completed),
				items_total = VALUES(items_total),
				completed_at = IF(VALUES(percent) >= 100, COALESCE(completed_at, VALUES(completed_at)), NULL),
				percent = VALUES(percent),
				last_item_id = COALESCE(VALUES(last_item_id), last_item_id),
				updated_at = VALUES(updated_at)';

		$args = array( $this->courses_table(), $user_id, $course_id );
		if ( $enrollment_id > 0 ) {
			$args[] = $enrollment_id;
		}
		array_push( $args, $completed, $total, $percent );
		if ( $last_item_id > 0 ) {
			$args[] = $last_item_id;
		}
		$args[] = $now;
		if ( 100 === $percent ) {
			$args[] = $now;
		}
		$args[] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; built from placeholders only; cache invalidated below.
		$this->db->query( $this->db->prepare( $sql, $args ) );
		Cache::delete( 'course_progress:user:' . $user_id );

		$row = $this->course_progress( $user_id, $course_id );
		return null === $row ? array() : $row;
	}

	/**
	 * Deletes a user's progress in one course, items and aggregate.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return void
	 */
	public function delete_for_course( int $user_id, int $course_id ): void {
		$where = array(
			'user_id'   => $user_id,
			'course_id' => $course_id,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
		$this->db->delete( $this->items_table(), $where, array( '%d', '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
		$this->db->delete( $this->courses_table(), $where, array( '%d', '%d' ) );
		Cache::delete( $this->items_key( $user_id, $course_id ) );
		Cache::delete( 'course_progress:user:' . $user_id );
	}

	/**
	 * Deletes every progress row of a user, for data erasure and fixture resets.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function delete_for_user( int $user_id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; listing the caches to invalidate.
		$course_ids = $this->db->get_col( $this->db->prepare( 'SELECT DISTINCT course_id FROM %i WHERE user_id = %d', $this->items_table(), $user_id ) );
		foreach ( (array) $course_ids as $course_id ) {
			Cache::delete( $this->items_key( $user_id, (int) $course_id ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
		$this->db->delete( $this->items_table(), array( 'user_id' => $user_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
		$this->db->delete( $this->courses_table(), array( 'user_id' => $user_id ), array( '%d' ) );
		Cache::delete( 'course_progress:user:' . $user_id );
	}

	/**
	 * Returns the cache key for a user's item rows in a course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return string
	 */
	private function items_key( int $user_id, int $course_id ): string {
		return 'progress_items:' . $user_id . ':' . $course_id;
	}

	/**
	 * Casts the numeric columns of an item row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function normalize_item( array $row ): array {
		foreach ( array( 'id', 'user_id', 'course_id', 'item_id' ) as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		return $row;
	}

	/**
	 * Casts the numeric columns of an aggregate row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function normalize_course( array $row ): array {
		foreach ( array( 'id', 'user_id', 'course_id', 'items_completed', 'items_total', 'percent' ) as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		foreach ( array( 'enrollment_id', 'last_item_id' ) as $column ) {
			$row[ $column ] = null === $row[ $column ] ? null : (int) $row[ $column ];
		}
		return $row;
	}
}
