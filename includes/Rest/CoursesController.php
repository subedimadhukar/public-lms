<?php
/**
 * Courses and curriculum routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Capabilities\Capabilities;
use PublicLMS\Courses\CourseFields;
use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Services\ProgressService;
use PublicLMS\Taxonomies\Taxonomies;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes:
 *
 * - GET    /courses                    Published courses for anyone; with context=edit, the courses the
 *                                      user can edit (managers: all; instructors: their own).
 * - POST   /courses                    Create a course. Needs the course post type's create_posts.
 * - GET    /courses/{id}               One course with its curriculum. AccessPolicy::can_view_course.
 * - PATCH  /courses/{id}               Update settings. publiclms_edit_course.
 * - DELETE /courses/{id}               Move to the trash. delete_post.
 * - GET    /courses/{id}/curriculum    Sections and items. AccessPolicy::can_view_course.
 * - PUT    /courses/{id}/curriculum    Reorder and reparent. publiclms_edit_course on every course touched.
 */
final class CoursesController extends AbstractController {

	/**
	 * Sort columns for the list, keyed by the `orderby` argument.
	 */
	const ORDERBY = array(
		'date'     => 'post_date',
		'modified' => 'post_modified',
		'title'    => 'post_title',
		'id'       => 'ID',
	);

	/**
	 * Statuses an editor may list or set.
	 */
	const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

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
	 * Course fields.
	 *
	 * @var CourseFields
	 */
	private $fields;

	/**
	 * Constructor.
	 *
	 * @param CourseStructureRepositoryInterface $structure   Course structure.
	 * @param EnrollmentRepository               $enrollments Enrolments.
	 * @param AccessPolicy                       $policy      Access policy.
	 * @param ProgressService                    $progress    Progress.
	 * @param CourseFields                       $fields      Course fields.
	 */
	public function __construct( CourseStructureRepositoryInterface $structure, EnrollmentRepository $enrollments, AccessPolicy $policy, ProgressService $progress, CourseFields $fields ) {
		parent::__construct();
		$this->rest_base   = 'courses';
		$this->structure   = $structure;
		$this->enrollments = $enrollments;
		$this->policy      = $policy;
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
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->write_args( true ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => $this->id_arg( __( 'Course ID.', 'public-lms' ) ),
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
					'args'                => $this->write_args( false ),
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/curriculum',
			array(
				'args'   => array(
					'id' => $this->id_arg( __( 'Course ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_curriculum' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_curriculum' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'sections' => array(
							'description'       => __( 'Every section of the course in order, each with every item in order.', 'public-lms' ),
							'type'              => 'array',
							'required'          => true,
							'items'             => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'id'    => array(
										'type'     => 'integer',
										'minimum'  => 1,
										'required' => true,
									),
									'items' => array(
										'type'     => 'array',
										'items'    => array(
											'type'    => 'integer',
											'minimum' => 1,
										),
										'required' => true,
									),
								),
							),
							'sanitize_callback' => 'rest_sanitize_request_arg',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				'schema' => array( $this, 'get_curriculum_schema' ),
			)
		);
	}

	/**
	 * Anyone may list published courses; edit context needs the authoring capability.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( 'edit' === $request['context'] || array( 'publish' ) !== $this->requested_statuses( $request ) ) {
			return current_user_can( Capabilities::EDIT_OWN_COURSE ) ? true : $this->forbidden();
		}
		return true;
	}

	/**
	 * Lists courses with cursor pagination.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$per_page = (int) $request['per_page'];
		$orderby  = (string) $request['orderby'];
		$order    = 'asc' === strtolower( (string) $request['order'] ) ? 'ASC' : 'DESC';
		$column   = self::ORDERBY[ $orderby ] ?? 'post_date';
		$editing  = 'edit' === $request['context'] || array( 'publish' ) !== $this->requested_statuses( $request );

		$args = array(
			'post_type'           => PostTypes::COURSE,
			'post_status'         => $this->requested_statuses( $request ),
			'posts_per_page'      => $per_page + 1,
			'orderby'             => array(
				'id' === $orderby ? 'ID' : $orderby => $order,
				'ID'                                => $order,
			),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'suppress_filters'    => false,
		);
		if ( '' !== (string) $request['search'] ) {
			$args['s'] = (string) $request['search'];
		}
		$author = (int) $request['author'];
		if ( $editing && ! current_user_can( Capabilities::MANAGE_COURSES ) ) {
			$author = get_current_user_id();
		}
		if ( $author > 0 ) {
			$args['author'] = $author;
		}
		$tax_query = array();
		foreach ( array(
			'category' => Taxonomies::COURSE_CATEGORY,
			'level'    => Taxonomies::LEVEL,
		) as $param => $taxonomy ) {
			$terms = array_filter( array_map( 'absint', (array) $request[ $param ] ) );
			if ( ! empty( $terms ) ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $terms,
				);
			}
		}
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Filtered admin and catalogue lists.
		}

		$total = $this->count( $args );

		$cursor = $this->decode_cursor( (string) $request['cursor'] );
		$where  = null;
		if ( null !== $cursor ) {
			$where = $this->cursor_where( $column, 'ASC' === $order ? '>' : '<', $cursor );
			add_filter( 'posts_where', $where, 10, 1 );
		}
		$query = new WP_Query( $args );
		if ( null !== $where ) {
			remove_filter( 'posts_where', $where, 10 );
		}

		$posts = $query->posts;
		$next  = '';
		if ( count( $posts ) > $per_page ) {
			$posts = array_slice( $posts, 0, $per_page );
			$last  = end( $posts );
			if ( $last instanceof WP_Post ) {
				$next = $this->encode_cursor( 'ID' === $column ? (int) $last->ID : (string) $last->{$column}, (int) $last->ID );
			}
		}

		$items = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $post, $request ) );
			}
		}
		return $this->paginated_response( $items, $total, $next, $request );
	}

	/**
	 * Anyone who can view the course may read it and its curriculum.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$course_id = (int) $request['id'];
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return $this->not_found();
		}
		if ( ! $this->policy->can_view_course( $course_id ) ) {
			return is_user_logged_in() ? $this->forbidden() : $this->not_found();
		}
		if ( 'edit' === $request['context'] && ! current_user_can( Capabilities::META_EDIT_COURSE, $course_id ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/**
	 * Returns one course with its curriculum.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}
		$response = $this->prepare_item_for_response( $post, $request );
		$data     = (array) $response->get_data();

		$data['curriculum'] = $this->curriculum_data( (int) $post->ID );
		$data['fields']     = $this->fields->all( (int) $post->ID );
		$data['enrollment'] = $this->enrollment_data( (int) $post->ID );

		$response->set_data( $data );
		return $response;
	}

	/**
	 * Needs the create capability of the course post type.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$type = get_post_type_object( PostTypes::COURSE );
		if ( null === $type || ! current_user_can( $type->cap->create_posts ) ) {
			return $this->forbidden();
		}
		return $this->check_status_permission( (string) ( $request['status'] ?? 'draft' ) );
	}

	/**
	 * Creates a course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$id = wp_insert_post(
			array(
				'post_type'    => PostTypes::COURSE,
				'post_title'   => (string) $request['title'],
				'post_excerpt' => (string) ( $request['excerpt'] ?? '' ),
				'post_status'  => (string) ( $request['status'] ?? 'draft' ),
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			$id->add_data( array( 'status' => 500 ) );
			return $id;
		}
		$result = $this->apply_settings( (int) $id, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post     = get_post( (int) $id );
		$response = $this->prepare_item_for_response( $post instanceof WP_Post ? $post : new WP_Post( (object) array() ), $request );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( $this->namespace . '/' . $this->rest_base . '/' . $id ) );
		return $response;
	}

	/**
	 * Needs publiclms_edit_course on the course, and publish rights to publish it.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$course_id = (int) $request['id'];
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return $this->not_found();
		}
		if ( ! current_user_can( Capabilities::META_EDIT_COURSE, $course_id ) ) {
			return $this->forbidden();
		}
		if ( isset( $request['status'] ) ) {
			return $this->check_status_permission( (string) $request['status'] );
		}
		return true;
	}

	/**
	 * Updates a course's settings.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$course_id = (int) $request['id'];
		$postarr   = array( 'ID' => $course_id );
		if ( isset( $request['title'] ) ) {
			$postarr['post_title'] = (string) $request['title'];
		}
		if ( isset( $request['excerpt'] ) ) {
			$postarr['post_excerpt'] = (string) $request['excerpt'];
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
		$result = $this->apply_settings( $course_id, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$post = get_post( $course_id );
		return $this->prepare_item_for_response( $post instanceof WP_Post ? $post : new WP_Post( (object) array() ), $request );
	}

	/**
	 * Needs delete_post on the course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		$course_id = (int) $request['id'];
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return $this->not_found();
		}
		return current_user_can( 'delete_post', $course_id ) ? true : $this->forbidden();
	}

	/**
	 * Moves a course to the trash. Its sections and items stay attached and come back with it.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found();
		}
		$previous = $this->prepare_item_for_response( $post, $request )->get_data();
		$trashed  = wp_trash_post( (int) $post->ID );
		if ( ! $trashed instanceof WP_Post ) {
			return new WP_Error( 'publiclms_rest_cannot_delete', __( 'The course could not be moved to the trash.', 'public-lms' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			)
		);
	}

	/**
	 * Returns the curriculum of a course.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_curriculum( $request ) {
		return rest_ensure_response( $this->curriculum_data( (int) $request['id'] ) );
	}

	/**
	 * Applies a new order to the curriculum.
	 *
	 * Every current section and item must be listed, so a client working from a
	 * stale copy cannot drop lessons by accident; deletions use the section and
	 * lesson routes. A section or item from another course may be moved in when
	 * the user can edit that course too.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_curriculum( $request ) {
		$course_id = (int) $request['id'];
		$tree      = array();
		$seen      = array();

		foreach ( (array) $request['sections'] as $section ) {
			$section_id = (int) $section['id'];
			$check      = $this->check_movable( $section_id, PostTypes::SECTION, $course_id, $seen );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$items = array();
			foreach ( (array) $section['items'] as $item_id ) {
				$item_id = (int) $item_id;
				$check   = $this->check_movable( $item_id, '', $course_id, $seen );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
				$items[] = $item_id;
			}
			$tree[] = array(
				'id'    => $section_id,
				'items' => $items,
			);
		}

		$missing = array();
		foreach ( $this->structure->get_structure( $course_id )['sections'] as $section ) {
			if ( ! isset( $seen[ (int) $section['id'] ] ) ) {
				$missing[] = (int) $section['id'];
			}
			foreach ( (array) $section['items'] as $item ) {
				if ( ! isset( $seen[ (int) $item['id'] ] ) ) {
					$missing[] = (int) $item['id'];
				}
			}
		}
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'publiclms_curriculum_incomplete',
				__( 'The curriculum changed since it was loaded. Reload it and try again.', 'public-lms' ),
				array(
					'status'  => 409,
					'missing' => $missing,
				)
			);
		}

		$this->structure->save_order( $course_id, $tree );
		return rest_ensure_response( $this->curriculum_data( $course_id ) );
	}

	/**
	 * Prepares one course for the response.
	 *
	 * @param WP_Post                               $item    Course.
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$post      = $item;
		$course_id = (int) $post->ID;
		$structure = $this->structure->get_public_structure( $course_id );
		$thumb_id  = (int) get_post_thumbnail_id( $post );
		$can_edit  = current_user_can( Capabilities::META_EDIT_COURSE, $course_id );
		$lessons   = $this->fields->lesson_count( $course_id );

		$data = array(
			'id'             => $course_id,
			'title'          => $can_edit ? (string) $post->post_title : wp_strip_all_tags( get_the_title( $post ) ),
			'slug'           => (string) $post->post_name,
			'status'         => (string) $post->post_status,
			'link'           => (string) get_permalink( $post ),
			'excerpt'        => $can_edit ? (string) $post->post_excerpt : wp_strip_all_tags( get_the_excerpt( $post ) ),
			'date_gmt'       => $this->gmt( (string) $post->post_date_gmt ),
			'modified_gmt'   => $this->gmt( (string) $post->post_modified_gmt ),
			'author'         => array(
				'id'   => (int) $post->post_author,
				'name' => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			),
			'featured_media' => $thumb_id,
			'featured_image' => $thumb_id > 0 ? (string) wp_get_attachment_image_url( $thumb_id, 'medium' ) : '',
			'categories'     => $this->term_ids( $course_id, Taxonomies::COURSE_CATEGORY ),
			'levels'         => $this->term_ids( $course_id, Taxonomies::LEVEL ),
			'access_mode'    => Meta::access_mode( $course_id ),
			'price'          => (string) get_post_meta( $course_id, Meta::PRICE, true ),
			'sale_price'     => (string) get_post_meta( $course_id, Meta::SALE_PRICE, true ),
			'duration'       => (int) get_post_meta( $course_id, Meta::DURATION, true ),
			'duration_label' => $this->fields->format_duration( $this->fields->duration_minutes( $course_id ) ),
			'prerequisites'  => Meta::id_list( $course_id, Meta::PREREQUISITES ),
			'certificate'    => Meta::issues_certificate( $course_id ),
			'sections_count' => count( $structure['sections'] ),
			'lessons_count'  => $lessons,
			'enrolled_count' => $this->enrollments->count_by_course( $course_id, EnrollmentRepository::STATUS_ACTIVE ),
			'edit_url'       => $can_edit ? (string) get_edit_post_link( $course_id, 'raw' ) : '',
			'permissions'    => array(
				'edit'   => $can_edit,
				'delete' => current_user_can( 'delete_post', $course_id ),
			),
		);

		$context = ! empty( $request['context'] ) ? (string) $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );
		return rest_ensure_response( $data );
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
		$view_edit    = array( 'view', 'edit' );
		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'publiclms-course',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'description' => __( 'Course ID.', 'public-lms' ),
					'type'        => 'integer',
					'context'     => $view_edit,
					'readonly'    => true,
				),
				'title'          => array(
					'description' => __( 'Course title.', 'public-lms' ),
					'type'        => 'string',
					'context'     => $view_edit,
				),
				'slug'           => array(
					'description' => __( 'URL slug.', 'public-lms' ),
					'type'        => 'string',
					'context'     => $view_edit,
					'readonly'    => true,
				),
				'status'         => array(
					'description' => __( 'Post status.', 'public-lms' ),
					'type'        => 'string',
					'enum'        => self::STATUSES,
					'context'     => $view_edit,
				),
				'link'           => array(
					'description' => __( 'Course URL.', 'public-lms' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => $view_edit,
					'readonly'    => true,
				),
				'excerpt'        => array(
					'description' => __( 'Short description.', 'public-lms' ),
					'type'        => 'string',
					'context'     => $view_edit,
				),
				'date_gmt'       => array(
					'description' => __( 'Publication date in UTC.', 'public-lms' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'context'     => $view_edit,
					'readonly'    => true,
				),
				'modified_gmt'   => array(
					'description' => __( 'Last modified date in UTC.', 'public-lms' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'context'     => $view_edit,
					'readonly'    => true,
				),
				'author'         => array(
					'description' => __( 'Instructor.', 'public-lms' ),
					'type'        => 'object',
					'context'     => $view_edit,
					'readonly'    => true,
					'properties'  => array(
						'id'   => array( 'type' => 'integer' ),
						'name' => array( 'type' => 'string' ),
					),
				),
				'featured_media' => array(
					'description' => __( 'Featured image attachment ID.', 'public-lms' ),
					'type'        => 'integer',
					'context'     => $view_edit,
				),
				'featured_image' => array(
					'description' => __( 'Featured image URL, medium size.', 'public-lms' ),
					'type'        => 'string',
					'context'     => $view_edit,
					'readonly'    => true,
				),
				'categories'     => array(
					'description' => __( 'Course category term IDs.', 'public-lms' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'context'     => $view_edit,
				),
				'levels'         => array(
					'description' => __( 'Level term IDs.', 'public-lms' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'context'     => $view_edit,
				),
				'access_mode'    => array(
					'description' => __( 'How learners enrol.', 'public-lms' ),
					'type'        => 'string',
					'enum'        => Meta::ACCESS_MODES,
					'context'     => $view_edit,
				),
				'price'          => array(
					'description' => __( 'Regular price for paid courses.', 'public-lms' ),
					'type'        => 'string',
					'context'     => $view_edit,
				),
				'sale_price'     => array(
					'description' => __( 'Sale price for paid courses.', 'public-lms' ),
					'type'        => 'string',
					'context'     => $view_edit,
				),
				'duration'       => array(
					'description' => __( 'Duration in minutes; 0 sums the lessons.', 'public-lms' ),
					'type'        => 'integer',
					'context'     => $view_edit,
				),
				'duration_label' => array(
					'description' => __( 'Human-readable duration.', 'public-lms' ),
					'type'        => 'string',
					'context'     => $view_edit,
					'readonly'    => true,
				),
				'prerequisites'  => array(
					'description' => __( 'Courses a learner must complete before enrolling.', 'public-lms' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'context'     => $view_edit,
				),
				'certificate'    => array(
					'description' => __( 'Whether completing the course issues a certificate.', 'public-lms' ),
					'type'        => 'boolean',
					'context'     => $view_edit,
				),
				'sections_count' => array(
					'description' => __( 'Published sections.', 'public-lms' ),
					'type'        => 'integer',
					'context'     => $view_edit,
					'readonly'    => true,
				),
				'lessons_count'  => array(
					'description' => __( 'Published lessons.', 'public-lms' ),
					'type'        => 'integer',
					'context'     => $view_edit,
					'readonly'    => true,
				),
				'enrolled_count' => array(
					'description' => __( 'Active enrolments.', 'public-lms' ),
					'type'        => 'integer',
					'context'     => $view_edit,
					'readonly'    => true,
				),
				'edit_url'       => array(
					'description' => __( 'Block editor URL, for users who can edit.', 'public-lms' ),
					'type'        => 'string',
					'context'     => $view_edit,
					'readonly'    => true,
				),
				'permissions'    => array(
					'description' => __( 'What the current user may do with the course.', 'public-lms' ),
					'type'        => 'object',
					'context'     => $view_edit,
					'readonly'    => true,
					'properties'  => array(
						'edit'   => array( 'type' => 'boolean' ),
						'delete' => array( 'type' => 'boolean' ),
					),
				),
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Returns the curriculum schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_curriculum_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'publiclms-curriculum',
			'type'       => 'object',
			'properties' => array(
				'course_id' => array( 'type' => 'integer' ),
				'editable'  => array( 'type' => 'boolean' ),
				'sections'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'     => array( 'type' => 'integer' ),
							'title'  => array( 'type' => 'string' ),
							'status' => array( 'type' => 'string' ),
							'items'  => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'id'            => array( 'type' => 'integer' ),
										'type'          => array( 'type' => 'string' ),
										'title'         => array( 'type' => 'string' ),
										'status'        => array( 'type' => 'string' ),
										'preview'       => array( 'type' => 'boolean' ),
										'duration'      => array( 'type' => 'integer' ),
										'link'          => array( 'type' => 'string' ),
										'edit_url'      => array( 'type' => 'string' ),
										'completed'     => array( 'type' => 'boolean' ),
										'locked'        => array( 'type' => 'boolean' ),
										'lock_reason'   => array( 'type' => 'string' ),
										'available_at'  => array( 'type' => array( 'integer', 'null' ) ),
										'prerequisites' => array(
											'type'  => 'array',
											'items' => array( 'type' => 'integer' ),
										),
										'drip_days'     => array( 'type' => 'integer' ),
										'drip_date'     => array( 'type' => 'string' ),
										'drip_date_gmt' => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Returns the collection arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params() {
		return array_merge(
			array(
				'context'  => $this->get_context_param( array( 'default' => 'view' ) ),
				'search'   => array(
					'description'       => __( 'Limit results to courses matching a string.', 'public-lms' ),
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'status'   => array(
					'description'       => __( 'Statuses to list; "any" lists every status but trash. Needs edit rights unless only "publish".', 'public-lms' ),
					'type'              => 'array',
					'items'             => array(
						'type' => 'string',
						'enum' => array_merge( self::STATUSES, array( 'any' ) ),
					),
					'default'           => array( 'publish' ),
					'sanitize_callback' => 'rest_sanitize_request_arg',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'author'   => array(
					'description'       => __( 'Limit to one instructor.', 'public-lms' ),
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'sanitize_callback' => 'absint',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'category' => array(
					'description'       => __( 'Limit to course category term IDs.', 'public-lms' ),
					'type'              => 'array',
					'items'             => array( 'type' => 'integer' ),
					'default'           => array(),
					'sanitize_callback' => 'rest_sanitize_request_arg',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'level'    => array(
					'description'       => __( 'Limit to level term IDs.', 'public-lms' ),
					'type'              => 'array',
					'items'             => array( 'type' => 'integer' ),
					'default'           => array(),
					'sanitize_callback' => 'rest_sanitize_request_arg',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'orderby'  => array(
					'description'       => __( 'Sort by.', 'public-lms' ),
					'type'              => 'string',
					'enum'              => array_keys( self::ORDERBY ),
					'default'           => 'date',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'order'    => array(
					'description'       => __( 'Sort direction.', 'public-lms' ),
					'type'              => 'string',
					'enum'              => array( 'asc', 'desc' ),
					'default'           => 'desc',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
			$this->cursor_collection_params()
		);
	}

	/**
	 * Returns the arguments for create and update.
	 *
	 * @param bool $creating Whether the title is required.
	 * @return array<string, array<string, mixed>>
	 */
	private function write_args( bool $creating ): array {
		return array(
			'title'          => array(
				'description'       => __( 'Course title.', 'public-lms' ),
				'type'              => 'string',
				'minLength'         => 1,
				'maxLength'         => 200,
				'required'          => $creating,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'excerpt'        => array(
				'description'       => __( 'Short description.', 'public-lms' ),
				'type'              => 'string',
				'maxLength'         => 2000,
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'status'         => array(
				'description'       => __( 'Post status.', 'public-lms' ),
				'type'              => 'string',
				'enum'              => self::STATUSES,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'featured_media' => array(
				'description'       => __( 'Featured image attachment ID; 0 removes it.', 'public-lms' ),
				'type'              => 'integer',
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'categories'     => array(
				'description'       => __( 'Course category term IDs.', 'public-lms' ),
				'type'              => 'array',
				'items'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'sanitize_callback' => 'rest_sanitize_request_arg',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'levels'         => array(
				'description'       => __( 'Level term IDs.', 'public-lms' ),
				'type'              => 'array',
				'items'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'sanitize_callback' => 'rest_sanitize_request_arg',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'access_mode'    => array(
				'description'       => __( 'How learners enrol.', 'public-lms' ),
				'type'              => 'string',
				'enum'              => Meta::ACCESS_MODES,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'price'          => array(
				'description'       => __( 'Regular price as a decimal number.', 'public-lms' ),
				'type'              => 'string',
				'pattern'           => '^$|^\d+([.,]\d{1,4})?$',
				'sanitize_callback' => array( Meta::class, 'sanitize_price' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'sale_price'     => array(
				'description'       => __( 'Sale price as a decimal number.', 'public-lms' ),
				'type'              => 'string',
				'pattern'           => '^$|^\d+([.,]\d{1,4})?$',
				'sanitize_callback' => array( Meta::class, 'sanitize_price' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'duration'       => array(
				'description'       => __( 'Duration in minutes; 0 sums the lessons.', 'public-lms' ),
				'type'              => 'integer',
				'minimum'           => 0,
				'maximum'           => 100000,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'prerequisites'  => AccessRules::prerequisites_arg( __( 'Courses a learner must complete before enrolling.', 'public-lms' ) ),
			'certificate'    => array(
				'description'       => __( 'Whether completing the course issues a certificate.', 'public-lms' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Writes the settings that live outside the post row.
	 *
	 * @param int                                   $course_id Course ID.
	 * @param WP_REST_Request<array<string, mixed>> $request   Request.
	 * @return true|WP_Error
	 */
	private function apply_settings( int $course_id, $request ) {
		if ( isset( $request['featured_media'] ) ) {
			$media_id = (int) $request['featured_media'];
			if ( 0 === $media_id ) {
				delete_post_thumbnail( $course_id );
			} elseif ( ! wp_attachment_is_image( $media_id ) ) {
				return new WP_Error( 'rest_invalid_param', __( 'The featured image must be an image in the media library.', 'public-lms' ), array( 'status' => 400 ) );
			} else {
				set_post_thumbnail( $course_id, $media_id );
			}
		}

		foreach ( array(
			'categories' => Taxonomies::COURSE_CATEGORY,
			'levels'     => Taxonomies::LEVEL,
		) as $param => $taxonomy ) {
			if ( ! isset( $request[ $param ] ) ) {
				continue;
			}
			$term_ids = array_values( array_unique( array_map( 'absint', (array) $request[ $param ] ) ) );
			foreach ( $term_ids as $term_id ) {
				$term = get_term( $term_id, $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					return new WP_Error( 'rest_invalid_param', __( 'A selected term does not exist.', 'public-lms' ), array( 'status' => 400 ) );
				}
			}
			$tax = get_taxonomy( $taxonomy );
			if ( false === $tax || ! current_user_can( $tax->cap->assign_terms ) ) {
				return $this->forbidden();
			}
			wp_set_object_terms( $course_id, $term_ids, $taxonomy );
		}

		if ( isset( $request['access_mode'] ) ) {
			update_post_meta( $course_id, Meta::ACCESS_MODE, Meta::sanitize_access_mode( $request['access_mode'] ) );
		}
		foreach ( array(
			'price'      => Meta::PRICE,
			'sale_price' => Meta::SALE_PRICE,
		) as $param => $key ) {
			if ( isset( $request[ $param ] ) ) {
				update_post_meta( $course_id, $key, Meta::sanitize_price( $request[ $param ] ) );
			}
		}
		if ( isset( $request['duration'] ) ) {
			update_post_meta( $course_id, Meta::DURATION, absint( $request['duration'] ) );
		}
		if ( isset( $request['prerequisites'] ) ) {
			$required = Meta::sanitize_id_list( $request['prerequisites'] );
			$valid    = AccessRules::validate_course_prerequisites( $course_id, $required );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			update_post_meta( $course_id, Meta::PREREQUISITES, $required );
		}
		if ( isset( $request['certificate'] ) ) {
			update_post_meta( $course_id, Meta::CERTIFICATE, (bool) $request['certificate'] ? '1' : '0' );
		}
		return true;
	}

	/**
	 * Builds the curriculum response: the full structure for editors, the
	 * published one with completion and lock state for everyone else.
	 *
	 * @param int $course_id Course ID.
	 * @return array<string, mixed>
	 */
	private function curriculum_data( int $course_id ): array {
		$editable  = current_user_can( Capabilities::META_EDIT_COURSE, $course_id );
		$structure = $editable ? $this->structure->get_structure( $course_id ) : $this->structure->get_public_structure( $course_id );
		$user_id   = get_current_user_id();
		$completed = $user_id > 0 ? $this->progress->summary( $user_id, $course_id )['completed_items'] : array();

		$sections = array();
		foreach ( $structure['sections'] as $section ) {
			$items = array();
			foreach ( (array) $section['items'] as $item ) {
				$item_id  = (int) $item['id'];
				$decision = $this->policy->can_access_item( $item_id );
				$items[]  = array_merge(
					array(
						'id'            => $item_id,
						'type'          => (string) $item['type'],
						'title'         => $editable ? (string) $item['title'] : wp_strip_all_tags( get_the_title( $item_id ) ),
						'status'        => (string) $item['status'],
						'preview'       => (bool) $item['preview'],
						'duration'      => (int) $item['duration'],
						'link'          => (string) get_permalink( $item_id ),
						'edit_url'      => $editable ? (string) get_edit_post_link( $item_id, 'raw' ) : '',
						'completed'     => in_array( $item_id, $completed, true ),
						'locked'        => ! $decision->is_allowed(),
						'lock_reason'   => $decision->reason(),
						'available_at'  => $decision->available_at(),
						'prerequisites' => Meta::id_list( $item_id, Meta::PREREQUISITES ),
					),
					AccessRules::drip_data( $item_id )
				);
			}
			$sections[] = array_merge(
				array(
					'id'     => (int) $section['id'],
					'title'  => $editable ? (string) $section['title'] : wp_strip_all_tags( get_the_title( (int) $section['id'] ) ),
					'status' => (string) $section['status'],
					'items'  => $items,
				),
				AccessRules::drip_data( (int) $section['id'] )
			);
		}

		return array(
			'course_id' => $course_id,
			'editable'  => $editable,
			'sections'  => $sections,
		);
	}

	/**
	 * Returns the current user's enrolment state in a course.
	 *
	 * @param int $course_id Course ID.
	 * @return array<string, mixed>
	 */
	private function enrollment_data( int $course_id ): array {
		$state = $this->fields->enroll_state( $course_id );
		$data  = array(
			'state' => $state['state'],
			'label' => $state['label'],
			'url'   => $state['url'],
		);
		if ( 'prerequisite' === $state['state'] ) {
			$data['access'] = $this->policy->can_enroll( $course_id )->to_array();
		}
		$user_id = get_current_user_id();
		if ( $user_id > 0 && $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			$data['progress'] = $this->progress->summary( $user_id, $course_id );
		}
		return $data;
	}

	/**
	 * Checks that an ID is a section (or an item when $type is '') the user may place in this course.
	 *
	 * @param int              $post_id   Section or item ID.
	 * @param string           $type      PostTypes::SECTION, or '' for any item type.
	 * @param int              $course_id Target course.
	 * @param array<int, bool> $seen      IDs already listed; updated.
	 * @return true|WP_Error
	 */
	private function check_movable( int $post_id, string $type, int $course_id, array &$seen ) {
		$post_type = (string) get_post_type( $post_id );
		$valid     = '' === $type
			? in_array( $post_type, array( PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT ), true )
			: $type === $post_type;
		if ( ! $valid || 'trash' === get_post_status( $post_id ) ) {
			/* translators: %d: post ID. */
			return new WP_Error( 'rest_invalid_param', sprintf( __( 'Item %d cannot be placed here.', 'public-lms' ), $post_id ), array( 'status' => 400 ) );
		}
		if ( isset( $seen[ $post_id ] ) ) {
			/* translators: %d: post ID. */
			return new WP_Error( 'rest_invalid_param', sprintf( __( 'Item %d is listed twice.', 'public-lms' ), $post_id ), array( 'status' => 400 ) );
		}
		$seen[ $post_id ] = true;

		$current = $this->structure->course_id_for( $post_id );
		if ( $current === $course_id ) {
			return true;
		}
		$allowed = $current > 0
			? current_user_can( Capabilities::META_EDIT_COURSE, $current )
			: current_user_can( 'edit_post', $post_id );
		return $allowed ? true : $this->forbidden();
	}

	/**
	 * Allows publishing only to users with the post type's publish capability.
	 *
	 * @param string $status Requested status.
	 * @return true|WP_Error
	 */
	private function check_status_permission( string $status ) {
		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			$type = get_post_type_object( PostTypes::COURSE );
			if ( null === $type || ! current_user_can( $type->cap->publish_posts ) ) {
				return $this->forbidden();
			}
		}
		return true;
	}

	/**
	 * Returns the statuses a list request asks for.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<int, string>
	 */
	private function requested_statuses( $request ): array {
		$statuses = array_map( 'strval', (array) $request['status'] );
		if ( in_array( 'any', $statuses, true ) ) {
			return self::STATUSES;
		}
		$statuses = array_values( array_intersect( $statuses, self::STATUSES ) );
		return empty( $statuses ) ? array( 'publish' ) : $statuses;
	}

	/**
	 * Counts the rows a list query would return without pagination.
	 *
	 * @param array<string, mixed> $args WP_Query arguments.
	 * @return int
	 */
	private function count( array $args ): int {
		$args['fields']         = 'ids';
		$args['posts_per_page'] = 1;
		$args['no_found_rows']  = false;
		$query                  = new WP_Query( $args );
		return (int) $query->found_posts;
	}

	/**
	 * Returns a posts_where callback that continues after a cursor.
	 *
	 * @param string                       $column Sort column on the posts table.
	 * @param string                       $cmp    '>' or '<'.
	 * @param array{0: string|int, 1: int} $cursor Sort value and ID.
	 * @return callable(string): string
	 */
	private function cursor_where( string $column, string $cmp, array $cursor ): callable {
		return static function ( $where ) use ( $column, $cmp, $cursor ) {
			global $wpdb;
			if ( 'ID' === $column ) {
				return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID {$cmp} %d", (int) $cursor[1] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column and operator come from whitelists.
			}
			return $where . $wpdb->prepare(
				" AND ({$wpdb->posts}.{$column} {$cmp} %s OR ({$wpdb->posts}.{$column} = %s AND {$wpdb->posts}.ID {$cmp} %d))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column and operator come from whitelists.
				(string) $cursor[0],
				(string) $cursor[0],
				(int) $cursor[1]
			);
		};
	}

	/**
	 * Returns the term IDs of a course in a taxonomy.
	 *
	 * @param int    $course_id Course ID.
	 * @param string $taxonomy  Taxonomy.
	 * @return array<int, int>
	 */
	private function term_ids( int $course_id, string $taxonomy ): array {
		$terms = get_the_terms( $course_id, $taxonomy );
		return is_array( $terms ) ? array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) ) : array();
	}

	/**
	 * Converts a GMT MySQL date to RFC 3339, or null for an unset date.
	 *
	 * @param string $date MySQL datetime.
	 * @return string|null
	 */
	private function gmt( string $date ): ?string {
		return '' === $date || '0000-00-00 00:00:00' === $date ? null : mysql_to_rfc3339( $date );
	}
}
