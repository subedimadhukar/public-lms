<?php
/**
 * Progress routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Capabilities\Capabilities;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Services\ProgressService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes:
 *
 * - GET  /courses/{id}/progress/me   The current user's progress. Logged in and enrolled (or able to
 *                                    edit the course, who get an empty summary).
 * - POST /progress                   Mark a lesson complete or incomplete. Logged in, enrolled, and
 *                                    AccessPolicy::can_access_item; the lesson player posts here with
 *                                    the REST nonce from Interactivity state.
 */
final class ProgressController extends AbstractController {

	/**
	 * Progress.
	 *
	 * @var ProgressService
	 */
	private $progress;

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	private $enrollments;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private $policy;

	/**
	 * Constructor.
	 *
	 * @param ProgressService      $progress    Progress.
	 * @param EnrollmentRepository $enrollments Enrolments.
	 * @param AccessPolicy         $policy      Access policy.
	 */
	public function __construct( ProgressService $progress, EnrollmentRepository $enrollments, AccessPolicy $policy ) {
		parent::__construct();
		$this->rest_base   = 'progress';
		$this->progress    = $progress;
		$this->enrollments = $enrollments;
		$this->policy      = $policy;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'item_id' => $this->id_arg( __( 'Lesson, quiz or assignment ID.', 'public-lms' ) ),
						'status'  => array(
							'description'       => __( 'completed, or incomplete to undo a completion.', 'public-lms' ),
							'type'              => 'string',
							'enum'              => array( 'completed', 'incomplete' ),
							'default'           => 'completed',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/courses/(?P<id>[\d]+)/progress/me',
			array(
				'args'   => array(
					'id' => $this->id_arg( __( 'Course ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Logged in, and enrolled or able to edit the course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$course_id = (int) $request['id'];
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return $this->not_found();
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'publiclms_rest_logged_out', __( 'Please log in first.', 'public-lms' ), array( 'status' => 401 ) );
		}
		if ( $this->enrollments->find_by_user_and_course( get_current_user_id(), $course_id ) || current_user_can( Capabilities::META_EDIT_COURSE, $course_id ) ) {
			return true;
		}
		return $this->denied( $this->policy->can_access_course( $course_id ) );
	}

	/**
	 * Returns the current user's progress in a course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$course_id = (int) $request['id'];
		$summary   = $this->progress->summary( get_current_user_id(), $course_id );
		$data      = array_merge(
			$summary,
			array(
				'resume_item' => $this->progress->item_link( $summary['resume_item'] ),
				'course_url'  => (string) get_permalink( $course_id ),
			)
		);
		return rest_ensure_response( $data );
	}

	/**
	 * Logged in, and the item is a lesson the user can open. Quizzes and
	 * assignments complete through their own routes (409 here).
	 *
	 * Enrolment is checked in the service, which also refuses editors who are
	 * previewing a course they are not enrolled in.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'publiclms_rest_logged_out', __( 'Please log in first.', 'public-lms' ), array( 'status' => 401 ) );
		}
		$item_id = (int) $request['item_id'];
		if ( ! in_array( get_post_type( $item_id ), array( PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT ), true ) ) {
			return $this->not_found();
		}
		if ( PostTypes::LESSON !== get_post_type( $item_id ) ) {
			// Quizzes complete when passed and assignments when submitted or graded (ADR-042, ADR-043).
			return new WP_Error(
				'publiclms_rest_not_manual',
				PostTypes::QUIZ === get_post_type( $item_id )
					? __( 'A quiz is complete once you pass it.', 'public-lms' )
					: __( 'An assignment is complete once you hand it in.', 'public-lms' ),
				array( 'status' => 409 )
			);
		}
		$decision = $this->policy->can_access_item( $item_id );
		return $decision->is_allowed() ? true : $this->denied( $decision );
	}

	/**
	 * Marks an item complete or incomplete and returns the new course progress and next item.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$user_id = get_current_user_id();
		$item_id = (int) $request['item_id'];
		$result  = 'incomplete' === $request['status']
			? $this->progress->uncomplete_item( $user_id, $item_id )
			: $this->progress->complete_item( $user_id, $item_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['resume_item'] = $this->progress->item_link( (int) $result['resume_item'] );
		$result['message']     = $result['completed']
			? __( 'Lesson complete. You have finished the course.', 'public-lms' )
			/* translators: %d: percentage of the course completed. */
			: sprintf( __( 'Progress saved. Course progress %d%%.', 'public-lms' ), (int) $result['percent'] );
		if ( ! $result['item_completed'] ) {
			/* translators: %d: percentage of the course completed. */
			$result['message'] = sprintf( __( 'Marked as not complete. Course progress %d%%.', 'public-lms' ), (int) $result['percent'] );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Returns the schema of a progress response.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$link         = array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'id'    => array( 'type' => 'integer' ),
				'title' => array( 'type' => 'string' ),
				'url'   => array( 'type' => 'string' ),
			),
		);
		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'publiclms-progress',
			'type'       => 'object',
			'properties' => array(
				'course_id'       => array( 'type' => 'integer' ),
				'percent'         => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				),
				'items_completed' => array( 'type' => 'integer' ),
				'items_total'     => array( 'type' => 'integer' ),
				'completed'       => array( 'type' => 'boolean' ),
				'completed_items' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'resume_item'     => $link,
				'next_item'       => $link,
				'item_id'         => array( 'type' => 'integer' ),
				'item_completed'  => array( 'type' => 'boolean' ),
				'course_url'      => array( 'type' => 'string' ),
				'message'         => array( 'type' => 'string' ),
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}
}
