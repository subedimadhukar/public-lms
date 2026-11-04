<?php
/**
 * Server-side grading of quiz answers.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Quizzes;

defined( 'ABSPATH' ) || exit;

/**
 * Grades one answer against one question's answer key (ADR-016, ADR-042).
 *
 * Pure: no database, no globals, so every rule is unit-tested directly.
 * Scoring per type:
 *
 * - single, true_false, short_answer: all or nothing.
 * - multiple: all or nothing; the chosen set must equal the correct set.
 * - fill_blank: each blank earns its share; the answer is "correct" when all are.
 * - matching: each pair earns its share; the answer is "correct" when all are.
 *
 * Text comparisons (fill blank, short answer) ignore case, surrounding space
 * and repeated inner spaces. Answers of the wrong shape score zero.
 */
final class Grader {

	/**
	 * Grades an answer.
	 *
	 * @param array<string, mixed> $question Question from QuestionBank::get(), with its answer key.
	 * @param mixed                $answer   The learner's answer, as sent; null when unanswered.
	 * @return array{fraction: float, correct: bool, points: float, max_points: float}
	 */
	public static function grade( array $question, $answer ): array {
		$max      = max( 0.0, (float) ( $question['points'] ?? 1 ) );
		$fraction = null === $answer ? 0.0 : self::fraction( $question, $answer );
		$fraction = max( 0.0, min( 1.0, $fraction ) );
		return array(
			'fraction'   => $fraction,
			'correct'    => $fraction >= 1.0,
			'points'     => round( $max * $fraction, 2 ),
			'max_points' => $max,
		);
	}

	/**
	 * Returns the share of the question answered correctly, from 0 to 1.
	 *
	 * @param array<string, mixed> $question Question with its answer key.
	 * @param mixed                $answer   Learner's answer.
	 * @return float
	 */
	private static function fraction( array $question, $answer ): float {
		switch ( (string) ( $question['type'] ?? '' ) ) {
			case QuestionBank::SINGLE:
				$correct = self::correct_option_ids( $question );
				return is_string( $answer ) && array( $answer ) === $correct ? 1.0 : 0.0;

			case QuestionBank::MULTIPLE:
				if ( ! is_array( $answer ) ) {
					return 0.0;
				}
				$chosen = array_values( array_unique( array_filter( $answer, 'is_string' ) ) );
				$right  = self::correct_option_ids( $question );
				sort( $chosen );
				sort( $right );
				return ! empty( $right ) && $chosen === $right ? 1.0 : 0.0;

			case QuestionBank::TRUE_FALSE:
				return is_bool( $answer ) && (bool) ( $question['answer'] ?? false ) === $answer ? 1.0 : 0.0;

			case QuestionBank::SHORT_ANSWER:
				if ( ! is_string( $answer ) || '' === self::normalize( $answer ) ) {
					return 0.0;
				}
				$accepted = array_map( array( self::class, 'normalize' ), (array) ( $question['accepted'] ?? array() ) );
				return in_array( self::normalize( $answer ), $accepted, true ) ? 1.0 : 0.0;

			case QuestionBank::FILL_BLANK:
				$blanks = (array) ( $question['blanks'] ?? array() );
				if ( empty( $blanks ) || ! is_array( $answer ) ) {
					return 0.0;
				}
				$right = 0;
				foreach ( array_values( $blanks ) as $index => $accepted ) {
					$given = $answer[ $index ] ?? null;
					if ( is_string( $given ) && in_array( self::normalize( $given ), array_map( array( self::class, 'normalize' ), (array) $accepted ), true ) ) {
						++$right;
					}
				}
				return $right / count( $blanks );

			case QuestionBank::MATCHING:
				$pairs = (array) ( $question['pairs'] ?? array() );
				if ( empty( $pairs ) || ! is_array( $answer ) ) {
					return 0.0;
				}
				$right = 0;
				foreach ( $pairs as $pair ) {
					$given = $answer[ (string) $pair['prompt_id'] ] ?? null;
					if ( is_string( $given ) && $given === (string) $pair['match_id'] ) {
						++$right;
					}
				}
				return $right / count( $pairs );
		}
		return 0.0;
	}

	/**
	 * Normalises free text for comparison: trimmed, inner spaces collapsed, lower case.
	 *
	 * @param mixed $text Text.
	 * @return string
	 */
	public static function normalize( $text ): string {
		$text = is_scalar( $text ) ? (string) $text : '';
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Returns the IDs of the correct options of a choice question.
	 *
	 * @param array<string, mixed> $question Question.
	 * @return array<int, string>
	 */
	private static function correct_option_ids( array $question ): array {
		$ids = array();
		foreach ( (array) ( $question['options'] ?? array() ) as $option ) {
			if ( ! empty( $option['correct'] ) ) {
				$ids[] = (string) $option['id'];
			}
		}
		return $ids;
	}
}
