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
		$this->assertSame( 1, preg_match_all( '/class="wp-block-publiclms-curriculum__item is-type-lesson is-completed"/', $html ) );
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
		$this->assertSame( 4, substr_count( $html, 'role="tabpanel"' ) );
		$this->assertStringContainsString( 'Welcome back, Maya Lama', $html );
		$this->assertStringContainsString( 'Introduction to Statistics', $html );
		$this->assertStringContainsString( '0 of 4 steps', $html );
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

	/**
	 * @covers \PublicLMS\Blocks\CourseQuery::related
	 * @covers \PublicLMS\Blocks\CourseQuery::query_vars
	 */
	public function test_course_query_related_prefers_shared_categories_and_leaves_the_course_out() {
		$query    = publiclms()->container()->get( CourseQuery::class );
		$business = self::factory()->term->create( array( 'taxonomy' => 'plms_course_category' ) );
		$science  = self::factory()->term->create( array( 'taxonomy' => 'plms_course_category' ) );
		$current  = $this->create_course( array( 'post_date' => '2026-01-01 10:00:00' ) );
		$sibling  = $this->create_course( array( 'post_date' => '2026-01-02 10:00:00' ) );
		$newest   = $this->create_course( array( 'post_date' => '2026-03-01 10:00:00' ) );
		$older    = $this->create_course( array( 'post_date' => '2025-06-01 10:00:00' ) );
		wp_set_object_terms( $current, array( $business ), 'plms_course_category' );
		wp_set_object_terms( $sibling, array( $business ), 'plms_course_category' );
		wp_set_object_terms( $newest, array( $science ), 'plms_course_category' );

		$vars = $query->related( array( 'posts_per_page' => 3 ), $current );
		$this->assertSame( 'post__in', $vars['orderby'] );
		$this->assertSame( $sibling, $vars['post__in'][0], 'A course from the same category comes first.' );
		$this->assertNotContains( $current, $vars['post__in'] );
		$this->assertCount( 3, $vars['post__in'], 'The row is filled with other courses.' );
		$this->assertContains( $newest, $vars['post__in'], 'Fillers are the newest other courses.' );
		$this->assertNotContains( $older, $vars['post__in'], 'The oldest course does not make the row.' );

		$this->assertSame( array( 'posts_per_page' => 3 ), $query->related( array( 'posts_per_page' => 3 ), 0 ), 'No course on screen: unchanged.' );

		$this->go_to( get_permalink( $this->fixture['lessons'][0] ) );
		$block       = new WP_Block(
			array( 'blockName' => 'core/post-template' ),
			array(
				'query' => array(
					'namespace'        => 'publiclms/course-query',
					'publiclmsRelated' => true,
				),
			)
		);
		$from_lesson = $query->query_vars( array( 'posts_per_page' => 5 ), $block );
		$this->assertNotContains( $this->fixture['course'], $from_lesson['post__in'], 'On a lesson, its course is the one left out.' );
	}

	/**
	 * Renders the course grid variation the way core does: `namespace` is a Query block attribute
	 * beside `query`, so the Post Template's context carries only the `query` keys (finding 11).
	 *
	 * @covers \PublicLMS\Blocks\CourseQuery::query_vars
	 */
	public function test_related_and_hide_enrolled_apply_in_real_block_rendering() {
		$current = $this->create_course(
			array(
				'post_title' => 'Course On Screen',
				'post_date'  => '2026-05-03 10:00:00',
			)
		);
		$other   = $this->create_course(
			array(
				'post_title' => 'Course Learner Has',
				'post_date'  => '2026-05-02 10:00:00',
			)
		);
		$third   = $this->create_course(
			array(
				'post_title' => 'Course Still Open',
				'post_date'  => '2026-05-01 10:00:00',
			)
		);
		$grid    = static function ( string $setting ): string {
			return '<!-- wp:query {"queryId":7,"query":{"perPage":3,"postType":"plms_course","order":"desc","orderBy":"date","inherit":false,"' . $setting . '":true},"namespace":"publiclms/course-query"} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		};

		$this->go_to( get_permalink( $current ) );
		$related = do_blocks( $grid( 'publiclmsRelated' ) );
		$this->assertStringNotContainsString( 'Course On Screen', $related, 'Related courses leave out the course on screen, even when it is among the newest.' );
		$this->assertStringContainsString( 'Course Learner Has', $related );

		publiclms()->container()->get( \PublicLMS\Services\EnrollmentService::class )->enroll( $this->learner, $other, 'manual' );
		wp_set_current_user( $this->learner );
		$open = do_blocks( $grid( 'publiclmsHideEnrolled' ) );
		$this->assertStringNotContainsString( 'Course Learner Has', $open, 'Hide-enrolled leaves out enrolled courses.' );
		$this->assertStringContainsString( 'Course Still Open', $open );
		$this->assertStringContainsString( 'Course On Screen', $open );
		unset( $third );
	}

	public function test_video_tracks_completion_only_for_enrolled_learners_on_items() {
		$lesson = $this->fixture['lessons'][1];
		$markup = '<!-- wp:publiclms/video {"src":"https://youtu.be/dQw4w9WgXcQ","title":"Reading a balance sheet","requiredPercent":80} /-->';

		$public = $this->render( $markup, $lesson );
		$this->assertStringContainsString( 'src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?', $public );
		$this->assertStringContainsString( 'title="Reading a balance sheet"', $public );
		$this->assertStringContainsString( '"track":false', $public );
		$this->assertStringNotContainsString( 'role="status"', $public );

		wp_set_current_user( $this->learner );
		$html = $this->render( $markup, $lesson );
		$this->assertStringContainsString( 'data-wp-init="callbacks.initVideo"', $html );
		$this->assertStringContainsString( '"track":true', $html );
		$this->assertStringContainsString( '"required":80', $html );
		$this->assertStringContainsString( 'role="status"', $html );

		$file = $this->render( '<!-- wp:publiclms/video {"provider":"self","src":"https://example.com/intro.mp4","captions":"https://example.com/intro.vtt"} /-->', $lesson );
		$this->assertStringContainsString( '<video controls', $file );
		$this->assertStringContainsString( '<track kind="captions" src="https://example.com/intro.vtt"', $file );
		$this->assertSame( '', trim( $this->render( '<!-- wp:publiclms/video {"src":"https://example.com/page"} /-->', $lesson ) ) );
	}

	public function test_lesson_content_lists_attachments_for_learners_who_can_open_it() {
		$lesson     = $this->fixture['lessons'][1];
		$attachment = self::factory()->attachment->create_object(
			'worksheet.pdf',
			$lesson,
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Practice worksheet',
			)
		);
		update_post_meta( $lesson, Meta::ATTACHMENTS, array( $attachment ) );

		$this->assertStringNotContainsString( 'Practice worksheet', $this->render( '<!-- wp:publiclms/lesson-content /-->', $lesson ) );
		wp_set_current_user( $this->learner );
		$html = $this->render( '<!-- wp:publiclms/lesson-content /-->', $lesson );
		$this->assertStringContainsString( 'Lesson files', $html );
		$this->assertStringContainsString( 'Practice worksheet', $html );
		$this->assertStringNotContainsString( 'Lesson files', $this->render( '<!-- wp:publiclms/lesson-content {"showAttachments":false} /-->', $lesson ) );
	}

	public function test_curriculum_shows_when_dripped_items_open() {
		update_post_meta( $this->fixture['lessons'][3], Meta::DRIP_DAYS, 5 );
		update_post_meta( $this->fixture['lessons'][2], Meta::PREREQUISITES, array( $this->fixture['lessons'][0] ) );
		wp_set_current_user( $this->learner );
		$html = $this->render( '<!-- wp:publiclms/curriculum /-->', $this->fixture['course'] );
		$this->assertStringContainsString( 'Opens ' . wp_date( (string) get_option( 'date_format' ), time() + 5 * DAY_IN_SECONDS ), $html );
		$this->assertStringContainsString( 'Complete earlier steps first', $html );
	}

	public function test_quiz_runner_shows_the_start_panel_without_answer_keys() {
		$quiz     = $this->create_quiz( $this->fixture['sections'][0] );
		$question = $this->create_question( $this->sample_questions()['short_answer'] );
		\PublicLMS\Quizzes\QuizSettings::set_question_ids( $quiz, array( $question ) );
		\PublicLMS\Quizzes\QuizSettings::update(
			$quiz,
			array(
				'time_limit'   => 20,
				'max_attempts' => 3,
			)
		);

		$locked = $this->render( '<!-- wp:publiclms/quiz /-->', $quiz );
		$this->assertStringContainsString( 'publiclms-locked-notice', $locked );
		$this->assertStringNotContainsString( 'actions.startQuiz', $locked );

		wp_set_current_user( $this->learner );
		$html = $this->render( '<!-- wp:publiclms/quiz /-->', $quiz );
		$this->assertStringContainsString( 'data-wp-on--click="actions.startQuiz"', $html );
		$this->assertStringContainsString( 'Start quiz', $html );
		$this->assertStringContainsString( '20 minutes', $html );
		$this->assertStringContainsString( '3 of 3 left', $html );
		$this->assertStringContainsString( 'data-wp-each--question="state.quizQuestions"', $html );
		$this->assertStringNotContainsString( 'Reconciliation', $html );
		$this->assertStringNotContainsString( 'wp-block-publiclms-quiz__facts', $this->render( '<!-- wp:publiclms/quiz {"showFacts":false} /-->', $quiz ) );

		$fields = publiclms()->container()->get( \PublicLMS\Courses\CourseFields::class );
		$this->assertSame( 'Time limit: 20 minutes', $fields->get( $this->fixture['course'], 'quiz_time_limit', 0, $quiz ) );
		$this->assertSame( 'Pass mark: 70%', $fields->get( $this->fixture['course'], 'quiz_pass_mark', 0, $quiz ) );
		$this->assertSame( 'Attempts left: 3 of 3', $fields->get( $this->fixture['course'], 'quiz_attempts_left', 0, $quiz ) );
		$this->assertSame( '1 question', $fields->get( $this->fixture['course'], 'quiz_question_count', 0, $quiz ) );
		$this->assertSame( '', $fields->get( $this->fixture['course'], 'quiz_pass_mark', 0, $this->fixture['lessons'][0] ), 'Quiz keys are empty off quizzes.' );
	}

	public function test_dashboard_lists_quiz_results() {
		$quiz     = $this->create_quiz( $this->fixture['sections'][0], 10, array( 'post_title' => 'Ledger check' ) );
		$question = $this->create_question( $this->sample_questions()['true_false'] );
		\PublicLMS\Quizzes\QuizSettings::set_question_ids( $quiz, array( $question ) );
		$service = publiclms()->container()->get( \PublicLMS\Services\QuizService::class );
		$attempt = $service->start( $this->learner, $quiz );
		$this->assertIsArray( $attempt );
		$service->submit( $this->learner, (int) $attempt['attempt']['id'], array( (string) $question => true ) );

		wp_set_current_user( $this->learner );
		$html = $this->render( '<!-- wp:publiclms/student-dashboard /-->', $this->fixture['course'] );
		$this->assertStringContainsString( 'Quiz results', $html );
		$this->assertStringContainsString( 'Ledger check', $html );
		$this->assertStringContainsString( '100%', $html );
		$this->assertStringContainsString( 'is-passed', $html );
	}

	public function test_assignment_block_shows_the_form_then_the_status() {
		$assignment = $this->create_assignment( $this->fixture['sections'][0] );
		\PublicLMS\Assignments\AssignmentSettings::update(
			$assignment,
			array(
				'max_files'  => 2,
				'file_types' => array( 'pdf', 'docx' ),
			)
		);
		$this->assertStringContainsString( 'publiclms-locked-notice', $this->render( '<!-- wp:publiclms/assignment /-->', $assignment ) );

		wp_set_current_user( $this->learner );
		$form = $this->render( '<!-- wp:publiclms/assignment /-->', $assignment );
		$this->assertStringContainsString( 'data-wp-on--submit="actions.submitAssignment"', $form );
		$this->assertStringContainsString( 'accept=".pdf,.docx"', $form );
		$this->assertStringContainsString( 'name="files[]" multiple', $form );
		$this->assertStringContainsString( 'Accepted: PDF, DOCX', $form );

		$handin = publiclms()->container()->get( \PublicLMS\Services\AssignmentService::class )->submit( $this->learner, $assignment, 'My answer', array() );
		$this->assertIsArray( $handin );
		$status = $this->render( '<!-- wp:publiclms/assignment /-->', $assignment );
		$this->assertStringContainsString( 'Waiting for a grade', $status );
		$this->assertStringContainsString( 'Hand in again', $status );
	}
}
