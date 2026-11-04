<?php
/**
 * Shared routes for curriculum items.
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
 * Routes every curriculum item type shares (lessons, quizzes, assignments):
 *
 * - GET    /{base}/{id}   Item details. AccessPolicy::can_view_course on its course; the content is
 *                          included only when AccessPolicy::can_access_item allows it.
 * - POST   /{base}        Add an item at the end of a section. publiclms_edit_course.
 * - PATCH  /{base}/{id}   Rename, move, publish, set preview, duration, drip and prerequisites, plus the
 *                         type's own settings. publiclms_edit_course on the item's course, and on the
 *                         target course when moving.
 * - DELETE /{base}/{id}   Move to the trash. publiclms_edit_course.
 *
 * Item content is edited in the block editor, not here (ADR-007). Subclasses
 * name the item type and add their own arguments and fields.
 */
abstract class AbstractItemController extends AbstractController {

	/**
	 * Statuses an editor may set.
	 */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Course structure.
	 *
	 * @var CourseStructureRepositoryInterface
	 */
	protected $structure;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	protected $policy;

	/**
	 * Progress.
	 *
	 * @var ProgressService
	 */
	protected $progress;

	/**
	 * Constructor.
	 *
	 * @param CourseStructureRepositoryInterface $structure Course structure.
	 * @param AccessPolicy                       $policy    Access policy.
	 * @param ProgressService                    $progress  Progress.
	 */
	public function __construct( CourseStructureRepositoryInterface $structure, AccessPolicy $policy, ProgressService $progress ) {
		parent::__construct();
		$this->structure = $structure;
		$this->policy    = $policy;
		$this->progress  = $progress;
	}

	/**
	 * Returns the item type: 'lesson', 'quiz' or 'assignment'.
	 *
	 * @return string
	 */
	abstract protected function item_type(): string;

	/**
	 * Returns the post type key.
	 *
	 * @return string
	 */
	abstract protected function post_type(): string;

	/**
	 * Returns the arguments the type adds to PATCH (and POST).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function settings_args(): array {
		return array();
	}

	/**
	 * Writes the type's own settings from a request.
	 *
	 * @param int                                   $item_id Item ID.
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	protected function apply_settings( int $item_id, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Extension point for subclasses.
		return true;
	}

	/**
	 * Returns the type's own response fields.
	 *
	 * @param WP_Post $post     Item.
	 * @param bool    $can_edit Whether the current user can edit the item's course.
	 * @return array<string, mixed>
	 */
	protected function settings_data( WP_Post $post, bool $can_edit ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Extension point for subclasses.
		return array();
	}

	/**
	 * Returns the type's own schema properties.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function settings_schema(): array {
		return array();
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$title  = array(
			'description'       => __( 'Title.', 'public-lms' ),
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
					'args'                => array_merge(
						array(
							'section_id' => $this->id_arg( __( 'Section the item belongs to.', 'public-lms' ) ),
							'title'      => array_merge( $title, array( 'required' => true ) ),
							'status'     => array_merge( $status, array( 'default' => 'publish' ) ),
						),
						$this->settings_args()
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
					'id' => $this->id_arg( __( 'Item ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array_merge(
						array(
							'title'         => $title,
							'status'        => $status,
							'section_id'    => $this->id_arg( __( 'Move to this section.', 'public-lms' ), false ),
							'position'      => array(
								'description'       => __( 'Zero-based position in the section.', 'public-lms' ),
								'type'              => 'integer',
								'minimum'           => 0,
								'sanitize_callback' => 'absint',
								'validate_callback' => 'rest_validate_request_arg',
							),
							'preview'       => array(
								'description'       => __( 'Open to anyone as a free preview.', 'public-lms' ),
								'type'              => 'boolean',
								'sanitize_callback' => 'rest_sanitize_boolean',
								'validate_callback' => 'rest_validate_request_arg',
							),
							'duration'      => array(
								'description'       => __( 'Duration in minutes.', 'public-lms' ),
								'type'              => 'integer',
								'minimum'           => 0,
								'maximum'           => 100000,
								'sanitize_callback' => 'absint',
								'validate_callback' => 'rest_validate_request_arg',
							),
							'prerequisites' => AccessRules::prerequisites_arg( __( 'Lessons, quizzes or assignments of the same course to complete first.', 'public-lms' ) ),
						),
						AccessRules::drip_args(),
						$this->settings_args()
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
	 * Anyone who can view the item's course may read the item's details.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$item_id = (int) $request['id'];
		if ( $this->post_type() !== get_post_type( $item_id ) ) {
			return $this->not_found();
		}
		$can_edit = current_user_can( 'edit_post', $item_id );
		if ( 'edit' === $request['context'] && ! $can_edit ) {
			return $this->forbidden();
		}
		$course_id = $this->structure->course_id_for( $item_id );
		if ( $course_id > 0 && $this->policy->can_view_course( $course_id ) ) {
			return true;
		}
		if ( $can_edit ) {
			return true;
		}
		return is_user_logged_in() ? $this->forbidden() : $this->not_found();
	}

	/**
	 * Returns an item.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}
		return rest_ensure_response( $this->item_data( $post, true ) );
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
	 * Adds an item; its author is the course's instructor.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$section_id = (int) $request['section_id'];
		$course_id  = $this->structure->course_id_for( $section_id );
		$id         = $this->structure->add_item( $section_id, $this->item_type(), (string) $request['title'], (int) get_post_field( 'post_author', $course_id ), (string) $request['status'] );
		$post       = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'publiclms_rest_cannot_create', __( 'The item could not be created.', 'public-lms' ), array( 'status' => 500 ) );
		}
		$result = $this->apply_settings( $id, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->structure->invalidate( $course_id );
		$response = rest_ensure_response( $this->item_data( $post, false ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Needs publiclms_edit_course on the item's course and on the target course of a move.
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
	 * Updates an item.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$item_id = (int) $request['id'];
		$postarr = array( 'ID' => $item_id );
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
			update_post_meta( $item_id, Meta::PREVIEW, (bool) $request['preview'] );
		}
		if ( isset( $request['duration'] ) ) {
			update_post_meta( $item_id, Meta::DURATION, absint( $request['duration'] ) );
		}
		if ( isset( $request['section_id'] ) || isset( $request['position'] ) ) {
			$section_id = isset( $request['section_id'] ) ? (int) $request['section_id'] : $this->structure->section_id_for( $item_id );
			$position   = isset( $request['position'] ) ? (int) $request['position'] : PHP_INT_MAX;
			if ( $section_id > 0 ) {
				$this->structure->move_item( $item_id, $section_id, $position );
			}
		}
		if ( isset( $request['prerequisites'] ) ) {
			$required = Meta::sanitize_id_list( $request['prerequisites'] );
			$valid    = AccessRules::validate_item_prerequisites( $item_id, $required, $this->structure );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			update_post_meta( $item_id, Meta::PREREQUISITES, $required );
		}
		AccessRules::apply_drip( $item_id, $request );
		$result = $this->apply_settings( $item_id, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Meta changes do not touch the post row, so clear the cached structure explicitly.
		$this->structure->invalidate( $this->structure->course_id_for( $item_id ) );

		$post = get_post( $item_id );
		return rest_ensure_response( $post instanceof WP_Post ? $this->item_data( $post, false ) : array() );
	}

	/**
	 * Needs publiclms_edit_course on the item's course (edit_post for an item outside any course).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		$item_id = (int) $request['id'];
		if ( $this->post_type() !== get_post_type( $item_id ) || 'trash' === get_post_status( $item_id ) ) {
			return $this->not_found();
		}
		$course_id = $this->structure->course_id_for( $item_id );
		$allowed   = $course_id > 0
			? current_user_can( Capabilities::META_EDIT_COURSE, $course_id )
			: current_user_can( 'edit_post', $item_id );
		return $allowed ? true : $this->forbidden();
	}

	/**
	 * Trashes an item.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}
		$previous = $this->item_data( $post, false );
		if ( ! $this->structure->delete_item( (int) $post->ID ) ) {
			return new WP_Error( 'publiclms_rest_cannot_delete', __( 'The item could not be deleted.', 'public-lms' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			)
		);
	}

	/**
	 * Builds an item response.
	 *
	 * @param WP_Post $post            Item.
	 * @param bool    $include_content Whether to include the rendered content when access allows it.
	 * @return array<string, mixed>
	 */
	protected function item_data( WP_Post $post, bool $include_content ): array {
		$item_id   = (int) $post->ID;
		$course_id = $this->structure->course_id_for( $item_id );
		$can_edit  = $course_id > 0 ? current_user_can( Capabilities::META_EDIT_COURSE, $course_id ) : current_user_can( 'edit_post', $item_id );
		$decision  = $this->policy->can_access_item( $item_id );
		$user_id   = get_current_user_id();
		$adjacent  = $this->structure->adjacent_items( $item_id );

		$data = array_merge(
			array(
				'id'            => $item_id,
				'type'          => $this->item_type(),
				'title'         => $can_edit ? (string) $post->post_title : wp_strip_all_tags( get_the_title( $post ) ),
				'status'        => (string) $post->post_status,
				'course_id'     => $course_id,
				'section_id'    => $this->structure->section_id_for( $item_id ),
				'menu_order'    => (int) $post->menu_order,
				'link'          => (string) get_permalink( $post ),
				'edit_url'      => $can_edit ? (string) get_edit_post_link( $item_id, 'raw' ) : '',
				'preview'       => (bool) get_post_meta( $item_id, Meta::PREVIEW, true ),
				'duration'      => (int) get_post_meta( $item_id, Meta::DURATION, true ),
				'prerequisites' => Meta::id_list( $item_id, Meta::PREREQUISITES ),
				'completed'     => $user_id > 0 && $course_id > 0 && in_array( $item_id, $this->progress->summary( $user_id, $course_id )['completed_items'], true ),
				'access'        => $decision->to_array(),
				'previous'      => $this->progress->item_link( $adjacent['previous'] ),
				'next'          => $this->progress->item_link( $adjacent['next'] ),
			),
			AccessRules::drip_data( $item_id ),
			$this->settings_data( $post, $can_edit )
		);

		if ( $include_content ) {
			$data['content'] = '';
			if ( $decision->is_allowed() ) {
				$previous_post   = $GLOBALS['post'] ?? null;
				$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Content filters read the global post; restored below.
				setup_postdata( $post );
				// Sanitise the stored HTML before blocks render, as publiclms/lesson-content does (ADR-035).
				$data['content'] = (string) apply_filters( 'the_content', wp_kses_post( $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
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
			'title'      => 'publiclms-' . $this->item_type(),
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'id'            => array(
						'type'     => 'integer',
						'readonly' => true,
					),
					'type'          => array(
						'type'     => 'string',
						'readonly' => true,
					),
					'title'         => array( 'type' => 'string' ),
					'status'        => array(
						'type' => 'string',
						'enum' => self::STATUSES,
					),
					'course_id'     => array(
						'type'     => 'integer',
						'readonly' => true,
					),
					'section_id'    => array( 'type' => 'integer' ),
					'menu_order'    => array(
						'type'     => 'integer',
						'readonly' => true,
					),
					'link'          => array(
						'type'     => 'string',
						'readonly' => true,
					),
					'edit_url'      => array(
						'type'     => 'string',
						'readonly' => true,
					),
					'preview'       => array( 'type' => 'boolean' ),
					'duration'      => array( 'type' => 'integer' ),
					'prerequisites' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'completed'     => array(
						'type'     => 'boolean',
						'readonly' => true,
					),
					'access'        => array(
						'type'     => 'object',
						'readonly' => true,
					),
					'previous'      => $link,
					'next'          => $link,
					'content'       => array(
						'description' => __( 'Rendered content, present on single reads and empty when the item is locked.', 'public-lms' ),
						'type'        => 'string',
						'readonly'    => true,
					),
				),
				AccessRules::drip_schema(),
				$this->settings_schema()
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}
}
