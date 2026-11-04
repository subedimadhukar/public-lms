<?php
/**
 * The question bank.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Quizzes;

use PublicLMS\PostTypes\PostTypes;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and saves questions (`plms_question` posts), and builds
 * the learner's view of a question, which never contains its answer key.
 *
 * A question is reusable: any number of quizzes list it (ADR-042). Its text
 * is the post content (HTML, through wp_kses_post); its type, points,
 * explanation and answer key live together in the protected meta
 * `_plms_question_data`, which is not registered for REST, so no wp/v2 route
 * can expose it. The question post type is not on wp/v2 either.
 *
 * Shapes of the answer key, by type:
 *
 * - single, multiple: `options` [{ id, text, correct }], 2 to 10.
 * - true_false:       `answer` bool.
 * - fill_blank:       `template` "The capital of Nepal is {{Kathmandu}}." and the
 *                     derived `blanks` [[ accepted answers ]]; `|` separates alternatives.
 * - short_answer:     `accepted` [ answers ], compared ignoring case and spacing.
 * - matching:         `pairs` [{ prompt_id, prompt, match_id, match }], 2 to 10.
 */
final class QuestionBank {

	const SINGLE       = 'single';
	const MULTIPLE     = 'multiple';
	const TRUE_FALSE   = 'true_false';
	const FILL_BLANK   = 'fill_blank';
	const SHORT_ANSWER = 'short_answer';
	const MATCHING     = 'matching';

	/**
	 * The six free question types (PRD §3).
	 */
	const TYPES = array( self::SINGLE, self::MULTIPLE, self::TRUE_FALSE, self::FILL_BLANK, self::SHORT_ANSWER, self::MATCHING );

	/**
	 * Meta key holding type, points, explanation and answer key.
	 */
	const DATA_META = '_plms_question_data';

	/**
	 * Most options or pairs a question may have.
	 */
	const MAX_CHOICES = 10;

	/**
	 * Returns a question with its answer key, or null when it is not a question.
	 *
	 * @param int $question_id Question ID.
	 * @return array<string, mixed>|null
	 */
	public function get( int $question_id ): ?array {
		$post = get_post( $question_id );
		if ( ! $post instanceof WP_Post || PostTypes::QUESTION !== $post->post_type ) {
			return null;
		}
		$data = get_post_meta( $question_id, self::DATA_META, true );
		$data = is_array( $data ) ? $data : array();
		$type = in_array( $data['type'] ?? '', self::TYPES, true ) ? (string) $data['type'] : self::SINGLE;

		return array_merge(
			array(
				'options'  => array(),
				'answer'   => false,
				'template' => '',
				'blanks'   => array(),
				'accepted' => array(),
				'pairs'    => array(),
			),
			$data,
			array(
				'id'          => (int) $post->ID,
				'type'        => $type,
				'status'      => (string) $post->post_status,
				'title'       => (string) $post->post_title,
				'text'        => (string) $post->post_content,
				'points'      => max( 0.0, (float) ( $data['points'] ?? 1 ) ),
				'explanation' => (string) ( $data['explanation'] ?? '' ),
				'author'      => (int) $post->post_author,
			)
		);
	}

	/**
	 * Validates and normalises a question from editor input.
	 *
	 * @param array<string, mixed> $input Raw fields: type, text, points, explanation and the type's key.
	 * @return array<string, mixed>|WP_Error The clean question data (without id), or why it is invalid.
	 */
	public function sanitize( array $input ) {
		$type = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return $this->invalid( __( 'Choose a question type.', 'public-lms' ) );
		}
		$text = trim( wp_kses_post( (string) ( $input['text'] ?? '' ) ) );
		if ( '' === $text && self::FILL_BLANK !== $type ) {
			return $this->invalid( __( 'Write the question.', 'public-lms' ) );
		}
		$clean = array(
			'type'        => $type,
			'text'        => $text,
			'points'      => round( max( 0.0, min( 1000.0, (float) ( $input['points'] ?? 1 ) ) ), 2 ),
			'explanation' => trim( wp_kses_post( (string) ( $input['explanation'] ?? '' ) ) ),
		);

		switch ( $type ) {
			case self::SINGLE:
			case self::MULTIPLE:
				$options = $this->sanitize_options( (array) ( $input['options'] ?? array() ) );
				if ( count( $options ) < 2 ) {
					return $this->invalid( __( 'Add at least two answers.', 'public-lms' ) );
				}
				$right = count( array_filter( wp_list_pluck( $options, 'correct' ) ) );
				if ( self::SINGLE === $type && 1 !== $right ) {
					return $this->invalid( __( 'Mark exactly one answer as correct.', 'public-lms' ) );
				}
				if ( self::MULTIPLE === $type && $right < 1 ) {
					return $this->invalid( __( 'Mark at least one answer as correct.', 'public-lms' ) );
				}
				$clean['options'] = $options;
				break;

			case self::TRUE_FALSE:
				$clean['answer'] = self::flag( $input['answer'] ?? false );
				break;

			case self::FILL_BLANK:
				$template = trim( sanitize_textarea_field( (string) ( $input['template'] ?? '' ) ) );
				$blanks   = self::parse_blanks( $template );
				if ( empty( $blanks ) ) {
					return $this->invalid( __( 'Wrap each missing word in double braces, for example {{Kathmandu}}.', 'public-lms' ) );
				}
				$clean['template'] = $template;
				$clean['blanks']   = $blanks;
				break;

			case self::SHORT_ANSWER:
				$accepted = array();
				foreach ( (array) ( $input['accepted'] ?? array() ) as $answer ) {
					$answer = trim( sanitize_text_field( (string) $answer ) );
					if ( '' !== $answer && ! in_array( Grader::normalize( $answer ), array_map( array( Grader::class, 'normalize' ), $accepted ), true ) ) {
						$accepted[] = $answer;
					}
				}
				if ( empty( $accepted ) ) {
					return $this->invalid( __( 'Add at least one accepted answer.', 'public-lms' ) );
				}
				$clean['accepted'] = array_slice( $accepted, 0, 20 );
				break;

			case self::MATCHING:
				$pairs = $this->sanitize_pairs( (array) ( $input['pairs'] ?? array() ) );
				if ( count( $pairs ) < 2 ) {
					return $this->invalid( __( 'Add at least two pairs to match.', 'public-lms' ) );
				}
				$clean['pairs'] = $pairs;
				break;
		}
		return $clean;
	}

	/**
	 * Creates or updates a question from clean data.
	 *
	 * @param array<string, mixed> $clean       Output of sanitize().
	 * @param int                  $question_id Existing question, or 0 to create one.
	 * @param int                  $author_id   Author of a new question; 0 for the current user.
	 * @return int|WP_Error The question ID.
	 */
	public function save( array $clean, int $question_id = 0, int $author_id = 0 ) {
		$text    = (string) $clean['text'];
		$label   = self::FILL_BLANK === $clean['type'] && '' === trim( wp_strip_all_tags( $text ) )
			? preg_replace( '/\{\{.*?\}\}/u', '____', (string) $clean['template'] )
			: wp_strip_all_tags( $text );
		$postarr = array(
			'post_type'    => PostTypes::QUESTION,
			'post_title'   => wp_trim_words( (string) $label, 16, '…' ),
			'post_content' => $text,
			'post_status'  => 'publish',
		);
		if ( $question_id > 0 ) {
			$postarr['ID'] = $question_id;
			$result        = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$postarr['post_author'] = $author_id > 0 ? $author_id : get_current_user_id();
			$result                 = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = $clean;
		unset( $data['text'] );
		update_post_meta( (int) $result, self::DATA_META, wp_slash( $data ) );
		return (int) $result;
	}

	/**
	 * Returns the view of a question a learner gets while answering: no answer key.
	 *
	 * Options and matches are shuffled with a seed, so the order is stable for
	 * one attempt and different between attempts.
	 *
	 * @param array<string, mixed> $question Question from get().
	 * @param string               $seed     Seed for the shuffles, for example the attempt ID.
	 * @param bool                 $shuffle  Whether to shuffle answer options (matches are always shuffled).
	 * @return array<string, mixed>
	 */
	public function public_view( array $question, string $seed, bool $shuffle ): array {
		$view = array(
			'id'     => (int) $question['id'],
			'type'   => (string) $question['type'],
			'text'   => wp_kses_post( (string) $question['text'] ),
			'points' => (float) $question['points'],
		);
		switch ( $question['type'] ) {
			case self::SINGLE:
			case self::MULTIPLE:
				$options = array();
				foreach ( (array) $question['options'] as $option ) {
					$options[] = array(
						'id'   => (string) $option['id'],
						'text' => (string) $option['text'],
					);
				}
				$view['options'] = $shuffle ? self::seeded_shuffle( $options, $seed . ':' . $question['id'] ) : $options;
				break;

			case self::FILL_BLANK:
				$view['segments'] = self::segments( (string) $question['template'] );
				break;

			case self::MATCHING:
				$prompts = array();
				$matches = array();
				foreach ( (array) $question['pairs'] as $pair ) {
					$prompts[] = array(
						'id'   => (string) $pair['prompt_id'],
						'text' => (string) $pair['prompt'],
					);
					$matches[] = array(
						'id'   => (string) $pair['match_id'],
						'text' => (string) $pair['match'],
					);
				}
				$view['prompts'] = $shuffle ? self::seeded_shuffle( $prompts, $seed . ':p:' . $question['id'] ) : $prompts;
				$view['matches'] = self::seeded_shuffle( $matches, $seed . ':m:' . $question['id'] );
				break;
		}
		return $view;
	}

	/**
	 * Returns the published quizzes that list a question.
	 *
	 * @param int $question_id Question ID.
	 * @return array<int, int>
	 */
	public function quizzes_using( int $question_id ): array {
		$ids = get_posts(
			array(
				'post_type'        => PostTypes::QUIZ,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => 100,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'lang'             => '', // All languages (Polylang).
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Editor-only lookup of a question's quizzes.
				'meta_query'       => array(
					array(
						'key'     => QuizSettings::QUESTIONS,
						'value'   => 'i:' . $question_id . ';',
						'compare' => 'LIKE',
					),
				),
			)
		);
		$found = array();
		foreach ( $ids as $quiz_id ) {
			if ( in_array( $question_id, QuizSettings::question_ids( (int) $quiz_id ), true ) ) {
				$found[] = (int) $quiz_id;
			}
		}
		return $found;
	}

	/**
	 * Parses "{{a|b}}" markers into lists of accepted answers.
	 *
	 * @param string $template Sentence with markers.
	 * @return array<int, array<int, string>>
	 */
	public static function parse_blanks( string $template ): array {
		$blanks = array();
		if ( preg_match_all( '/\{\{(.+?)\}\}/u', $template, $matches ) ) {
			foreach ( $matches[1] as $marker ) {
				$accepted = array_values(
					array_filter(
						array_map( 'trim', explode( '|', $marker ) ),
						static function ( $answer ) {
							return '' !== $answer;
						}
					)
				);
				if ( ! empty( $accepted ) ) {
					$blanks[] = $accepted;
				}
			}
		}
		return array_slice( $blanks, 0, 20 );
	}

	/**
	 * Splits a fill-in-the-blank template into text and blank segments for learners.
	 *
	 * @param string $template Sentence with markers.
	 * @return array<int, array{type: string, text?: string, index?: int}>
	 */
	public static function segments( string $template ): array {
		$parts    = preg_split( '/(\{\{.+?\}\})/u', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$segments = array();
		$index    = 0;
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			if ( preg_match( '/^\{\{.+?\}\}$/u', $part ) ) {
				$segments[] = array(
					'type'  => 'blank',
					'index' => $index++,
				);
			} else {
				$segments[] = array(
					'type' => 'text',
					'text' => $part,
				);
			}
		}
		return $segments;
	}

	/**
	 * Shuffles a list in an order fixed by a seed.
	 *
	 * @param array<int, array<string, mixed>> $items Items with an `id`.
	 * @param string                           $seed  Seed.
	 * @return array<int, array<string, mixed>>
	 */
	public static function seeded_shuffle( array $items, string $seed ): array {
		usort(
			$items,
			static function ( $a, $b ) use ( $seed ) {
				return strcmp( md5( $seed . '|' . $a['id'] ), md5( $seed . '|' . $b['id'] ) );
			}
		);
		return $items;
	}

	/**
	 * Cleans choice options, keeping known IDs and creating new ones.
	 *
	 * @param array<int|string, mixed> $raw Raw options.
	 * @return array<int, array{id: string, text: string, correct: bool}>
	 */
	private function sanitize_options( array $raw ): array {
		$options = array();
		$seen    = array();
		foreach ( $raw as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$text = trim( sanitize_text_field( (string) ( $option['text'] ?? '' ) ) );
			if ( '' === $text ) {
				continue;
			}
			$id        = self::clean_id( $option['id'] ?? '', 'o', $seen );
			$seen[]    = $id;
			$options[] = array(
				'id'      => $id,
				'text'    => $text,
				'correct' => self::flag( $option['correct'] ?? false ),
			);
		}
		return array_slice( $options, 0, self::MAX_CHOICES );
	}

	/**
	 * Cleans matching pairs, keeping known IDs and creating new ones.
	 *
	 * Prompt and match IDs are independent random strings, so the learner's
	 * view (prompts and shuffled matches) does not reveal which belong together.
	 *
	 * @param array<int|string, mixed> $raw Raw pairs.
	 * @return array<int, array{prompt_id: string, prompt: string, match_id: string, match: string}>
	 */
	private function sanitize_pairs( array $raw ): array {
		$pairs = array();
		$seen  = array();
		foreach ( $raw as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$prompt = trim( sanitize_text_field( (string) ( $pair['prompt'] ?? '' ) ) );
			$match  = trim( sanitize_text_field( (string) ( $pair['match'] ?? '' ) ) );
			if ( '' === $prompt || '' === $match ) {
				continue;
			}
			$prompt_id = self::clean_id( $pair['prompt_id'] ?? '', 'p', $seen );
			$seen[]    = $prompt_id;
			$match_id  = self::clean_id( $pair['match_id'] ?? '', 'm', $seen );
			$seen[]    = $match_id;
			$pairs[]   = array(
				'prompt_id' => $prompt_id,
				'prompt'    => $prompt,
				'match_id'  => $match_id,
				'match'     => $match,
			);
		}
		return array_slice( $pairs, 0, self::MAX_CHOICES );
	}

	/**
	 * Keeps a valid existing ID or makes a new random one.
	 *
	 * @param mixed              $id     Submitted ID.
	 * @param string             $prefix One-letter prefix for new IDs.
	 * @param array<int, string> $taken  IDs already used in this question.
	 * @return string
	 */
	private static function clean_id( $id, string $prefix, array $taken ): string {
		$id = is_string( $id ) ? $id : '';
		if ( preg_match( '/^[a-z][a-z0-9]{5,31}$/', $id ) && ! in_array( $id, $taken, true ) ) {
			return $id;
		}
		do {
			$id = $prefix . strtolower( wp_generate_password( 9, false, false ) );
		} while ( in_array( $id, $taken, true ) );
		return $id;
	}

	/**
	 * Reads a boolean sent as a boolean, number or string.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function flag( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return is_scalar( $value ) && in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Returns a validation error.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private function invalid( string $message ): WP_Error {
		return new WP_Error( 'publiclms_invalid_question', $message, array( 'status' => 400 ) );
	}
}
