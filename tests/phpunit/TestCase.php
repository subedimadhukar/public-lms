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
}
