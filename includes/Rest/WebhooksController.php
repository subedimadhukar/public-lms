<?php
/**
 * Payment webhooks.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Commerce\OrderService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /webhooks/{gateway} (ADR-047).
 *
 * Open to anyone, because providers call it without a cookie or nonce. The
 * gateway verifies the provider's signature against the raw body before
 * anything else; an unsigned or wrongly signed request gets 400 and changes
 * nothing. Events the plugin does not use are acknowledged with 200 so the
 * provider stops retrying them.
 */
final class WebhooksController extends AbstractController {

	/**
	 * Order service.
	 *
	 * @var OrderService
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param OrderService $service Order service.
	 */
	public function __construct( OrderService $service ) {
		parent::__construct();
		$this->rest_base = 'webhooks';
		$this->service   = $service;
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<gateway>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive' ),
					'permission_callback' => '__return_true',
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Verifies and applies a webhook.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive( $request ) {
		$gateway = $this->service->gateways()->get( (string) $request['gateway'] );
		if ( null === $gateway || ! $gateway->supports( 'webhooks' ) ) {
			return $this->not_found();
		}
		$results = $gateway->webhook( $request );
		if ( is_wp_error( $results ) ) {
			return $results;
		}
		$applied = $this->service->apply( $gateway, $results );
		return rest_ensure_response(
			array(
				'received' => true,
				'applied'  => $applied,
			)
		);
	}
}
