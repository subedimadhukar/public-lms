<?php
/**
 * Order routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\Commerce\OrderService;
use PublicLMS\Core\RateLimiter;
use PublicLMS\Repositories\OrderRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes (ADR-046):
 *
 * - POST /orders/checkout          Create an order and start paying: logged in, or a guest with name and email.
 * - POST /orders/quote             Price courses with a coupon, for the checkout form.
 * - GET  /orders                   Own orders; every order with publiclms_manage_orders (status, search, before).
 * - GET  /orders/counts            Orders per status, for publiclms_manage_orders.
 * - GET  /orders/{id}              Own order, or any with publiclms_manage_order.
 * - POST /orders/{id}/complete     Mark an unpaid order paid (offline payments).
 * - POST /orders/{id}/cancel       Cancel an unpaid order.
 * - POST /orders/{id}/refund       Refund a paid order, through its gateway or by hand (`manual`).
 *
 * Checkout and quotes are rate-limited per user or address (429 with Retry-After).
 */
final class OrdersController extends AbstractController {

	/**
	 * Order service.
	 *
	 * @var OrderService
	 */
	private $service;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Constructor.
	 *
	 * @param OrderService $service Order service.
	 * @param RateLimiter  $limiter Rate limiter.
	 */
	public function __construct( OrderService $service, RateLimiter $limiter ) {
		parent::__construct();
		$this->rest_base = 'orders';
		$this->service   = $service;
		$this->limiter   = $limiter;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$buy = array(
			'courses' => array(
				'description'       => __( 'Courses to buy.', 'public-lms' ),
				'type'              => 'array',
				'items'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'minItems'          => 1,
				'maxItems'          => 20,
				'required'          => true,
				'validate_callback' => 'rest_validate_request_arg',
			),
			'coupon'  => array(
				'description'       => __( 'Coupon code.', 'public-lms' ),
				'type'              => 'string',
				'default'           => '',
				'maxLength'         => 100,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'email'   => array(
				'description'       => __( 'Guest buyer\'s email.', 'public-lms' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'checkout' ),
					'permission_callback' => '__return_true',
					'args'                => array_merge(
						$buy,
						array(
							'gateway' => array(
								'description'       => __( 'Payment method ID.', 'public-lms' ),
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_key',
								'validate_callback' => 'rest_validate_request_arg',
							),
							'name'    => array(
								'description'       => __( 'Guest buyer\'s name, for certificates.', 'public-lms' ),
								'type'              => 'string',
								'default'           => '',
								'maxLength'         => 200,
								'sanitize_callback' => 'sanitize_text_field',
								'validate_callback' => 'rest_validate_request_arg',
							),
						)
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/quote',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'quote' ),
					'permission_callback' => '__return_true',
					'args'                => $buy,
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
						'status'   => array(
							'description'       => __( 'Only orders with this status.', 'public-lms' ),
							'type'              => 'string',
							'enum'              => array_merge( array( '' ), OrderRepository::STATUSES ),
							'default'           => '',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'search'   => array(
							'description'       => __( 'Order number, key, email or payment reference.', 'public-lms' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'before'   => array(
							'description'       => __( 'Return orders with an ID lower than this (the X-PublicLMS-Next-Cursor of the previous page).', 'public-lms' ),
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'per_page' => array(
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
			'/' . $this->rest_base . '/counts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'counts' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_ORDERS ),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args' => array( 'id' => $this->id_arg( __( 'Order ID.', 'public-lms' ) ) ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
			)
		);

		foreach ( array( 'complete', 'cancel', 'refund' ) as $action ) {
			$args = array( 'id' => $this->id_arg( __( 'Order ID.', 'public-lms' ) ) );
			if ( 'refund' === $action ) {
				$args['manual'] = array(
					'description'       => __( 'The money was returned outside the payment provider; only record the refund.', 'public-lms' ),
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'validate_callback' => 'rest_validate_request_arg',
				);
			}
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>[\d]+)/' . $action,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, $action . '_order' ),
						'permission_callback' => array( $this, 'manage_order_permissions_check' ),
						'args'                => $args,
					),
				)
			);
		}
	}

	/**
	 * Creates an order and starts its payment.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function checkout( $request ) {
		$limited = $this->limit( 'checkout', 10 );
		if ( null !== $limited ) {
			return $limited;
		}
		$result = $this->service->checkout(
			array(
				'courses' => (array) $request['courses'],
				'gateway' => (string) $request['gateway'],
				'coupon'  => (string) $request['coupon'],
				'email'   => (string) $request['email'],
				'name'    => (string) $request['name'],
				'user_id' => get_current_user_id(),
				'ip'      => self::client_ip(),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response( $result );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Prices courses with a coupon.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function quote( $request ) {
		$limited = $this->limit( 'quote', 30 );
		if ( null !== $limited ) {
			return $limited;
		}
		$quote = $this->service->quote( (array) $request['courses'], (string) $request['coupon'], get_current_user_id(), (string) $request['email'] );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		$currency = $quote['currency'];
		return rest_ensure_response(
			array(
				'currency'           => $currency,
				'coupon'             => $quote['coupon'],
				'subtotal'           => $quote['subtotal'],
				'discount'           => $quote['discount'],
				'total'              => $quote['total'],
				'subtotal_formatted' => \PublicLMS\Commerce\Money::format( $quote['subtotal'], $currency ),
				'discount_formatted' => \PublicLMS\Commerce\Money::format( $quote['discount'], $currency ),
				'total_formatted'    => \PublicLMS\Commerce\Money::format( $quote['total'], $currency ),
			)
		);
	}

	/**
	 * Logged in.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return is_user_logged_in() ? true : new WP_Error( 'publiclms_rest_logged_out', __( 'Please log in first.', 'public-lms' ), array( 'status' => 401 ) );
	}

	/**
	 * Lists orders: all for order managers, otherwise the user's own.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$repo     = $this->service->repository();
		$per_page = (int) $request['per_page'];
		if ( ! current_user_can( Capabilities::MANAGE_ORDERS ) ) {
			$rows     = $repo->for_user( get_current_user_id(), 100 );
			$response = rest_ensure_response(
				array_map(
					function ( $order ) {
						return $this->service->prepare( $order );
					},
					$rows
				)
			);
			$response->header( 'X-WP-Total', (string) count( $rows ) );
			return $response;
		}

		$status = (string) $request['status'];
		$search = (string) $request['search'];
		$rows   = $repo->search( $status, $search, (int) $request['before'], $per_page + 1 );
		$next   = '';
		if ( count( $rows ) > $per_page ) {
			$rows = array_slice( $rows, 0, $per_page );
			$next = (string) end( $rows )['id'];
		}
		$response = rest_ensure_response(
			array_map(
				function ( $order ) {
					return $this->service->prepare( $order, true );
				},
				$rows
			)
		);
		$response->header( 'X-WP-Total', (string) $repo->count( $status, $search ) );
		if ( '' !== $next ) {
			$response->header( 'X-PublicLMS-Next-Cursor', $next );
		}
		return $response;
	}

	/**
	 * Returns orders per status.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function counts( $request ) {
		return rest_ensure_response( $this->service->repository()->status_counts() );
	}

	/**
	 * The order's buyer, or an order manager.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'publiclms_rest_logged_out', __( 'Please log in first.', 'public-lms' ), array( 'status' => 401 ) );
		}
		$order = $this->service->repository()->find( (int) $request['id'] );
		if ( null === $order ) {
			return $this->not_found();
		}
		if ( current_user_can( Capabilities::META_MANAGE_ORDER, (int) $order['id'] ) || get_current_user_id() === (int) $order['user_id'] ) {
			return true;
		}
		return $this->not_found();
	}

	/**
	 * Returns an order.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$order = $this->service->repository()->find( (int) $request['id'] );
		if ( null === $order ) {
			return $this->not_found();
		}
		return rest_ensure_response( $this->service->prepare( $order, current_user_can( Capabilities::META_MANAGE_ORDER, (int) $order['id'] ) ) );
	}

	/**
	 * Order managers only.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function manage_order_permissions_check( $request ) {
		if ( null === $this->service->repository()->find( (int) $request['id'] ) ) {
			return current_user_can( Capabilities::MANAGE_ORDERS ) ? $this->not_found() : $this->forbidden();
		}
		return current_user_can( Capabilities::META_MANAGE_ORDER, (int) $request['id'] ) ? true : $this->forbidden();
	}

	/**
	 * Marks an order paid.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete_order( $request ) {
		return $this->respond( $this->service->mark_paid( (int) $request['id'] ) );
	}

	/**
	 * Cancels an order.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_order( $request ) {
		return $this->respond( $this->service->cancel( (int) $request['id'] ) );
	}

	/**
	 * Refunds an order.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refund_order( $request ) {
		return $this->respond( $this->service->refund( (int) $request['id'], (bool) $request['manual'] ) );
	}

	/**
	 * Returns the client address the web server saw. Proxy headers are not trusted.
	 *
	 * @return string
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip;
	}

	/**
	 * Wraps a service answer in an admin view of the order.
	 *
	 * @param array<string, mixed>|WP_Error $order Order or error.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( $order ) {
		return is_wp_error( $order ) ? $order : rest_ensure_response( $this->service->prepare( $order, true ) );
	}

	/**
	 * Applies a rate limit per user, or per address for guests.
	 *
	 * @param string $action Action.
	 * @param int    $limit  Requests per ten minutes.
	 * @return WP_REST_Response|null A 429 response with Retry-After, or null when allowed.
	 */
	private function limit( string $action, int $limit ): ?WP_REST_Response {
		$subject = is_user_logged_in() ? 'user:' . get_current_user_id() : 'ip:' . self::client_ip();
		$wait    = $this->limiter->hit( 'order_' . $action, $subject, $limit, 600 );
		if ( $wait <= 0 ) {
			return null;
		}
		$response = rest_convert_error_to_response( new WP_Error( 'publiclms_rate_limited', __( 'Too many attempts. Please wait a few minutes and try again.', 'public-lms' ), array( 'status' => 429 ) ) );
		$response->header( 'Retry-After', (string) $wait );
		return $response;
	}
}
