<?php
/**
 * Quiz attempt repository.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

use PublicLMS\Core\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Rows in plms_quiz_attempts (one per attempt) and plms_quiz_answers (one per
 * question of an attempt).
 *
 * Attempt status: `in_progress` while the learner answers, `graded` once the
 * server scored every answer, `pending` when an answer waits for a person
 * (Pro's essay questions). `question_ids` is the JSON list of the questions
 * drawn for the attempt, in the order shown. Answers hold the learner's
 * answer as JSON; `is_correct` and `points` stay null until graded.
 *
 * Cache key (group 'publiclms'): `quiz_attempts:{user_id}:{quiz_id}` holds a
 * user's attempts at a quiz, newest first.
 */
class QuizAttemptRepository extends AbstractRepository implements QuizAttemptRepositoryInterface {

	const STATUS_IN_PROGRESS = 'in_progress';
	const STATUS_GRADED      = 'graded';
	const STATUS_PENDING     = 'pending';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function table_name(): string {
		return 'plms_quiz_attempts';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function columns(): array {
		return array(
			'user_id'        => '%d',
			'quiz_id'        => '%d',
			'course_id'      => '%d',
			'attempt_number' => '%d',
			'status'         => '%s',
			'score'          => '%f',
			'max_score'      => '%f',
			'percent'        => '%f',
			'passed'         => '%d',
			'question_ids'   => '%s',
			'time_spent'     => '%d',
			'started_at'     => '%s',
			'submitted_at'   => '%s',
			'graded_at'      => '%s',
			'graded_by'      => '%d',
		);
	}

	/**
	 * Returns the answers table name.
	 *
	 * @return string
	 */
	public function answers_table(): string {
		return $this->db->prefix . 'plms_quiz_answers';
	}

	/**
	 * Starts an attempt.
	 *
	 * @param int             $user_id      User ID.
	 * @param int             $quiz_id      Quiz ID.
	 * @param int             $course_id    The quiz's course.
	 * @param array<int, int> $question_ids Questions drawn, in order.
	 * @param float           $max_score    Sum of the questions' points.
	 * @return int Attempt ID, or 0.
	 */
	public function start( int $user_id, int $quiz_id, int $course_id, array $question_ids, float $max_score ): int {
		$number = $this->count_attempts( $user_id, $quiz_id ) + 1;
		$id     = $this->insert(
			array(
				'user_id'        => $user_id,
				'quiz_id'        => $quiz_id,
				'course_id'      => $course_id,
				'attempt_number' => $number,
				'status'         => self::STATUS_IN_PROGRESS,
				'max_score'      => $max_score,
				'question_ids'   => (string) wp_json_encode( array_values( array_map( 'intval', $question_ids ) ) ),
				'started_at'     => $this->now(),
			)
		);
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Attempt ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = parent::find( $id );
		return null === $row ? null : $this->normalize( $row );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function attempts_for_user( int $user_id, int $quiz_id ): array {
		if ( $user_id <= 0 || $quiz_id <= 0 ) {
			return array();
		}
		$key    = 'quiz_attempts:' . $user_id . ':' . $quiz_id;
		$cached = Cache::get( $key, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cached below.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d AND quiz_id = %d ORDER BY id DESC', $this->table(), $user_id, $quiz_id ), ARRAY_A );
		$rows = array_map( array( $this, 'normalize' ), is_array( $rows ) ? $rows : array() );
		Cache::set( $key, $rows );
		return $rows;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return int
	 */
	public function count_attempts( int $user_id, int $quiz_id ): int {
		return count( $this->attempts_for_user( $user_id, $quiz_id ) );
	}

	/**
	 * Counts a user's submitted attempts at a quiz (graded or waiting for grading).
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return int
	 */
	public function count_finished( int $user_id, int $quiz_id ): int {
		return count(
			array_filter(
				$this->attempts_for_user( $user_id, $quiz_id ),
				static function ( $row ) {
					return self::STATUS_IN_PROGRESS !== $row['status'];
				}
			)
		);
	}

	/**
	 * Returns a user's attempt in progress at a quiz, or null.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return array<string, mixed>|null
	 */
	public function in_progress( int $user_id, int $quiz_id ): ?array {
		foreach ( $this->attempts_for_user( $user_id, $quiz_id ) as $row ) {
			if ( self::STATUS_IN_PROGRESS === $row['status'] ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Returns a user's best graded attempt at a quiz: highest percent, then earliest.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return array<string, mixed>|null
	 */
	public function best( int $user_id, int $quiz_id ): ?array {
		$best = null;
		foreach ( array_reverse( $this->attempts_for_user( $user_id, $quiz_id ) ) as $row ) {
			if ( self::STATUS_GRADED !== $row['status'] ) {
				continue;
			}
			if ( null === $best || (float) $row['percent'] > (float) $best['percent'] ) {
				$best = $row;
			}
		}
		return $best;
	}

	/**
	 * Records the grade of a submitted attempt.
	 *
	 * @param int                  $attempt_id Attempt ID.
	 * @param array<string, mixed> $data       score, max_score, percent, passed, status, time_spent, graded_by.
	 * @return bool
	 */
	public function finish( int $attempt_id, array $data ): bool {
		$now  = $this->now();
		$data = array_merge(
			array(
				'submitted_at' => $now,
				'graded_at'    => $now,
			),
			$data
		);
		return $this->update( $attempt_id, $data );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $attempt_id  Attempt ID.
	 * @param int                  $question_id Question ID.
	 * @param array<string, mixed> $answer      answer (any JSON value), and when graded is_correct, points, max_points, feedback, graded_by.
	 * @return bool
	 */
	public function save_answer( int $attempt_id, int $question_id, array $answer ): bool {
		$now    = $this->now();
		$graded = array_key_exists( 'points', $answer );
		$sql    = 'INSERT INTO %i (attempt_id, question_id, answer, is_correct, points, max_points, feedback, answered_at, graded_at, graded_by)
			VALUES (%d, %d, %s, ' . ( $graded ? '%d, %f, %f, %s, %s, %s, %d' : 'NULL, NULL, NULL, NULL, %s, NULL, NULL' ) . ')
			ON DUPLICATE KEY UPDATE
				answered_at = IF(VALUES(answer) <=> answer, answered_at, VALUES(answered_at)),
				answer = VALUES(answer),
				is_correct = VALUES(is_correct),
				points = VALUES(points),
				max_points = VALUES(max_points),
				feedback = VALUES(feedback),
				graded_at = VALUES(graded_at),
				graded_by = VALUES(graded_by)';

		$args = array( $this->answers_table(), $attempt_id, $question_id, (string) wp_json_encode( $answer['answer'] ?? null ) );
		if ( $graded ) {
			array_push(
				$args,
				empty( $answer['is_correct'] ) ? 0 : 1,
				(float) $answer['points'],
				(float) ( $answer['max_points'] ?? 0 ),
				(string) ( $answer['feedback'] ?? '' ),
				$now,
				$now,
				(int) ( $answer['graded_by'] ?? 0 )
			);
		} else {
			$args[] = $now;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; built from placeholders only.
		return false !== $this->db->query( $this->db->prepare( $sql, $args ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $attempt_id Attempt ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function answers( int $attempt_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; read once per attempt.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE attempt_id = %d', $this->answers_table(), $attempt_id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['question_id'] ] = array(
				'question_id' => (int) $row['question_id'],
				'answer'      => null === $row['answer'] ? null : json_decode( (string) $row['answer'], true ),
				'is_correct'  => null === $row['is_correct'] ? null : (bool) $row['is_correct'],
				'points'      => null === $row['points'] ? null : (float) $row['points'],
				'max_points'  => null === $row['max_points'] ? null : (float) $row['max_points'],
				'feedback'    => (string) $row['feedback'],
				'answered_at' => (string) $row['answered_at'],
			);
		}
		return $out;
	}

	/**
	 * Returns a user's attempts across quizzes, newest first, for the dashboard.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent_for_user( int $user_id, int $limit = 200 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; dashboard read.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d AND status <> %s ORDER BY id DESC LIMIT %d', $this->table(), $user_id, self::STATUS_IN_PROGRESS, max( 1, min( 1000, $limit ) ) ), ARRAY_A );
		return array_map( array( $this, 'normalize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Returns the best graded percent per user and quiz, for the gradebook.
	 *
	 * @param array<int, int> $user_ids Users.
	 * @param array<int, int> $quiz_ids Quizzes.
	 * @return array<int, array<int, array{percent: float, score: float, max_score: float, passed: bool, attempts: int}>> Keyed by user, then quiz.
	 */
	public function best_by_users( array $user_ids, array $quiz_ids ): array {
		$user_ids = array_values( array_filter( array_map( 'intval', $user_ids ) ) );
		$quiz_ids = array_values( array_filter( array_map( 'intval', $quiz_ids ) ) );
		if ( empty( $user_ids ) || empty( $quiz_ids ) ) {
			return array();
		}
		$sql  = 'SELECT user_id, quiz_id, percent, score, max_score, passed FROM %i
			WHERE status = %s AND user_id IN (' . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')
			AND quiz_id IN (' . implode( ',', array_fill( 0, count( $quiz_ids ), '%d' ) ) . ')
			ORDER BY id ASC';
		$args = array_merge( array( $this->table(), self::STATUS_GRADED ), $user_ids, $quiz_ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; placeholders only.
		$rows = $this->db->get_results( $this->db->prepare( $sql, $args ), ARRAY_A );
		$best = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$user = (int) $row['user_id'];
			$quiz = (int) $row['quiz_id'];
			$prev = $best[ $user ][ $quiz ] ?? null;
			if ( null === $prev || (float) $row['percent'] > $prev['percent'] ) {
				$best[ $user ][ $quiz ] = array(
					'percent'   => (float) $row['percent'],
					'score'     => (float) $row['score'],
					'max_score' => (float) $row['max_score'],
					'passed'    => (bool) $row['passed'],
					'attempts'  => null === $prev ? 1 : $prev['attempts'] + 1,
				);
			} else {
				$best[ $user ][ $quiz ]['attempts'] = $prev['attempts'] + 1;
			}
		}
		return $best;
	}

	/**
	 * Returns a page of a user's attempts with their answers, oldest first, for data export.
	 *
	 * @param int $user_id User ID.
	 * @param int $page    1-based page.
	 * @param int $per     Rows per page.
	 * @return array<int, array<string, mixed>>
	 */
	public function export_page( int $user_id, int $page, int $per = 100 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; privacy export.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', $this->table(), $user_id, $per, max( 0, ( $page - 1 ) * $per ) ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row            = $this->normalize( $row );
			$row['answers'] = $this->answers( (int) $row['id'] );
			$out[]          = $row;
		}
		return $out;
	}

	/**
	 * Deletes every attempt and answer of a user, for data erasure and fixture resets.
	 *
	 * @param int $user_id User ID.
	 * @return int Attempts deleted.
	 */
	public function delete_for_user( int $user_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; listing rows to delete.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT id, quiz_id FROM %i WHERE user_id = %d', $this->table(), $user_id ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$this->db->delete( $this->answers_table(), array( 'attempt_id' => (int) $row['id'] ), array( '%d' ) );
			Cache::delete( 'quiz_attempts:' . $user_id . ':' . (int) $row['quiz_id'] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; caches cleared above.
		$this->db->delete( $this->table(), array( 'user_id' => $user_id ), array( '%d' ) );
		return count( $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Also clears the cached attempts of the row's user and quiz.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int
	 */
	public function insert( array $data ): int {
		$id = parent::insert( $data );
		Cache::delete( 'quiz_attempts:' . (int) ( $data['user_id'] ?? 0 ) . ':' . (int) ( $data['quiz_id'] ?? 0 ) );
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $id   Attempt ID.
	 * @param array<string, mixed> $data Column values.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		$row    = parent::find( $id );
		$result = parent::update( $id, $data );
		if ( null !== $row ) {
			Cache::delete( 'quiz_attempts:' . (int) $row['user_id'] . ':' . (int) $row['quiz_id'] );
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Attempt ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		$row = parent::find( $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$this->db->delete( $this->answers_table(), array( 'attempt_id' => $id ), array( '%d' ) );
		$result = parent::delete( $id );
		if ( null !== $row ) {
			Cache::delete( 'quiz_attempts:' . (int) $row['user_id'] . ':' . (int) $row['quiz_id'] );
		}
		return $result;
	}

	/**
	 * Casts the columns of an attempt row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function normalize( array $row ): array {
		foreach ( array( 'id', 'user_id', 'quiz_id', 'course_id', 'attempt_number' ) as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		foreach ( array( 'score', 'max_score', 'percent' ) as $column ) {
			$row[ $column ] = null === $row[ $column ] ? null : (float) $row[ $column ];
		}
		$row['passed']       = null === $row['passed'] ? null : (bool) $row['passed'];
		$row['time_spent']   = null === $row['time_spent'] ? null : (int) $row['time_spent'];
		$row['graded_by']    = null === $row['graded_by'] ? null : (int) $row['graded_by'];
		$ids                 = json_decode( (string) $row['question_ids'], true );
		$row['question_ids'] = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		return $row;
	}
}
