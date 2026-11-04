<?php
/**
 * Tests for the front-end enrolment handler's login redirect.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Frontend;

use PublicLMS\Frontend\EnrollmentHandler;
use PublicLMS\Tests\TestCase;

/**
 * @covers \PublicLMS\Frontend\EnrollmentHandler
 */
class EnrollmentHandlerTest extends TestCase {

	public function test_learners_land_on_their_dashboard_after_login() {
		$handler = publiclms()->container()->get( EnrollmentHandler::class );
		$page    = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'publiclms_dashboard_page_id', $page );
		$student    = get_userdata( self::factory()->user->create( array( 'role' => 'plms_student' ) ) );
		$instructor = get_userdata( self::factory()->user->create( array( 'role' => 'plms_instructor' ) ) );
		$admin_url  = admin_url();

		$this->assertSame( get_permalink( $page ), $handler->login_redirect( $admin_url, '', $student ) );
		$this->assertSame( $admin_url, $handler->login_redirect( $admin_url, '', $instructor ) );
		$this->assertSame( 'https://example.test/lessons/x/', $handler->login_redirect( 'https://example.test/lessons/x/', 'https://example.test/lessons/x/', $student ) );
		$this->assertSame( $admin_url, $handler->login_redirect( $admin_url, '', new \WP_Error( 'nope' ) ) );
	}
}
