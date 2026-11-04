<?php
/**
 * Course field values.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Courses;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Quizzes\QuizSettings;
use PublicLMS\Services\ProgressService;
use PublicLMS\Services\QuizService;
use PublicLMS\Taxonomies\Taxonomies;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the course values shown to people: price, duration, counts,
 * instructor and the current user's enrol state.
 *
 * One class feeds the `publiclms/course-field` bindings source (keys frozen
 * in docs/CONTRACTS.md A7), the course blocks and the REST responses, so the
 * same course never shows two different lesson counts. Values are raw text
 * or URLs; callers escape for their context.
 */
class CourseFields {

	/**
	 * Binding keys, in the order of the contract.
	 */
	const KEYS = array(
		'price',
		'regular_price',
		'duration',
		'lesson_count',
		'level',
		'instructor',
		'instructor_url',
		'instructor_avatar',
		'enrolled_count',
		'rating',
		'enroll_url',
		'enroll_label',
		'progress',
		'course_title',
		'course_url',
		'section_title',
		'updated',
		'quiz_question_count',
		'quiz_time_limit',
		'quiz_pass_mark',
		'quiz_attempts_left',
	);

	/**
	 * Keys whose value is a URL.
	 */
	const URL_KEYS = array( 'instructor_url', 'instructor_avatar', 'enroll_url', 'course_url' );

	/**
	 * Currency symbols for the formats the plugin ships; other ISO codes print the code.
	 */
	const SYMBOLS = array(
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'INR' => '₹',
		'NPR' => 'Rs ',
		'AUD' => 'A$',
		'CAD' => 'C$',
		'JPY' => '¥',
	);

	/**
	 * Course structure.
	 *
	 * @var CourseStructureRepositoryInterface
	 */
	private $structure;

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	private $enrollments;

	/**
	 * Progress.
	 *
	 * @var ProgressService
	 */
	private $progress;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private $policy;

	/**
	 * Quiz service, for the quiz keys.
	 *
	 * @var QuizService|null
	 */
	private $quizzes;

	/**
	 * Constructor.
	 *
	 * @param CourseStructureRepositoryInterface $structure   Course structure.
	 * @param EnrollmentRepository               $enrollments Enrolments.
	 * @param ProgressService                    $progress    Progress.
	 * @param AccessPolicy                       $policy      Access policy.
	 * @param QuizService|null                   $quizzes     Quiz service; without it the quiz keys are empty.
	 */
	public function __construct( CourseStructureRepositoryInterface $structure, EnrollmentRepository $enrollments, ProgressService $progress, AccessPolicy $policy, ?QuizService $quizzes = null ) {
		$this->structure   = $structure;
		$this->enrollments = $enrollments;
		$this->progress    = $progress;
		$this->policy      = $policy;
		$this->quizzes     = $quizzes;
	}

	/**
	 * Returns the course a post stands for: the course itself, or the course of a section or item.
	 *
	 * @param int $post_id Course, section, lesson, quiz or assignment ID.
	 * @return int
	 */
	public function resolve_course_id( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}
		if ( PostTypes::COURSE === get_post_type( $post_id ) ) {
			return $post_id;
		}
		return $this->structure->course_id_for( $post_id );
	}

	/**
	 * Returns one binding value as raw text or URL, or '' for an unknown key.
	 *
	 * @param int    $course_id Course ID.
	 * @param string $key       One of KEYS.
	 * @param int    $user_id   User for the per-user keys; 0 for the current user.
	 * @param int    $post_id   The post the value is shown for (a lesson for section_title), or 0.
	 * @return string
	 */
	public function get( int $course_id, string $key, int $user_id = 0, int $post_id = 0 ): string {
		$course = get_post( $course_id );
		if ( ! $course instanceof WP_Post || PostTypes::COURSE !== $course->post_type ) {
			return '';
		}
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		switch ( $key ) {
			case 'price':
				return $this->price_label( $course_id );
			case 'regular_price':
				return $this->regular_price_label( $course_id );
			case 'duration':
				return $this->format_duration( $this->duration_minutes( $course_id ) );
			case 'lesson_count':
				$count = $this->lesson_count( $course_id );
				/* translators: %s: number of lessons. */
				return sprintf( _n( '%s lesson', '%s lessons', $count, 'public-lms' ), number_format_i18n( $count ) );
			case 'level':
				return $this->level_name( $course_id );
			case 'instructor':
				return (string) get_the_author_meta( 'display_name', (int) $course->post_author );
			case 'instructor_url':
				return (string) get_author_posts_url( (int) $course->post_author );
			case 'instructor_avatar':
				return (string) get_avatar_url( (int) $course->post_author, array( 'size' => 96 ) );
			case 'enrolled_count':
				$count = $this->enrollments->count_by_course( $course_id, EnrollmentRepository::STATUS_ACTIVE );
				/* translators: %s: number of learners. */
				return sprintf( _n( '%s learner', '%s learners', $count, 'public-lms' ), number_format_i18n( $count ) );
			case 'rating':
				return '';
			case 'enroll_url':
				return $this->enroll_state( $course_id, $user_id )['url'];
			case 'enroll_label':
				return $this->enroll_state( $course_id, $user_id )['label'];
			case 'progress':
				if ( ! $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
					return '';
				}
				/* translators: %d: percentage of the course completed. */
				return sprintf( __( '%d%%', 'public-lms' ), $this->progress->summary( $user_id, $course_id )['percent'] );
			case 'course_title':
				return wp_strip_all_tags( get_the_title( $course ) );
			case 'course_url':
				return (string) get_permalink( $course );
			case 'updated':
				return (string) get_the_modified_date( '', $course );
			case 'section_title':
				$section_id = $post_id > 0 ? $this->structure->section_id_for( $post_id ) : 0;
				return $section_id > 0 ? wp_strip_all_tags( get_the_title( $section_id ) ) : '';
			case 'quiz_question_count':
			case 'quiz_time_limit':
			case 'quiz_pass_mark':
			case 'quiz_attempts_left':
				return $this->quiz_value( $key, $post_id, $user_id );
		}
		return '';
	}

	/**
	 * Returns a quiz key's value for the quiz on screen, or '' elsewhere.
	 *
	 * @param string $key     Quiz key.
	 * @param int    $quiz_id The post the value is shown for.
	 * @param int    $user_id User, for attempts left.
	 * @return string
	 */
	private function quiz_value( string $key, int $quiz_id, int $user_id ): string {
		if ( $quiz_id <= 0 || PostTypes::QUIZ !== get_post_type( $quiz_id ) || null === $this->quizzes ) {
			return '';
		}
		$settings = QuizSettings::get( $quiz_id );
		switch ( $key ) {
			case 'quiz_question_count':
				$count = $this->quizzes->question_total( $quiz_id );
				/* translators: %s: number of questions. */
				return sprintf( _n( '%s question', '%s questions', $count, 'public-lms' ), number_format_i18n( $count ) );
			case 'quiz_time_limit':
				return $settings['time_limit'] > 0
					/* translators: %d: minutes. */
					? sprintf( _n( 'Time limit: %d minute', 'Time limit: %d minutes', $settings['time_limit'], 'public-lms' ), $settings['time_limit'] )
					: __( 'No time limit', 'public-lms' );
			case 'quiz_pass_mark':
				/* translators: %d: pass mark as a percentage. */
				return sprintf( __( 'Pass mark: %d%%', 'public-lms' ), $settings['pass_mark'] );
			case 'quiz_attempts_left':
				if ( $settings['max_attempts'] <= 0 ) {
					return __( 'Unlimited attempts', 'public-lms' );
				}
				$left = $this->quizzes->learner_summary( $user_id, $quiz_id )['attempts_left'];
				/* translators: 1: attempts left, 2: attempts allowed. */
				return sprintf( __( 'Attempts left: %1$d of %2$d', 'public-lms' ), (int) $left, $settings['max_attempts'] );
		}
		return '';
	}

	/**
	 * Returns every binding value, for REST and the editor.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User; 0 for the current user.
	 * @param int $post_id   The post the values are shown for, or 0.
	 * @return array<string, string>
	 */
	public function all( int $course_id, int $user_id = 0, int $post_id = 0 ): array {
		$values = array();
		foreach ( self::KEYS as $key ) {
			$values[ $key ] = $this->get( $course_id, $key, $user_id, $post_id );
		}
		return $values;
	}

	/**
	 * Describes what the enrol button should do for a user.
	 *
	 * States: logged_out, can_enroll, enrolled, completed, closed, paid, prerequisite, unavailable.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User; 0 for the current user.
	 * @return array{state: string, url: string, label: string, resume_item: int}
	 */
	public function enroll_state( int $course_id, int $user_id = 0 ): array {
		$user_id    = $user_id > 0 ? $user_id : get_current_user_id();
		$course_url = (string) get_permalink( $course_id );

		if ( $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			$summary = $this->progress->summary( $user_id, $course_id );
			$resume  = $summary['resume_item'];
			if ( $summary['completed'] || 0 === $resume ) {
				$items = $this->structure->item_ids( $course_id );
				return array(
					'state'       => $summary['completed'] ? 'completed' : 'enrolled',
					'url'         => empty( $items ) ? $course_url : (string) get_permalink( $items[0] ),
					'label'       => $summary['completed'] ? __( 'Review course', 'public-lms' ) : __( 'Start learning', 'public-lms' ),
					'resume_item' => empty( $items ) ? 0 : (int) $items[0],
				);
			}
			return array(
				'state'       => 'enrolled',
				'url'         => (string) get_permalink( $resume ),
				'label'       => $summary['items_completed'] > 0 ? __( 'Continue learning', 'public-lms' ) : __( 'Start learning', 'public-lms' ),
				'resume_item' => $resume,
			);
		}

		$decision = $this->policy->can_enroll( $course_id, $user_id );
		switch ( $decision->reason() ) {
			case '':
				return array(
					'state'       => 'can_enroll',
					'url'         => self::enroll_action_url( $course_id ),
					'label'       => __( 'Enrol for free', 'public-lms' ),
					'resume_item' => 0,
				);
			case 'logged_out':
				$mode = Meta::access_mode( $course_id );
				return array(
					'state'       => 'logged_out',
					'url'         => wp_login_url( $course_url ),
					'label'       => 'paid' === $mode ? $this->buy_label( $course_id ) : __( 'Log in to enrol', 'public-lms' ),
					'resume_item' => 0,
				);
			case 'payment_required':
				return array(
					'state'       => 'paid',
					'url'         => $course_url,
					'label'       => $this->buy_label( $course_id ),
					'resume_item' => 0,
				);
			case 'enrollment_closed':
				return array(
					'state'       => 'closed',
					'url'         => $course_url,
					'label'       => __( 'Enrolment by invitation', 'public-lms' ),
					'resume_item' => 0,
				);
			case 'prerequisite':
				$required = $decision->requires();
				return array(
					'state'       => 'prerequisite',
					'url'         => empty( $required ) ? $course_url : (string) get_permalink( $required[0] ),
					'label'       => __( 'Complete the required course first', 'public-lms' ),
					'resume_item' => 0,
				);
		}
		return array(
			'state'       => 'unavailable',
			'url'         => $course_url,
			'label'       => __( 'Not open for enrolment', 'public-lms' ),
			'resume_item' => 0,
		);
	}

	/**
	 * Returns the nonce-protected URL that enrols the current user and redirects to the first lesson.
	 *
	 * The URL is raw (unescaped); escape it with esc_url() when printing it.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public static function enroll_action_url( int $course_id ): string {
		// Not wp_nonce_url(): it HTML-escapes the URL, and callers escape for their own context.
		return add_query_arg(
			array(
				'action'    => 'publiclms_enroll',
				'course_id' => $course_id,
				'_wpnonce'  => wp_create_nonce( 'publiclms_enroll_' . $course_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Returns the price shown to learners: "Free" or the formatted (sale) price.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public function price_label( int $course_id ): string {
		$amount = $this->effective_price( $course_id );
		return null === $amount ? __( 'Free', 'public-lms' ) : self::format_price( $amount );
	}

	/**
	 * Returns the regular price when a sale price applies, else ''.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public function regular_price_label( int $course_id ): string {
		if ( 'paid' !== Meta::access_mode( $course_id ) ) {
			return '';
		}
		$regular = (string) get_post_meta( $course_id, Meta::PRICE, true );
		$sale    = (string) get_post_meta( $course_id, Meta::SALE_PRICE, true );
		if ( '' === $regular || '' === $sale || (float) $sale >= (float) $regular ) {
			return '';
		}
		return self::format_price( (float) $regular );
	}

	/**
	 * Returns the price a learner pays, or null when the course is free.
	 *
	 * @param int $course_id Course ID.
	 * @return float|null
	 */
	public function effective_price( int $course_id ): ?float {
		if ( 'paid' !== Meta::access_mode( $course_id ) ) {
			return null;
		}
		$regular = (string) get_post_meta( $course_id, Meta::PRICE, true );
		$sale    = (string) get_post_meta( $course_id, Meta::SALE_PRICE, true );
		$price   = '' !== $sale && ( '' === $regular || (float) $sale < (float) $regular ) ? (float) $sale : (float) $regular;
		return $price > 0 ? $price : null;
	}

	/**
	 * Formats an amount in the store currency (option publiclms_currency, default USD).
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function format_price( float $amount ): string {
		$currency = strtoupper( (string) get_option( 'publiclms_currency', 'USD' ) );
		$decimals = abs( $amount - round( $amount ) ) < 0.005 ? 0 : 2;
		$number   = number_format_i18n( $amount, $decimals );
		if ( isset( self::SYMBOLS[ $currency ] ) ) {
			return self::SYMBOLS[ $currency ] . $number;
		}
		return $currency . ' ' . $number;
	}

	/**
	 * Returns a course's duration in minutes: its own setting, or the sum of its published items.
	 *
	 * @param int $course_id Course ID.
	 * @return int
	 */
	public function duration_minutes( int $course_id ): int {
		$own = (int) get_post_meta( $course_id, Meta::DURATION, true );
		if ( $own > 0 ) {
			return $own;
		}
		$total = 0;
		foreach ( $this->structure->get_public_structure( $course_id )['sections'] as $section ) {
			foreach ( (array) $section['items'] as $item ) {
				$total += (int) $item['duration'];
			}
		}
		return $total;
	}

	/**
	 * Formats minutes as "6h 30m", "45m" or "2h", or '' for zero.
	 *
	 * @param int $minutes Minutes.
	 * @return string
	 */
	public function format_duration( int $minutes ): string {
		if ( $minutes <= 0 ) {
			return '';
		}
		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;
		if ( 0 === $hours ) {
			/* translators: %d: minutes. */
			return sprintf( __( '%dm', 'public-lms' ), $rest );
		}
		if ( 0 === $rest ) {
			/* translators: %d: hours. */
			return sprintf( __( '%dh', 'public-lms' ), $hours );
		}
		/* translators: 1: hours, 2: minutes. */
		return sprintf( __( '%1$dh %2$dm', 'public-lms' ), $hours, $rest );
	}

	/**
	 * Counts the published lessons of a course.
	 *
	 * @param int $course_id Course ID.
	 * @return int
	 */
	public function lesson_count( int $course_id ): int {
		$count = 0;
		foreach ( $this->structure->get_public_structure( $course_id )['sections'] as $section ) {
			foreach ( (array) $section['items'] as $item ) {
				if ( 'lesson' === $item['type'] ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * Returns the name of the course's first level term, or ''.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public function level_name( int $course_id ): string {
		$terms = get_the_terms( $course_id, Taxonomies::LEVEL );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}
		return (string) $terms[0]->name;
	}

	/**
	 * Returns "Buy for $49" for a paid course.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	private function buy_label( int $course_id ): string {
		$amount = $this->effective_price( $course_id );
		if ( null === $amount ) {
			return __( 'Enrol now', 'public-lms' );
		}
		/* translators: %s: formatted price. */
		return sprintf( __( 'Buy for %s', 'public-lms' ), self::format_price( $amount ) );
	}
}
