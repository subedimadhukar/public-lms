<?php
/**
 * Enrolment repository.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

use PublicLMS\Core\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Rows in plms_enrollments: one per user and course.
 *
 * Status values: active, pending, cancelled, expired. Course completion is
 * completed_at, not a status, so a completed learner keeps access. Source
 * values: free (self-enrolment), manual (instructor or manager), order,
 * group, import.
 *
 * Cache keys (group 'publiclms'): `enrollments:user:{user_id}` holds every
 * row of a user keyed by course ID, which serves the per-request access
 * checks; `enrolled_count:{course_id}` holds the active count.
 */
class EnrollmentRepository extends AbstractRepository implements EnrollmentRepositoryInterface {

	const STATUS_ACTIVE    = 'active';
	const STATUS_PENDING   = 'pending';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_EXPIRED   = 'expired';

	const STATUSES = array( self::STATUS_ACTIVE, self::STATUS_PENDING, self::STATUS_CANCELLED, self::STATUS_EXPIRED );

	const SOURCES = array( 'free', 'manual', 'order', 'group', 'import', 'woocommerce' );

	/**
	 * Sort orders the student list supports.
	 */
	const STUDENT_ORDERBY = array( 'id', 'name', 'enrolled' );

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function table_name(): string {
		return 'plms_enrollments';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function columns(): array {
		return array(
			'user_id'      => '%d',
			'course_id'    => '%d',
			'status'       => '%s',
			'source'       => '%s',
			'source_id'    => '%d',
			'group_id'     => '%d',
			'enrolled_at'  => '%s',
			'expires_at'   => '%s',
			'completed_at' => '%s',
			'updated_at'   => '%s',
		);
	}

	/**
	 * Enrols a user, or reactivates an existing enrolment, in one upsert.
	 *
	 * The original enrolled_at and completed_at are kept when the row was
	 * already active; a cancelled or expired row restarts its enrolled_at.
	 *
	 * @param int         $user_id    User ID.
	 * @param int         $course_id  Course ID.
	 * @param string      $source     One of SOURCES.
	 * @param int         $source_id  Order, group or import ID, or 0.
	 * @param string|null $expires_at UTC expiry as 'Y-m-d H:i:s', or null for lifetime access.
	 * @return int The enrolment ID, or 0 on failure.
	 */
	public function enroll( int $user_id, int $course_id, string $source = 'manual', int $source_id = 0, ?string $expires_at = null ): int {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return 0;
		}
		$source = in_array( $source, self::SOURCES, true ) ? $source : 'manual';
		$now    = $this->now();

		// Assignments in ON DUPLICATE KEY UPDATE run left to right and see earlier
		// results, so enrolled_at is decided before status changes.
		$sql = 'INSERT INTO %i (user_id, course_id, status, source, source_id, enrolled_at, expires_at, updated_at)
			VALUES (%d, %d, %s, %s, ' . ( $source_id > 0 ? '%d' : 'NULL' ) . ', %s, ' . ( null === $expires_at ? 'NULL' : '%s' ) . ', %s)
			ON DUPLICATE KEY UPDATE
				enrolled_at = IF(status = %s, enrolled_at, VALUES(enrolled_at)),
				status = VALUES(status),
				source = VALUES(source),
				source_id = VALUES(source_id),
				expires_at = VALUES(expires_at),
				updated_at = VALUES(updated_at),
				id = LAST_INSERT_ID(id)';

		$args = array( $this->table(), $user_id, $course_id, self::STATUS_ACTIVE, $source );
		if ( $source_id > 0 ) {
			$args[] = $source_id;
		}
		$args[] = $now;
		if ( null !== $expires_at ) {
			$args[] = $expires_at;
		}
		$args[] = $now;
		$args[] = self::STATUS_ACTIVE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; the query is built from placeholders only and prepared here.
		$result = $this->db->query( $this->db->prepare( $sql, $args ) );
		$this->invalidate( $user_id, $course_id );

		return false === $result ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Changes the status of a user's enrolment.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $course_id Course ID.
	 * @param string $status    One of STATUSES.
	 * @return bool Whether a row changed.
	 */
	public function set_status( int $user_id, int $course_id, string $status ): bool {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
		$result = $this->db->update(
			$this->table(),
			array(
				'status'     => $status,
				'updated_at' => $this->now(),
			),
			array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);
		$this->invalidate( $user_id, $course_id );
		return is_int( $result ) && $result > 0;
	}

	/**
	 * Cancels a user's enrolment. Progress is kept, so re-enrolling resumes it.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return bool Whether a row changed.
	 */
	public function unenroll( int $user_id, int $course_id ): bool {
		return $this->set_status( $user_id, $course_id, self::STATUS_CANCELLED );
	}

	/**
	 * Records (or clears) the course completion time on an enrolment.
	 *
	 * @param int         $user_id      User ID.
	 * @param int         $course_id    Course ID.
	 * @param string|null $completed_at UTC time, or null to clear it.
	 * @return bool
	 */
	public function set_completed( int $user_id, int $course_id, ?string $completed_at ): bool {
		$sql  = 'UPDATE %i SET completed_at = ' . ( null === $completed_at ? 'NULL' : '%s' ) . ', updated_at = %s WHERE user_id = %d AND course_id = %d';
		$args = array( $this->table() );
		if ( null !== $completed_at ) {
			$args[] = $completed_at;
		}
		array_push( $args, $this->now(), $user_id, $course_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; built from placeholders only.
		$result = $this->db->query( $this->db->prepare( $sql, $args ) );
		$this->invalidate( $user_id, $course_id );
		return false !== $result;
	}

	/**
	 * Deletes every enrolment of a user, for data erasure and fixture resets.
	 *
	 * @param int $user_id User ID.
	 * @return int Rows deleted.
	 */
	public function delete_for_user( int $user_id ): int {
		$course_ids = array_keys( $this->rows_for_user( $user_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
		$result = $this->db->delete( $this->table(), array( 'user_id' => $user_id ), array( '%d' ) );
		Cache::delete( 'enrollments:user:' . $user_id );
		foreach ( $course_ids as $course_id ) {
			Cache::delete( 'enrolled_count:' . $course_id );
		}
		return is_int( $result ) ? $result : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_user_and_course( int $user_id, int $course_id ): ?array {
		$rows = $this->rows_for_user( $user_id );
		return $rows[ $course_id ] ?? null;
	}

	/**
	 * Whether a user has an active, unexpired enrolment in a course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return bool
	 */
	public function is_enrolled( int $user_id, int $course_id ): bool {
		$row = $this->find_by_user_and_course( $user_id, $course_id );
		return null !== $row && self::STATUS_ACTIVE === $row['status'] && ! $this->is_expired( $row );
	}

	/**
	 * Whether an enrolment row is past its expiry date.
	 *
	 * @param array<string, mixed> $row Enrolment row.
	 * @return bool
	 */
	public function is_expired( array $row ): bool {
		if ( empty( $row['expires_at'] ) ) {
			return false;
		}
		return strtotime( $row['expires_at'] . ' UTC' ) <= time();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int         $user_id User ID.
	 * @param string|null $status  Only rows with this status, or all when null.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_user( int $user_id, ?string $status = null ): array {
		$rows = array_values( $this->rows_for_user( $user_id ) );
		if ( null === $status ) {
			return $rows;
		}
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $status ) {
					return $row['status'] === $status;
				}
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int         $course_id Course ID.
	 * @param string|null $status    Only rows with this status, or all when null.
	 * @param int         $after_id  Return rows with an ID greater than this.
	 * @param int         $limit     Maximum rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_course( int $course_id, ?string $status = null, int $after_id = 0, int $limit = 50 ): array {
		$limit = max( 1, min( 500, $limit ) );
		if ( null === $status ) {
			$sql = $this->db->prepare( 'SELECT * FROM %i WHERE course_id = %d AND id > %d ORDER BY id ASC LIMIT %d', $this->table(), $course_id, $after_id, $limit );
		} else {
			$sql = $this->db->prepare( 'SELECT * FROM %i WHERE course_id = %d AND status = %s AND id > %d ORDER BY id ASC LIMIT %d', $this->table(), $course_id, $status, $after_id, $limit );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; prepared above; paginated lists are not cached.
		$rows = $this->db->get_results( $sql, ARRAY_A );
		return array_map( array( $this, 'normalize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int         $course_id Course ID.
	 * @param string|null $status    Only rows with this status, or all when null.
	 * @return int
	 */
	public function count_by_course( int $course_id, ?string $status = null ): int {
		$use_cache = self::STATUS_ACTIVE === $status;
		if ( $use_cache ) {
			$cached = Cache::get( 'enrolled_count:' . $course_id, $found );
			if ( $found ) {
				return (int) $cached;
			}
		}
		if ( null === $status ) {
			$sql = $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d', $this->table(), $course_id );
		} else {
			$sql = $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d AND status = %s', $this->table(), $course_id, $status );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; prepared above; active count cached below.
		$count = (int) $this->db->get_var( $sql );
		if ( $use_cache ) {
			Cache::set( 'enrolled_count:' . $course_id, $count );
		}
		return $count;
	}

	/**
	 * Lists learners with at least one enrolment, one row per user, with cursor pagination.
	 *
	 * Each row: user_id, display_name, user_email, courses (count of
	 * enrolments in scope), last_enrolled_at. The cursor is the sort value and
	 * user ID of the last row of the previous page, so pages stay stable while
	 * rows are added.
	 *
	 * @param array<int, int>|null              $course_ids Limit to these courses; null for every course.
	 * @param string                            $search     Matches display name, email or login.
	 * @param string                            $orderby    One of STUDENT_ORDERBY.
	 * @param string                            $order      'asc' or 'desc'.
	 * @param array{0: string|int, 1: int}|null $cursor     Sort value and user ID to continue after.
	 * @param int                               $limit      Maximum rows.
	 * @param string                            $status     Enrolment status to count.
	 * @return array<int, array<string, mixed>>
	 */
	public function students( ?array $course_ids, string $search = '', string $orderby = 'id', string $order = 'asc', ?array $cursor = null, int $limit = 20, string $status = self::STATUS_ACTIVE ): array {
		if ( is_array( $course_ids ) && empty( $course_ids ) ) {
			return array();
		}
		$orderby = in_array( $orderby, self::STUDENT_ORDERBY, true ) ? $orderby : 'id';
		$desc    = 'desc' === strtolower( $order );
		$cmp     = $desc ? '<' : '>';
		$dir     = $desc ? 'DESC' : 'ASC';
		$limit   = max( 1, min( 100, $limit ) );

		$where = array( 'e.status = %s' );
		$args  = array( $status );

		if ( is_array( $course_ids ) ) {
			$course_ids = array_values( array_unique( array_map( 'intval', $course_ids ) ) );
			$where[]    = 'e.course_id IN (' . implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) ) . ')';
			$args       = array_merge( $args, $course_ids );
		}

		if ( '' !== $search ) {
			$like    = '%' . $this->db->esc_like( $search ) . '%';
			$where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)';
			array_push( $args, $like, $like, $like );
		}

		$having      = '';
		$having_args = array();
		if ( null !== $cursor ) {
			$cursor_id = (int) $cursor[1];
			if ( 'id' === $orderby ) {
				$where[] = "e.user_id {$cmp} %d";
				$args[]  = $cursor_id;
			} elseif ( 'name' === $orderby ) {
				$where[] = "(u.display_name {$cmp} %s OR (u.display_name = %s AND e.user_id {$cmp} %d))";
				array_push( $args, (string) $cursor[0], (string) $cursor[0], $cursor_id );
			} else {
				$having      = "HAVING (last_enrolled_at {$cmp} %s OR (last_enrolled_at = %s AND e.user_id {$cmp} %d))";
				$having_args = array( (string) $cursor[0], (string) $cursor[0], $cursor_id );
			}
		}

		$sort_column = array(
			'id'       => 'e.user_id',
			'name'     => 'u.display_name',
			'enrolled' => 'last_enrolled_at',
		)[ $orderby ];

		$sql = 'SELECT e.user_id, u.display_name, u.user_email, COUNT(*) AS courses, MAX(e.enrolled_at) AS last_enrolled_at
			FROM %i e INNER JOIN %i u ON u.ID = e.user_id
			WHERE ' . implode( ' AND ', $where ) . '
			GROUP BY e.user_id, u.display_name, u.user_email
			' . $having . '
			ORDER BY ' . $sort_column . ' ' . $dir . ', e.user_id ' . $dir . '
			LIMIT %d';

		$all_args = array_merge( array( $this->table(), $this->db->users ), $args, $having_args, array( $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; every value is a placeholder and column names come from a whitelist.
		$rows = $this->db->get_results( $this->db->prepare( $sql, $all_args ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map(
			static function ( $row ) {
				return array(
					'user_id'          => (int) $row['user_id'],
					'display_name'     => (string) $row['display_name'],
					'user_email'       => (string) $row['user_email'],
					'courses'          => (int) $row['courses'],
					'last_enrolled_at' => (string) $row['last_enrolled_at'],
				);
			},
			$rows
		);
	}

	/**
	 * Counts the learners students() would list across all pages.
	 *
	 * @param array<int, int>|null $course_ids Limit to these courses; null for every course.
	 * @param string               $search     Matches display name, email or login.
	 * @param string               $status     Enrolment status to count.
	 * @return int
	 */
	public function count_students( ?array $course_ids, string $search = '', string $status = self::STATUS_ACTIVE ): int {
		if ( is_array( $course_ids ) && empty( $course_ids ) ) {
			return 0;
		}
		$where = array( 'e.status = %s' );
		$args  = array( $this->table(), $this->db->users, $status );
		if ( is_array( $course_ids ) ) {
			$course_ids = array_values( array_unique( array_map( 'intval', $course_ids ) ) );
			$where[]    = 'e.course_id IN (' . implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) ) . ')';
			$args       = array_merge( $args, $course_ids );
		}
		if ( '' !== $search ) {
			$like    = '%' . $this->db->esc_like( $search ) . '%';
			$where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		$sql = 'SELECT COUNT(DISTINCT e.user_id) FROM %i e INNER JOIN %i u ON u.ID = e.user_id WHERE ' . implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; every value is a placeholder.
		return (int) $this->db->get_var( $this->db->prepare( $sql, $args ) );
	}

	/**
	 * Returns every enrolment of a user keyed by course ID, cached.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$key    = 'enrollments:user:' . $user_id;
		$cached = Cache::get( $key, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cached below.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id ASC', $this->table(), $user_id ), ARRAY_A );

		$by_course = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row                                  = $this->normalize( $row );
			$by_course[ (int) $row['course_id'] ] = $row;
		}
		Cache::set( $key, $by_course );
		return $by_course;
	}

	/**
	 * Casts the numeric columns of a row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function normalize( array $row ): array {
		foreach ( array( 'id', 'user_id', 'course_id' ) as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		foreach ( array( 'source_id', 'group_id' ) as $column ) {
			$row[ $column ] = null === $row[ $column ] ? null : (int) $row[ $column ];
		}
		return $row;
	}

	/**
	 * Drops the cached rows of a user and the active count of a course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return void
	 */
	public function invalidate( int $user_id, int $course_id ): void {
		Cache::delete( 'enrollments:user:' . $user_id );
		Cache::delete( 'enrolled_count:' . $course_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Also clears the caches of the row's user and course.
	 *
	 * @param int                  $id   Row ID.
	 * @param array<string, mixed> $data Column values.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		$row    = $this->find( $id );
		$result = parent::update( $id, $data );
		if ( null !== $row ) {
			$this->invalidate( (int) $row['user_id'], (int) $row['course_id'] );
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int
	 */
	public function insert( array $data ): int {
		$id = parent::insert( $data );
		$this->invalidate( (int) ( $data['user_id'] ?? 0 ), (int) ( $data['course_id'] ?? 0 ) );
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		$row    = $this->find( $id );
		$result = parent::delete( $id );
		if ( null !== $row ) {
			$this->invalidate( (int) $row['user_id'], (int) $row['course_id'] );
		}
		return $result;
	}
}
