<?php
/**
 * Enrolment routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\Courses\CourseFields;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Services\EnrollmentService;
use PublicLMS\Services\ProgressService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes:
 *
 * - GET    /enrollments/me                         The current user's enrolments. Logged in.
 * - POST   /enrollments/me                         Free self-enrolment. Logged in + AccessPolicy::can_enroll.
 * - GET    /courses/{id}/enrollments               Learners of a course, keyset paginated.
 *                                                  publiclms_view_course_reports.
 * - POST   /courses/{id}/enrollments               Enrol a learner. publiclms_enroll_student.
 * - DELETE /courses/{id}/enrollments/{user_id}     Cancel a learner's enrolment. publiclms_enroll_student.
 */
final class EnrollmentsController extends AbstractController {

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	private $enrollments;

	/**
	 * Enrolment service.
	 *
	 * @var EnrollmentService
	 */
	private $service;

	/**
	 * Progress.
	 *
	 * @var ProgressService
	 */
	private $progress;

	/**
	 * Course fields.
	 *
	 * @var CourseFields
	 */
	private $fields;

	/**
	 * Constructor.
	 *
	 * @param EnrollmentRepository $enrollments Enrolments.
	 * @param EnrollmentService    $service     Enrolment service.
	 * @param ProgressService      $progress    Progress.
	 * @param CourseFields         $fields      Course fields.
	 */
	public function __construct( EnrollmentRepository $enrollments, EnrollmentService $service, ProgressService $progress, CourseFields $fields ) {
		parent::__construct();
		$this->rest_base   = 'enrollments';
		$this->enrollments = $enrollments;
		$this->service     = $service;
		$this->progress    = $progress;
		$this->fields      = $fields;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_mine' ),
					'permission_callback' => array( $this, 'logged_in' ),
					'args'                => array(
						'status' => array(
							'description'       => __( 'Only enrolments with this status.', 'public-lms' ),
							'type'              => 'string',
							'enum'              => array_merge( array( 'all' ), EnrollmentRepository::STATUSES ),
							'default'           => 'active',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'enroll_me' ),
					'permission_callback' => array( $this, 'logged_in' ),
					'args'                => array(
						'course_id' => $this->id_arg( __( 'Course to enrol in.', 'public-lms' ) ),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/courses/(?P<id>[\d]+)/' . $this->rest_base,
			array(
				'args'   => array(
					'id' => $this->id_arg( __( 'Course ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array_merge(
						array(
							'status' => array(
								'description'       => __( 'Only enrolments with this status.', 'public-lms' ),
								'type'              => 'string',
								'enum'              => array_merge( array( 'all' ), EnrollmentRepository::STATUSES ),
								'default'           => 'active',
								'sanitize_callback' => 'sanitize_key',
								'validate_callback' => 'rest_validate_request_arg',
							),
						),
						$this->keyset_collection_params()
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'user_id' => $this->id_arg( __( 'Learner to enrol.', 'public-lms' ) ),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/courses/(?P<id>[\d]+)/' . $this->rest_base . '/(?P<user_id>[\d]+)',
			array(
				'args' => array(
					'id'      => $this->id_arg( __( 'Course ID.', 'public-lms' ) ),
					'user_id' => $this->id_arg( __( 'Learner ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Requires a logged-in user.
	 *
	 * @return true|WP_Error
	 */
	public function logged_in() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error( 'publiclms_rest_logged_out', __( 'Please log in first.', 'public-lms' ), array( 'status' => 401 ) );
	}

	/**
	 * Lists the current user's enrolments with progress.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_mine( $request ) {
		$user_id = get_current_user_id();
		$status  = (string) $request['status'];
		$rows    = $this->enrollments->find_by_user( $user_id, 'all' === $status ? null : $status );
		$items   = array();
		foreach ( $rows as $row ) {
			if ( PostTypes::COURSE !== get_post_type( (int) $row['course_id'] ) ) {
				continue;
			}
			$items[] = $this->enrollment_data( $row, true );
		}
		return rest_ensure_response( $items );
	}

	/**
	 * Enrols the current user in a free course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function enroll_me( $request ) {
		$user_id   = get_current_user_id();
		$course_id = (int) $request['course_id'];
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return $this->not_found();
		}
		$result = $this->service->self_enroll( $user_id, $course_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$row      = $this->enrollments->find_by_user_and_course( $user_id, $course_id );
		$response = rest_ensure_response( null === $row ? array() : $this->enrollment_data( $row, true ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Needs publiclms_view_course_reports on the course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$course_id = (int) $request['id'];
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return $this->not_found();
		}
		return current_user_can( Capabilities::META_VIEW_COURSE_REPORTS, $course_id ) ? true : $this->forbidden();
	}

	/**
	 * Lists a course's learners.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$course_id = (int) $request['id'];
		$status    = (string) $request['status'];
		$status    = 'all' === $status ? null : $status;
		$per_page  = (int) $request['per_page'];
		$rows      = $this->enrollments->find_by_course( $course_id, $status, (int) $request['after'], $per_page + 1 );

		$next = '';
		if ( count( $rows ) > $per_page ) {
			$rows = array_slice( $rows, 0, $per_page );
			$next = (string) end( $rows )['id'];
		}
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = $this->enrollment_data( $row, false );
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $this->enrollments->count_by_course( $course_id, $status ) );
		if ( '' !== $next ) {
			$response->header( 'X-PublicLMS-Next-Cursor', $next );
			$params          = $request->get_query_params();
			$params['after'] = $next;
			$response->link_header( 'next', add_query_arg( urlencode_deep( $params ), rest_url( ltrim( $request->get_route(), '/' ) ) ) );
		}
		return $response;
	}

	/**
	 * Needs publiclms_enroll_student on the course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$course_id = (int) $request['id'];
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return $this->not_found();
		}
		return current_user_can( Capabilities::META_ENROLL_STUDENT, $course_id ) ? true : $this->forbidden();
	}

	/**
	 * Enrols a learner manually.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$course_id = (int) $request['id'];
		$user_id   = (int) $request['user_id'];
		if ( false === get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'That user does not exist.', 'public-lms' ), array( 'status' => 400 ) );
		}
		if ( $this->service->enroll( $user_id, $course_id, 'manual' ) <= 0 ) {
			return new WP_Error( 'publiclms_enroll_failed', __( 'The enrolment could not be saved.', 'public-lms' ), array( 'status' => 500 ) );
		}
		$row      = $this->enrollments->find_by_user_and_course( $user_id, $course_id );
		$response = rest_ensure_response( null === $row ? array() : $this->enrollment_data( $row, false ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Cancels a learner's enrolment; progress is kept.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$course_id = (int) $request['id'];
		$user_id   = (int) $request['user_id'];
		$row       = $this->enrollments->find_by_user_and_course( $user_id, $course_id );
		if ( null === $row ) {
			return $this->not_found();
		}
		$this->service->unenroll( $user_id, $course_id );
		$row = $this->enrollments->find_by_user_and_course( $user_id, $course_id );
		return rest_ensure_response(
			array(
				'deleted'  => true,
				'previous' => null === $row ? array() : $this->enrollment_data( $row, false ),
			)
		);
	}

	/**
	 * Builds an enrolment response.
	 *
	 * @param array<string, mixed> $row         Enrolment row.
	 * @param bool                 $with_course Whether to include course details (learner view) or user details (course view).
	 * @return array<string, mixed>
	 */
	private function enrollment_data( array $row, bool $with_course ): array {
		$user_id   = (int) $row['user_id'];
		$course_id = (int) $row['course_id'];
		$summary   = $this->progress->summary( $user_id, $course_id );

		$data = array(
			'id'           => (int) $row['id'],
			'course_id'    => $course_id,
			'user_id'      => $user_id,
			'status'       => (string) $row['status'],
			'source'       => (string) $row['source'],
			'enrolled_at'  => mysql_to_rfc3339( (string) $row['enrolled_at'] ),
			'expires_at'   => empty( $row['expires_at'] ) ? null : mysql_to_rfc3339( (string) $row['expires_at'] ),
			'completed_at' => empty( $row['completed_at'] ) ? null : mysql_to_rfc3339( (string) $row['completed_at'] ),
			'progress'     => array(
				'percent'         => $summary['percent'],
				'items_completed' => $summary['items_completed'],
				'items_total'     => $summary['items_total'],
				'completed'       => $summary['completed'],
			),
		);

		if ( $with_course ) {
			$state          = $this->fields->enroll_state( $course_id, $user_id );
			$data['course'] = array(
				'title'      => wp_strip_all_tags( get_the_title( $course_id ) ),
				'link'       => (string) get_permalink( $course_id ),
				'resume_url' => $state['url'],
				'image'      => (string) get_the_post_thumbnail_url( $course_id, 'medium' ),
			);
		} else {
			$user         = get_userdata( $user_id );
			$data['user'] = array(
				'name'   => false === $user ? '' : (string) $user->display_name,
				'email'  => false === $user ? '' : (string) $user->user_email,
				'avatar' => (string) get_avatar_url( $user_id, array( 'size' => 48 ) ),
			);
		}
		return $data;
	}

	/**
	 * Returns the item schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'publiclms-enrollment',
			'type'       => 'object',
			'properties' => array(
				'id'           => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'course_id'    => array( 'type' => 'integer' ),
				'user_id'      => array( 'type' => 'integer' ),
				'status'       => array(
					'type' => 'string',
					'enum' => EnrollmentRepository::STATUSES,
				),
				'source'       => array(
					'type' => 'string',
					'enum' => EnrollmentRepository::SOURCES,
				),
				'enrolled_at'  => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'expires_at'   => array(
					'type'   => array( 'string', 'null' ),
					'format' => 'date-time',
				),
				'completed_at' => array(
					'type'   => array( 'string', 'null' ),
					'format' => 'date-time',
				),
				'progress'     => array(
					'type'       => 'object',
					'readonly'   => true,
					'properties' => array(
						'percent'         => array( 'type' => 'integer' ),
						'items_completed' => array( 'type' => 'integer' ),
						'items_total'     => array( 'type' => 'integer' ),
						'completed'       => array( 'type' => 'boolean' ),
					),
				),
				'course'       => array(
					'type'     => 'object',
					'readonly' => true,
				),
				'user'         => array(
					'type'     => 'object',
					'readonly' => true,
				),
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}
}
