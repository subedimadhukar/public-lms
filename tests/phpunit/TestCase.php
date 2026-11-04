<?php
/**
 * Shared test helpers.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests;

use PublicLMS\PostTypes\PostTypes;
use WP_UnitTestCase;

/**
 * Base test case with course fixtures.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Creates a published course.
	 *
	 * @param array<string, mixed> $args Extra post arguments.
	 * @return int
	 */
	protected function create_course( array $args = array() ): int {
		return (int) self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => PostTypes::COURSE,
					'post_title'  => 'Introduction to Statistics',
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * Creates a section in a course.
	 *
	 * @param int                  $course_id Course ID.
	 * @param int                  $order     Menu order.
	 * @param array<string, mixed> $args      Extra post arguments.
	 * @return int
	 */
	protected function create_section( int $course_id, int $order = 0, array $args = array() ): int {
		return (int) self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => PostTypes::SECTION,
					'post_title'  => 'Section ' . ( $order + 1 ),
					'post_status' => 'publish',
					'post_parent' => $course_id,
					'menu_order'  => $order,
				),
				$args
			)
		);
	}

	/**
	 * Creates a lesson in a section.
	 *
	 * @param int                  $section_id Section ID.
	 * @param int                  $order      Menu order.
	 * @param array<string, mixed> $args       Extra post arguments.
	 * @return int
	 */
	protected function create_lesson( int $section_id, int $order = 0, array $args = array() ): int {
		return (int) self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => PostTypes::LESSON,
					'post_title'   => 'Lesson ' . ( $order + 1 ),
					'post_status'  => 'publish',
					'post_parent'  => $section_id,
					'menu_order'   => $order,
					'post_content' => '<!-- wp:paragraph --><p>Lesson body.</p><!-- /wp:paragraph -->',
				),
				$args
			)
		);
	}

	/**
	 * Creates a course with two sections of two lessons each.
	 *
	 * @param array<string, mixed> $course_args Extra course arguments.
	 * @return array{course: int, sections: array<int, int>, lessons: array<int, int>}
	 */
	protected function create_course_with_lessons( array $course_args = array() ): array {
		$course   = $this->create_course( $course_args );
		$author   = (int) get_post_field( 'post_author', $course );
		$sections = array(
			$this->create_section( $course, 0, array( 'post_author' => $author ) ),
			$this->create_section( $course, 1, array( 'post_author' => $author ) ),
		);
		$lessons  = array(
			$this->create_lesson( $sections[0], 0, array( 'post_author' => $author ) ),
			$this->create_lesson( $sections[0], 1, array( 'post_author' => $author ) ),
			$this->create_lesson( $sections[1], 0, array( 'post_author' => $author ) ),
			$this->create_lesson( $sections[1], 1, array( 'post_author' => $author ) ),
		);
		return array(
			'course'   => $course,
			'sections' => $sections,
			'lessons'  => $lessons,
		);
	}

	/**
	 * Creates a quiz in a section.
	 *
	 * @param int                  $section_id Section ID.
	 * @param int                  $order      Menu order.
	 * @param array<string, mixed> $args       Extra post arguments.
	 * @return int
	 */
	protected function create_quiz( int $section_id, int $order = 10, array $args = array() ): int {
		return (int) self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => PostTypes::QUIZ,
					'post_title'  => 'Check your understanding',
					'post_status' => 'publish',
					'post_parent' => $section_id,
					'menu_order'  => $order,
				),
				$args
			)
		);
	}

	/**
	 * Creates a question in the bank from editor input.
	 *
	 * @param array<string, mixed> $input     Fields for QuestionBank::sanitize().
	 * @param int                  $author_id Author.
	 * @return int
	 */
	protected function create_question( array $input, int $author_id = 0 ): int {
		$bank  = publiclms()->container()->get( \PublicLMS\Quizzes\QuestionBank::class );
		$clean = $bank->sanitize( $input );
		$this->assertIsArray( $clean, is_wp_error( $clean ) ? $clean->get_error_message() : '' );
		$id = $bank->save( $clean, 0, $author_id );
		$this->assertIsInt( $id );
		return (int) $id;
	}

	/**
	 * Returns one question of each type, as editor input.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function sample_questions(): array {
		return array(
			'single'       => array(
				'type'    => 'single',
				'text'    => 'Which statement shows profit for a month?',
				'options' => array(
					array(
						'text'    => 'Profit and loss',
						'correct' => true,
					),
					array( 'text' => 'Balance sheet' ),
					array( 'text' => 'Bank statement' ),
				),
			),
			'multiple'     => array(
				'type'    => 'multiple',
				'text'    => 'Which are expenses?',
				'points'  => 2,
				'options' => array(
					array(
						'text'    => 'Rent',
						'correct' => true,
					),
					array(
						'text'    => 'Wages',
						'correct' => true,
					),
					array( 'text' => 'Sales' ),
				),
			),
			'true_false'   => array(
				'type'   => 'true_false',
				'text'   => 'Cash accounting records money when it moves.',
				'answer' => true,
			),
			'fill_blank'   => array(
				'type'     => 'fill_blank',
				'text'     => 'Complete the sentence.',
				'template' => 'A {{chart of accounts|COA}} lists every {{category}}.',
				'points'   => 2,
			),
			'short_answer' => array(
				'type'     => 'short_answer',
				'text'     => 'What do you call matching your books to the bank statement?',
				'accepted' => array( 'Reconciliation', 'reconciling' ),
			),
			'matching'     => array(
				'type'  => 'matching',
				'text'  => 'Match each term to its meaning.',
				'pairs' => array(
					array(
						'prompt' => 'Asset',
						'match'  => 'Something the business owns',
					),
					array(
						'prompt' => 'Liability',
						'match'  => 'Something the business owes',
					),
				),
			),
		);
	}
}
