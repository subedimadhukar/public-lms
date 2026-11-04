<?php
/**
 * Lesson routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Capabilities\Capabilities;
use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Services\ProgressService;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes:
 *
 * - GET    /lessons/{id}   Lesson details. AccessPolicy::can_view_course on its course; the content is
 *                          included only when AccessPolicy::can_access_item allows it.
 * - POST   /lessons        Add a lesson at the end of a section. publiclms_edit_course.
 * - PATCH  /lessons/{id}   Rename, move, publish, set preview or duration. publiclms_edit_course on the
 *                          lesson's course, and on the target course when moving.
 * - DELETE /lessons/{id}   Move to the trash. publiclms_edit_course.
 *
 * Lesson content is edited in the block editor, not here (ADR-007).
 */
final class LessonsController extends AbstractController {

	/**
	 * Statuses an editor may set.
	 */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Course structure.
	 *
	 * @var CourseStructureRepositoryInterface
	 */
	private $structure;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private $policy;

	/**
	 * Progress.
	 *
	 * @var ProgressService
	 */
	private $progress;

	/**
	 * Constructor.
	 *
	 * @param CourseStructureRepositoryInterface $structure Course structure.
	 * @param AccessPolicy                       $policy    Access policy.
	 * @param ProgressService                    $progress  Progress.
	 */
	public function __construct( CourseStructureRepositoryInterface $structure, AccessPolicy $policy, ProgressService $progress ) {
		parent::__construct();
		$this->rest_base = 'lessons';
		$this->structure = $structure;
		$this->policy    = $policy;
		$this->progress  = $progress;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$title  = array(
			'description'       => __( 'Lesson title.', 'public-lms' ),
			'type'              => 'string',
			'minLength'         => 1,
			'maxLength'         => 200,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$status = array(
			'description'       => __( 'Post status.', 'public-lms' ),
			'type'              => 'string',
			'enum'              => self::STATUSES,
			'sanitize_callback' => 'sanitize_key',
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
						'section_id' => $this->id_arg( __( 'Section the lesson belongs to.', 'public-lms' ) ),
						'title'      => array_merge( $title, array( 'required' => true ) ),
						'status'     => array_merge( $status, array( 'default' => 'publish' ) ),
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
					'id' => $this->id_arg( __( 'Lesson ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'title'      => $title,
						'status'     => $status,
						'section_id' => $this->id_arg( __( 'Move to this section.', 'public-lms' ), false ),
						'position'   => array(
							'description'       => __( 'Zero-based position in the section.', 'public-lms' ),
							'type'              => 'integer',
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'preview'    => array(
							'description'       => __( 'Open to anyone as a free preview.', 'public-lms' ),
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'duration'   => array(
							'description'       => __( 'Duration in minutes.', 'public-lms' ),
							'type'              => 'integer',
							'minimum'           => 0,
							'maximum'           => 100000,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Anyone who can view the lesson's course may read the lesson's details.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$lesson_id = (int) $request['id'];
		if ( PostTypes::LESSON !== get_post_type( $lesson_id ) ) {
			return $this->not_found();
		}
		$course_id = $this->structure->course_id_for( $lesson_id );
		if ( $course_id > 0 && $this->policy->can_view_course( $course_id ) ) {
			return true;
		}
		if ( current_user_can( 'edit_post', $lesson_id ) ) {
			return true;
		}
		return is_user_logged_in() ? $this->forbidden() : $this->not_found();
	}

	/**
	 * Returns a lesson.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}
		return rest_ensure_response( $this->lesson_data( $post, true ) );
	}

	/**
	 * Needs publiclms_edit_course on the section's course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$section_id = (int) $request['section_id'];
		if ( PostTypes::SECTION !== get_post_type( $section_id ) ) {
			return $this->not_found();
		}
		$course_id = $this->structure->course_id_for( $section_id );
		return $course_id > 0 && current_user_can( Capabilities::META_EDIT_COURSE, $course_id ) ? true : $this->forbidden();
	}

	/**
	 * Adds a lesson; its author is the course's instructor.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$section_id = (int) $request['section_id'];
		$course_id  = $this->structure->course_id_for( $section_id );
		$id         = $this->structure->add_item( $section_id, 'lesson', (string) $request['title'], (int) get_post_field( 'post_author', $course_id ), (string) $request['status'] );
		$post       = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'publiclms_rest_cannot_create', __( 'The lesson could not be created.', 'public-lms' ), array( 'status' => 500 ) );
		}
		$response = rest_ensure_response( $this->lesson_data( $post, false ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Needs publiclms_edit_course on the lesson's course and on the target course of a move.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$check = $this->delete_item_permissions_check( $request );
		if ( true !== $check ) {
			return $check;
		}
		if ( isset( $request['section_id'] ) ) {
			$section_id = (int) $request['section_id'];
			if ( PostTypes::SECTION !== get_post_type( $section_id ) ) {
				return new WP_Error( 'rest_invalid_param', __( 'The target section does not exist.', 'public-lms' ), array( 'status' => 400 ) );
			}
			$target = $this->structure->course_id_for( $section_id );
			if ( $target <= 0 || ! current_user_can( Capabilities::META_EDIT_COURSE, $target ) ) {
				return $this->forbidden();
			}
		}
		return true;
	}

	/**
	 * Updates a lesson.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$lesson_id = (int) $request['id'];
		$postarr   = array( 'ID' => $lesson_id );
		if ( isset( $request['title'] ) ) {
			$postarr['post_title'] = (string) $request['title'];
		}
		if ( isset( $request['status'] ) ) {
			$postarr['post_status'] = (string) $request['status'];
		}
		if ( count( $postarr ) > 1 ) {
			$result = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 500 ) );
				return $result;
			}
		}
		if ( isset( $request['preview'] ) ) {
			update_post_meta( $lesson_id, Meta::PREVIEW, (bool) $request['preview'] );
		}
		if ( isset( $request['duration'] ) ) {
			update_post_meta( $lesson_id, Meta::DURATION, absint( $request['duration'] ) );
		}
		if ( isset( $request['section_id'] ) || isset( $request['position'] ) ) {
			$section_id = isset( $request['section_id'] ) ? (int) $request['section_id'] : $this->structure->section_id_for( $lesson_id );
			$position   = isset( $request['position'] ) ? (int) $request['position'] : PHP_INT_MAX;
			if ( $section_id > 0 ) {
				$this->structure->move_item( $lesson_id, $section_id, $position );
			}
		}
		// Meta changes do not touch the post row, so clear the cached structure explicitly.
		$this->structure->invalidate( $this->structure->course_id_for( $lesson_id ) );

		$post = get_post( $lesson_id );
		return rest_ensure_response( $post instanceof WP_Post ? $this->lesson_data( $post, false ) : array() );
	}

	/**
	 * Needs publiclms_edit_course on the lesson's course (edit_post for a lesson outside any course).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		$lesson_id = (int) $request['id'];
		if ( PostTypes::LESSON !== get_post_type( $lesson_id ) || 'trash' === get_post_status( $lesson_id ) ) {
			return $this->not_found();
		}
		$course_id = $this->structure->course_id_for( $lesson_id );
		$allowed   = $course_id > 0
			? current_user_can( Capabilities::META_EDIT_COURSE, $course_id )
			: current_user_can( 'edit_post', $lesson_id );
		return $allowed ? true : $this->forbidden();
	}

	/**
	 * Trashes a lesson.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}
		$previous = $this->lesson_data( $post, false );
		if ( ! $this->structure->delete_item( (int) $post->ID ) ) {
			return new WP_Error( 'publiclms_rest_cannot_delete', __( 'The lesson could not be deleted.', 'public-lms' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			)
		);
	}

	/**
	 * Builds a lesson response.
	 *
	 * @param WP_Post $post            Lesson.
	 * @param bool    $include_content Whether to include the rendered content when access allows it.
	 * @return array<string, mixed>
	 */
	private function lesson_data( WP_Post $post, bool $include_content ): array {
		$lesson_id = (int) $post->ID;
		$course_id = $this->structure->course_id_for( $lesson_id );
		$can_edit  = $course_id > 0 ? current_user_can( Capabilities::META_EDIT_COURSE, $course_id ) : current_user_can( 'edit_post', $lesson_id );
		$decision  = $this->policy->can_access_item( $lesson_id );
		$user_id   = get_current_user_id();
		$adjacent  = $this->structure->adjacent_items( $lesson_id );

		$data = array(
			'id'         => $lesson_id,
			'title'      => $can_edit ? (string) $post->post_title : wp_strip_all_tags( get_the_title( $post ) ),
			'status'     => (string) $post->post_status,
			'course_id'  => $course_id,
			'section_id' => $this->structure->section_id_for( $lesson_id ),
			'menu_order' => (int) $post->menu_order,
			'link'       => (string) get_permalink( $post ),
			'edit_url'   => $can_edit ? (string) get_edit_post_link( $lesson_id, 'raw' ) : '',
			'preview'    => (bool) get_post_meta( $lesson_id, Meta::PREVIEW, true ),
			'duration'   => (int) get_post_meta( $lesson_id, Meta::DURATION, true ),
			'completed'  => $user_id > 0 && $course_id > 0 && in_array( $lesson_id, $this->progress->summary( $user_id, $course_id )['completed_items'], true ),
			'access'     => $decision->to_array(),
			'previous'   => $this->progress->item_link( $adjacent['previous'] ),
			'next'       => $this->progress->item_link( $adjacent['next'] ),
		);

		if ( $include_content ) {
			$data['content'] = '';
			if ( $decision->is_allowed() ) {
				$previous_post   = $GLOBALS['post'] ?? null;
				$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Content filters read the global post; restored below.
				setup_postdata( $post );
				$data['content'] = wp_kses_post( (string) apply_filters( 'the_content', $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
				$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring.
				wp_reset_postdata();
			}
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
		$link         = array(
			'type'       => array( 'object', 'null' ),
			'readonly'   => true,
			'properties' => array(
				'id'    => array( 'type' => 'integer' ),
				'title' => array( 'type' => 'string' ),
				'url'   => array( 'type' => 'string' ),
			),
		);
		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'publiclms-lesson',
			'type'       => 'object',
			'properties' => array(
				'id'         => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'title'      => array( 'type' => 'string' ),
				'status'     => array(
					'type' => 'string',
					'enum' => self::STATUSES,
				),
				'course_id'  => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'section_id' => array( 'type' => 'integer' ),
				'menu_order' => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'link'       => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'edit_url'   => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'preview'    => array( 'type' => 'boolean' ),
				'duration'   => array( 'type' => 'integer' ),
				'completed'  => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'access'     => array(
					'type'     => 'object',
					'readonly' => true,
				),
				'previous'   => $link,
				'next'       => $link,
				'content'    => array(
					'description' => __( 'Rendered content, present on single reads and empty when the lesson is locked.', 'public-lms' ),
					'type'        => 'string',
					'readonly'    => true,
				),
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}
}
