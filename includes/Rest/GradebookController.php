<?php
/**
 * Gradebook routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Services\Gradebook;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes:
 *
 * - GET /courses/{id}/gradebook             Learners by quizzes and assignments, keyset pages of
 *                                           enrolments (`after`, `per_page`).
 * - GET /courses/{id}/gradebook?format=csv  The whole gradebook as a CSV download.
 *
 * Both need publiclms_view_course_reports on the course.
 */
final class GradebookController extends AbstractController {

	/**
	 * Gradebook.
	 *
	 * @var Gradebook
	 */
	private $gradebook;

	/**
	 * Constructor.
	 *
	 * @param Gradebook $gradebook Gradebook.
	 */
	public function __construct( Gradebook $gradebook ) {
		parent::__construct();
		$this->rest_base = 'courses';
		$this->gradebook = $gradebook;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/gradebook',
			array(
				'args' => array(
					'id' => $this->id_arg( __( 'Course ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array_merge(
						$this->keyset_collection_params(),
						array(
							'format' => array(
								'description'       => __( 'json, or csv for a download of the whole gradebook.', 'public-lms' ),
								'type'              => 'string',
								'enum'              => array( 'json', 'csv' ),
								'default'           => 'json',
								'sanitize_callback' => 'sanitize_key',
								'validate_callback' => 'rest_validate_request_arg',
							),
						)
					),
				),
			)
		);
	}

	/**
	 * Needs the course's reports.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$course_id = (int) $request['id'];
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return $this->not_found();
		}
		return current_user_can( Capabilities::META_VIEW_COURSE_REPORTS, $course_id ) ? true : $this->forbidden();
	}

	/**
	 * Returns a page of the gradebook, or the CSV.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$course_id = (int) $request['id'];
		if ( 'csv' === $request['format'] ) {
			$name = sanitize_file_name( sanitize_title( get_the_title( $course_id ) ) . '-gradebook-' . gmdate( 'Y-m-d' ) . '.csv' );
			return FileResponse::from_string( $this->gradebook->csv( $course_id ), $name, 'text/csv; charset=utf-8' );
		}
		$page     = $this->gradebook->page( $course_id, (int) $request['after'], (int) $request['per_page'] );
		$response = rest_ensure_response( $page );
		$response->header( 'X-WP-Total', (string) $page['total'] );
		if ( $page['next'] > 0 ) {
			$response->header( 'X-PublicLMS-Next-Cursor', (string) $page['next'] );
		}
		return $response;
	}
}
