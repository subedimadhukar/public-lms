<?php
/**
 * Assignment submission repository.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Rows in plms_assignment_submissions: one per hand-in (ADR-043).
 *
 * Status: `submitted` (waiting for a grade), `graded`, or `replaced` (the
 * learner handed in again before it was graded). `files` is JSON: a list of
 * { id, name, path, size, type } as PrivateFiles::store() returns them.
 * Grades are DECIMAL(10,2) points out of `max_grade`.
 */
class SubmissionRepository extends AbstractRepository {

	const STATUS_SUBMITTED = 'submitted';
	const STATUS_GRADED    = 'graded';
	const STATUS_REPLACED  = 'replaced';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function table_name(): string {
		return 'plms_assignment_submissions';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function columns(): array {
		return array(
			'user_id'       => '%d',
			'assignment_id' => '%d',
			'course_id'     => '%d',
			'attempt'       => '%d',
			'status'        => '%s',
			'content'       => '%s',
			'files'         => '%s',
			'grade'         => '%f',
			'max_grade'     => '%f',
			'passed'        => '%d',
			'feedback'      => '%s',
			'submitted_at'  => '%s',
			'graded_at'     => '%s',
			'graded_by'     => '%d',
			'updated_at'    => '%s',
		);
	}

	/**
	 * Records a hand-in.
	 *
	 * @param int                              $user_id       Learner.
	 * @param int                              $assignment_id Assignment.
	 * @param int                              $course_id     The assignment's course.
	 * @param string                           $content       Typed answer (sanitised HTML or text).
	 * @param array<int, array<string, mixed>> $files         Stored files.
	 * @param float                            $max_grade     Highest grade at the time.
	 * @return int Submission ID, or 0.
	 */
	public function create( int $user_id, int $assignment_id, int $course_id, string $content, array $files, float $max_grade ): int {
		$now = $this->now();
		return $this->insert(
			array(
				'user_id'       => $user_id,
				'assignment_id' => $assignment_id,
				'course_id'     => $course_id,
				'attempt'       => count( $this->for_user( $user_id, $assignment_id ) ) + 1,
				'status'        => self::STATUS_SUBMITTED,
				'content'       => $content,
				'files'         => (string) wp_json_encode( array_values( $files ) ),
				'max_grade'     => $max_grade,
				'submitted_at'  => $now,
				'updated_at'    => $now,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Submission ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = parent::find( $id );
		return null === $row ? null : $this->normalize( $row );
	}

	/**
	 * Returns a learner's hand-ins for an assignment, newest first.
	 *
	 * @param int $user_id       Learner.
	 * @param int $assignment_id Assignment.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id, int $assignment_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; small per-learner read.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d AND assignment_id = %d ORDER BY id DESC', $this->table(), $user_id, $assignment_id ), ARRAY_A );
		return array_map( array( $this, 'normalize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Returns a learner's current hand-in for an assignment (the newest not replaced), or null.
	 *
	 * @param int $user_id       Learner.
	 * @param int $assignment_id Assignment.
	 * @return array<string, mixed>|null
	 */
	public function current( int $user_id, int $assignment_id ): ?array {
		foreach ( $this->for_user( $user_id, $assignment_id ) as $row ) {
			if ( self::STATUS_REPLACED !== $row['status'] ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Lists hand-ins for teachers, newest first, with keyset pagination on the ID.
	 *
	 * @param array<int, int>|null $course_ids Limit to these courses; null for every course.
	 * @param string               $status     A status, or '' for submitted and graded.
	 * @param int                  $assignment Limit to one assignment, or 0.
	 * @param int                  $before_id  Rows with an ID below this; 0 from the newest.
	 * @param int                  $limit      Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( ?array $course_ids, string $status, int $assignment, int $before_id, int $limit ): array {
		list( $where, $args ) = $this->where( $course_ids, $status, $assignment );
		if ( $before_id > 0 ) {
			$where[] = 'id < %d';
			$args[]  = $before_id;
		}
		$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; placeholders only.
		$rows = $this->db->get_results( $this->db->prepare( $sql, array_merge( array( $this->table() ), $args, array( max( 1, min( 100, $limit ) ) ) ) ), ARRAY_A );
		return array_map( array( $this, 'normalize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Counts what search() would list across all pages.
	 *
	 * @param array<int, int>|null $course_ids Limit to these courses; null for every course.
	 * @param string               $status     A status, or ''.
	 * @param int                  $assignment Limit to one assignment, or 0.
	 * @return int
	 */
	public function count( ?array $course_ids, string $status, int $assignment ): int {
		list( $where, $args ) = $this->where( $course_ids, $status, $assignment );
		$sql                  = 'SELECT COUNT(*) FROM %i WHERE ' . implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; placeholders only.
		return (int) $this->db->get_var( $this->db->prepare( $sql, array_merge( array( $this->table() ), $args ) ) );
	}

	/**
	 * Returns each learner's current hand-in per assignment, for the gradebook.
	 *
	 * @param array<int, int> $user_ids       Learners.
	 * @param array<int, int> $assignment_ids Assignments.
	 * @return array<int, array<int, array<string, mixed>>> Keyed by user, then assignment.
	 */
	public function current_by_users( array $user_ids, array $assignment_ids ): array {
		$user_ids       = array_values( array_filter( array_map( 'intval', $user_ids ) ) );
		$assignment_ids = array_values( array_filter( array_map( 'intval', $assignment_ids ) ) );
		if ( empty( $user_ids ) || empty( $assignment_ids ) ) {
			return array();
		}
		$sql  = 'SELECT id, user_id, assignment_id, status, grade, max_grade, passed, submitted_at FROM %i
			WHERE status <> %s AND user_id IN (' . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')
			AND assignment_id IN (' . implode( ',', array_fill( 0, count( $assignment_ids ), '%d' ) ) . ')
			ORDER BY id ASC';
		$args = array_merge( array( $this->table(), self::STATUS_REPLACED ), $user_ids, $assignment_ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; placeholders only.
		$rows = $this->db->get_results( $this->db->prepare( $sql, $args ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['user_id'] ][ (int) $row['assignment_id'] ] = array(
				'id'           => (int) $row['id'],
				'status'       => (string) $row['status'],
				'grade'        => null === $row['grade'] ? null : (float) $row['grade'],
				'max_grade'    => null === $row['max_grade'] ? null : (float) $row['max_grade'],
				'passed'       => null === $row['passed'] ? null : (bool) $row['passed'],
				'submitted_at' => (string) $row['submitted_at'],
			);
		}
		return $out;
	}

	/**
	 * Returns a page of a learner's hand-ins, oldest first, for data export and erasure.
	 *
	 * @param int $user_id Learner.
	 * @param int $page    1-based page.
	 * @param int $per     Rows per page.
	 * @return array<int, array<string, mixed>>
	 */
	public function page_for_user( int $user_id, int $page, int $per = 100 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; privacy tools.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', $this->table(), $user_id, $per, max( 0, ( $page - 1 ) * $per ) ), ARRAY_A );
		return array_map( array( $this, 'normalize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Casts a row's columns and decodes its files.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function normalize( array $row ): array {
		foreach ( array( 'id', 'user_id', 'assignment_id', 'course_id', 'attempt' ) as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		foreach ( array( 'grade', 'max_grade' ) as $column ) {
			$row[ $column ] = null === $row[ $column ] ? null : (float) $row[ $column ];
		}
		$row['passed']    = null === $row['passed'] ? null : (bool) $row['passed'];
		$row['graded_by'] = null === $row['graded_by'] ? null : (int) $row['graded_by'];
		$files            = json_decode( (string) $row['files'], true );
		$row['files']     = is_array( $files ) ? $files : array();
		$row['content']   = (string) $row['content'];
		$row['feedback']  = (string) $row['feedback'];
		return $row;
	}

	/**
	 * Builds the WHERE parts of a teacher listing.
	 *
	 * @param array<int, int>|null $course_ids Courses, or null for all.
	 * @param string               $status     Status, or ''.
	 * @param int                  $assignment Assignment, or 0.
	 * @return array{0: array<int, string>, 1: array<int, mixed>}
	 */
	private function where( ?array $course_ids, string $status, int $assignment ): array {
		$where = array();
		$args  = array();
		if ( '' === $status ) {
			$where[] = 'status <> %s';
			$args[]  = self::STATUS_REPLACED;
		} else {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( null !== $course_ids ) {
			$course_ids = array_values( array_filter( array_map( 'intval', $course_ids ) ) );
			$course_ids = empty( $course_ids ) ? array( 0 ) : $course_ids;
			$where[]    = 'course_id IN (' . implode( ',', array_fill( 0, count( $course_ids ), '%d' ) ) . ')';
			$args       = array_merge( $args, $course_ids );
		}
		if ( $assignment > 0 ) {
			$where[] = 'assignment_id = %d';
			$args[]  = $assignment;
		}
		return array( $where, $args );
	}
}
