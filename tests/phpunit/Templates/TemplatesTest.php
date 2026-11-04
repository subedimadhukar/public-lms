<?php
/**
 * Tests for templates, patterns and the classic theme fallback.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Templates;

use PublicLMS\Templates\ClassicTemplates;
use PublicLMS\Templates\PatternRegistrar;
use PublicLMS\Tests\TestCase;
use WP_Block_Patterns_Registry;

/**
 * @covers \PublicLMS\Templates\PatternRegistrar
 * @covers \PublicLMS\Templates\TemplateRegistrar
 * @covers \PublicLMS\Templates\ClassicTemplates
 */
class TemplatesTest extends TestCase {

	public function test_every_phase_1_pattern_is_registered_in_the_plugin_category() {
		$expected = array(
			'publiclms/course-hero',
			'publiclms/course-details',
			'publiclms/course-curriculum',
			'publiclms/course-instructor',
			'publiclms/course-enroll-card',
			'publiclms/course-archive-header',
			'publiclms/course-grid',
			'publiclms/lesson-header',
			'publiclms/lesson-content',
			'publiclms/lesson-navigation',
			'publiclms/lesson-sidebar',
			'publiclms/student-dashboard',
		);
		$registry = WP_Block_Patterns_Registry::get_instance();
		foreach ( $expected as $slug ) {
			$this->assertTrue( $registry->is_registered( $slug ), "{$slug} is not registered." );
			$pattern = $registry->get_registered( $slug );
			$this->assertContains( 'publiclms', $pattern['categories'] );
			$this->assertStringNotContainsString( '<?php', (string) ( $pattern['content'] ?? '' ) );
		}
		$this->assertFalse( $registry->get_registered( 'publiclms/lesson-header' )['inserter'] );
		$this->assertStringContainsString( 'publiclms/course-query', (string) $registry->get_registered( 'publiclms/course-grid' )['content'] );
	}

	public function test_pattern_files_outside_the_namespace_are_ignored() {
		$dir  = get_temp_dir() . 'publiclms-patterns-' . wp_generate_password( 6, false );
		$file = $dir . '/rogue.php';
		wp_mkdir_p( $dir );
		file_put_contents( $file, "<?php\n/**\n * Title: Rogue\n * Slug: theme/rogue\n */\n?><p>x</p>" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->assertNull( ( new PatternRegistrar( $dir ) )->read( $file ) );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup.
	}

	public function test_the_four_phase_1_templates_are_registered() {
		$slugs = wp_list_pluck( get_block_templates( array(), 'wp_template' ), 'slug' );
		foreach ( array( 'single-plms_course', 'archive-plms_course', 'single-plms_lesson', 'plms-dashboard' ) as $slug ) {
			$this->assertContains( $slug, $slugs );
		}
		$this->assertNotContains( 'single-plms_quiz', $slugs, 'Quiz templates arrive with quizzes in phase 2.' );
	}

	public function test_classic_themes_get_the_plugin_wrapper_for_plugin_requests() {
		$theme = get_stylesheet();
		switch_theme( 'twentytwentyone' );
		if ( wp_is_block_theme() ) {
			switch_theme( $theme );
			$this->markTestSkipped( 'Twenty Twenty-One is not installed.' );
		}
		$classic = publiclms()->container()->get( ClassicTemplates::class );
		$fixture = $this->create_course_with_lessons();

		$this->go_to( get_permalink( $fixture['lessons'][0] ) );
		$this->assertSame( 'single-plms_lesson', $classic->slug_for_request() );
		$this->assertStringEndsWith( 'templates/classic.php', $classic->template_include( '/theme/single.php' ) );
		$html = $classic->render();
		$this->assertStringContainsString( 'publiclms-template--lesson', $html );
		$this->assertStringContainsString( 'wp-block-publiclms-lesson-navigation', $html );
		$this->assertStringNotContainsString( 'wp-block-template-part', $html );

		$this->go_to( get_permalink( $fixture['course'] ) );
		$this->assertSame( 'single-plms_course', $classic->slug_for_request() );

		$this->go_to( get_post_type_archive_link( 'plms_course' ) );
		$this->assertSame( 'archive-plms_course', $classic->slug_for_request() );

		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'publiclms_dashboard_page_id', $page );
		$this->go_to( get_permalink( $page ) );
		$this->assertSame( 'plms-dashboard', $classic->slug_for_request() );

		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', $classic->slug_for_request() );
		$this->assertSame( '/theme/index.php', $classic->template_include( '/theme/index.php' ) );

		switch_theme( $theme );
		$this->assertSame( '/theme/single.php', $classic->template_include( '/theme/single.php' ), 'Block themes keep their own template resolution.' );
	}
}
