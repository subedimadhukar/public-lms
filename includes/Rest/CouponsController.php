<?php
/**
 * Coupon routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\Commerce\Coupons;
use PublicLMS\Commerce\Money;
use PublicLMS\PostTypes\PostTypes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes, for users with publiclms_manage_orders (ADR-050):
 *
 * - GET    /coupons         Every coupon, newest first (up to 500).
 * - POST   /coupons         Create one.
 * - GET    /coupons/{id}    One coupon.
 * - PATCH  /coupons/{id}    Update it.
 * - DELETE /coupons/{id}    Delete it for good.
 */
final class CouponsController extends AbstractController {

	/**
	 * Coupons.
	 *
	 * @var Coupons
	 */
	private $coupons;

	/**
	 * Constructor.
	 *
	 * @param Coupons $coupons Coupons.
	 */
	public function __construct( Coupons $coupons ) {
		parent::__construct();
		$this->rest_base = 'coupons';
		$this->coupons   = $coupons;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$can = $this->require_capability( Capabilities::MANAGE_ORDERS );
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $can,
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $can,
					'args'                => $this->coupon_args( true ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'Coupon ID.', 'public-lms' ) ) ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $can,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $can,
					'args'                => $this->coupon_args( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $can,
				),
			)
		);
	}

	/**
	 * Lists coupons.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$posts = get_posts(
			array(
				'post_type'      => PostTypes::COUPON,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Admin list of discount codes; sites have few.
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$items = array();
		foreach ( $posts as $post ) {
			$items[] = $this->prepare( $this->coupons->get( $post ) );
		}
		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) count( $items ) );
		return $response;
	}

	/**
	 * Returns a coupon.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post = $this->post( (int) $request['id'] );
		return null === $post ? $this->not_found() : rest_ensure_response( $this->prepare( $this->coupons->get( $post ) ) );
	}

	/**
	 * Creates a coupon.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$id = $this->coupons->save( $this->input( $request ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = $this->post( $id );
		if ( null === $post ) {
			return $this->not_found();
		}
		$response = rest_ensure_response( $this->prepare( $this->coupons->get( $post ) ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Updates a coupon.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		if ( null === $this->post( (int) $request['id'] ) ) {
			return $this->not_found();
		}
		$id = $this->coupons->save( $this->input( $request ), (int) $request['id'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = $this->post( $id );
		return null === $post ? $this->not_found() : rest_ensure_response( $this->prepare( $this->coupons->get( $post ) ) );
	}

	/**
	 * Deletes a coupon. Orders keep the code they used.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$post = $this->post( (int) $request['id'] );
		if ( null === $post ) {
			return $this->not_found();
		}
		wp_delete_post( (int) $post->ID, true );
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => (int) $post->ID,
			)
		);
	}

	/**
	 * Returns a coupon post, or null.
	 *
	 * @param int $id Post ID.
	 * @return \WP_Post|null
	 */
	private function post( int $id ): ?\WP_Post {
		$post = get_post( $id );
		return $post instanceof \WP_Post && PostTypes::COUPON === $post->post_type ? $post : null;
	}

	/**
	 * Collects the fields present in the request.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	private function input( $request ): array {
		$data = array();
		foreach ( array( 'code', 'active', 'type', 'amount', 'courses', 'starts', 'expires', 'usage_limit', 'user_limit' ) as $key ) {
			if ( isset( $request[ $key ] ) ) {
				$data[ $key ] = $request[ $key ];
			}
		}
		return $data;
	}

	/**
	 * Adds display values to a coupon.
	 *
	 * @param array<string, mixed> $coupon Coupon.
	 * @return array<string, mixed>
	 */
	private function prepare( array $coupon ): array {
		$coupon['amount_formatted'] = 'percent' === $coupon['type']
			/* translators: %s: percentage. */
			? sprintf( __( '%s%%', 'public-lms' ), number_format_i18n( (float) $coupon['amount'], abs( $coupon['amount'] - round( $coupon['amount'] ) ) < 0.001 ? 0 : 2 ) )
			: Money::format( (float) $coupon['amount'] );
		return $coupon;
	}

	/**
	 * Returns the coupon arguments.
	 *
	 * @param bool $create Whether the code is required.
	 * @return array<string, array<string, mixed>>
	 */
	private function coupon_args( bool $create ): array {
		return array(
			'code'        => array(
				'description'       => __( 'The code buyers type, without spaces; matched without regard to case.', 'public-lms' ),
				'type'              => 'string',
				'required'          => $create,
				'minLength'         => 1,
				'maxLength'         => 100,
				'validate_callback' => 'rest_validate_request_arg',
			),
			'active'      => array(
				'description'       => __( 'Whether buyers can use it now.', 'public-lms' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'type'        => array(
				'description'       => __( 'percent or fixed (in the store currency).', 'public-lms' ),
				'type'              => 'string',
				'enum'              => Coupons::TYPES,
				'validate_callback' => 'rest_validate_request_arg',
			),
			'amount'      => array(
				'description'       => __( 'Percentage off, or the amount off.', 'public-lms' ),
				'type'              => 'number',
				'minimum'           => 0,
				'required'          => $create,
				'validate_callback' => 'rest_validate_request_arg',
			),
			'courses'     => array(
				'description'       => __( 'Courses it applies to; empty for every course.', 'public-lms' ),
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'starts'      => array(
				'description'       => __( 'First day it works (YYYY-MM-DD, site time), or empty.', 'public-lms' ),
				'type'              => 'string',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'expires'     => array(
				'description'       => __( 'Last day it works (YYYY-MM-DD, site time), or empty.', 'public-lms' ),
				'type'              => 'string',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'usage_limit' => array(
				'description'       => __( 'Uses in all; 0 for no limit.', 'public-lms' ),
				'type'              => 'integer',
				'minimum'           => 0,
				'validate_callback' => 'rest_validate_request_arg',
			),
			'user_limit'  => array(
				'description'       => __( 'Uses per buyer; 0 for no limit.', 'public-lms' ),
				'type'              => 'integer',
				'minimum'           => 0,
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}
}
