<?php
/**
 * Stripe.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce\Gateways;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe Checkout, hosted by Stripe (ADR-011, ADR-048).
 *
 * The order becomes a Checkout Session in payment mode with dynamic payment
 * methods: the request names no payment method types, so the owner chooses
 * them in the Stripe Dashboard and buyers see what works where they are.
 * Every request pins the API version, so a Dashboard upgrade cannot change
 * what the plugin receives. The buyer's return is checked against the
 * session; webhooks (checkout.session.*, refunds) are verified with the
 * Stripe-Signature header and a five-minute timestamp tolerance.
 */
final class StripeGateway extends AbstractGateway {

	/**
	 * Stripe API version every request pins.
	 */
	const API_VERSION = '2026-10-28.endive';

	/**
	 * API base.
	 */
	const API = 'https://api.stripe.com/v1/';

	/**
	 * Seconds a webhook's timestamp may differ from the server clock.
	 */
	const TOLERANCE = 300;

	/**
	 * Stripe's zero-decimal currencies: amounts are sent in whole units.
	 */
	const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/**
	 * Zero-decimal in ISO but sent as two-decimal values ending in 00.
	 */
	const WHOLE_TWO_DECIMAL = array( 'ISK', 'UGX' );

	/**
	 * Three-decimal currencies: sent in thousandths, last digit 0.
	 */
	const THREE_DECIMAL = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'stripe';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function admin_title(): string {
		return 'Stripe';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function title(): string {
		$title = trim( (string) $this->setting( 'title' ) );
		return '' === $title ? __( 'Pay online', 'public-lms' ) : $title;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'You finish paying on Stripe’s secure page, with a card, a wallet or another method available where you are.', 'public-lms' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function declared_fields(): array {
		return array(
			'title'           => array(
				'label'   => __( 'Name at checkout', 'public-lms' ),
				'type'    => 'text',
				'default' => '',
				'help'    => __( 'Leave empty for "Pay online".', 'public-lms' ),
			),
			'publishable_key' => array(
				'label'    => __( 'Publishable key', 'public-lms' ),
				'type'     => 'text',
				'constant' => 'PUBLICLMS_STRIPE_PUBLISHABLE_KEY',
				'help'     => __( 'Starts with pk_test_ or pk_live_. Stripe Dashboard → Developers → API keys.', 'public-lms' ),
			),
			'secret_key'      => array(
				'label'    => __( 'Secret key', 'public-lms' ),
				'type'     => 'secret',
				'constant' => 'PUBLICLMS_STRIPE_SECRET_KEY',
				'help'     => __( 'Starts with sk_ or rk_ (a restricted key with Checkout Sessions and Refunds write access is enough).', 'public-lms' ),
			),
			'webhook_secret'  => array(
				'label'    => __( 'Webhook signing secret', 'public-lms' ),
				'type'     => 'secret',
				'constant' => 'PUBLICLMS_STRIPE_WEBHOOK_SECRET',
				'help'     => __( 'Starts with whsec_. Add the webhook URL below in Stripe with the events checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired and charge.refunded.', 'public-lms' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->secret();
	}

	/**
	 * Returns "test" or "live" from the secret key, or '' when there is none.
	 *
	 * @return string
	 */
	public function mode(): string {
		$secret = $this->secret();
		if ( '' === $secret ) {
			return '';
		}
		return 1 === preg_match( '/^(sk|rk)_test_/', $secret ) ? 'test' : 'live';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Stripe takes more than 135 currencies, which differ by account; the
	 * session request reports one the account cannot take.
	 *
	 * @param string $currency Currency.
	 * @return bool
	 */
	public function supports_currency( string $currency ): bool {
		return '' !== $currency;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $feature Feature.
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return in_array( $feature, array( 'refunds', 'webhooks' ), true );
	}

	/**
	 * Converts an amount to Stripe's integer amount for a currency.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency ISO 4217 code.
	 * @return int
	 */
	public static function to_minor( float $amount, string $currency ): int {
		$currency = strtoupper( $currency );
		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return (int) round( $amount );
		}
		if ( in_array( $currency, self::WHOLE_TWO_DECIMAL, true ) ) {
			return (int) round( $amount ) * 100;
		}
		if ( in_array( $currency, self::THREE_DECIMAL, true ) ) {
			return (int) round( $amount * 100 ) * 10;
		}
		return (int) round( $amount * 100 );
	}

	/**
	 * Converts Stripe's integer amount back to an amount.
	 *
	 * @param int    $minor    Stripe amount.
	 * @param string $currency ISO 4217 code.
	 * @return float
	 */
	public static function from_minor( int $minor, string $currency ): float {
		$currency = strtoupper( $currency );
		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return (float) $minor;
		}
		if ( in_array( $currency, self::THREE_DECIMAL, true ) ) {
			return $minor / 1000;
		}
		return $minor / 100;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed>             $order      Order.
	 * @param array<int, array<string, mixed>> $items      Items.
	 * @param string                           $return_url Order view.
	 * @param string                           $cancel_url Checkout, with the cancel marker.
	 * @return array{redirect: string, gateway_order_id: string, status: string}|WP_Error
	 */
	public function start( array $order, array $items, string $return_url, string $cancel_url ) {
		$currency = strtoupper( (string) $order['currency'] );
		$total    = self::to_minor( (float) $order['total'], $currency );
		if ( abs( self::from_minor( $total, $currency ) - (float) $order['total'] ) > 0.0001 ) {
			return new WP_Error(
				'publiclms_stripe_amount',
				/* translators: %s: currency code. */
				sprintf( __( 'Stripe takes %s amounts in steps of 0.010. Change the price so its last digit is 0.', 'public-lms' ), $currency ),
				array( 'status' => 400 )
			);
		}

		$lines = array();
		$sum   = 0;
		foreach ( $items as $item ) {
			$amount = self::to_minor( (float) $item['total'], $currency );
			if ( $amount <= 0 ) {
				continue;
			}
			$sum    += $amount;
			$lines[] = array(
				'quantity'   => 1,
				'price_data' => array(
					'currency'     => strtolower( $currency ),
					'unit_amount'  => $amount,
					'product_data' => array(
						'name' => '' === trim( (string) $item['name'] ) ? __( 'Course', 'public-lms' ) : (string) $item['name'],
					),
				),
			);
		}
		if ( empty( $lines ) ) {
			return new WP_Error( 'publiclms_stripe_empty', __( 'There is nothing to pay.', 'public-lms' ), array( 'status' => 400 ) );
		}
		if ( $sum !== $total ) {
			$last = count( $lines ) - 1;
			$lines[ $last ]['price_data']['unit_amount'] += $total - $sum;
		}

		$metadata = array(
			'order_key' => (string) $order['order_key'],
			'order_id'  => (string) $order['id'],
			'site'      => home_url( '/' ),
		);
		$params   = array(
			'mode'                => 'payment',
			'line_items'          => $lines,
			'success_url'         => $return_url . ( false === strpos( $return_url, '?' ) ? '?' : '&' ) . 'session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => $cancel_url,
			'client_reference_id' => (string) $order['order_key'],
			'locale'              => 'auto',
			'metadata'            => $metadata,
			'payment_intent_data' => array( 'metadata' => $metadata ),
		);
		if ( is_email( (string) $order['billing_email'] ) ) {
			$params['customer_email'] = (string) $order['billing_email'];
		}

		/**
		 * Filters the Checkout Session parameters before they go to Stripe.
		 *
		 * Do not add payment_method_types: the account's Dashboard settings decide.
		 *
		 * @param array<string, mixed> $params Parameters.
		 * @param array<string, mixed> $order  Order.
		 */
		$params  = (array) apply_filters( 'publiclms_stripe_session_params', $params, $order );
		$session = $this->api( 'POST', 'checkout/sessions', $params, 'publiclms-session-' . $order['order_key'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		if ( empty( $session['id'] ) || empty( $session['url'] ) ) {
			return new WP_Error( 'publiclms_stripe_session', __( 'Stripe did not return a payment page.', 'public-lms' ), array( 'status' => 502 ) );
		}
		return array(
			'redirect'         => (string) $session['url'],
			'gateway_order_id' => (string) $session['id'],
			'status'           => 'pending',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed>  $order  Order.
	 * @param array<string, string> $params Return arguments.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function confirm( array $order, array $params ) {
		$session_id = (string) ( $params['session_id'] ?? '' );
		if ( '' === $session_id || $session_id !== (string) $order['gateway_order_id'] ) {
			return array();
		}
		$session = $this->api( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$result = $this->session_result( $session, 'checkout.session.completed' );
		return null === $result ? array() : array( $result );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $order   Order.
	 * @param array<string, mixed> $payment Payment transaction (the PaymentIntent ID).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function refund( array $order, array $payment ) {
		$refund = $this->api(
			'POST',
			'refunds',
			array(
				'payment_intent' => (string) $payment['gateway_transaction_id'],
				'metadata'       => array(
					'order_key' => (string) $order['order_key'],
					'order_id'  => (string) $order['id'],
				),
			),
			// A fresh key per attempt so a failed refund can be retried; Stripe refuses to refund more than was paid.
			'publiclms-refund-' . $order['order_key'] . '-' . wp_generate_uuid4()
		);
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		if ( in_array( (string) ( $refund['status'] ?? '' ), array( 'failed', 'canceled' ), true ) ) {
			return new WP_Error( 'publiclms_stripe_refund', __( 'Stripe could not refund this payment. See the payment in the Stripe Dashboard.', 'public-lms' ), array( 'status' => 502 ) );
		}
		$result             = $this->refund_result( $refund );
		$result['order_id'] = (int) $order['id'];
		return array( $result );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function webhook( $request ) {
		$secret = (string) $this->setting( 'webhook_secret' );
		if ( '' === $secret ) {
			return new WP_Error( 'publiclms_webhook_not_configured', __( 'The Stripe webhook signing secret is not set.', 'public-lms' ), array( 'status' => 503 ) );
		}
		$body = (string) $request->get_body();
		if ( ! self::verify_signature( $body, (string) $request->get_header( 'stripe_signature' ), $secret, time() ) ) {
			return new WP_Error( 'publiclms_webhook_signature', __( 'The webhook signature is not valid.', 'public-lms' ), array( 'status' => 400 ) );
		}
		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || ! isset( $event['type'], $event['data']['object'] ) || ! is_array( $event['data']['object'] ) ) {
			return new WP_Error( 'publiclms_webhook_payload', __( 'The webhook body is not a Stripe event.', 'public-lms' ), array( 'status' => 400 ) );
		}
		$object = $event['data']['object'];
		switch ( (string) $event['type'] ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
			case 'checkout.session.async_payment_failed':
			case 'checkout.session.expired':
				$result = $this->session_result( $object, (string) $event['type'] );
				return null === $result ? array() : array( $result );

			case 'refund.created':
			case 'refund.updated':
				return 'succeeded' === ( $object['status'] ?? '' ) || 'pending' === ( $object['status'] ?? '' ) ? array( $this->refund_result( $object ) ) : array();

			case 'charge.refunded':
				$intent = (string) ( is_array( $object['payment_intent'] ?? null ) ? ( $object['payment_intent']['id'] ?? '' ) : ( $object['payment_intent'] ?? '' ) );
				if ( '' === $intent ) {
					return array();
				}
				$list = $this->api( 'GET', 'refunds?' . http_build_query( array( 'payment_intent' => $intent, 'limit' => 100 ), '', '&' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				if ( is_wp_error( $list ) ) {
					return $list;
				}
				$results = array();
				foreach ( (array) ( $list['data'] ?? array() ) as $refund ) {
					if ( is_array( $refund ) && in_array( (string) ( $refund['status'] ?? '' ), array( 'succeeded', 'pending' ), true ) ) {
						$results[] = $this->refund_result( $refund );
					}
				}
				return $results;
		}
		return array();
	}

	/**
	 * Verifies a Stripe-Signature header: HMAC-SHA256 of "{t}.{body}" with any v1 signature, within the tolerance.
	 *
	 * @param string $body   Raw body.
	 * @param string $header Stripe-Signature header.
	 * @param string $secret Webhook signing secret.
	 * @param int    $now    Current time.
	 * @return bool
	 */
	public static function verify_signature( string $body, string $header, string $secret, int $now ): bool {
		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] && ctype_digit( $pair[1] ) ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}
		if ( $timestamp <= 0 || empty( $signatures ) || abs( $now - $timestamp ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Translates a Checkout Session into a payment result.
	 *
	 * @param array<string, mixed> $session Session object.
	 * @param string               $type    Event type that carried it.
	 * @return array<string, mixed>|null
	 */
	private function session_result( array $session, string $type ): ?array {
		$id       = (string) ( $session['id'] ?? '' );
		$currency = strtoupper( (string) ( $session['currency'] ?? '' ) );
		$intent   = $session['payment_intent'] ?? '';
		$intent   = is_array( $intent ) ? (string) ( $intent['id'] ?? '' ) : (string) $intent;
		$details  = array(
			'gateway_order_id' => $id,
			'transaction_id'   => '' === $intent ? $id : $intent,
			'amount'           => self::from_minor( (int) ( $session['amount_total'] ?? 0 ), $currency ),
			'currency'         => $currency,
		);
		if ( '' === $id ) {
			return null;
		}
		if ( 'checkout.session.expired' === $type ) {
			return $this->result( 'cancelled', $details );
		}
		if ( 'checkout.session.async_payment_failed' === $type ) {
			return $this->result( 'failed', $details );
		}
		$status = (string) ( $session['payment_status'] ?? '' );
		if ( 'paid' === $status ) {
			return $this->result( 'paid', $details );
		}
		if ( 'expired' === ( $session['status'] ?? '' ) ) {
			return $this->result( 'cancelled', $details );
		}
		if ( 'complete' === ( $session['status'] ?? '' ) ) {
			$details['message'] = __( 'The buyer finished on Stripe; the payment method confirms later.', 'public-lms' );
			return $this->result( 'pending', $details );
		}
		return null;
	}

	/**
	 * Translates a Refund object into a payment result.
	 *
	 * @param array<string, mixed> $refund Refund object.
	 * @return array<string, mixed>
	 */
	private function refund_result( array $refund ): array {
		$currency = strtoupper( (string) ( $refund['currency'] ?? '' ) );
		$intent   = $refund['payment_intent'] ?? '';
		return $this->result(
			'refunded',
			array(
				'payment_id'     => is_array( $intent ) ? (string) ( $intent['id'] ?? '' ) : (string) $intent,
				'transaction_id' => (string) ( $refund['id'] ?? '' ),
				'amount'         => self::from_minor( (int) ( $refund['amount'] ?? 0 ), $currency ),
				'currency'       => $currency,
				'full'           => false,
			)
		);
	}

	/**
	 * Calls the Stripe API with the pinned version.
	 *
	 * @param string               $method          GET or POST.
	 * @param string               $path            Path under /v1/.
	 * @param array<string, mixed> $params          Form parameters for POST.
	 * @param string               $idempotency_key Idempotency key for POST, or ''.
	 * @return array<string, mixed>|WP_Error
	 */
	private function api( string $method, string $path, array $params = array(), string $idempotency_key = '' ) {
		$secret = $this->secret();
		if ( '' === $secret ) {
			return new WP_Error( 'publiclms_stripe_not_configured', __( 'Stripe is not set up.', 'public-lms' ), array( 'status' => 503 ) );
		}
		$headers = array(
			'Authorization'  => 'Bearer ' . $secret,
			'Stripe-Version' => self::API_VERSION,
		);
		$body    = null;
		if ( 'POST' === $method ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			if ( '' !== $idempotency_key ) {
				$headers['Idempotency-Key'] = $idempotency_key;
			}
			$body = http_build_query( $params, '', '&' );
		}
		$response = $this->http( $method, self::API . $path, $headers, $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response['status'] >= 400 ) {
			$message = (string) ( $response['body']['error']['message'] ?? '' );
			return new WP_Error(
				'publiclms_stripe_error',
				'' === $message ? __( 'Stripe refused the request.', 'public-lms' ) : sprintf( /* translators: %s: Stripe's error message. */ __( 'Stripe: %s', 'public-lms' ), $message ),
				array( 'status' => $response['status'] >= 500 ? 502 : 400 )
			);
		}
		return $response['body'];
	}

	/**
	 * Returns the secret key.
	 *
	 * @return string
	 */
	private function secret(): string {
		$secret = $this->setting( 'secret_key' );
		return is_string( $secret ) ? trim( $secret ) : '';
	}
}
