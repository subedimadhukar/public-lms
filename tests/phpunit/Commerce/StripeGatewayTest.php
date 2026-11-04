<?php
/**
 * Stripe gateway tests (mocked HTTP).
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Commerce;

use PublicLMS\Commerce\Gateways\Gateways;
use PublicLMS\Commerce\Gateways\StripeGateway;
use PublicLMS\Commerce\Money;
use PublicLMS\Repositories\OrderRepository;
use WP_REST_Request;

/**
 * Checkout Sessions, return check, webhooks and refunds (ADR-048).
 */
class StripeGatewayTest extends CommerceTestCase {

	const SECRET  = 'sk_test_unit';
	const WEBHOOK = 'whsec_unit';

	/**
	 * Stripe gateway.
	 *
	 * @var StripeGateway
	 */
	private $stripe;

	/**
	 * HTTP mock.
	 *
	 * @var HttpMock
	 */
	private $http;

	public function set_up() {
		parent::set_up();
		update_option(
			'publiclms_gateway_stripe',
			array(
				'enabled'        => true,
				'secret_key'     => self::SECRET,
				'webhook_secret' => self::WEBHOOK,
			)
		);
		$this->stripe  = new StripeGateway();
		$this->service = $this->build_service( new Gateways( array( $this->offline, $this->stripe ) ) );
		$this->http    = new HttpMock();
	}

	public function tear_down() {
		$this->http->stop();
		delete_option( 'publiclms_gateway_stripe' );
		parent::tear_down();
	}

	/**
	 * Places a Stripe order for a new learner.
	 *
	 * @param string $price Price.
	 * @return array{order: array<string, mixed>, user: int, course: int}
	 */
	private function stripe_order( string $price = '49.50' ): array {
		$this->http->on(
			'POST https://api.stripe.com/v1/checkout/sessions',
			200,
			array(
				'id'  => 'cs_test_1',
				'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
			)
		);
		$course = $this->paid_course( $price );
		$user   = self::factory()->user->create( array( 'user_email' => 'kenji@example.org' ) );
		$result = $this->service->checkout(
			array(
				'courses' => array( $course ),
				'gateway' => 'stripe',
				'user_id' => $user,
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'https://checkout.stripe.com/c/pay/cs_test_1', $result['redirect'] );
		return array(
			'order'  => (array) $this->orders->find( (int) $result['order']['id'] ),
			'user'   => $user,
			'course' => $course,
		);
	}

	/**
	 * Builds a signed webhook request.
	 *
	 * @param array<string, mixed> $event     Event.
	 * @param int|null             $timestamp Signature time.
	 * @param string               $secret    Signing secret.
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private function webhook_request( array $event, ?int $timestamp = null, string $secret = self::WEBHOOK ): WP_REST_Request {
		$body      = (string) wp_json_encode( $event );
		$timestamp = null === $timestamp ? time() : $timestamp;
		$request   = new WP_REST_Request( 'POST', '/publiclms/v1/webhooks/stripe' );
		$request->set_body( $body );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Stripe-Signature', 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ) );
		return $request;
	}

	/**
	 * Builds a paid session object.
	 *
	 * @param string $status Payment status.
	 * @param int    $amount Amount in cents.
	 * @return array<string, mixed>
	 */
	private function session( string $status = 'paid', int $amount = 4950 ): array {
		return array(
			'id'             => 'cs_test_1',
			'object'         => 'checkout.session',
			'status'         => 'paid' === $status ? 'complete' : 'open',
			'payment_status' => $status,
			'payment_intent' => 'pi_test_1',
			'amount_total'   => $amount,
			'currency'       => 'eur',
		);
	}

	/**
	 * Reports the session paid through a signed webhook.
	 *
	 * @return void
	 */
	private function pay(): void {
		$event = array(
			'type' => 'checkout.session.completed',
			'data' => array( 'object' => $this->session() ),
		);
		$this->service->apply( $this->stripe, $this->stripe->webhook( $this->webhook_request( $event ) ) );
	}

	public function test_minor_units_follow_stripes_rules() {
		$this->assertSame( 1099, StripeGateway::to_minor( 10.99, 'USD' ) );
		$this->assertSame( 5000, StripeGateway::to_minor( 5000, 'JPY' ) );
		$this->assertSame( 5000, StripeGateway::to_minor( 5000, 'KRW' ) );
		$this->assertSame( 1250, StripeGateway::to_minor( 1.25, 'KWD' ), 'Three-decimal currencies end in 0.' );
		$this->assertSame( 50000, StripeGateway::to_minor( 500, 'ISK' ), 'ISK is sent as a two-decimal value ending in 00.' );
		$this->assertSame( 500, StripeGateway::to_minor( 5, 'UGX' ) );
		$this->assertSame( 1.25, StripeGateway::from_minor( 1250, 'KWD' ) );
		$this->assertSame( 5000.0, StripeGateway::from_minor( 5000, 'JPY' ) );
		$this->assertSame( 500.0, StripeGateway::from_minor( 50000, 'ISK' ) );
	}

	public function test_session_is_hosted_with_dynamic_payment_methods_and_a_pinned_version() {
		$placed  = $this->stripe_order();
		$request = $this->http->last( 'POST https://api.stripe.com/v1/checkout/sessions' );
		$this->assertSame( 'Bearer ' . self::SECRET, $request['headers']['Authorization'] );
		$this->assertSame( StripeGateway::API_VERSION, $request['headers']['Stripe-Version'] );
		$this->assertSame( 'publiclms-session-' . $placed['order']['order_key'], $request['headers']['Idempotency-Key'] );
		parse_str( (string) $request['body'], $params );
		$this->assertArrayNotHasKey( 'payment_method_types', $params, 'The account decides the payment methods.' );
		$this->assertSame( 'payment', $params['mode'] );
		$this->assertSame( 'eur', $params['line_items'][0]['price_data']['currency'] );
		$this->assertSame( '4950', $params['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( $placed['order']['order_key'], $params['client_reference_id'] );
		$this->assertSame( 'kenji@example.org', $params['customer_email'] );
		$this->assertStringEndsWith( '&session_id={CHECKOUT_SESSION_ID}', $params['success_url'] );
		$this->assertStringContainsString( 'cancelled=', $params['cancel_url'] );
		$this->assertSame( 'cs_test_1', $placed['order']['gateway_order_id'] );
		$this->assertSame( OrderRepository::STATUS_PENDING, $placed['order']['status'] );
	}

	public function test_zero_decimal_currency_amounts() {
		update_option( Money::OPTION, 'JPY' );
		$this->stripe_order( '4800' );
		parse_str( (string) $this->http->last( 'checkout/sessions' )['body'], $params );
		$this->assertSame( '4800', $params['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 'jpy', $params['line_items'][0]['price_data']['currency'] );
	}

	public function test_three_decimal_amount_stripe_cannot_take_is_refused() {
		update_option( Money::OPTION, 'KWD' );
		$result = $this->service->checkout(
			array(
				'courses' => array( $this->paid_course( '12.345' ) ),
				'gateway' => 'stripe',
				'user_id' => self::factory()->user->create(),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'publiclms_stripe_amount', $result->get_error_code() );
	}

	public function test_stripe_errors_reach_the_buyer_without_secrets() {
		$this->http->on( 'POST https://api.stripe.com/v1/checkout/sessions', 400, array( 'error' => array( 'message' => 'The currency xyz is not supported.' ) ) );
		$result = $this->service->checkout(
			array(
				'courses' => array( $this->paid_course() ),
				'gateway' => 'stripe',
				'user_id' => self::factory()->user->create(),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'Stripe: The currency xyz is not supported.', $result->get_error_message() );
		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $result->get_all_error_data() ) );
	}

	public function test_return_checks_the_session_and_completes() {
		$placed = $this->stripe_order();
		$this->http->on( 'GET https://api.stripe.com/v1/checkout/sessions/cs_test_1', 200, $this->session() );
		$this->service->handle_return( $placed['order'], array( 'session_id' => 'cs_other' ) );
		$this->assertSame( OrderRepository::STATUS_PENDING, $this->orders->find( (int) $placed['order']['id'] )['status'], 'Another session ID is ignored.' );

		$after = $this->service->handle_return( $placed['order'], array( 'session_id' => 'cs_test_1' ) );
		$this->assertSame( OrderRepository::STATUS_COMPLETED, $after['status'] );
		$this->assertTrue( $this->enrollments->is_enrolled( $placed['user'], $placed['course'] ) );
		$this->assertSame( 'pi_test_1', $this->service->payment_transaction( (int) $placed['order']['id'] )['gateway_transaction_id'] );
	}

	public function test_webhook_signature_is_required() {
		$this->stripe_order();
		$event    = array(
			'type' => 'checkout.session.completed',
			'data' => array( 'object' => $this->session() ),
		);
		$tampered = $this->webhook_request( $event );
		$tampered->set_body( str_replace( '4950', '1', (string) $tampered->get_body() ) );
		$this->assertSame( 400, rest_do_request( $tampered )->get_status() );
		$this->assertSame( 400, rest_do_request( $this->webhook_request( $event, time() - 600 ) )->get_status(), 'Old timestamps are refused.' );
		$this->assertSame( 400, rest_do_request( $this->webhook_request( $event, null, 'whsec_wrong' ) )->get_status() );
		$unsigned = new WP_REST_Request( 'POST', '/publiclms/v1/webhooks/stripe' );
		$unsigned->set_body( (string) wp_json_encode( $event ) );
		$this->assertSame( 400, rest_do_request( $unsigned )->get_status() );
	}

	public function test_verify_signature_accepts_any_v1_and_rejects_bad_headers() {
		$body = '{"id":"evt_1"}';
		$t    = 1700000000;
		$good = hash_hmac( 'sha256', $t . '.' . $body, 'whsec_x' );
		$this->assertTrue( StripeGateway::verify_signature( $body, "t=$t,v1=deadbeef,v1=$good", 'whsec_x', $t + 10 ) );
		$this->assertFalse( StripeGateway::verify_signature( $body, "t=$t,v0=$good", 'whsec_x', $t ) );
		$this->assertFalse( StripeGateway::verify_signature( $body, "v1=$good", 'whsec_x', $t ) );
		$this->assertFalse( StripeGateway::verify_signature( $body, "t=$t,v1=$good", 'whsec_x', $t + 301 ) );
	}

	public function test_completed_webhook_enrols_once() {
		$placed = $this->stripe_order();
		add_filter( 'publiclms_rest_controllers', array( $this, 'use_test_service' ) );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild the REST server with the test service.
		$event                     = array(
			'type' => 'checkout.session.completed',
			'data' => array( 'object' => $this->session() ),
		);
		$this->assertSame( 200, rest_do_request( $this->webhook_request( $event ) )->get_status() );
		$this->assertSame( 200, rest_do_request( $this->webhook_request( $event ) )->get_status(), 'Stripe retries are harmless.' );
		$this->assertSame( OrderRepository::STATUS_COMPLETED, $this->orders->find( (int) $placed['order']['id'] )['status'] );
		$this->assertCount( 1, $this->orders->transactions( (int) $placed['order']['id'] ) );
		remove_filter( 'publiclms_rest_controllers', array( $this, 'use_test_service' ) );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- As above.
	}

	public function test_async_payment_waits_then_completes_or_fails() {
		$placed = $this->stripe_order();
		$this->service->apply( $this->stripe, $this->stripe->webhook( $this->webhook_request( array( 'type' => 'checkout.session.completed', 'data' => array( 'object' => $this->session( 'unpaid' ) ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( OrderRepository::STATUS_PENDING, $this->orders->find( (int) $placed['order']['id'] )['status'] );
		$this->service->apply( $this->stripe, $this->stripe->webhook( $this->webhook_request( array( 'type' => 'checkout.session.async_payment_failed', 'data' => array( 'object' => $this->session( 'unpaid' ) ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( OrderRepository::STATUS_FAILED, $this->orders->find( (int) $placed['order']['id'] )['status'] );
		$this->service->apply( $this->stripe, $this->stripe->webhook( $this->webhook_request( array( 'type' => 'checkout.session.async_payment_succeeded', 'data' => array( 'object' => $this->session() ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( OrderRepository::STATUS_COMPLETED, $this->orders->find( (int) $placed['order']['id'] )['status'] );
	}

	public function test_expired_session_cancels_the_order() {
		$placed = $this->stripe_order();
		$this->service->apply( $this->stripe, $this->stripe->webhook( $this->webhook_request( array( 'type' => 'checkout.session.expired', 'data' => array( 'object' => $this->session( 'unpaid' ) ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( OrderRepository::STATUS_CANCELLED, $this->orders->find( (int) $placed['order']['id'] )['status'] );
	}

	public function test_refund_from_the_admin_and_the_echoed_webhook() {
		$placed = $this->stripe_order();
		$this->pay();
		$this->http->on(
			'POST https://api.stripe.com/v1/refunds',
			200,
			array(
				'id'             => 're_test_1',
				'status'         => 'succeeded',
				'amount'         => 4950,
				'currency'       => 'eur',
				'payment_intent' => 'pi_test_1',
			)
		);
		$refunded = $this->service->refund( (int) $placed['order']['id'] );
		$this->assertSame( OrderRepository::STATUS_REFUNDED, $refunded['status'] );
		$this->assertFalse( $this->enrollments->is_enrolled( $placed['user'], $placed['course'] ) );
		parse_str( (string) $this->http->last( 'POST https://api.stripe.com/v1/refunds' )['body'], $params );
		$this->assertSame( 'pi_test_1', $params['payment_intent'] );
		$this->assertArrayNotHasKey( 'amount', $params, 'A full refund names no amount.' );

		$this->http->on( 'GET https://api.stripe.com/v1/refunds', 200, array( 'data' => array( array( 'id' => 're_test_1', 'status' => 'succeeded', 'amount' => 4950, 'currency' => 'eur', 'payment_intent' => 'pi_test_1' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->service->apply( $this->stripe, $this->stripe->webhook( $this->webhook_request( array( 'type' => 'charge.refunded', 'data' => array( 'object' => array( 'id' => 'ch_1', 'payment_intent' => 'pi_test_1' ) ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$refunds = array_filter(
			$this->orders->transactions( (int) $placed['order']['id'] ),
			static function ( $txn ) {
				return 'refund' === $txn['type'];
			}
		);
		$this->assertCount( 1, $refunds, 'The webhook for our own refund adds nothing.' );
	}

	public function test_dashboard_refunds_adding_up_to_the_total_refund_the_order() {
		$placed = $this->stripe_order();
		$this->pay();
		$this->http->on(
			'GET https://api.stripe.com/v1/refunds',
			200,
			array(
				'data' => array(
					array( 'id' => 're_a', 'status' => 'succeeded', 'amount' => 2000, 'currency' => 'eur', 'payment_intent' => 'pi_test_1' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'id' => 're_b', 'status' => 'succeeded', 'amount' => 2950, 'currency' => 'eur', 'payment_intent' => 'pi_test_1' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				),
			)
		);
		$this->service->apply( $this->stripe, $this->stripe->webhook( $this->webhook_request( array( 'type' => 'charge.refunded', 'data' => array( 'object' => array( 'id' => 'ch_1', 'payment_intent' => 'pi_test_1' ) ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( OrderRepository::STATUS_REFUNDED, $this->orders->find( (int) $placed['order']['id'] )['status'] );
	}

	public function test_webhook_without_a_secret_is_refused() {
		update_option( 'publiclms_gateway_stripe', array( 'enabled' => true, 'secret_key' => self::SECRET ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$result = $this->stripe->webhook( $this->webhook_request( array( 'type' => 'ping', 'data' => array( 'object' => array() ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertWPError( $result );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_mode_comes_from_the_key() {
		$this->assertSame( 'test', $this->stripe->mode() );
		update_option( 'publiclms_gateway_stripe', array( 'secret_key' => 'sk_live_x' ) );
		$this->assertSame( 'live', $this->stripe->mode() );
		update_option( 'publiclms_gateway_stripe', array() );
		$this->assertSame( '', $this->stripe->mode() );
		$this->assertFalse( $this->stripe->is_configured() );
	}

	/**
	 * Swaps the webhook controller's service for the test one.
	 *
	 * @param array<int, \WP_REST_Controller> $controllers Controllers.
	 * @return array<int, \WP_REST_Controller>
	 */
	public function use_test_service( $controllers ): array {
		foreach ( $controllers as $index => $controller ) {
			if ( $controller instanceof \PublicLMS\Rest\WebhooksController ) {
				$controllers[ $index ] = new \PublicLMS\Rest\WebhooksController( $this->service );
			}
		}
		return $controllers;
	}
}
