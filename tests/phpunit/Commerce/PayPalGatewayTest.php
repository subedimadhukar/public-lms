<?php
/**
 * PayPal gateway tests (mocked HTTP).
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Commerce;

use PublicLMS\Commerce\Gateways\Gateways;
use PublicLMS\Commerce\Gateways\PayPalGateway;
use PublicLMS\Commerce\Money;
use PublicLMS\Repositories\OrderRepository;
use WP_REST_Request;

/**
 * Orders v2, capture on return, refunds and verified webhooks (ADR-049).
 */
class PayPalGatewayTest extends CommerceTestCase {

	const API = 'https://api-m.sandbox.paypal.com';

	/**
	 * PayPal gateway.
	 *
	 * @var PayPalGateway
	 */
	private $paypal;

	/**
	 * HTTP mock.
	 *
	 * @var HttpMock
	 */
	private $http;

	public function set_up() {
		parent::set_up();
		update_option(
			'publiclms_gateway_paypal',
			array(
				'enabled'       => true,
				'sandbox'       => true,
				'client_id'     => 'client-unit',
				'client_secret' => 'secret-unit',
				'webhook_id'    => 'WH-UNIT',
			)
		);
		$this->paypal  = new PayPalGateway();
		$this->service = $this->build_service( new Gateways( array( $this->offline, $this->paypal ) ) );
		$this->http    = new HttpMock();
		$this->http->on(
			'POST ' . self::API . '/v1/oauth2/token',
			200,
			array(
				'access_token' => 'A21-token',
				'expires_in'   => 32400,
			)
		);
	}

	public function tear_down() {
		$this->http->stop();
		delete_option( 'publiclms_gateway_paypal' );
		delete_transient( 'publiclms_paypal_token_' . md5( 'client-unit|sandbox' ) );
		parent::tear_down();
	}

	/**
	 * Places a PayPal order.
	 *
	 * @param string $price Price.
	 * @return array{order: array<string, mixed>, user: int, course: int}
	 */
	private function paypal_order( string $price = '49.50' ): array {
		$this->http->on(
			'POST ' . self::API . '/v2/checkout/orders',
			200,
			array(
				'id'     => '5O190127TN364715T',
				'status' => 'PAYER_ACTION_REQUIRED',
				'links'  => array(
					array(
						'rel'  => 'self',
						'href' => self::API . '/v2/checkout/orders/5O190127TN364715T',
					),
					array(
						'rel'  => 'payer-action',
						'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T',
					),
				),
			)
		);
		$course = $this->paid_course( $price );
		$user   = self::factory()->user->create();
		$result = $this->service->checkout(
			array(
				'courses' => array( $course ),
				'gateway' => 'paypal',
				'user_id' => $user,
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return array(
			'order'  => (array) $this->orders->find( (int) $result['order']['id'] ),
			'user'   => $user,
			'course' => $course,
		);
	}

	/**
	 * Builds a captured order response.
	 *
	 * @param string $status Capture status.
	 * @return array<string, mixed>
	 */
	private function captured( string $status = 'COMPLETED' ): array {
		return array(
			'id'             => '5O190127TN364715T',
			'status'         => 'COMPLETED',
			'purchase_units' => array(
				array(
					'payments' => array(
						'captures' => array(
							array(
								'id'     => '3C679366HH908993F',
								'status' => $status,
								'amount' => array(
									'currency_code' => 'EUR',
									'value'         => '49.50',
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Builds a webhook request with PayPal's transmission headers.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private function webhook_request( array $event ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/publiclms/v1/webhooks/paypal' );
		$request->set_body( (string) wp_json_encode( $event ) );
		foreach ( array(
			'Paypal-Auth-Algo'         => 'SHA256withRSA',
			'Paypal-Cert-Url'          => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
			'Paypal-Transmission-Id'   => '69cd13f0-d67a-11e5-baa3-778b53f4ae55',
			'Paypal-Transmission-Sig'  => 'sig',
			'Paypal-Transmission-Time' => '2026-11-04T10:00:00Z',
		) as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return $request;
	}

	public function test_currencies_and_whole_unit_values() {
		$this->assertTrue( $this->paypal->supports_currency( 'EUR' ) );
		$this->assertTrue( $this->paypal->supports_currency( 'JPY' ) );
		$this->assertFalse( $this->paypal->supports_currency( 'INR' ), 'PayPal does not take INR.' );
		$this->assertSame( '4800', PayPalGateway::value( 4800, 'JPY' ) );
		$this->assertSame( '1500', PayPalGateway::value( 1500, 'HUF' ), 'HUF has decimals in ISO but not at PayPal.' );
		$this->assertSame( '49.50', PayPalGateway::value( 49.5, 'EUR' ) );
		update_option( Money::OPTION, 'INR' );
		$this->assertArrayNotHasKey( 'paypal', $this->service->gateways()->available( 'INR' ) );
	}

	public function test_order_is_created_for_capture_without_shipping() {
		$placed  = $this->paypal_order();
		$request = $this->http->last( 'POST ' . self::API . '/v2/checkout/orders' );
		$this->assertSame( 'Bearer A21-token', $request['headers']['Authorization'] );
		$this->assertSame( 'publiclms-order-' . $placed['order']['order_key'], $request['headers']['PayPal-Request-Id'] );
		$body = json_decode( (string) $request['body'], true );
		$this->assertSame( 'CAPTURE', $body['intent'] );
		$unit = $body['purchase_units'][0];
		$this->assertSame( $placed['order']['order_key'], $unit['custom_id'] );
		$this->assertSame(
			array(
				'currency_code' => 'EUR',
				'value'         => '49.50',
			),
			array(
				'currency_code' => $unit['amount']['currency_code'],
				'value'         => $unit['amount']['value'],
			)
		);
		$this->assertSame( '49.50', $unit['amount']['breakdown']['item_total']['value'] );
		$this->assertSame( 'DIGITAL_GOODS', $unit['items'][0]['category'] );
		$context = $body['payment_source']['paypal']['experience_context'];
		$this->assertSame( 'NO_SHIPPING', $context['shipping_preference'] );
		$this->assertSame( 'PAY_NOW', $context['user_action'] );
		$this->assertStringContainsString( 'order=', $context['return_url'] );
		$this->assertSame( '5O190127TN364715T', $placed['order']['gateway_order_id'] );
	}

	public function test_token_is_cached() {
		$this->paypal_order();
		$this->paypal_order();
		$tokens = array_filter(
			$this->http->requests,
			static function ( $request ) {
				return false !== strpos( $request['url'], '/v1/oauth2/token' );
			}
		);
		$this->assertCount( 1, $tokens );
		$auth = array_values( $tokens )[0]['headers']['Authorization'];
		$this->assertSame( 'Basic ' . base64_encode( 'client-unit:secret-unit' ), $auth ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Expected Basic header.
	}

	public function test_discount_goes_in_the_breakdown() {
		$this->coupon(
			array(
				'code'   => 'TEN',
				'type'   => 'fixed',
				'amount' => 10,
			)
		);
		$this->http->on(
			'POST ' . self::API . '/v2/checkout/orders',
			200,
			array(
				'id'    => 'ORDER2',
				'links' => array(
					array(
						'rel'  => 'payer-action',
						'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER2',
					),
				),
			)
		);
		$this->service->checkout(
			array(
				'courses' => array( $this->paid_course( '49.50' ) ),
				'gateway' => 'paypal',
				'coupon'  => 'TEN',
				'user_id' => self::factory()->user->create(),
			)
		);
		$body = json_decode( (string) $this->http->last( '/v2/checkout/orders' )['body'], true );
		$unit = $body['purchase_units'][0];
		$this->assertSame( '39.50', $unit['amount']['value'] );
		$this->assertSame( '49.50', $unit['amount']['breakdown']['item_total']['value'] );
		$this->assertSame( '10.00', $unit['amount']['breakdown']['discount']['value'] );
	}

	public function test_fractional_amount_in_a_whole_unit_currency_is_refused() {
		update_option( Money::OPTION, 'HUF' );
		$result = $this->service->checkout(
			array(
				'courses' => array( $this->paid_course( '1999.50' ) ),
				'gateway' => 'paypal',
				'user_id' => self::factory()->user->create(),
			)
		);
		$this->assertSame( 'publiclms_paypal_amount', $result->get_error_code() );
	}

	public function test_return_captures_and_completes_without_a_webhook() {
		$placed = $this->paypal_order();
		$this->http->on( 'POST ' . self::API . '/v2/checkout/orders/5O190127TN364715T/capture', 201, $this->captured() );
		$this->service->handle_return( $placed['order'], array( 'token' => 'SOMEONE-ELSE' ) );
		$this->assertNull( $this->http->last( '/capture' ), 'Another token captures nothing.' );

		$after = $this->service->handle_return( $placed['order'], array( 'token' => '5O190127TN364715T', 'PayerID' => 'ABC' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( OrderRepository::STATUS_COMPLETED, $after['status'] );
		$this->assertTrue( $this->enrollments->is_enrolled( $placed['user'], $placed['course'] ) );
		$this->assertStringStartsWith( 'publiclms-capture-' . $placed['order']['order_key'] . '-', $this->http->last( '/capture' )['headers']['PayPal-Request-Id'] );
		$this->assertSame( '3C679366HH908993F', $this->service->payment_transaction( (int) $placed['order']['id'] )['gateway_transaction_id'] );
	}

	public function test_already_captured_order_is_read_back() {
		$placed = $this->paypal_order();
		$this->http->on( 'POST ' . self::API . '/v2/checkout/orders/5O190127TN364715T/capture', 422, array( 'name' => 'UNPROCESSABLE_ENTITY', 'details' => array( array( 'issue' => 'ORDER_ALREADY_CAPTURED', 'description' => 'Order already captured.' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->http->on( 'GET ' . self::API . '/v2/checkout/orders/5O190127TN364715T', 200, $this->captured() );
		$after = $this->service->handle_return( $placed['order'], array( 'token' => '5O190127TN364715T' ) );
		$this->assertSame( OrderRepository::STATUS_COMPLETED, $after['status'] );
	}

	public function test_unapproved_order_stays_pending_and_pending_capture_waits() {
		$placed = $this->paypal_order();
		$this->http->on( 'POST ' . self::API . '/v2/checkout/orders/5O190127TN364715T/capture', 422, array( 'details' => array( array( 'issue' => 'ORDER_NOT_APPROVED' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( OrderRepository::STATUS_PENDING, $this->service->handle_return( $placed['order'], array( 'token' => '5O190127TN364715T' ) )['status'] );
	}

	public function test_refund_through_paypal() {
		$placed = $this->paypal_order();
		$this->http->on( 'POST ' . self::API . '/v2/checkout/orders/5O190127TN364715T/capture', 201, $this->captured() );
		$this->service->handle_return( $placed['order'], array( 'token' => '5O190127TN364715T' ) );
		$this->http->on(
			'POST ' . self::API . '/v2/payments/captures/3C679366HH908993F/refund',
			201,
			array(
				'id'     => '1JU08902781691411',
				'status' => 'COMPLETED',
			)
		);
		$refunded = $this->service->refund( (int) $placed['order']['id'] );
		$this->assertSame( OrderRepository::STATUS_REFUNDED, $refunded['status'] );
		$this->assertFalse( $this->enrollments->is_enrolled( $placed['user'], $placed['course'] ) );
		$this->assertSame( '{}', $this->http->last( '/refund' )['body'], 'An empty body refunds the whole capture.' );
	}

	public function test_webhook_is_verified_by_paypal_with_the_raw_event() {
		$placed = $this->paypal_order();
		$event  = array(
			'id'         => 'WH-EVT-1',
			'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
			'resource'   => array(
				'id'                 => '3C679366HH908993F',
				'status'             => 'COMPLETED',
				'amount'             => array(
					'currency_code' => 'EUR',
					'value'         => '49.50',
				),
				'supplementary_data' => array( 'related_ids' => array( 'order_id' => '5O190127TN364715T' ) ),
			),
		);
		$this->http->on( 'POST ' . self::API . '/v1/notifications/verify-webhook-signature', 200, array( 'verification_status' => 'SUCCESS' ) );
		$results = $this->paypal->webhook( $this->webhook_request( $event ) );
		$this->assertIsArray( $results );
		$this->service->apply( $this->paypal, $results );
		$this->assertSame( OrderRepository::STATUS_COMPLETED, $this->orders->find( (int) $placed['order']['id'] )['status'] );

		$check = json_decode( (string) $this->http->last( 'verify-webhook-signature' )['body'], true );
		$this->assertSame( 'WH-UNIT', $check['webhook_id'] );
		$this->assertSame( 'SHA256withRSA', $check['auth_algo'] );
		$this->assertSame( $event, $check['webhook_event'] );
	}

	public function test_failed_verification_and_missing_headers_are_refused() {
		$this->http->on( 'POST ' . self::API . '/v1/notifications/verify-webhook-signature', 200, array( 'verification_status' => 'FAILURE' ) );
		$event  = array(
			'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
			'resource'   => array( 'id' => 'X' ),
		);
		$result = $this->paypal->webhook( $this->webhook_request( $event ) );
		$this->assertSame( 400, $result->get_error_data()['status'] );

		$bare = new WP_REST_Request( 'POST', '/publiclms/v1/webhooks/paypal' );
		$bare->set_body( (string) wp_json_encode( $event ) );
		$this->assertSame( 400, $this->paypal->webhook( $bare )->get_error_data()['status'] );
	}

	public function test_webhook_needs_a_webhook_id() {
		$settings               = get_option( 'publiclms_gateway_paypal' );
		$settings['webhook_id'] = '';
		update_option( 'publiclms_gateway_paypal', $settings );
		$result = $this->paypal->webhook( $this->webhook_request( array( 'event_type' => 'X', 'resource' => array() ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_refund_made_at_paypal_reaches_the_order() {
		$placed = $this->paypal_order();
		$this->http->on( 'POST ' . self::API . '/v2/checkout/orders/5O190127TN364715T/capture', 201, $this->captured() );
		$this->service->handle_return( $placed['order'], array( 'token' => '5O190127TN364715T' ) );
		$this->http->on( 'POST ' . self::API . '/v1/notifications/verify-webhook-signature', 200, array( 'verification_status' => 'SUCCESS' ) );
		$event = array(
			'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
			'resource'   => array(
				'id'     => '1JU08902781691411',
				'status' => 'COMPLETED',
				'amount' => array(
					'currency_code' => 'EUR',
					'value'         => '49.50',
				),
				'links'  => array(
					array(
						'rel'  => 'up',
						'href' => self::API . '/v2/payments/captures/3C679366HH908993F',
					),
				),
			),
		);
		$this->service->apply( $this->paypal, $this->paypal->webhook( $this->webhook_request( $event ) ) );
		$this->assertSame( OrderRepository::STATUS_REFUNDED, $this->orders->find( (int) $placed['order']['id'] )['status'] );
	}

	public function test_approved_order_whose_buyer_never_returned_is_captured() {
		$placed = $this->paypal_order();
		$this->http->on( 'POST ' . self::API . '/v1/notifications/verify-webhook-signature', 200, array( 'verification_status' => 'SUCCESS' ) );
		$this->http->on( 'POST ' . self::API . '/v2/checkout/orders/5O190127TN364715T/capture', 201, $this->captured() );
		$event = array(
			'event_type' => 'CHECKOUT.ORDER.APPROVED',
			'resource'   => array(
				'id'             => '5O190127TN364715T',
				'purchase_units' => array( array( 'custom_id' => $placed['order']['order_key'] ) ),
			),
		);
		$this->service->apply( $this->paypal, $this->paypal->webhook( $this->webhook_request( $event ) ) );
		$this->assertSame( OrderRepository::STATUS_COMPLETED, $this->orders->find( (int) $placed['order']['id'] )['status'] );
	}

	public function test_mode_and_bad_credentials() {
		$this->assertSame( 'test', $this->paypal->mode() );
		delete_transient( 'publiclms_paypal_token_' . md5( 'client-unit|sandbox' ) );
		$this->http->stop();
		$this->http = new HttpMock();
		$this->http->on( 'POST ' . self::API . '/v1/oauth2/token', 401, array( 'error' => 'invalid_client' ) );
		$result = $this->service->checkout(
			array(
				'courses' => array( $this->paid_course() ),
				'gateway' => 'paypal',
				'user_id' => self::factory()->user->create(),
			)
		);
		$this->assertSame( 'publiclms_paypal_auth', $result->get_error_code() );
	}
}
