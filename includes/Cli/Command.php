<?php
/**
 * WP-CLI commands.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Cli;

use PublicLMS\Assignments\AssignmentSettings;
use PublicLMS\Capabilities\Roles;
use PublicLMS\Certificates\CertificateService;
use PublicLMS\Core\Container;
use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Repositories\EventRepositoryInterface;
use PublicLMS\Quizzes\QuestionBank;
use PublicLMS\Quizzes\QuizSettings;
use PublicLMS\Repositories\CertificateRepository;
use PublicLMS\Repositories\ProgressRepository;
use PublicLMS\Repositories\QuizAttemptRepository;
use PublicLMS\Services\AssignmentService;
use PublicLMS\Services\EnrollmentService;
use PublicLMS\Services\ProgressService;
use PublicLMS\Services\QuizService;
use PublicLMS\Taxonomies\Taxonomies;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and removes fixture data for the internal alpha and end-to-end tests.
 *
 * Everything the seed creates is flagged (post, term and user meta
 * `_plms_seed`), so `reset` removes exactly that and nothing else.
 */
final class Command {

	/**
	 * Meta key flagging seeded posts, terms and users.
	 */
	const FLAG = '_plms_seed';

	/**
	 * Password for seeded users when neither --password nor PUBLICLMS_SEED_PASSWORD is set.
	 * Local fixtures only; the command refuses to run on production.
	 */
	const DEFAULT_PASSWORD = 'public-lms-demo';

	/**
	 * Course structure.
	 *
	 * @var CourseStructureRepositoryInterface
	 */
	private $structure;

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentService
	 */
	private $enroll;

	/**
	 * Progress.
	 *
	 * @var ProgressService
	 */
	private $progress;

	/**
	 * Enrolment rows.
	 *
	 * @var EnrollmentRepository
	 */
	private $enrollments;

	/**
	 * Progress rows.
	 *
	 * @var ProgressRepository
	 */
	private $progress_rows;

	/**
	 * Event rows.
	 *
	 * @var EventRepositoryInterface
	 */
	private $events;

	/**
	 * Service container, for the quiz, assignment and certificate services.
	 *
	 * @var Container|null
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param CourseStructureRepositoryInterface $structure     Course structure.
	 * @param EnrollmentService                  $enroll        Enrolment service.
	 * @param ProgressService                    $progress      Progress service.
	 * @param EnrollmentRepository               $enrollments   Enrolments.
	 * @param ProgressRepository                 $progress_rows Progress rows.
	 * @param EventRepositoryInterface           $events        Event log.
	 * @param Container|null                     $container     Service container; without it the seed has no quizzes, assignments or certificates.
	 */
	public function __construct( CourseStructureRepositoryInterface $structure, EnrollmentService $enroll, ProgressService $progress, EnrollmentRepository $enrollments, ProgressRepository $progress_rows, EventRepositoryInterface $events, ?Container $container = null ) {
		$this->structure     = $structure;
		$this->enroll        = $enroll;
		$this->progress      = $progress;
		$this->enrollments   = $enrollments;
		$this->progress_rows = $progress_rows;
		$this->events        = $events;
		$this->container     = $container;
	}

	/**
	 * Creates realistic courses, instructors and learners.
	 *
	 * Creates three instructors and up to six learners, then the courses with
	 * two sections of three lessons each, a four-question quiz after the first
	 * section and an assignment at the end, categories and levels, free
	 * enrolments with some progress (quizzes taken, work handed in and graded,
	 * one course completed with its certificate), and a dashboard page if none
	 * is set.
	 *
	 * ## OPTIONS
	 *
	 * [--courses=<number>]
	 * : How many courses to create. Ten distinct courses exist; more repeat them as later cohorts.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--students=<number>]
	 * : How many learners to create, up to 6.
	 * ---
	 * default: 6
	 * ---
	 *
	 * [--password=<password>]
	 * : Password for every seeded user. Defaults to the PUBLICLMS_SEED_PASSWORD environment variable, then "public-lms-demo".
	 *
	 * [--force]
	 * : Seed again even if seeded data exists, or on a production site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp publiclms seed --courses=10
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function seed( $args, $assoc_args ): void {
		$force = isset( $assoc_args['force'] );
		if ( 'production' === wp_get_environment_type() && ! $force ) {
			WP_CLI::error( 'This site reports a production environment. Seed data is for local and staging sites; pass --force to seed anyway.' );
		}
		if ( ! $force && ! empty( $this->seeded_post_ids() ) ) {
			WP_CLI::error( 'Seed data already exists. Run `wp publiclms reset --yes` first, or pass --force to add more.' );
		}

		$count    = max( 1, min( 100, (int) ( $assoc_args['courses'] ?? 10 ) ) );
		$students = max( 0, min( count( SeedData::students() ), (int) ( $assoc_args['students'] ?? 6 ) ) );
		$env      = getenv( 'PUBLICLMS_SEED_PASSWORD' );
		$password = (string) ( $assoc_args['password'] ?? ( is_string( $env ) && '' !== $env ? $env : self::DEFAULT_PASSWORD ) );

		Roles::install();

		$instructors = array();
		foreach ( SeedData::instructors() as $login => $name ) {
			$instructors[ $login ] = $this->user( $login, $name, Roles::INSTRUCTOR, $password );
		}
		$learners = array();
		foreach ( array_slice( SeedData::students(), 0, $students, true ) as $login => $name ) {
			$learners[ $login ] = $this->user( $login, $name, Roles::STUDENT, $password );
		}

		$data    = SeedData::courses();
		$courses = array();
		$lessons = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$course    = $data[ $i % count( $data ) ];
			$cohort    = intdiv( $i, count( $data ) );
			$title     = $course['title'] . ( $cohort > 0 ? ' (Cohort ' . ( $cohort + 1 ) . ')' : '' );
			$course_id = $this->course( $course, $title, $instructors[ $course['instructor'] ] );
			$courses[] = $course_id;
			$lessons  += count( $this->structure->item_ids( $course_id ) );
			WP_CLI::log( sprintf( 'Created course %d: %s', $course_id, $title ) );
		}

		// Each learner takes three courses; progress varies so the dashboard has something to show.
		$enrolments  = 0;
		$position    = 0;
		$per_learner = min( 3, count( $courses ) );
		foreach ( $learners as $user_id ) {
			for ( $k = 0; $k < $per_learner; $k++ ) {
				$course_id = $courses[ ( $position + $k * 3 ) % count( $courses ) ];
				if ( $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
					continue;
				}
				$this->enroll->enroll( $user_id, $course_id, 'free' );
				++$enrolments;
				$items = $this->structure->item_ids( $course_id );
				$done  = 0 === $position && 0 === $k ? count( $items ) : ( $position + $k ) % 5;
				foreach ( array_slice( $items, 0, $done ) as $item_id ) {
					$this->complete_step( $user_id, $item_id, 1 === $position % 2 );
				}
			}
			++$position;
		}

		$page_id = (int) get_option( 'publiclms_dashboard_page_id', 0 );
		if ( $page_id <= 0 || 'page' !== get_post_type( $page_id ) ) {
			$page_id = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'My learning',
					'post_name'    => 'my-learning',
					'post_content' => '<!-- wp:paragraph --><p>Pick up where you left off, or start something new.</p><!-- /wp:paragraph -->',
					'meta_input'   => array( self::FLAG => 1 ),
				)
			);
			update_option( 'publiclms_dashboard_page_id', $page_id );
		}

		// One hand-in waits for a grade, so the Submissions screen has work to show.
		$waiting = array_values( $learners )[1] ?? 0;
		if ( null !== $this->container && $waiting > 0 ) {
			foreach ( $this->enrollments->find_by_user( $waiting ) as $enrolment ) {
				foreach ( $this->structure->item_ids( (int) $enrolment['course_id'] ) as $item_id ) {
					if ( PostTypes::ASSIGNMENT === get_post_type( $item_id ) ) {
						$this->container->get( AssignmentService::class )->submit( $waiting, $item_id, '<p>My first draft. I was not sure about the last part, so I added a question for you at the end.</p>', array() );
						break 2;
					}
				}
			}
		}

		$certificates = 0;
		if ( null !== $this->container ) {
			foreach ( $learners as $user_id ) {
				$certificates += count( $this->container->get( CertificateService::class )->for_user( $user_id ) );
			}
		}

		WP_CLI::success( sprintf( 'Seeded %d courses with %d steps (lessons, quizzes, assignments), %d instructors, %d learners and %d enrolments; certificates issued: %d.', count( $courses ), $lessons, count( $instructors ), count( $learners ), $enrolments, $certificates ) );
		WP_CLI::log( sprintf( 'Course catalogue: %s', (string) get_post_type_archive_link( PostTypes::COURSE ) ) );
		WP_CLI::log( sprintf( 'Student dashboard: %s', (string) get_permalink( $page_id ) ) );
		WP_CLI::log( sprintf( 'Log in as an instructor (%s) or a learner (%s) with the seed password.', implode( ', ', array_keys( $instructors ) ), implode( ', ', array_keys( $learners ) ) ) );
	}

	/**
	 * Deletes everything `wp publiclms seed` created.
	 *
	 * Removes seeded courses, sections, lessons, quizzes, questions,
	 * assignments, terms, the seeded dashboard page, and seeded users with
	 * their enrolments, progress, quiz attempts, hand-ins (and files),
	 * certificates and events.
	 * Nothing without the seed flag is touched.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp publiclms reset --yes
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function reset( $args, $assoc_args ): void {
		WP_CLI::confirm( 'Delete all Public LMS seed data?', $assoc_args );

		$posts = $this->seeded_post_ids();
		foreach ( $posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$dashboard = (int) get_option( 'publiclms_dashboard_page_id', 0 );
		if ( $dashboard > 0 && ! get_post( $dashboard ) ) {
			delete_option( 'publiclms_dashboard_page_id' );
		}

		$users = get_users(
			array(
				'meta_key' => self::FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off maintenance command.
				'fields'   => 'ID',
			)
		);
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $users as $user_id ) {
			$user_id = (int) $user_id;
			if ( null !== $this->container ) {
				$this->container->get( QuizAttemptRepository::class )->delete_for_user( $user_id );
				$this->container->get( AssignmentService::class )->delete_for_user( $user_id );
				$this->container->get( CertificateRepository::class )->delete_for_user( $user_id );
			}
			$this->enrollments->delete_for_user( $user_id );
			$this->progress_rows->delete_for_user( $user_id );
			$this->events->delete_for_user( $user_id );
			wp_delete_user( $user_id );
		}

		$terms      = get_terms(
			array(
				'taxonomy'   => Taxonomies::KEYS,
				'hide_empty' => false,
				'meta_key'   => self::FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off maintenance command.
				'fields'     => 'all',
			)
		);
		$term_count = 0;
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term instanceof \WP_Term ) {
				wp_delete_term( $term->term_id, $term->taxonomy );
				++$term_count;
			}
		}

		WP_CLI::success( sprintf( 'Removed %d posts, %d users and %d terms.', count( $posts ), count( $users ), $term_count ) );
	}

	/**
	 * Returns the IDs of every seeded post, in any status.
	 *
	 * @return array<int, int>
	 */
	private function seeded_post_ids(): array {
		$ids = get_posts(
			array(
				'post_type'        => array_merge( PostTypes::KEYS, array( 'page' ) ),
				'post_status'      => 'any,trash,auto-draft',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => self::FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off maintenance command.
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Creates or reuses a seeded user.
	 *
	 * @param string $login    Login.
	 * @param string $name     Display name.
	 * @param string $role     Role.
	 * @param string $password Password.
	 * @return int User ID.
	 */
	private function user( string $login, string $name, string $role, string $password ): int {
		$existing = get_user_by( 'login', $login );
		if ( $existing instanceof \WP_User ) {
			return (int) $existing->ID;
		}
		$parts   = explode( ' ', $name, 2 );
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $login . '@example.test',
				'user_pass'    => $password,
				'display_name' => $name,
				'first_name'   => $parts[0],
				'last_name'    => $parts[1] ?? '',
				'role'         => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			WP_CLI::error( $user_id->get_error_message() );
		}
		update_user_meta( (int) $user_id, self::FLAG, 1 );
		return (int) $user_id;
	}

	/**
	 * Returns a term ID, creating and flagging the term when it is new.
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	private function term( string $name, string $taxonomy ): int {
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term instanceof \WP_Term ) {
			return (int) $term->term_id;
		}
		$created = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $created ) ) {
			return 0;
		}
		update_term_meta( (int) $created['term_id'], self::FLAG, 1 );
		return (int) $created['term_id'];
	}

	/**
	 * Creates one course with its sections and lessons.
	 *
	 * @param array<string, mixed> $course    Course data from SeedData.
	 * @param string               $title     Title.
	 * @param int                  $author_id Instructor.
	 * @return int Course ID.
	 */
	private function course( array $course, string $title, int $author_id ): int {
		$description = '';
		foreach ( explode( '|', (string) $course['description'] ) as $paragraph ) {
			$description .= '<!-- wp:paragraph --><p>' . esc_html( $paragraph ) . '</p><!-- /wp:paragraph -->';
		}
		$course_id = wp_insert_post(
			array(
				'post_type'    => PostTypes::COURSE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => (string) $course['excerpt'],
				'post_content' => $description,
				'post_author'  => $author_id,
				'meta_input'   => array(
					self::FLAG        => 1,
					Meta::ACCESS_MODE => 'free',
				),
			),
			true
		);
		if ( is_wp_error( $course_id ) ) {
			WP_CLI::error( $course_id->get_error_message() );
		}
		$course_id = (int) $course_id;
		wp_set_object_terms( $course_id, array( $this->term( (string) $course['category'], Taxonomies::COURSE_CATEGORY ) ), Taxonomies::COURSE_CATEGORY );
		wp_set_object_terms( $course_id, array( $this->term( (string) $course['level'], Taxonomies::LEVEL ) ), Taxonomies::LEVEL );

		$first    = true;
		$sections = array();
		foreach ( (array) $course['sections'] as $section_title => $lessons ) {
			$section_id = $this->structure->add_section( $course_id, (string) $section_title, $author_id );
			update_post_meta( $section_id, self::FLAG, 1 );
			foreach ( (array) $lessons as $lesson ) {
				$lesson_id = $this->structure->add_item( $section_id, 'lesson', (string) $lesson[0], $author_id );
				wp_update_post(
					array(
						'ID'           => $lesson_id,
						'post_content' => $this->lesson_content( (string) $lesson[0], (string) $lesson[2] ),
					)
				);
				update_post_meta( $lesson_id, self::FLAG, 1 );
				update_post_meta( $lesson_id, Meta::DURATION, (int) $lesson[1] );
				if ( $first ) {
					update_post_meta( $lesson_id, Meta::PREVIEW, true );
					$first = false;
				}
			}
			$sections[] = $section_id;
		}
		if ( null !== $this->container && ! empty( $sections ) ) {
			$this->quiz( (string) $course['title'], $sections[0], $author_id );
			$this->assignment( (string) $course['title'], (int) end( $sections ), $author_id );
		}
		$this->structure->invalidate( $course_id );
		return $course_id;
	}

	/**
	 * Adds the course's quiz at the end of a section.
	 *
	 * @param string $course    Course title (the key in SeedData::quizzes()).
	 * @param int    $section   Section ID.
	 * @param int    $author_id Instructor.
	 * @return void
	 */
	private function quiz( string $course, int $section, int $author_id ): void {
		$questions = SeedData::quizzes()[ $course ] ?? array();
		if ( empty( $questions ) || null === $this->container ) {
			return;
		}
		$bank    = $this->container->get( QuestionBank::class );
		$quiz_id = $this->structure->add_item( $section, 'quiz', 'Check your understanding', $author_id );
		wp_update_post(
			array(
				'ID'           => $quiz_id,
				'post_content' => '<!-- wp:paragraph --><p>Four quick questions on this section. You need 60% to pass, and you have three attempts.</p><!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $quiz_id, self::FLAG, 1 );
		update_post_meta( $quiz_id, Meta::DURATION, 5 );
		$ids = array();
		foreach ( $questions as $input ) {
			$clean = $bank->sanitize( $input );
			if ( is_wp_error( $clean ) ) {
				WP_CLI::log( 'Skipped a question: ' . $clean->get_error_message() );
				continue;
			}
			$id = $bank->save( $clean, 0, $author_id );
			if ( ! is_wp_error( $id ) ) {
				update_post_meta( (int) $id, self::FLAG, 1 );
				$ids[] = (int) $id;
			}
		}
		QuizSettings::set_question_ids( $quiz_id, $ids );
		QuizSettings::update(
			$quiz_id,
			array(
				'pass_mark'       => 60,
				'max_attempts'    => 3,
				'shuffle_answers' => true,
			)
		);
	}

	/**
	 * Adds the course's assignment at the end of a section.
	 *
	 * @param string $course    Course title (the key in SeedData::assignments()).
	 * @param int    $section   Section ID.
	 * @param int    $author_id Instructor.
	 * @return void
	 */
	private function assignment( string $course, int $section, int $author_id ): void {
		$data = SeedData::assignments()[ $course ] ?? null;
		if ( null === $data ) {
			return;
		}
		$id = $this->structure->add_item( $section, 'assignment', $data[0], $author_id );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '<!-- wp:paragraph --><p>' . esc_html( $data[1] ) . '</p><!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $id, self::FLAG, 1 );
		update_post_meta( $id, Meta::DURATION, 30 );
		AssignmentSettings::update(
			$id,
			array(
				'points'      => 10,
				'pass_points' => 6,
				'file_types'  => array( 'pdf', 'doc', 'docx', 'odt', 'txt', 'xlsx', 'ods', 'csv', 'jpg', 'jpeg', 'png', 'zip' ),
			)
		);
	}

	/**
	 * Completes one step for a seeded learner the way a learner would: lessons
	 * are marked complete, quizzes are taken (after a failed try when asked),
	 * assignments are handed in and graded by the instructor.
	 *
	 * @param int  $user_id   Learner.
	 * @param int  $item_id   Lesson, quiz or assignment.
	 * @param bool $fail_once Whether to fail the quiz once before passing.
	 * @return void
	 */
	private function complete_step( int $user_id, int $item_id, bool $fail_once ): void {
		$type = get_post_type( $item_id );
		if ( null === $this->container || PostTypes::LESSON === $type ) {
			$this->progress->complete_item( $user_id, $item_id );
			return;
		}
		if ( PostTypes::QUIZ === $type ) {
			$quizzes = $this->container->get( QuizService::class );
			if ( $fail_once ) {
				$attempt = $quizzes->start( $user_id, $item_id );
				if ( is_array( $attempt ) ) {
					$quizzes->submit( $user_id, (int) $attempt['attempt']['id'], array() );
				}
			}
			$attempt = $quizzes->start( $user_id, $item_id );
			if ( is_array( $attempt ) ) {
				$quizzes->submit( $user_id, (int) $attempt['attempt']['id'], $this->right_answers( (array) $attempt['attempt']['question_ids'] ) );
			}
			return;
		}
		$assignments = $this->container->get( AssignmentService::class );
		$handin      = $assignments->submit( $user_id, $item_id, '<p>Here is my work for this assignment, with my notes on what I would do differently next time.</p>', array() );
		if ( is_array( $handin ) ) {
			$teacher = (int) get_post_field( 'post_author', $this->structure->course_id_for( $item_id ) );
			$assignments->grade( (int) $handin['id'], 8, 'Clear and complete. Next time, add one example from your own work.', $teacher );
		}
	}

	/**
	 * Returns answers that get every listed question right, from the answer keys.
	 *
	 * @param array<int, int> $question_ids Questions.
	 * @return array<int|string, mixed>
	 */
	private function right_answers( array $question_ids ): array {
		$bank    = null === $this->container ? null : $this->container->get( QuestionBank::class );
		$answers = array();
		foreach ( $question_ids as $question_id ) {
			$question = null === $bank ? null : $bank->get( (int) $question_id );
			if ( null === $question ) {
				continue;
			}
			switch ( $question['type'] ) {
				case QuestionBank::SINGLE:
				case QuestionBank::MULTIPLE:
					$right = array();
					foreach ( (array) $question['options'] as $option ) {
						if ( ! empty( $option['correct'] ) ) {
							$right[] = (string) $option['id'];
						}
					}
					$answers[ (string) $question_id ] = QuestionBank::SINGLE === $question['type'] ? ( $right[0] ?? '' ) : $right;
					break;
				case QuestionBank::TRUE_FALSE:
					$answers[ (string) $question_id ] = (bool) $question['answer'];
					break;
				case QuestionBank::FILL_BLANK:
					$answers[ (string) $question_id ] = array_map(
						static function ( $accepted ) {
							return (string) ( (array) $accepted )[0];
						},
						(array) $question['blanks']
					);
					break;
				case QuestionBank::SHORT_ANSWER:
					$answers[ (string) $question_id ] = (string) ( (array) $question['accepted'] )[0];
					break;
				case QuestionBank::MATCHING:
					$answers[ (string) $question_id ] = wp_list_pluck( (array) $question['pairs'], 'match_id', 'prompt_id' );
					break;
			}
		}
		return $answers;
	}

	/**
	 * Builds a lesson's block content.
	 *
	 * @param string $title Lesson title.
	 * @param string $body  Lesson text.
	 * @return string
	 */
	private function lesson_content( string $title, string $body ): string {
		return '<!-- wp:paragraph --><p>' . esc_html( $body ) . '</p><!-- /wp:paragraph -->'
			. '<!-- wp:heading --><h2 class="wp-block-heading">Try this</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>' . esc_html( sprintf( 'Before you move on, write down one idea from “%s” that you can use this week, and one question you still have.', $title ) ) . '</p><!-- /wp:paragraph -->';
	}
}
