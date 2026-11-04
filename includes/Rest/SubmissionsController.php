<?php
/**
 * Assignment hand-in routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\SubmissionRepository;
use PublicLMS\Services\AssignmentService;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes (ARCHITECTURE §4, ADR-043):
 *
 * - GET  /assignments/{id}/submissions                     Own hand-ins; a learner's (`user_id`) needs
 *                                                          publiclms_view_course_reports.
 * - POST /assignments/{id}/submissions                     Hand in (multipart: `content`, `files[]`).
 *                                                          AccessPolicy::can_submit_assignment; rate-limited.
 * - PUT  /assignments/{id}/submissions/{submission}/grade  Grade with feedback. publiclms_edit_course.
 * - GET  /submissions                                      Hand-ins to grade across the courses the user
 *                                                          teaches (all with publiclms_manage_courses).
 * - GET  /submissions/{id}                                 One hand-in: its learner or report viewers.
 * - GET  /submissions/{id}/files/{file}                    Download a file, streamed after the same check.
 *                                                          Files have no public URL.
 */
final class SubmissionsController extends AbstractController {

	/**
	 * Assignment service.
	 *
	 * @var AssignmentService
	 */
	private $assignments;

	/**
	 * Hand-ins.
	 *
	 * @var SubmissionRepository
	 */
	private $submissions;

	/**
	 * Course structure.
	 *
	 * @var CourseStructureRepositoryInterface
	 */
	private $structure;

	/**
	 * Constructor.
	 *
	 * @param AssignmentService                  $assignments Assignment service.
	 * @param SubmissionRepository               $submissions Hand-ins.
	 * @param CourseStructureRepositoryInterface $structure   Course structure.
	 */
	public function __construct( AssignmentService $assignments, SubmissionRepository $submissions, CourseStructureRepositoryInterface $structure ) {
		parent::__construct();
		$this->rest_base   = 'submissions';
		$this->assignments = $assignments;
		$this->submissions = $submissions;
		$this->structure   = $structure;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/assignments/(?P<id>[\d]+)/submissions',
			array(
				'args' => array(
					'id' => $this->id_arg( __( 'Assignment ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_for_assignment' ),
					'permission_callback' => array( $this, 'assignment_read_check' ),
					'args'                => array(
						'user_id' => $this->id_arg( __( 'Learner; defaults to the current user.', 'public-lms' ), false ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'content' => array(
							'description'       => __( 'Typed answer.', 'public-lms' ),
							'type'              => 'string',
							'default'           => '',
							'maxLength'         => 50000,
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/assignments/(?P<id>[\d]+)/submissions/(?P<submission>[\d]+)/grade',
			array(
				'args' => array(
					'id'         => $this->id_arg( __( 'Assignment ID.', 'public-lms' ) ),
					'submission' => $this->id_arg( __( 'Submission ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'grade' ),
					'permission_callback' => array( $this, 'grade_permissions_check' ),
					'args'                => array(
						'grade'    => array(
							'description'       => __( 'Points awarded.', 'public-lms' ),
							'type'              => 'number',
							'minimum'           => 0,
							'required'          => true,
							'validate_callback' => 'rest_validate_request_arg',
						),
						'feedback' => array(
							'description'       => __( 'Feedback for the learner.', 'public-lms' ),
							'type'              => 'string',
							'default'           => '',
							'maxLength'         => 20000,
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'status'     => array(
							'description'       => __( 'submitted (waiting for a grade), graded, or empty for both.', 'public-lms' ),
							'type'              => 'string',
							'enum'              => array( '', SubmissionRepository::STATUS_SUBMITTED, SubmissionRepository::STATUS_GRADED ),
							'default'           => SubmissionRepository::STATUS_SUBMITTED,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'course'     => $this->id_arg( __( 'Limit to one course.', 'public-lms' ), false ),
						'assignment' => $this->id_arg( __( 'Limit to one assignment.', 'public-lms' ), false ),
						'before'     => array(
							'description'       => __( 'Return hand-ins with an ID below this.', 'public-lms' ),
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'per_page'   => array(
							'description'       => __( 'Maximum number of items to return.', 'public-lms' ),
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => $this->id_arg( __( 'Submission ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/files/(?P<file>[a-z0-9]+)',
			array(
				'args' => array(
					'id'   => $this->id_arg( __( 'Submission ID.', 'public-lms' ) ),
					'file' => array(
						'description' => __( 'File ID.', 'public-lms' ),
						'type'        => 'string',
						'pattern'     => '^[a-z0-9]{6,32}$',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'download' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Own hand-ins, or a report viewer of the course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function assignment_read_check( $request ) {
		$check = $this->assignment_check( $request );
		if ( true !== $check ) {
			return $check;
		}
		$user_id = isset( $request['user_id'] ) ? (int) $request['user_id'] : get_current_user_id();
		return get_current_user_id() === $user_id || $this->can_view_reports( (int) $request['id'] ) ? true : $this->forbidden();
	}

	/**
	 * Lists a learner's hand-ins for an assignment, newest first.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_for_assignment( $request ) {
		$user_id = isset( $request['user_id'] ) ? (int) $request['user_id'] : get_current_user_id();
		$items   = array_map( array( $this->assignments, 'prepare' ), $this->submissions->for_user( $user_id, (int) $request['id'] ) );
		return rest_ensure_response( $items );
	}

	/**
	 * Logged in, and the assignment exists; the service applies the access policy.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		return $this->assignment_check( $request );
	}

	/**
	 * Hands in work.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$result = $this->assignments->submit( get_current_user_id(), (int) $request['id'], (string) $request['content'], self::uploads( $request->get_file_params() ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response( $this->assignments->prepare( $result ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Needs publiclms_edit_course on the assignment's course, and a hand-in of this assignment.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function grade_permissions_check( $request ) {
		$check = $this->assignment_check( $request );
		if ( true !== $check ) {
			return $check;
		}
		$submission = $this->submissions->find( (int) $request['submission'] );
		if ( null === $submission || (int) $submission['assignment_id'] !== (int) $request['id'] ) {
			return $this->not_found();
		}
		$course_id = $this->structure->course_id_for( (int) $request['id'] );
		$allowed   = $course_id > 0
			? current_user_can( Capabilities::META_EDIT_COURSE, $course_id )
			: current_user_can( 'edit_post', (int) $request['id'] );
		return $allowed ? true : $this->forbidden();
	}

	/**
	 * Grades a hand-in.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function grade( $request ) {
		$result = $this->assignments->grade( (int) $request['submission'], (float) $request['grade'], (string) $request['feedback'], get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $this->assignments->prepare( $result ) );
	}

	/**
	 * Needs publiclms_view_reports; `course` needs reports on that course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( Capabilities::VIEW_REPORTS ) ) {
			return $this->forbidden();
		}
		if ( isset( $request['course'] ) && ! current_user_can( Capabilities::META_VIEW_COURSE_REPORTS, (int) $request['course'] ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/**
	 * Lists hand-ins for teachers, newest first.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$courses    = isset( $request['course'] ) ? array( (int) $request['course'] ) : $this->taught_courses();
		$status     = (string) $request['status'];
		$assignment = isset( $request['assignment'] ) ? (int) $request['assignment'] : 0;
		$per_page   = (int) $request['per_page'];
		$rows       = $this->submissions->search( $courses, $status, $assignment, (int) $request['before'], $per_page + 1 );
		$next       = '';
		if ( count( $rows ) > $per_page ) {
			$rows = array_slice( $rows, 0, $per_page );
			$next = (string) end( $rows )['id'];
		}
		$response = rest_ensure_response( array_map( array( $this->assignments, 'prepare' ), $rows ) );
		$response->header( 'X-WP-Total', (string) $this->submissions->count( $courses, $status, $assignment ) );
		if ( '' !== $next ) {
			$response->header( 'X-PublicLMS-Next-Cursor', $next );
		}
		return $response;
	}

	/**
	 * The hand-in's learner, or a report viewer of its course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'publiclms_rest_logged_out', __( 'Please log in first.', 'public-lms' ), array( 'status' => 401 ) );
		}
		$submission = $this->submissions->find( (int) $request['id'] );
		if ( null === $submission ) {
			return $this->not_found();
		}
		if ( get_current_user_id() === (int) $submission['user_id'] || $this->can_view_reports( (int) $submission['assignment_id'] ) ) {
			return true;
		}
		return $this->not_found();
	}

	/**
	 * Returns a hand-in.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$submission = $this->submissions->find( (int) $request['id'] );
		return null === $submission ? $this->not_found() : rest_ensure_response( $this->assignments->prepare( $submission ) );
	}

	/**
	 * Streams a hand-in file.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return FileResponse|WP_Error
	 */
	public function download( $request ) {
		$submission = $this->submissions->find( (int) $request['id'] );
		$file       = null === $submission ? null : $this->assignments->file( $submission, (string) $request['file'] );
		if ( null === $file ) {
			return $this->not_found();
		}
		return FileResponse::from_path( $file['path'], $file['name'], $file['type'] );
	}

	/**
	 * Logged in, and the route's assignment exists.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	private function assignment_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'publiclms_rest_logged_out', __( 'Please log in first.', 'public-lms' ), array( 'status' => 401 ) );
		}
		if ( PostTypes::ASSIGNMENT !== get_post_type( (int) $request['id'] ) ) {
			return $this->not_found();
		}
		return true;
	}

	/**
	 * Whether the current user may see others' hand-ins for an assignment.
	 *
	 * @param int $assignment_id Assignment ID.
	 * @return bool
	 */
	private function can_view_reports( int $assignment_id ): bool {
		$course_id = $this->structure->course_id_for( $assignment_id );
		return $course_id > 0
			? current_user_can( Capabilities::META_VIEW_COURSE_REPORTS, $course_id )
			: current_user_can( 'edit_post', $assignment_id );
	}

	/**
	 * Returns the courses whose hand-ins the user may see: all (null) for managers, else their own.
	 *
	 * @return array<int, int>|null
	 */
	private function taught_courses(): ?array {
		if ( current_user_can( Capabilities::MANAGE_COURSES ) ) {
			return null;
		}
		$query = new WP_Query(
			array(
				'post_type'      => PostTypes::COURSE,
				'post_status'    => 'any',
				'author'         => get_current_user_id(),
				'fields'         => 'ids',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- IDs only; an instructor's own courses.
				'no_found_rows'  => true,
			)
		);
		$ids   = array_map( 'intval', $query->posts );

		/**
		 * Filters the courses whose reports a user sees. Pro adds co-taught courses.
		 *
		 * @param array<int, int> $ids     Course IDs.
		 * @param int             $user_id User ID.
		 */
		return array_values( array_map( 'intval', (array) apply_filters( 'publiclms_reported_courses', $ids, get_current_user_id() ) ) );
	}

	/**
	 * Turns $_FILES['files'] (single or multiple) into a list of file arrays.
	 *
	 * @param array<string, mixed> $params File parameters of the request.
	 * @return array<int, array<string, mixed>>
	 */
	public static function uploads( array $params ): array {
		$files = $params['files'] ?? ( $params['file'] ?? null );
		if ( ! is_array( $files ) || ! isset( $files['name'] ) ) {
			return array();
		}
		if ( ! is_array( $files['name'] ) ) {
			return array( $files );
		}
		$list = array();
		foreach ( array_keys( $files['name'] ) as $index ) {
			$list[] = array(
				'name'     => $files['name'][ $index ] ?? '',
				'type'     => $files['type'][ $index ] ?? '',
				'tmp_name' => $files['tmp_name'][ $index ] ?? '',
				'error'    => $files['error'][ $index ] ?? UPLOAD_ERR_NO_FILE,
				'size'     => $files['size'][ $index ] ?? 0,
			);
		}
		return $list;
	}
}
