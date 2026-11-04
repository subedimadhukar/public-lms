<?php
/**
 * Tests for the settings route and the wp/v2 course field.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Rest;

use WP_REST_Request;

/**
 * @covers \PublicLMS\Rest\SettingsController
 * @covers \PublicLMS\Rest\PostFields
 */
class SettingsControllerTest extends RestTestCase {

	public function test_settings_need_the_manage_capability() {
		$this->assertSame( 403, $this->request( 'GET', '/settings', array(), $this->instructor )->get_status() );
		$this->assertSame( 200, $this->request( 'GET', '/settings', array(), $this->manager )->get_status() );

		$saved = $this->request( 'POST', '/settings', array( 'currency' => 'npr' ), $this->manager );
		$this->assertSame( 'NPR', $saved->get_data()['currency'] );
		$this->assertSame( 400, $this->request( 'POST', '/settings', array( 'currency' => 'NEPAL' ), $this->manager )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/settings', array( 'dashboard_page_id' => $this->fixture['course'] ), $this->manager )->get_status() );
	}

	public function test_only_plugin_deleters_may_switch_on_data_removal() {
		$this->assertSame( 403, $this->request( 'POST', '/settings', array( 'remove_data_on_uninstall' => true ), $this->manager )->get_status() );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$saved = $this->request( 'POST', '/settings', array( 'remove_data_on_uninstall' => true ), $admin );
		$this->assertTrue( $saved->get_data()['remove_data_on_uninstall'] );
		$this->assertSame( 'yes', get_option( 'publiclms_remove_data_on_uninstall' ) );
	}

	public function test_create_dashboard_page() {
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$response = $this->request( 'POST', '/settings/dashboard-page', array(), $admin );
		$page_id  = $response->get_data()['dashboard_page_id'];
		$this->assertSame( 'page', get_post_type( $page_id ) );
		$this->assertSame( $page_id, (int) get_option( 'publiclms_dashboard_page_id' ) );
		$this->assertSame( 403, $this->request( 'POST', '/settings/dashboard-page', array(), $this->instructor )->get_status() );
	}

	public function test_wp_v2_items_carry_their_course_context() {
		wp_set_current_user( $this->instructor );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/plms_lesson/' . $this->fixture['lessons'][0] ) );
		$field    = $response->get_data()['publiclms'];
		$this->assertSame( $this->fixture['course'], $field['course_id'] );
		$this->assertStringContainsString( 'page=publiclms&course=' . $this->fixture['course'], $field['builder_url'] );
		$this->assertSame( '4 lessons', $field['fields']['lesson_count'] );

		wp_set_current_user( 0 );
		$public = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/plms_course/' . $this->fixture['course'] ) )->get_data()['publiclms'];
		$this->assertSame( '', $public['builder_url'] );
		$this->assertSame( 'Free', $public['fields']['price'] );
	}
}
