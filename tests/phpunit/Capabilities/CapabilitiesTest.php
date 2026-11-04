<?php
/**
 * Capability mapping tests.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Capabilities;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\Tests\TestCase;

/**
 * Meta capabilities, and capabilities checked with object arguments (finding 6).
 */
class CapabilitiesTest extends TestCase {

	public function test_object_arguments_from_core_raise_no_warning() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$context = new \WP_Block_Editor_Context( array( 'name' => 'core/edit-post' ) );
		// Core checks block bindings with the editor context as the argument; this must stay warning-free.
		$this->assertIsBool( current_user_can( 'edit_block_binding', $context ) );
		$caps = publiclms()->container()->get( Capabilities::class )->map_meta_cap( array( 'edit_posts' ), 'edit_block_binding', $admin, array( $context ) );
		$this->assertSame( array( 'edit_posts' ), $caps, 'Capabilities the plugin does not own pass through.' );
		$this->assertSame( array( 'do_not_allow' ), publiclms()->container()->get( Capabilities::class )->map_meta_cap( array(), Capabilities::META_EDIT_COURSE, $admin, array( $context ) ), 'An object is never read as a course ID.' );
	}

	public function test_course_capabilities_still_resolve() {
		$instructor = self::factory()->user->create( array( 'role' => 'plms_instructor' ) );
		$course     = $this->create_course( array( 'post_author' => $instructor ) );
		$caps       = publiclms()->container()->get( Capabilities::class )->map_meta_cap( array(), Capabilities::META_EDIT_COURSE, $instructor, array( (string) $course ) );
		$this->assertSame( array( Capabilities::EDIT_OWN_COURSE ), $caps );
	}
}
