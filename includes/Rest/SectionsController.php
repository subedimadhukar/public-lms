<?php
/**
 * Section routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes, each needing publiclms_edit_course on the section's course:
 *
 * - POST   /sections         Add a section at the end of a course.
 * - PATCH  /sections/{id}    Rename, or move to a position among the course's sections.
 * - DELETE /sections/{id}    Move the section and its items to the trash.
 */
final class SectionsController extends AbstractController {

	/**
	 * Course structure.
	 *
	 * @var CourseStructureRepositoryInterface
	 */
	private $structure;

	/**
	 * Constructor.
	 *
	 * @param CourseStructureRepositoryInterface $structure Course structure.
	 */
	public function __construct( CourseStructureRepositoryInterface $structure ) {
		parent::__construct();
		$this->rest_base = 'sections';
		$this->structure = $structure;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$title = array(
			'description'       => __( 'Section title.', 'public-lms' ),
			'type'              => 'string',
			'minLength'         => 1,
			'maxLength'         => 200,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'course_id' => $this->id_arg( __( 'Course the section belongs to.', 'public-lms' ) ),
						'title'     => array_merge( $title, array( 'required' => true ) ),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => $this->id_arg( __( 'Section ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array_merge(
						array(
							'title'    => $title,
							'position' => array(
								'description'       => __( 'Zero-based position among the course\'s sections.', 'public-lms' ),
								'type'              => 'integer',
								'minimum'           => 0,
								'sanitize_callback' => 'absint',
								'validate_callback' => 'rest_validate_request_arg',
							),
						),
						AccessRules::drip_args()
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Needs publiclms_edit_course on the target course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$course_id = (int) $request['course_id'];
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return $this->not_found();
		}
		return current_user_can( Capabilities::META_EDIT_COURSE, $course_id ) ? true : $this->forbidden();
	}

	/**
	 * Adds a section.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$course_id = (int) $request['course_id'];
		$id        = $this->structure->add_section( $course_id, (string) $request['title'], (int) get_post_field( 'post_author', $course_id ) );
		if ( $id <= 0 ) {
			return new WP_Error( 'publiclms_rest_cannot_create', __( 'The section could not be created.', 'public-lms' ), array( 'status' => 500 ) );
		}
		$response = rest_ensure_response( $this->section_data( $id ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Needs publiclms_edit_course on the section's course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$section_id = (int) $request['id'];
		if ( PostTypes::SECTION !== get_post_type( $section_id ) || 'trash' === get_post_status( $section_id ) ) {
			return $this->not_found();
		}
		$course_id = $this->structure->course_id_for( $section_id );
		$allowed   = $course_id > 0
			? current_user_can( Capabilities::META_EDIT_COURSE, $course_id )
			: current_user_can( 'edit_post', $section_id );
		return $allowed ? true : $this->forbidden();
	}

	/**
	 * Renames or moves a section.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$section_id = (int) $request['id'];
		if ( isset( $request['title'] ) && ! $this->structure->rename( $section_id, (string) $request['title'] ) ) {
			return new WP_Error( 'publiclms_rest_cannot_update', __( 'The section could not be renamed.', 'public-lms' ), array( 'status' => 500 ) );
		}
		if ( isset( $request['drip_days'] ) || isset( $request['drip_date'] ) ) {
			AccessRules::apply_drip( $section_id, $request );
			$this->structure->invalidate( $this->structure->course_id_for( $section_id ) );
		}
		if ( isset( $request['position'] ) ) {
			$course_id = $this->structure->course_id_for( $section_id );
			$tree      = array();
			$own       = array();
			foreach ( $this->structure->get_structure( $course_id )['sections'] as $section ) {
				$items = array_map( 'intval', wp_list_pluck( (array) $section['items'], 'id' ) );
				if ( (int) $section['id'] === $section_id ) {
					$own = $items;
					continue;
				}
				$tree[] = array(
					'id'    => (int) $section['id'],
					'items' => $items,
				);
			}
			$position = min( (int) $request['position'], count( $tree ) );
			array_splice(
				$tree,
				$position,
				0,
				array(
					array(
						'id'    => $section_id,
						'items' => $own,
					),
				)
			);
			$this->structure->save_order( $course_id, $tree );
		}
		return rest_ensure_response( $this->section_data( $section_id ) );
	}

	/**
	 * Trashes a section and its items.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$section_id = (int) $request['id'];
		$previous   = $this->section_data( $section_id );
		if ( ! $this->structure->delete_section( $section_id ) ) {
			return new WP_Error( 'publiclms_rest_cannot_delete', __( 'The section could not be deleted.', 'public-lms' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			)
		);
	}

	/**
	 * Returns a section with its items.
	 *
	 * @param int $section_id Section ID.
	 * @return array<string, mixed>
	 */
	private function section_data( int $section_id ): array {
		$course_id = $this->structure->course_id_for( $section_id );
		foreach ( $this->structure->get_structure( $course_id )['sections'] as $section ) {
			if ( (int) $section['id'] === $section_id ) {
				return array_merge( array( 'course_id' => $course_id ), $section, AccessRules::drip_data( $section_id ) );
			}
		}
		return array_merge(
			array(
				'id'         => $section_id,
				'course_id'  => $course_id,
				'title'      => (string) get_post_field( 'post_title', $section_id ),
				'status'     => (string) get_post_status( $section_id ),
				'menu_order' => (int) get_post_field( 'menu_order', $section_id ),
				'items'      => array(),
			),
			AccessRules::drip_data( $section_id )
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
			'title'      => 'publiclms-section',
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'id'         => array(
						'type'     => 'integer',
						'readonly' => true,
					),
					'course_id'  => array( 'type' => 'integer' ),
					'title'      => array( 'type' => 'string' ),
					'status'     => array(
						'type'     => 'string',
						'readonly' => true,
					),
					'menu_order' => array(
						'type'     => 'integer',
						'readonly' => true,
					),
					'items'      => array(
						'type'     => 'array',
						'readonly' => true,
						'items'    => array( 'type' => 'object' ),
					),
				),
				AccessRules::drip_schema()
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}
}
