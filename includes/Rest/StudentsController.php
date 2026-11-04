<?php
/**
 * Student list routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

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
 * Routes for course managers and instructors:
 *
 * - GET /students        Learners with at least one active enrolment, cursor paginated, searchable and
 *                        sortable. Managers (publiclms_view_reports + publiclms_manage_courses) see every
 *                        course; instructors (publiclms_view_reports) see the courses they teach.
 * - GET /students/{id}   One learner's enrolments and progress, within the same scope.
 */
final class StudentsController extends AbstractController {

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
	 * Constructor.
	 *
	 * @param EnrollmentRepository $enrollments Enrolments.
	 * @param ProgressService      $progress    Progress.
	 */
	public function __construct( EnrollmentRepository $enrollments, ProgressService $progress ) {
		parent::__construct();
		$this->rest_base   = 'students';
		$this->enrollments = $enrollments;
		$this->progress    = $progress;
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
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => $this->id_arg( __( 'Learner ID.', 'public-lms' ) ),
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
	 * Needs publiclms_view_reports; a course filter needs publiclms_view_course_reports on that course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( Capabilities::VIEW_REPORTS ) ) {
			return $this->forbidden();
		}
		$course_id = (int) $request['course'];
		if ( $course_id > 0 && ! current_user_can( Capabilities::META_VIEW_COURSE_REPORTS, $course_id ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/**
	 * Lists learners.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$course_id  = (int) $request['course'];
		$course_ids = $course_id > 0 ? array( $course_id ) : $this->scope();
		$per_page   = (int) $request['per_page'];
		$orderby    = (string) $request['orderby'];
		$order      = (string) $request['order'];
		$search     = (string) $request['search'];
		$cursor     = $this->decode_cursor( (string) $request['cursor'] );

		$rows = $this->enrollments->students( $course_ids, $search, $orderby, $order, $cursor, $per_page + 1 );
		$next = '';
		if ( count( $rows ) > $per_page ) {
			$rows = array_slice( $rows, 0, $per_page );
			$last = end( $rows );
			$sort = array(
				'id'       => $last['user_id'],
				'name'     => $last['display_name'],
				'enrolled' => $last['last_enrolled_at'],
			)[ $orderby ] ?? $last['user_id'];
			$next = $this->encode_cursor( $sort, (int) $last['user_id'] );
		}

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'id'               => (int) $row['user_id'],
				'name'             => (string) $row['display_name'],
				'email'            => (string) $row['user_email'],
				'avatar'           => (string) get_avatar_url( (int) $row['user_id'], array( 'size' => 48 ) ),
				'courses'          => (int) $row['courses'],
				'last_enrolled_at' => mysql_to_rfc3339( (string) $row['last_enrolled_at'] ),
			);
		}

		return $this->paginated_response( $items, $this->count( $course_ids, $search ), $next, $request );
	}

	/**
	 * Needs publiclms_view_reports and an enrolment of the learner within scope.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! current_user_can( Capabilities::VIEW_REPORTS ) ) {
			return $this->forbidden();
		}
		return empty( $this->rows_in_scope( (int) $request['id'] ) ) ? $this->not_found() : true;
	}

	/**
	 * Returns a learner's enrolments and progress within scope.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$user_id = (int) $request['id'];
		$user    = get_userdata( $user_id );
		$courses = array();
		foreach ( $this->rows_in_scope( $user_id ) as $row ) {
			$course_id = (int) $row['course_id'];
			$summary   = $this->progress->summary( $user_id, $course_id );
			$courses[] = array(
				'course_id'    => $course_id,
				'title'        => wp_strip_all_tags( get_the_title( $course_id ) ),
				'status'       => (string) $row['status'],
				'enrolled_at'  => mysql_to_rfc3339( (string) $row['enrolled_at'] ),
				'completed_at' => empty( $row['completed_at'] ) ? null : mysql_to_rfc3339( (string) $row['completed_at'] ),
				'percent'      => $summary['percent'],
			);
		}
		return rest_ensure_response(
			array(
				'id'      => $user_id,
				'name'    => false === $user ? '' : (string) $user->display_name,
				'email'   => false === $user ? '' : (string) $user->user_email,
				'avatar'  => (string) get_avatar_url( $user_id, array( 'size' => 96 ) ),
				'courses' => $courses,
			)
		);
	}

	/**
	 * Returns the course IDs the current user may report on, or null for every course.
	 *
	 * @return array<int, int>|null
	 */
	private function scope(): ?array {
		if ( current_user_can( Capabilities::MANAGE_COURSES ) ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'        => PostTypes::COURSE,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'author'           => get_current_user_id(),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		/**
		 * Filters the courses an instructor's student list covers. Pro adds co-taught courses.
		 *
		 * @param array<int, int> $ids     Course IDs.
		 * @param int             $user_id Current user ID.
		 */
		return array_map( 'intval', (array) apply_filters( 'publiclms_instructor_course_ids', array_map( 'intval', $ids ), get_current_user_id() ) );
	}

	/**
	 * Returns a learner's enrolments in the current user's scope.
	 *
	 * @param int $user_id Learner ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_in_scope( int $user_id ): array {
		$scope = $this->scope();
		return array_values(
			array_filter(
				$this->enrollments->find_by_user( $user_id ),
				static function ( $row ) use ( $scope ) {
					return null === $scope || in_array( (int) $row['course_id'], $scope, true );
				}
			)
		);
	}

	/**
	 * Counts the learners a list request matches.
	 *
	 * @param array<int, int>|null $course_ids Scope.
	 * @param string               $search     Search string.
	 * @return int
	 */
	private function count( ?array $course_ids, string $search ): int {
		return $this->enrollments->count_students( $course_ids, $search );
	}

	/**
	 * Returns the collection arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params() {
		return array_merge(
			array(
				'search'  => array(
					'description'       => __( 'Match name, email or username.', 'public-lms' ),
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'course'  => array(
					'description'       => __( 'Only learners of this course.', 'public-lms' ),
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'sanitize_callback' => 'absint',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'orderby' => array(
					'description'       => __( 'Sort by.', 'public-lms' ),
					'type'              => 'string',
					'enum'              => EnrollmentRepository::STUDENT_ORDERBY,
					'default'           => 'name',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'order'   => array(
					'description'       => __( 'Sort direction.', 'public-lms' ),
					'type'              => 'string',
					'enum'              => array( 'asc', 'desc' ),
					'default'           => 'asc',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
			$this->cursor_collection_params()
		);
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
			'title'      => 'publiclms-student',
			'type'       => 'object',
			'properties' => array(
				'id'               => array( 'type' => 'integer' ),
				'name'             => array( 'type' => 'string' ),
				'email'            => array(
					'type'   => 'string',
					'format' => 'email',
				),
				'avatar'           => array( 'type' => 'string' ),
				'courses'          => array(
					'description' => __( 'Number of enrolments in scope on lists; enrolment details on single reads.', 'public-lms' ),
					'type'        => array( 'integer', 'array' ),
				),
				'last_enrolled_at' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}
}
