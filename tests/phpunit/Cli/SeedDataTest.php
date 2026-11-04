<?php
/**
 * Tests for the seed fixtures.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Cli;

use PublicLMS\Cli\SeedData;
use PublicLMS\Tests\TestCase;

/**
 * @covers \PublicLMS\Cli\SeedData
 */
class SeedDataTest extends TestCase {

	public function test_ten_distinct_courses_with_two_sections_of_three_lessons() {
		$courses = SeedData::courses();
		$this->assertCount( 10, $courses );
		$this->assertCount( 10, array_unique( wp_list_pluck( $courses, 'title' ) ) );
		foreach ( $courses as $course ) {
			$this->assertArrayHasKey( $course['instructor'], SeedData::instructors() );
			$this->assertCount( 2, $course['sections'] );
			foreach ( $course['sections'] as $lessons ) {
				$this->assertCount( 3, $lessons );
				foreach ( $lessons as $lesson ) {
					$this->assertGreaterThan( 0, $lesson[1] );
					$this->assertGreaterThan( 60, strlen( $lesson[2] ), 'Each lesson has real body text.' );
				}
			}
		}
	}

	public function test_no_placeholder_copy() {
		$text = strtolower( (string) wp_json_encode( SeedData::courses() ) );
		foreach ( array( 'lorem', 'ipsum', 'dolor sit' ) as $placeholder ) {
			$this->assertStringNotContainsString( $placeholder, $text );
		}
	}

	public function test_every_course_has_a_valid_quiz_and_an_assignment() {
		$bank = publiclms()->container()->get( \PublicLMS\Quizzes\QuestionBank::class );
		foreach ( SeedData::courses() as $course ) {
			$quiz = SeedData::quizzes()[ $course['title'] ] ?? array();
			$this->assertCount( 4, $quiz, $course['title'] );
			foreach ( $quiz as $question ) {
				$clean = $bank->sanitize( $question );
				$this->assertIsArray( $clean, $course['title'] . ': ' . ( is_wp_error( $clean ) ? $clean->get_error_message() : '' ) );
			}
			$this->assertGreaterThan( 2, count( array_unique( wp_list_pluck( $quiz, 'type' ) ) ), 'Quizzes mix question types.' );
			$this->assertArrayHasKey( $course['title'], SeedData::assignments() );
		}
	}
}
