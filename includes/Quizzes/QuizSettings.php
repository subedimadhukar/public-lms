<?php
/**
 * Quiz settings.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Quizzes;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes a quiz's settings and question list (post meta on
 * `plms_quiz`, ADR-042). Values are validated here, so REST, the seed command
 * and Pro write the same shapes.
 *
 * | Key                | Meta                       | Default | Meaning                                        |
 * |--------------------|----------------------------|---------|------------------------------------------------|
 * | questions          | _plms_quiz_questions       | []      | Question IDs in order                          |
 * | time_limit         | _plms_quiz_time_limit      | 0       | Minutes; 0 for no limit                        |
 * | max_attempts       | _plms_quiz_max_attempts    | 0       | Submitted attempts allowed; 0 for unlimited    |
 * | pass_mark          | _plms_quiz_pass_mark       | 70      | Percent needed to pass and complete the quiz   |
 * | question_count     | _plms_quiz_question_count  | 0       | Questions drawn at random per attempt; 0 = all |
 * | shuffle_questions  | _plms_quiz_shuffle         | false   | Shuffle question order per attempt             |
 * | shuffle_answers    | _plms_quiz_shuffle_answers | false   | Shuffle answer options per attempt             |
 * | show_results       | _plms_quiz_show_results    | true    | Show right or wrong per question after submit  |
 */
final class QuizSettings {

	const QUESTIONS         = '_plms_quiz_questions';
	const TIME_LIMIT        = '_plms_quiz_time_limit';
	const MAX_ATTEMPTS      = '_plms_quiz_max_attempts';
	const PASS_MARK         = '_plms_quiz_pass_mark';
	const QUESTION_COUNT    = '_plms_quiz_question_count';
	const SHUFFLE_QUESTIONS = '_plms_quiz_shuffle';
	const SHUFFLE_ANSWERS   = '_plms_quiz_shuffle_answers';
	const SHOW_RESULTS      = '_plms_quiz_show_results';

	/**
	 * Setting keys and their meta keys.
	 */
	const KEYS = array(
		'time_limit'        => self::TIME_LIMIT,
		'max_attempts'      => self::MAX_ATTEMPTS,
		'pass_mark'         => self::PASS_MARK,
		'question_count'    => self::QUESTION_COUNT,
		'shuffle_questions' => self::SHUFFLE_QUESTIONS,
		'shuffle_answers'   => self::SHUFFLE_ANSWERS,
		'show_results'      => self::SHOW_RESULTS,
	);

	/**
	 * Default values.
	 */
	const DEFAULTS = array(
		'time_limit'        => 0,
		'max_attempts'      => 0,
		'pass_mark'         => 70,
		'question_count'    => 0,
		'shuffle_questions' => false,
		'shuffle_answers'   => false,
		'show_results'      => true,
	);

	/**
	 * Returns a quiz's settings with defaults filled in.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array{time_limit: int, max_attempts: int, pass_mark: int, question_count: int, shuffle_questions: bool, shuffle_answers: bool, show_results: bool}
	 */
	public static function get( int $quiz_id ): array {
		$settings = self::DEFAULTS;
		foreach ( self::KEYS as $key => $meta ) {
			if ( metadata_exists( 'post', $quiz_id, $meta ) ) {
				$settings[ $key ] = self::clean( $key, get_post_meta( $quiz_id, $meta, true ) );
			}
		}
		/**
		 * Settings, typed for static analysis.
		 *
		 * @var array{time_limit: int, max_attempts: int, pass_mark: int, question_count: int, shuffle_questions: bool, shuffle_answers: bool, show_results: bool} $settings
		 */
		return $settings;
	}

	/**
	 * Writes the given settings; unknown keys are ignored.
	 *
	 * @param int                  $quiz_id Quiz ID.
	 * @param array<string, mixed> $values  Settings to write.
	 * @return void
	 */
	public static function update( int $quiz_id, array $values ): void {
		foreach ( self::KEYS as $key => $meta ) {
			if ( array_key_exists( $key, $values ) ) {
				$value = self::clean( $key, $values[ $key ] );
				update_post_meta( $quiz_id, $meta, is_bool( $value ) ? ( $value ? '1' : '0' ) : $value );
			}
		}
	}

	/**
	 * Returns the question IDs of a quiz, in order.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array<int, int>
	 */
	public static function question_ids( int $quiz_id ): array {
		$ids = get_post_meta( $quiz_id, self::QUESTIONS, true );
		return is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();
	}

	/**
	 * Sets the question IDs of a quiz, in order.
	 *
	 * @param int             $quiz_id Quiz ID.
	 * @param array<int, int> $ids     Question IDs.
	 * @return void
	 */
	public static function set_question_ids( int $quiz_id, array $ids ): void {
		update_post_meta( $quiz_id, self::QUESTIONS, array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) );
	}

	/**
	 * Cleans one setting value.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @return int|bool
	 */
	public static function clean( string $key, $value ) {
		switch ( $key ) {
			case 'time_limit':
				return min( 1440, is_scalar( $value ) ? absint( $value ) : 0 );
			case 'max_attempts':
				return min( 100, is_scalar( $value ) ? absint( $value ) : 0 );
			case 'pass_mark':
				return min( 100, is_scalar( $value ) ? absint( $value ) : 0 );
			case 'question_count':
				return min( 500, is_scalar( $value ) ? absint( $value ) : 0 );
		}
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
		}
		return (bool) $value;
	}
}
