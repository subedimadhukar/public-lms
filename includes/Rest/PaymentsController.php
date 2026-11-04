<?php
/**
 * Payment settings routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\Commerce\CheckoutPage;
use PublicLMS\Commerce\Gateways\AbstractGateway;
use PublicLMS\Commerce\Gateways\Gateways;
use PublicLMS\Commerce\Money;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes, for users with publiclms_manage_orders (ADR-047):
 *
 * - GET  /settings/payments                Currency, checkout page, guest checkout and every gateway.
 * - POST /settings/payments                Update them; `gateways` is { id: { field: value } }.
 * - POST /settings/payments/checkout-page  Create a "Checkout" page with the checkout block and select it.
 *
 * Secret fields are write-only: responses say whether each is set and
 * whether it comes from configuration (a constant), never the value.
 */
final class PaymentsController extends AbstractController {

	/**
	 * Gateways.
	 *
	 * @var Gateways
	 */
	private $gateways;

	/**
	 * Constructor.
	 *
	 * @param Gateways $gateways Gateways.
	 */
	public function __construct( Gateways $gateways ) {
		parent::__construct();
		$this->rest_base = 'settings/payments';
		$this->gateways  = $gateways;
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
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_ORDERS ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_ORDERS ),
					'args'                => array(
						'currency'         => array(
							'description'       => __( 'ISO 4217 code of the store currency.', 'public-lms' ),
							'type'              => 'string',
							'enum'              => Money::CURRENCIES,
							'validate_callback' => 'rest_validate_request_arg',
						),
						'checkout_page_id' => array(
							'description'       => __( 'Page with the checkout block; 0 for none.', 'public-lms' ),
							'type'              => 'integer',
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'guest_checkout'   => array(
							'description'       => __( 'Let buyers check out without an account; one is created once they pay.', 'public-lms' ),
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'gateways'         => array(
							'description' => __( 'Gateway settings by gateway ID.', 'public-lms' ),
							'type'        => 'object',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/checkout-page',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_page' ),
					'permission_callback' => array( $this, 'create_page_permissions_check' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Returns the payment settings.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		return rest_ensure_response( $this->data() );
	}

	/**
	 * Updates the payment settings.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		if ( isset( $request['currency'] ) ) {
			update_option( Money::OPTION, strtoupper( (string) $request['currency'] ) );
		}
		if ( isset( $request['checkout_page_id'] ) ) {
			$page_id = (int) $request['checkout_page_id'];
			if ( $page_id > 0 && 'page' !== get_post_type( $page_id ) ) {
				return new WP_Error( 'rest_invalid_param', __( 'Choose a page for the checkout.', 'public-lms' ), array( 'status' => 400 ) );
			}
			update_option( CheckoutPage::OPTION, $page_id );
		}
		if ( isset( $request['guest_checkout'] ) ) {
			update_option( CheckoutPage::GUEST_OPTION, $request['guest_checkout'] ? 'yes' : 'no' );
		}
		$gateways = $request['gateways'];
		if ( is_array( $gateways ) ) {
			foreach ( $gateways as $id => $values ) {
				$gateway = $this->gateways->get( (string) $id );
				if ( null !== $gateway && is_array( $values ) ) {
					$gateway->update_settings( $values );
				}
			}
		}
		return rest_ensure_response( $this->data() );
	}

	/**
	 * Needs publiclms_manage_orders and the right to publish pages.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function create_page_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE_ORDERS ) && current_user_can( 'publish_pages' ) ? true : $this->forbidden();
	}

	/**
	 * Creates the checkout page.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_page( $request ) {
		$page_id = CheckoutPage::create();
		if ( is_wp_error( $page_id ) ) {
			$page_id->add_data( array( 'status' => 500 ) );
			return $page_id;
		}
		return rest_ensure_response( $this->data() );
	}

	/**
	 * Returns the settings and the state of every gateway.
	 *
	 * @return array<string, mixed>
	 */
	private function data(): array {
		$currency = Money::currency();
		$page_id  = CheckoutPage::page_id();
		$gateways = array();
		foreach ( $this->gateways->all() as $gateway ) {
			$fields = array();
			foreach ( $gateway->fields() as $key => $field ) {
				$fields[ $key ] = array(
					'label'   => (string) $field['label'],
					'type'    => (string) $field['type'],
					'help'    => (string) ( $field['help'] ?? '' ),
					'options' => (array) ( $field['options'] ?? array() ),
				);
			}
			$gateways[] = array(
				'id'                => $gateway->id(),
				'title'             => $gateway->admin_title(),
				'enabled'           => $gateway->is_enabled(),
				'configured'        => $gateway->is_configured(),
				'supports_currency' => '' !== $currency && $gateway->supports_currency( $currency ),
				'webhooks'          => $gateway->supports( 'webhooks' ),
				'webhook_url'       => $gateway instanceof AbstractGateway && $gateway->supports( 'webhooks' ) ? $gateway->webhook_url() : '',
				'mode'              => method_exists( $gateway, 'mode' ) ? (string) $gateway->mode() : '',
				'fields'            => $fields,
				'settings'          => $gateway->public_settings(),
			);
		}
		return array(
			'currency'          => $currency,
			'currencies'        => Money::CURRENCIES,
			'checkout_page_id'  => $page_id,
			'checkout_page_url' => $page_id > 0 ? (string) get_permalink( $page_id ) : '',
			'guest_checkout'    => CheckoutPage::guests_allowed(),
			'gateways'          => $gateways,
		);
	}
}
