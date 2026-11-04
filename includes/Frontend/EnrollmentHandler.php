<?php
/**
 * Enrolment without JavaScript.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Frontend;

use PublicLMS\Courses\CourseFields;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Services\EnrollmentService;

defined( 'ABSPATH' ) || exit;

/**
 * Handles `admin-post.php?action=publiclms_enroll`.
 *
 * The enrol button posts here when JavaScript is off, and the `enroll_url`
 * binding key links here, so a core Button in a theme pattern can enrol a
 * learner. Every request carries a per-user, per-course nonce; the access
 * policy decides as it does for REST.
 */
final class EnrollmentHandler {

	/**
	 * Enrolment service.
	 *
	 * @var EnrollmentService
	 */
	private $service;

	/**
	 * Course fields, for the redirect target.
	 *
	 * @var CourseFields
	 */
	private $fields;

	/**
	 * Constructor.
	 *
	 * @param EnrollmentService $service Enrolment service.
	 * @param CourseFields      $fields  Course fields.
	 */
	public function __construct( EnrollmentService $service, CourseFields $fields ) {
		$this->service = $service;
		$this->fields  = $fields;
	}

	/**
	 * Hooks the handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_publiclms_enroll', array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_publiclms_enroll', array( $this, 'handle_logged_out' ) );
	}

	/**
	 * Enrols the current user and redirects to the first lesson, or back to the course with a notice.
	 *
	 * @return void
	 */
	public function handle(): void {
		$course_id = isset( $_REQUEST['course_id'] ) ? absint( wp_unslash( $_REQUEST['course_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.
		check_admin_referer( 'publiclms_enroll_' . $course_id );

		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			wp_die( esc_html__( 'This course does not exist.', 'public-lms' ), '', array( 'response' => 404 ) );
		}

		$result = $this->service->self_enroll( get_current_user_id(), $course_id );
		if ( is_wp_error( $result ) ) {
			$data   = (array) $result->get_error_data();
			$target = add_query_arg( 'publiclms_notice', sanitize_key( (string) ( $data['reason'] ?? 'error' ) ), (string) get_permalink( $course_id ) );
			wp_safe_redirect( $target );
			exit;
		}

		wp_safe_redirect( $this->fields->enroll_state( $course_id )['url'] );
		exit;
	}

	/**
	 * Sends a logged-out visitor to the login screen, then back to the course.
	 *
	 * @return void
	 */
	public function handle_logged_out(): void {
		$course_id = isset( $_REQUEST['course_id'] ) ? absint( wp_unslash( $_REQUEST['course_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- No state changes; only a redirect.
		$back      = PostTypes::COURSE === get_post_type( $course_id ) ? (string) get_permalink( $course_id ) : home_url( '/' );
		wp_safe_redirect( wp_login_url( $back ) );
		exit;
	}
}
