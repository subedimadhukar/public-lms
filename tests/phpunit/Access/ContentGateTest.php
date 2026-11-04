<?php
/**
 * Tests for the content gate and capability mapping of structure posts.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Access;

use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Tests\TestCase;
use WP_REST_Request;

/**
 * @covers \PublicLMS\Access\ContentGate
 * @covers \PublicLMS\Capabilities\Capabilities
 */
class ContentGateTest extends TestCase {

	public function test_locked_lesson_content_never_reaches_the_core_rest_api() {
		$fixture = $this->create_course_with_lessons();
		$lesson  = $fixture['lessons'][1];
		wp_update_post(
			array(
				'ID'           => $lesson,
				'post_content' => '<!-- wp:paragraph --><p>The secret answer is 42.</p><!-- /wp:paragraph -->',
			)
		);

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/plms_lesson/' . $lesson ) );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( '42', $data['content']['rendered'] );
		$this->assertStringContainsString( 'publiclms-locked-notice', $data['content']['rendered'] );
		$this->assertStringNotContainsString( '42', $data['excerpt']['rendered'] );

		$student = self::factory()->user->create();
		publiclms()->container()->get( EnrollmentRepository::class )->enroll( $student, $fixture['course'] );
		wp_set_current_user( $student );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/plms_lesson/' . $lesson ) );
		$this->assertStringContainsString( 'The secret answer is 42.', $response->get_data()['content']['rendered'] );
	}

	public function test_the_content_filter_gates_the_global_post() {
		$fixture = $this->create_course_with_lessons();
		$this->go_to( get_permalink( $fixture['lessons'][0] ) );
		$this->assertTrue( is_singular( 'plms_lesson' ) );
		$GLOBALS['post'] = get_post( $fixture['lessons'][0] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test sets the loop post.

		$output = apply_filters( 'the_content', 'Lesson body.' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$this->assertStringContainsString( 'publiclms-locked-notice', $output );
		$this->assertStringNotContainsString( 'Lesson body.', $output );
	}

	public function test_structure_posts_belong_to_the_course_teacher() {
		$teacher  = self::factory()->user->create( array( 'role' => 'plms_instructor' ) );
		$author_b = self::factory()->user->create( array( 'role' => 'plms_instructor' ) );
		$manager  = self::factory()->user->create( array( 'role' => 'plms_manager' ) );
		$fixture  = $this->create_course_with_lessons( array( 'post_author' => $teacher ) );
		$by_b     = $this->create_lesson( $fixture['sections'][0], 5, array( 'post_author' => $author_b ) );
		$by_mgr   = $this->create_lesson( $fixture['sections'][0], 6, array( 'post_author' => $manager ) );

		$this->assertTrue( user_can( $teacher, 'edit_post', $by_mgr ), 'The teacher edits lessons a manager added to their course.' );
		$this->assertTrue( user_can( $teacher, 'delete_post', $fixture['sections'][0] ) );
		$this->assertFalse( user_can( $author_b, 'edit_post', $by_b ), 'Authoring a lesson in someone else\'s course is not enough.' );
		$this->assertTrue( user_can( $manager, 'edit_post', $by_b ) );
		$this->assertFalse( user_can( self::factory()->user->create( array( 'role' => 'subscriber' ) ), 'edit_post', $by_b ) );
	}
}
