<?php
/**
 * Quiz attempt repository contract.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes plms_quiz_attempts and plms_quiz_answers.
 *
 * Scores are always computed on the server (ADR-016); this layer only stores them.
 */
interface QuizAttemptRepositoryInterface extends RepositoryInterface {

	/**
	 * Lists a user's attempts at a quiz, newest first.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function attempts_for_user( int $user_id, int $quiz_id ): array;

	/**
	 * Counts a user's attempts at a quiz.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return int
	 */
	public function count_attempts( int $user_id, int $quiz_id ): int;

	/**
	 * Stores or replaces the answer to one question in an attempt.
	 *
	 * @param int                  $attempt_id  Attempt ID.
	 * @param int                  $question_id Question ID.
	 * @param array<string, mixed> $answer      Answer payload and grading fields.
	 * @return bool
	 */
	public function save_answer( int $attempt_id, int $question_id, array $answer ): bool;

	/**
	 * Returns the answers of an attempt, keyed by question ID.
	 *
	 * @param int $attempt_id Attempt ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function answers( int $attempt_id ): array;
}
