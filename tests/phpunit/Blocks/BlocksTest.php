<?php
/**
 * Render tests for the plugin's blocks, the bindings source and the course query variation.
 *
 * Needs the built blocks (npm run build).
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Blocks;

use PublicLMS\Blocks\BindingsSource;
use PublicLMS\Blocks\CourseQuery;
use PublicLMS\PostTypes\Meta;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Services\ProgressService;
use PublicLMS\Tests\TestCase;
use WP_Block;

/**
 * @covers \PublicLMS\Blocks\Blocks
 */
class BlocksTest extends TestCase {

	/**
	 * Fixture.
	 *
	 * @var array{course: int, sections: array<int, int>, lessons: array<int, int>}
	 */
	private $fixture;

	/**
	 * Enrolled learner.
	 *
	 * @var int
	 */
	private $learner;

	public function set_up() {
		parent::set_up();
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'publiclms/curriculum' ) ) {
			$this->markTestSkipped( 'Blocks are not built; run npm run build.' );
		}
		$this->fixture = $this->create_course_with_lessons();
		$this->learner = self::factory()->user->create( array( 'display_name' => 'Maya Lama' ) );
		publiclms()->container()->get( EnrollmentRepository::class )->enroll( $this->learner, $this->fixture['course'], 'free' );
	}

	/**
	 * Renders block markup with a post as the global post.
	 *
	 * @param string $markup  Block markup.
	 * @param int    $post_id Post in context.
	 * @return string
	 */
	private function render( string $markup, int $post_id ): string {
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test sets the loop post.
		setup_postdata( $GLOBALS['post'] );
		$html = do_blocks( $markup );
		wp_reset_postdata();
		return $html;
	}

	public function test_mark_complete_renders_only_for_enrolled_learners() {
		$lesson = $this->fixture['lessons'][1];
		$this->assertSame( '', trim( $this->render( '<!-- wp:publiclms/mark-complete /-->', $lesson ) ) );

		wp_set_current_user( $this->learner );
		$html = $this->render( '<!-- wp:publiclms/mark-complete {"label":"I have finished this"} /-->', $lesson );
		$this->assertStringContainsString( 'data-wp-interactive="publiclms/learning"', $html );
		$this->assertStringContainsString( 'data-wp-on--click="actions.toggleComplete"', $html );
		$this->assertStringContainsString( '"itemId":' . $lesson, $html );
		$this->assertStringContainsString( 'I have finished this', $html );
		$this->assertStringContainsString( 'role="status"', $html );

		$state = wp_interactivity_state( 'publiclms/learning' );
		$this->assertSame( wp_create_nonce( 'wp_rest' ), $state['nonce'] );
		$this->assertSame( get_permalink( $this->fixture['lessons'][2] ), $state['next'][ (string) $this->fixture['course'] ]['url'] );
	}

	public function test_course_progress_shows_the_learners_percentage() {
		$course = $this->fixture['course'];
		$this->assertSame( '', trim( $this->render( '<!-- wp:publiclms/course-progress /-->', $course ) ) );

		wp_set_current_user( $this->learner );
		publiclms()->container()->get( ProgressService::class )->complete_item( $this->learner, $this->fixture['lessons'][0] );
		$html = $this->render( '<!-- wp:publiclms/course-progress {"className":"is-style-ring"} /-->', $course );
		$this->assertStringContainsString( 'role="progressbar"', $html );
		$this->assertStringContainsString( 'aria-valuenow="25"', $html );
		$this->assertStringContainsString( 'is-style-ring', $html );
		$this->assertStringContainsString( '--publiclms-progress:25%', $html );
	}

	public function test_curriculum_shows_completion_locks_and_the_current_lesson() {
		$lessons = $this->fixture['lessons'];
		$public  = $this->render( '<!-- wp:publiclms/curriculum /-->', $this->fixture['course'] );
		$this->assertSame( 4, substr_count( $public, 'is-locked' ) - substr_count( $public, 'data-wp-class--is-locked' ) );
		$this->assertStringContainsString( '<details', $public );

		wp_set_current_user( $this->learner );
		publiclms()->container()->get( ProgressService::class )->complete_item( $this->learner, $lessons[0] );
		$html = $this->render( '<!-- wp:publiclms/curriculum {"collapsible":false,"headingLevel":4} /-->', $lessons[1] );
		$this->assertStringNotContainsString( '<details', $html );
		$this->assertStringContainsString( '<h4 class="wp-block-publiclms-curriculum__section-title">', $html );
		$this->assertSame( 1, preg_match_all( '/class="wp-block-publiclms-curriculum__item is-completed"/', $html ) );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertStringNotContainsString( 'is-locked', $html );
	}

	public function test_lesson_content_is_gated_and_sanitised() {
		$lesson = $this->fixture['lessons'][1];
		wp_update_post(
			array(
				'ID'           => $lesson,
				'post_content' => '<!-- wp:paragraph --><p>Debits on the left, credits on the right.</p><!-- /wp:paragraph --><script>alert(1)</script>',
			)
		);

		$locked = $this->render( '<!-- wp:publiclms/lesson-content /-->', $lesson );
		$this->assertStringContainsString( 'is-locked', $locked );
		$this->assertStringNotContainsString( 'Debits', $locked );
		$this->assertStringContainsString( 'wp-login.php', $locked );

		wp_set_current_user( $this->learner );
		$open = $this->render( '<!-- wp:publiclms/lesson-content /-->', $lesson );
		$this->assertStringContainsString( 'Debits on the left, credits on the right.', $open );
		$this->assertStringNotContainsString( '<script', $open );
	}

	public function test_enroll_button_states() {
		$course = $this->fixture['course'];
		$this->assertStringContainsString( 'wp-login.php', $this->render( '<!-- wp:publiclms/enroll-button /-->', $course ) );

		wp_set_current_user( self::factory()->user->create() );
		$form = $this->render( '<!-- wp:publiclms/enroll-button {"label":"Join the course"} /-->', $course );
		$this->assertStringContainsString( 'name="action" value="publiclms_enroll"', $form );
		$this->assertStringContainsString( 'name="_wpnonce"', $form );
		$this->assertStringContainsString( 'data-wp-on--submit="actions.enroll"', $form );
		$this->assertStringContainsString( 'Join the course', $form );
		$this->assertStringContainsString( 'Free', $form );

		wp_set_current_user( $this->learner );
		$continue = $this->render( '<!-- wp:publiclms/enroll-button /-->', $course );
		$this->assertStringContainsString( 'Start learning', $continue );
		$this->assertStringContainsString( (string) get_permalink( $this->fixture['lessons'][0] ), $continue );

		update_post_meta( $course, Meta::ACCESS_MODE, 'closed' );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertStringContainsString( 'Enrolment by invitation', $this->render( '<!-- wp:publiclms/enroll-button /-->', $course ) );
	}

	public function test_lesson_navigation_links_neighbours() {
		$lessons = $this->fixture['lessons'];
		$html    = $this->render( '<!-- wp:publiclms/lesson-navigation /-->', $lessons[1] );
		$this->assertStringContainsString( (string) get_permalink( $lessons[0] ), $html );
		$this->assertStringContainsString( (string) get_permalink( $lessons[2] ), $html );
		$this->assertStringContainsString( 'data-wp-bind--href="state.nextUrl"', $html );

		$last = $this->render( '<!-- wp:publiclms/lesson-navigation /-->', $lessons[3] );
		$this->assertStringContainsString( 'is-course', $last );
		$this->assertStringContainsString( (string) get_permalink( $this->fixture['course'] ), $last );
	}

	public function test_student_dashboard_lists_enrolled_courses_in_tabs() {
		$this->assertStringContainsString( 'wp-login.php', $this->render( '<!-- wp:publiclms/student-dashboard /-->', $this->fixture['course'] ) );

		wp_set_current_user( $this->learner );
		$html = $this->render( '<!-- wp:publiclms/student-dashboard /-->', $this->fixture['course'] );
		$this->assertStringContainsString( 'role="tablist"', $html );
		$this->assertSame( 2, substr_count( $html, 'role="tabpanel"' ) );
		$this->assertStringContainsString( 'Welcome back, Maya Lama', $html );
		$this->assertStringContainsString( 'Introduction to Statistics', $html );
		$this->assertStringContainsString( '0 of 4 lessons', $html );
	}

	public function test_course_meta_lists_course_facts() {
		update_post_meta( $this->fixture['lessons'][0], Meta::DURATION, 25 );
		update_post_meta( $this->fixture['lessons'][1], Meta::DURATION, 50 );
		publiclms()->container()->get( \PublicLMS\Repositories\CourseStructureRepositoryInterface::class )->invalidate( $this->fixture['course'] );

		$html = $this->render( '<!-- wp:publiclms/course-meta {"fields":["lessons","duration","learners"]} /-->', $this->fixture['course'] );
		$this->assertStringContainsString( '4 lessons', $html );
		$this->assertStringContainsString( '1h 15m', $html );
		$this->assertStringContainsString( '1 learner', $html );
	}

	/**
	 * @covers \PublicLMS\Blocks\BindingsSource
	 */
	public function test_bindings_source_escapes_text_and_keeps_urls_intact() {
		$source = publiclms()->container()->get( BindingsSource::class );
		wp_set_current_user( self::factory()->user->create() );
		$block = new WP_Block(
			array(
				'blockName' => 'core/paragraph',
				'attrs'     => array(
					'metadata' => array(
						'bindings' => array(
							'content' => array(
								'source' => 'publiclms/course-field',
								'args'   => array( 'key' => 'price' ),
							),
						),
					),
				),
			),
			array(
				'postId'   => $this->fixture['lessons'][0],
				'postType' => 'plms_lesson',
			)
		);

		// Core copies the source's uses_context into the block just before calling get_value().
		$block->context = array(
			'postId'   => $this->fixture['lessons'][0],
			'postType' => 'plms_lesson',
		);
		$this->assertSame( 'Free', $source->get_value( array( 'key' => 'price' ), $block, 'content' ) );
		$this->assertSame( '4 lessons', $source->get_value( array( 'key' => 'lesson_count' ), $block, 'content' ) );
		$this->assertSame(
			'4 lessons',
			$source->get_value(
				array(
					'key'      => 'lesson_count',
					'courseId' => $this->fixture['course'],
				),
				$block,
				'content'
			)
		);
		$url = $source->get_value( array( 'key' => 'enroll_url' ), $block, 'url' );
		$this->assertStringContainsString( '&_wpnonce=', (string) $url );
		$this->assertNull( $source->get_value( array( 'key' => 'nope' ), $block, 'content' ) );

		// Text is HTML-escaped once: an ampersand stored as &amp; is not encoded twice.
		$level = self::factory()->term->create(
			array(
				'taxonomy' => 'plms_level',
				'name'     => 'Beginner & up',
			)
		);
		wp_set_object_terms( $this->fixture['course'], array( $level ), 'plms_level' );
		$this->assertSame( 'Beginner &amp; up', $source->get_value( array( 'key' => 'level' ), $block, 'content' ) );

		$html = $this->render(
			'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"lesson_count"}}}}} --><p>placeholder</p><!-- /wp:paragraph -->',
			$this->fixture['course']
		);
		$this->assertMatchesRegularExpression( '#<p[^>]*>4 lessons</p>#', $html );
	}

	/**
	 * @covers \PublicLMS\Blocks\CourseQuery
	 */
	public function test_course_query_can_hide_enrolled_courses() {
		$query = publiclms()->container()->get( CourseQuery::class );
		$block = new WP_Block(
			array( 'blockName' => 'core/post-template' ),
			array(
				'query' => array(
					'namespace'             => 'publiclms/course-query',
					'publiclmsHideEnrolled' => true,
				),
			)
		);
		$plain = new WP_Block( array( 'blockName' => 'core/post-template' ), array( 'query' => array( 'postType' => 'post' ) ) );
		wp_set_current_user( $this->learner );

		$vars = $query->query_vars( array( 'post_type' => 'post' ), $block );
		$this->assertSame( 'plms_course', $vars['post_type'] );
		$this->assertSame( array( $this->fixture['course'] ), $vars['post__not_in'] );
		$this->assertSame( array( 'post_type' => 'post' ), $query->query_vars( array( 'post_type' => 'post' ), $plain ) );
	}
}
