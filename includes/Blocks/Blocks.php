<?php
/**
 * Block registration and shared render helpers.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Blocks;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Courses\CourseFields;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Services\ProgressService;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's dynamic blocks, the token stylesheet and the shared
 * Interactivity API store module, and gives render.php files one place to
 * resolve the course and seed the learner state.
 *
 * Every block is dynamic and reads course data through CourseFields and the
 * AccessPolicy, so the same rules apply as on REST (ADR-008, ADR-035).
 */
final class Blocks {

	/**
	 * Interactivity API store namespace shared by the learner blocks.
	 */
	const STORE = 'publiclms/learning';

	/**
	 * Script module ID of the shared store.
	 */
	const MODULE = '@publiclms/learning';

	/**
	 * Style handle of the token aliases (CONTRACTS.md B5).
	 */
	const TOKENS = 'publiclms-tokens';

	/**
	 * Block directories under build/blocks/.
	 */
	const BLOCKS = array(
		'certificate-button',
		'checkout',
		'course-meta',
		'course-progress',
		'curriculum',
		'enroll-button',
		'lesson-content',
		'lesson-navigation',
		'mark-complete',
		'assignment',
		'quiz',
		'student-dashboard',
		'video',
	);

	/**
	 * The single instance, for render.php files.
	 *
	 * @var Blocks|null
	 */
	private static $instance = null;

	/**
	 * Course fields.
	 *
	 * @var CourseFields
	 */
	public $fields;

	/**
	 * Course structure.
	 *
	 * @var CourseStructureRepositoryInterface
	 */
	public $structure;

	/**
	 * Progress.
	 *
	 * @var ProgressService
	 */
	public $progress;

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	public $enrollments;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	public $policy;

	/**
	 * Items whose content is being rendered, to stop a lesson rendering itself.
	 *
	 * @var array<int, bool>
	 */
	public static $rendering = array();

	/**
	 * Items whose locked notice was already printed in this request, so a
	 * quiz or assignment block under the gated introduction does not repeat it.
	 *
	 * @var array<int, bool>
	 */
	public static $locked_notice_shown = array();

	/**
	 * Courses whose learner state was already seeded in this request.
	 *
	 * @var array<int, bool>
	 */
	private $seeded = array();

	/**
	 * Constructor.
	 *
	 * @param CourseFields                       $fields      Course fields.
	 * @param CourseStructureRepositoryInterface $structure   Course structure.
	 * @param ProgressService                    $progress    Progress.
	 * @param EnrollmentRepository               $enrollments Enrolments.
	 * @param AccessPolicy                       $policy      Access policy.
	 */
	public function __construct( CourseFields $fields, CourseStructureRepositoryInterface $structure, ProgressService $progress, EnrollmentRepository $enrollments, AccessPolicy $policy ) {
		$this->fields      = $fields;
		$this->structure   = $structure;
		$this->progress    = $progress;
		$this->enrollments = $enrollments;
		$this->policy      = $policy;
		self::$instance    = $this;
	}

	/**
	 * Returns the instance for render.php files.
	 *
	 * @return Blocks
	 */
	public static function instance(): Blocks {
		if ( null === self::$instance ) {
			self::$instance = publiclms()->container()->get( self::class );
		}
		return self::$instance;
	}

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ), 20 );
		add_filter( 'block_categories_all', array( $this, 'block_category' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_shared_styles' ) );
	}

	/**
	 * Registers the token stylesheet, the store module and the blocks.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		wp_register_style( self::TOKENS, PUBLICLMS_URL . 'assets/css/tokens.css', array(), PUBLICLMS_VERSION );
		// `npm run build` writes tokens-rtl.css with rtlcss (bin/build-rtl.js).
		wp_style_add_data( self::TOKENS, 'rtl', 'replace' );

		$module_asset = PUBLICLMS_PATH . 'build/interactivity/learning.asset.php';
		if ( function_exists( 'wp_register_script_module' ) && is_readable( $module_asset ) ) {
			$asset = require $module_asset;
			wp_register_script_module( self::MODULE, PUBLICLMS_URL . 'build/interactivity/learning.js', $asset['dependencies'], $asset['version'] );
		}

		foreach ( self::BLOCKS as $name ) {
			$dir = PUBLICLMS_PATH . 'build/blocks/' . $name;
			if ( is_readable( $dir . '/block.json' ) ) {
				register_block_type( $dir );
			}
		}
		foreach ( self::BLOCKS as $name ) {
			$handle = generate_block_asset_handle( 'publiclms/' . $name, 'editorScript' );
			wp_set_script_translations( $handle, 'public-lms', PUBLICLMS_PATH . 'languages' );
		}
	}

	/**
	 * Loads the token aliases and pattern helpers on plugin pages, including
	 * the course archive, whose cards are core blocks only.
	 *
	 * @return void
	 */
	public function enqueue_shared_styles(): void {
		$dashboard = (int) get_option( 'publiclms_dashboard_page_id', 0 );
		if ( is_singular( array( PostTypes::COURSE, PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT ) )
			|| is_post_type_archive( PostTypes::COURSE )
			|| is_tax( \PublicLMS\Taxonomies\Taxonomies::KEYS )
			|| ( $dashboard > 0 && is_page( $dashboard ) ) ) {
			wp_enqueue_style( self::TOKENS );
		}
	}

	/**
	 * Adds the "Public LMS" block category.
	 *
	 * @param array<int, array<string, mixed>> $categories Categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function block_category( $categories ): array {
		$categories   = (array) $categories;
		$categories[] = array(
			'slug'  => 'publiclms',
			'title' => __( 'Public LMS', 'public-lms' ),
			'icon'  => null,
		);
		return $categories;
	}

	/**
	 * Resolves the course a block shows: its courseId attribute, then the post
	 * in context (a course, or the course of a lesson), then the queried post.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param WP_Block|null        $block      Block instance.
	 * @return int Course ID, or 0.
	 */
	public function course_id( array $attributes, $block = null ): int {
		$course_id = isset( $attributes['courseId'] ) ? absint( $attributes['courseId'] ) : 0;
		if ( $course_id > 0 ) {
			return PostTypes::COURSE === get_post_type( $course_id ) ? $course_id : 0;
		}
		$post_id = 0;
		if ( $block instanceof WP_Block && isset( $block->context['postId'] ) ) {
			$post_id = absint( $block->context['postId'] );
		}
		if ( 0 === $post_id ) {
			$post_id = (int) get_queried_object_id();
		}
		return $this->fields->resolve_course_id( $post_id );
	}

	/**
	 * Returns the lesson, quiz or assignment a block is on, or 0.
	 *
	 * @param WP_Block|null $block Block instance.
	 * @return int
	 */
	public function item_id( $block = null ): int {
		$post_id = $block instanceof WP_Block && isset( $block->context['postId'] ) ? absint( $block->context['postId'] ) : (int) get_queried_object_id();
		return in_array( get_post_type( $post_id ), array( PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT ), true ) ? $post_id : 0;
	}

	/**
	 * Seeds the shared Interactivity state for a course: REST access, the
	 * current user's progress, enrolment and the next item, plus the
	 * translated strings the store announces.
	 *
	 * @param int $course_id Course ID.
	 * @param int $item_id   The item on screen, or 0.
	 * @return array<string, mixed> The course's progress state.
	 */
	public function seed_state( int $course_id, int $item_id = 0 ): array {
		$user_id  = get_current_user_id();
		$enrolled = $user_id > 0 && $this->enrollments->is_enrolled( $user_id, $course_id );
		$summary  = $enrolled ? $this->progress->summary( $user_id, $course_id ) : null;

		$progress = array(
			'percent'   => null === $summary ? 0 : $summary['percent'],
			'done'      => null === $summary ? 0 : $summary['items_completed'],
			'total'     => null === $summary ? count( $this->structure->item_ids( $course_id ) ) : $summary['items_total'],
			'completed' => null !== $summary && $summary['completed'],
			'items'     => (object) array_fill_keys( null === $summary ? array() : $summary['completed_items'], true ),
		);

		if ( ! isset( $this->seeded[ $course_id ] ) || $item_id > 0 ) {
			$state = array(
				'restUrl'  => esc_url_raw( rest_url( 'publiclms/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'progress' => array( (string) $course_id => $progress ),
				'enrolled' => array( (string) $course_id => $enrolled ),
				'i18n'     => array(
					'error'          => __( 'That did not work. Check your connection and try again.', 'public-lms' ),
					'enrolled'       => __( 'You are enrolled. Your first lesson is ready.', 'public-lms' ),
					/* translators: %d: percentage of the course completed. */
					'percent'        => __( '%d%% complete', 'public-lms' ),
					'backToCourse'   => __( 'Back to course', 'public-lms' ),
					'courseComplete' => __( 'Course complete', 'public-lms' ),
					'startLearning'  => __( 'Start learning', 'public-lms' ),
					'videoComplete'  => __( 'Video watched. This lesson is now complete.', 'public-lms' ),
				),
			);
			if ( $item_id > 0 ) {
				$state['next'] = array( (string) $course_id => $this->next_link( $course_id, $item_id ) );
			}
			wp_interactivity_state( self::STORE, array_merge( $state, self::derived_state() ) );
			$this->seeded[ $course_id ] = true;
		}
		return $progress;
	}

	/**
	 * Server-side versions of the store's derived state (the getters in
	 * src/interactivity/learning.js), so directives render the same values on
	 * the server as on the client. Closures are evaluated during directive
	 * processing and never serialized (WordPress 6.6+).
	 *
	 * @return array<string, \Closure>
	 */
	public static function derived_state(): array {
		$course       = static function (): array {
			$state   = wp_interactivity_state( self::STORE );
			$context = wp_interactivity_get_context( self::STORE );
			$id      = (string) ( $context['courseId'] ?? '' );
			$entry   = $state['progress'][ $id ] ?? array();
			return array(
				'percent'   => (int) ( $entry['percent'] ?? 0 ),
				'done'      => (int) ( $entry['done'] ?? 0 ),
				'total'     => (int) ( $entry['total'] ?? 0 ),
				'completed' => ! empty( $entry['completed'] ),
				'items'     => (array) ( $entry['items'] ?? array() ),
			);
		};
		$item_done    = static function () use ( $course ): bool {
			$context = wp_interactivity_get_context( self::STORE );
			return isset( $course()['items'][ (int) ( $context['itemId'] ?? 0 ) ] );
		};
		$next         = static function (): array {
			$state   = wp_interactivity_state( self::STORE );
			$context = wp_interactivity_get_context( self::STORE );
			return (array) ( $state['next'][ (string) ( $context['courseId'] ?? '' ) ] ?? array() );
		};
		$tab_selected = static function (): bool {
			$context = wp_interactivity_get_context( self::STORE );
			$element = function_exists( 'wp_interactivity_get_element' ) ? wp_interactivity_get_element() : null;
			$tab     = is_array( $element ) ? (string) ( $element['attributes']['data-tab'] ?? '' ) : '';
			return '' !== $tab && ( $context['selected'] ?? '' ) === $tab;
		};

		$quiz  = static function (): array {
			$state   = wp_interactivity_state( self::STORE );
			$context = wp_interactivity_get_context( self::STORE );
			return (array) ( $state['quiz'][ (string) ( $context['quizId'] ?? '' ) ] ?? array() );
		};
		$phase = static function () use ( $quiz ): string {
			return (string) ( $quiz()['phase'] ?? 'intro' );
		};

		return array(
			'quizIsIntro'        => static function () use ( $phase ): bool {
				return 'intro' === $phase();
			},
			'quizIsRunning'      => static function () use ( $phase ): bool {
				return 'running' === $phase();
			},
			'quizIsDone'         => static function () use ( $phase ): bool {
				return 'done' === $phase();
			},
			'quizIsBusy'         => static function () use ( $quiz ): bool {
				return ! empty( $quiz()['busy'] );
			},
			'quizQuestions'      => static function () use ( $quiz ): array {
				return (array) ( $quiz()['questions'] ?? array() );
			},
			'quizMessage'        => static function () use ( $quiz ): string {
				return (string) ( $quiz()['message'] ?? '' );
			},
			'quizNotice'         => static function () use ( $quiz ): string {
				return (string) ( $quiz()['notice'] ?? '' );
			},
			'quizVerdict'        => static function () use ( $quiz ): string {
				return (string) ( $quiz()['verdict'] ?? '' );
			},
			'quizScore'          => static function () use ( $quiz ): string {
				return (string) ( $quiz()['score'] ?? '' );
			},
			'quizPassed'         => static function () use ( $quiz ): bool {
				return ! empty( $quiz()['passed'] );
			},
			'quizCanRetake'      => static function () use ( $quiz ): bool {
				return ! empty( $quiz()['canRetake'] );
			},
			'quizHasTimer'       => static function () use ( $quiz, $phase ): bool {
				return 'running' === $phase() && is_int( $quiz()['timeLeft'] ?? null );
			},
			'quizTimeText'       => static function (): string {
				return '';
			},
			'quizSubmitLabel'    => static function (): string {
				return __( 'Submit answers', 'public-lms' );
			},
			'percent'            => static function () use ( $course ): int {
				return $course()['percent'];
			},
			'percentWidth'       => static function () use ( $course ): string {
				return $course()['percent'] . '%';
			},
			'percentLabel'       => static function () use ( $course ): string {
				return self::percent_label( $course()['percent'] );
			},
			'progressSummary'    => static function () use ( $course ): string {
				$progress = $course();
				return $progress['done'] . ' / ' . $progress['total'];
			},
			'isEnrolled'         => static function (): bool {
				$state   = wp_interactivity_state( self::STORE );
				$context = wp_interactivity_get_context( self::STORE );
				return ! empty( $state['enrolled'][ (string) ( $context['courseId'] ?? '' ) ] );
			},
			'isItemCompleted'    => $item_done,
			'isItemLocked'       => static function (): bool {
				$state   = wp_interactivity_state( self::STORE );
				$context = wp_interactivity_get_context( self::STORE );
				return ! empty( $context['locked'] ) && empty( $state['enrolled'][ (string) ( $context['courseId'] ?? '' ) ] );
			},
			'isCourseComplete'   => static function () use ( $course ): bool {
				return $course()['completed'];
			},
			'undoHintId'         => static function () use ( $item_done ): ?string {
				$context = wp_interactivity_get_context( self::STORE );
				return $item_done() ? 'publiclms-undo-' . (int) ( $context['itemId'] ?? 0 ) : null;
			},
			'completeButtonText' => static function () use ( $item_done ): string {
				$context = wp_interactivity_get_context( self::STORE );
				return (string) ( $item_done() ? ( $context['completedLabel'] ?? '' ) : ( $context['label'] ?? '' ) );
			},
			'nextUrl'            => static function () use ( $next ): string {
				return (string) ( $next()['url'] ?? '' );
			},
			'nextTitle'          => static function () use ( $next ): string {
				return (string) ( $next()['title'] ?? '' );
			},
			'nextIsCourse'       => static function () use ( $next ): bool {
				return ! empty( $next()['isCourse'] );
			},
			'isSelectedTab'      => $tab_selected,
			'isTabHidden'        => static function () use ( $tab_selected ): bool {
				return ! $tab_selected();
			},
			'tabIndex'           => static function () use ( $tab_selected ): int {
				return $tab_selected() ? 0 : -1;
			},
		);
	}

	/**
	 * Returns the "next" target for an item: the following item in the
	 * curriculum, or the course page after the last item.
	 *
	 * @param int $course_id Course ID.
	 * @param int $item_id   Item ID.
	 * @return array{url: string, title: string, isCourse: bool}
	 */
	public function next_link( int $course_id, int $item_id ): array {
		$next = $this->structure->adjacent_items( $item_id )['next'];
		if ( $next > 0 ) {
			return array(
				'url'      => (string) get_permalink( $next ),
				'title'    => wp_strip_all_tags( get_the_title( $next ) ),
				'isCourse' => false,
			);
		}
		return array(
			'url'      => (string) get_permalink( $course_id ),
			'title'    => __( 'Back to course', 'public-lms' ),
			'isCourse' => true,
		);
	}

	/**
	 * Formats a grade or score with as few decimals as it needs: 8, 8.5, 8.25.
	 *
	 * @param float $value Number.
	 * @return string
	 */
	public static function number( float $value ): string {
		$decimals = 0;
		if ( abs( $value - round( $value ) ) >= 0.005 ) {
			$decimals = abs( $value * 10 - round( $value * 10 ) ) >= 0.05 ? 2 : 1;
		}
		return number_format_i18n( $value, $decimals );
	}

	/**
	 * Describes a list of curriculum items by type: "3 lessons, 1 quiz".
	 *
	 * @param array<int, array<string, mixed>> $items Structure items with a `type`.
	 * @return string
	 */
	public static function items_label( array $items ): string {
		$counts = array(
			'lesson'     => 0,
			'quiz'       => 0,
			'assignment' => 0,
		);
		foreach ( $items as $item ) {
			$type = (string) ( $item['type'] ?? 'lesson' );
			if ( isset( $counts[ $type ] ) ) {
				++$counts[ $type ];
			}
		}
		$parts = array();
		if ( $counts['lesson'] > 0 || ( 0 === $counts['quiz'] && 0 === $counts['assignment'] ) ) {
			/* translators: %d: number of lessons. */
			$parts[] = sprintf( _n( '%d lesson', '%d lessons', $counts['lesson'], 'public-lms' ), $counts['lesson'] );
		}
		if ( $counts['quiz'] > 0 ) {
			/* translators: %d: number of quizzes. */
			$parts[] = sprintf( _n( '%d quiz', '%d quizzes', $counts['quiz'], 'public-lms' ), $counts['quiz'] );
		}
		if ( $counts['assignment'] > 0 ) {
			/* translators: %d: number of assignments. */
			$parts[] = sprintf( _n( '%d assignment', '%d assignments', $counts['assignment'], 'public-lms' ), $counts['assignment'] );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Returns a percentage label, e.g. "40% complete".
	 *
	 * @param int $percent Percentage.
	 * @return string
	 */
	public static function percent_label( int $percent ): string {
		/* translators: %d: percentage of the course completed. */
		return sprintf( __( '%d%% complete', 'public-lms' ), $percent );
	}
}
