<?php
/**
 * PayPal.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce\Gateways;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * PayPal Checkout with the Orders v2 API (ADR-011, ADR-049).
 *
 * The order becomes a PayPal order (intent CAPTURE, no shipping, "Pay
 * now"); the buyer approves it on PayPal and comes back to the order page,
 * where the site captures it. The return alone completes the order, so a
 * site PayPal cannot reach (no public HTTPS address) still works; webhooks,
 * verified by PayPal's verify-webhook-signature API, add asynchronous
 * captures, refunds made at PayPal and approvals whose buyer never came
 * back.
 */
final class PayPalGateway extends AbstractGateway {

	const LIVE_API    = 'https://api-m.paypal.com';
	const SANDBOX_API = 'https://api-m.sandbox.paypal.com';

	/**
	 * Currencies PayPal takes (BRL, CNY and MYR only for accounts in those countries).
	 */
	const CURRENCIES = array( 'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD' );

	/**
	 * Currencies PayPal takes without decimals.
	 */
	const NO_DECIMALS = array( 'HUF', 'JPY', 'TWD' );

	/**
	 * Webhook events the plugin acts on.
	 */
	const EVENTS = array( 'CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED' );

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'paypal';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function admin_title(): string {
		return 'PayPal';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function title(): string {
		$title = trim( (string) $this->setting( 'title' ) );
		return '' === $title ? 'PayPal' : $title;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'You approve the payment on PayPal, then come back here to finish.', 'public-lms' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function declared_fields(): array {
		return array(
			'title'         => array(
				'label'   => __( 'Name at checkout', 'public-lms' ),
				'type'    => 'text',
				'default' => '',
				'help'    => __( 'Leave empty for "PayPal".', 'public-lms' ),
			),
			'sandbox'       => array(
				'label'    => __( 'Sandbox (test payments only)', 'public-lms' ),
				'type'     => 'toggle',
				'default'  => true,
				'constant' => 'PUBLICLMS_PAYPAL_SANDBOX',
				'help'     => __( 'Switch off when you enter live credentials.', 'public-lms' ),
			),
			'client_id'     => array(
				'label'    => __( 'Client ID', 'public-lms' ),
				'type'     => 'text',
				'constant' => 'PUBLICLMS_PAYPAL_CLIENT_ID',
				'help'     => __( 'From a REST app in the PayPal Developer Dashboard → Apps & Credentials.', 'public-lms' ),
			),
			'client_secret' => array(
				'label'    => __( 'Secret', 'public-lms' ),
				'type'     => 'secret',
				'constant' => 'PUBLICLMS_PAYPAL_CLIENT_SECRET',
			),
			'webhook_id'    => array(
				'label'    => __( 'Webhook ID', 'public-lms' ),
				'type'     => 'text',
				'constant' => 'PUBLICLMS_PAYPAL_WEBHOOK_ID',
				'help'     => __( 'Optional. Payments complete when buyers return without it. To receive refunds made at PayPal, add the webhook URL below to your app (it needs a public HTTPS address), then paste the webhook\'s ID here.', 'public-lms' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->string_setting( 'client_id' ) && '' !== $this->string_setting( 'client_secret' );
	}

	/**
	 * Returns "test" (sandbox) or "live", or '' when not set up.
	 *
	 * @return string
	 */
	public function mode(): string {
		if ( ! $this->is_configured() ) {
			return '';
		}
		return $this->sandbox() ? 'test' : 'live';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $currency Currency.
	 * @return bool
	 */
	public function supports_currency( string $currency ): bool {
		/**
		 * Filters the currencies the PayPal gateway offers itself for.
		 *
		 * @param array<int, string> $currencies ISO 4217 codes.
		 */
		return in_array( strtoupper( $currency ), (array) apply_filters( 'publiclms_paypal_currencies', self::CURRENCIES ), true );
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
	 * Formats an amount as PayPal's value string.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency ISO 4217 code.
	 * @return string
	 */
	public static function value( float $amount, string $currency ): string {
		return number_format( $amount, in_array( strtoupper( $currency ), self::NO_DECIMALS, true ) ? 0 : 2, '.', '' );
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
		foreach ( array( (float) $order['total'], (float) $order['subtotal'], (float) $order['discount_total'] ) as $amount ) {
			if ( abs( (float) self::value( $amount, $currency ) - $amount ) > 0.0001 ) {
				return new WP_Error(
					'publiclms_paypal_amount',
					/* translators: %s: currency code. */
					sprintf( __( 'PayPal takes %s amounts in whole units only. Change the price or the coupon so there are no decimals.', 'public-lms' ), $currency ),
					array( 'status' => 400 )
				);
			}
		}
		$site  = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$money = static function ( float $amount ) use ( $currency ): array {
			return array(
				'currency_code' => $currency,
				'value'         => self::value( $amount, $currency ),
			);
		};
		$lines = array();
		foreach ( $items as $item ) {
			$lines[] = array(
				'name'        => mb_substr( '' === trim( (string) $item['name'] ) ? __( 'Course', 'public-lms' ) : (string) $item['name'], 0, 127 ),
				'quantity'    => '1',
				'unit_amount' => $money( (float) $item['subtotal'] ),
				'category'    => 'DIGITAL_GOODS',
			);
		}
		$breakdown = array( 'item_total' => $money( (float) $order['subtotal'] ) );
		if ( (float) $order['discount_total'] > 0 ) {
			$breakdown['discount'] = $money( (float) $order['discount_total'] );
		}
		$body = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'reference_id' => (string) $order['order_key'],
					'custom_id'    => (string) $order['order_key'],
					/* translators: 1: site name, 2: order number. */
					'description'  => mb_substr( sprintf( __( '%1$s order #%2$d', 'public-lms' ), $site, (int) $order['id'] ), 0, 127 ),
					'amount'       => array_merge( $money( (float) $order['total'] ), array( 'breakdown' => $breakdown ) ),
					'items'        => $lines,
				),
			),
			'payment_source' => array(
				'paypal' => array(
					'experience_context' => array(
						'brand_name'          => mb_substr( '' === $site ? home_url() : $site, 0, 127 ),
						'shipping_preference' => 'NO_SHIPPING',
						'user_action'         => 'PAY_NOW',
						'return_url'          => $return_url,
						'cancel_url'          => $cancel_url,
					),
				),
			),
		);

		/**
		 * Filters the PayPal order before it is created.
		 *
		 * @param array<string, mixed> $body  Orders v2 request body.
		 * @param array<string, mixed> $order Order.
		 */
		$body     = (array) apply_filters( 'publiclms_paypal_order_body', $body, $order );
		$response = $this->api( 'POST', '/v2/checkout/orders', (string) wp_json_encode( $body ), 'publiclms-order-' . $order['order_key'] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$approve = '';
		foreach ( (array) ( $response['links'] ?? array() ) as $link ) {
			if ( is_array( $link ) && in_array( $link['rel'] ?? '', array( 'payer-action', 'approve' ), true ) ) {
				$approve = (string) $link['href'];
				break;
			}
		}
		if ( empty( $response['id'] ) || '' === $approve ) {
			return new WP_Error( 'publiclms_paypal_order', __( 'PayPal did not return an approval page.', 'public-lms' ), array( 'status' => 502 ) );
		}
		return array(
			'redirect'         => $approve,
			'gateway_order_id' => (string) $response['id'],
			'status'           => 'pending',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Captures the approved PayPal order when the buyer comes back with its token.
	 *
	 * @param array<string, mixed>  $order  Order.
	 * @param array<string, string> $params Return arguments (token, PayerID).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function confirm( array $order, array $params ) {
		$token = (string) ( $params['token'] ?? '' );
		if ( '' === $token || $token !== (string) $order['gateway_order_id'] ) {
			return array();
		}
		return $this->capture( $token, (string) $order['order_key'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $order   Order.
	 * @param array<string, mixed> $payment Payment transaction (the capture ID).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function refund( array $order, array $payment ) {
		$capture  = (string) $payment['gateway_transaction_id'];
		$response = $this->api( 'POST', '/v2/payments/captures/' . rawurlencode( $capture ) . '/refund', '{}', 'publiclms-refund-' . $order['order_key'] . '-' . wp_generate_uuid4() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! in_array( (string) ( $response['status'] ?? '' ), array( 'COMPLETED', 'PENDING' ), true ) ) {
			return new WP_Error( 'publiclms_paypal_refund', __( 'PayPal could not refund this payment. See the transaction in your PayPal account.', 'public-lms' ), array( 'status' => 502 ) );
		}
		$amount = isset( $response['amount']['value'] ) ? (float) $response['amount']['value'] : (float) $order['total'];
		return array(
			$this->result(
				'refunded',
				array(
					'order_id'       => (int) $order['id'],
					'payment_id'     => $capture,
					'transaction_id' => (string) ( $response['id'] ?? '' ),
					'amount'         => $amount,
					'currency'       => (string) ( $response['amount']['currency_code'] ?? $order['currency'] ),
					'full'           => true,
				)
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function webhook( $request ) {
		$webhook_id = $this->string_setting( 'webhook_id' );
		if ( '' === $webhook_id || ! $this->is_configured() ) {
			return new WP_Error( 'publiclms_webhook_not_configured', __( 'The PayPal webhook ID is not set.', 'public-lms' ), array( 'status' => 503 ) );
		}
		$raw   = (string) $request->get_body();
		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) || ! isset( $event['event_type'] ) || ! is_array( $event['resource'] ?? null ) ) {
			return new WP_Error( 'publiclms_webhook_payload', __( 'The webhook body is not a PayPal event.', 'public-lms' ), array( 'status' => 400 ) );
		}
		$headers = array();
		foreach ( array( 'auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time' ) as $name ) {
			$headers[ $name ] = (string) $request->get_header( 'paypal_' . $name );
			if ( '' === $headers[ $name ] ) {
				return new WP_Error( 'publiclms_webhook_signature', __( 'The webhook signature is not valid.', 'public-lms' ), array( 'status' => 400 ) );
			}
		}
		// The event goes back byte for byte: re-encoding it can change the signed text.
		$check = '{' . substr( (string) wp_json_encode( array_merge( $headers, array( 'webhook_id' => $webhook_id ) ) ), 1, -1 ) . ',"webhook_event":' . $raw . '}';
		$valid = $this->api( 'POST', '/v1/notifications/verify-webhook-signature', $check );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( 'SUCCESS' !== ( $valid['verification_status'] ?? '' ) ) {
			return new WP_Error( 'publiclms_webhook_signature', __( 'The webhook signature is not valid.', 'public-lms' ), array( 'status' => 400 ) );
		}

		$type     = (string) $event['event_type'];
		$resource = $event['resource'];
		switch ( $type ) {
			case 'CHECKOUT.ORDER.APPROVED':
				$id = (string) ( $resource['id'] ?? '' );
				return '' === $id ? array() : $this->capture( $id, (string) ( $resource['purchase_units'][0]['custom_id'] ?? $id ) );

			case 'PAYMENT.CAPTURE.COMPLETED':
			case 'PAYMENT.CAPTURE.PENDING':
			case 'PAYMENT.CAPTURE.DENIED':
			case 'PAYMENT.CAPTURE.DECLINED':
				$result = $this->capture_result( $resource, (string) ( $resource['supplementary_data']['related_ids']['order_id'] ?? '' ) );
				return null === $result ? array() : array( $result );

			case 'PAYMENT.CAPTURE.REFUNDED':
			case 'PAYMENT.CAPTURE.REVERSED':
				$capture = '';
				foreach ( (array) ( $resource['links'] ?? array() ) as $link ) {
					if ( is_array( $link ) && 'up' === ( $link['rel'] ?? '' ) && preg_match( '#/captures/([^/?]+)#', (string) ( $link['href'] ?? '' ), $match ) ) {
						$capture = $match[1];
					}
				}
				if ( 'PAYMENT.CAPTURE.REVERSED' === $type ) {
					$capture = (string) ( $resource['id'] ?? $capture );
				}
				return array(
					$this->result(
						'refunded',
						array(
							'payment_id'     => $capture,
							'transaction_id' => (string) ( $resource['id'] ?? '' ) . ( 'PAYMENT.CAPTURE.REVERSED' === $type ? ':reversed' : '' ),
							'amount'         => (float) ( $resource['amount']['value'] ?? 0 ),
							'currency'       => (string) ( $resource['amount']['currency_code'] ?? '' ),
							'full'           => 'PAYMENT.CAPTURE.REVERSED' === $type,
							'message'        => 'PAYMENT.CAPTURE.REVERSED' === $type ? __( 'PayPal reversed the payment (a dispute or chargeback).', 'public-lms' ) : '',
						)
					),
				);
		}
		return array();
	}

	/**
	 * Captures a PayPal order and translates the capture.
	 *
	 * @param string $paypal_order PayPal order ID.
	 * @param string $order_key    Our order key, for the request ID.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function capture( string $paypal_order, string $order_key ) {
		// A fresh request ID per attempt: PayPal replays a cached answer for a repeated ID, which would
		// repeat "not approved yet" after the buyer approves. PayPal itself refuses a second capture.
		$response = $this->api( 'POST', '/v2/checkout/orders/' . rawurlencode( $paypal_order ) . '/capture', '{}', 'publiclms-capture-' . $order_key . '-' . wp_generate_uuid4(), true );
		if ( is_wp_error( $response ) ) {
			$issue = (string) ( $response->get_error_data()['issue'] ?? '' );
			if ( 'ORDER_ALREADY_CAPTURED' === $issue ) {
				$response = $this->api( 'GET', '/v2/checkout/orders/' . rawurlencode( $paypal_order ) );
			} elseif ( in_array( $issue, array( 'ORDER_NOT_APPROVED', 'PAYER_ACTION_REQUIRED' ), true ) ) {
				return array();
			}
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}
		$capture = $response['purchase_units'][0]['payments']['captures'][0] ?? null;
		if ( ! is_array( $capture ) ) {
			return array();
		}
		$result = $this->capture_result( $capture, $paypal_order );
		return null === $result ? array() : array( $result );
	}

	/**
	 * Translates a capture into a payment result.
	 *
	 * @param array<string, mixed> $capture      Capture object.
	 * @param string               $paypal_order PayPal order ID.
	 * @return array<string, mixed>|null
	 */
	private function capture_result( array $capture, string $paypal_order ): ?array {
		if ( '' === $paypal_order || empty( $capture['id'] ) ) {
			return null;
		}
		$details = array(
			'gateway_order_id' => $paypal_order,
			'transaction_id'   => (string) $capture['id'],
			'amount'           => (float) ( $capture['amount']['value'] ?? 0 ),
			'currency'         => (string) ( $capture['amount']['currency_code'] ?? '' ),
		);
		switch ( (string) ( $capture['status'] ?? '' ) ) {
			case 'COMPLETED':
				return $this->result( 'paid', $details );
			case 'PENDING':
				$reason             = (string) ( $capture['status_details']['reason'] ?? '' );
				$details['message'] = '' === $reason
					? __( 'PayPal is holding the payment for review.', 'public-lms' )
					/* translators: %s: PayPal's reason code, such as PENDING_REVIEW. */
					: sprintf( __( 'PayPal is holding the payment (%s).', 'public-lms' ), $reason );
				return $this->result( 'pending', $details );
			case 'DECLINED':
			case 'DENIED':
			case 'FAILED':
				return $this->result( 'failed', $details );
		}
		return null;
	}

	/**
	 * Calls the PayPal REST API with a cached OAuth token.
	 *
	 * @param string $method     GET or POST.
	 * @param string $path       Path, for example /v2/checkout/orders.
	 * @param string $body       JSON body for POST.
	 * @param string $request_id PayPal-Request-Id for idempotent POSTs, or ''.
	 * @param bool   $retry      Whether a 401 may refresh the token once.
	 * @return array<string, mixed>|WP_Error
	 */
	private function api( string $method, string $path, string $body = '', string $request_id = '', bool $retry = true ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		);
		if ( '' !== $request_id ) {
			$headers['PayPal-Request-Id'] = $request_id;
		}
		$response = $this->http( $method, $this->base() . $path, $headers, 'POST' === $method ? $body : null );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 401 === $response['status'] && $retry ) {
			delete_transient( $this->token_key() );
			return $this->api( $method, $path, $body, $request_id, false );
		}
		if ( $response['status'] >= 400 ) {
			$issue   = (string) ( $response['body']['details'][0]['issue'] ?? '' );
			$message = (string) ( $response['body']['details'][0]['description'] ?? $response['body']['message'] ?? '' );
			return new WP_Error(
				'publiclms_paypal_error',
				'' === $message ? __( 'PayPal refused the request.', 'public-lms' ) : sprintf( /* translators: %s: PayPal's error message. */ __( 'PayPal: %s', 'public-lms' ), $message ),
				array(
					'status' => $response['status'] >= 500 ? 502 : 400,
					'issue'  => $issue,
				)
			);
		}
		return $response['body'];
	}

	/**
	 * Returns an OAuth access token, cached until shortly before it expires.
	 *
	 * @return string|WP_Error
	 */
	private function token() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'publiclms_paypal_not_configured', __( 'PayPal is not set up.', 'public-lms' ), array( 'status' => 503 ) );
		}
		$cached = get_transient( $this->token_key() );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$response = $this->http(
			'POST',
			$this->base() . '/v1/oauth2/token',
			array(
				'Authorization' => 'Basic ' . base64_encode( $this->string_setting( 'client_id' ) . ':' . $this->string_setting( 'client_secret' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'grant_type=client_credentials'
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$token = (string) ( $response['body']['access_token'] ?? '' );
		if ( $response['status'] >= 400 || '' === $token ) {
			return new WP_Error( 'publiclms_paypal_auth', __( 'PayPal did not accept the client ID and secret. Check them, and whether sandbox mode matches them.', 'public-lms' ), array( 'status' => 502 ) );
		}
		set_transient( $this->token_key(), $token, max( 60, (int) ( $response['body']['expires_in'] ?? 3600 ) - 120 ) );
		return $token;
	}

	/**
	 * Returns the transient key for the token, per credentials and mode.
	 *
	 * @return string
	 */
	private function token_key(): string {
		return 'publiclms_paypal_token_' . md5( $this->string_setting( 'client_id' ) . '|' . ( $this->sandbox() ? 'sandbox' : 'live' ) );
	}

	/**
	 * Returns the API base for the mode.
	 *
	 * @return string
	 */
	private function base(): string {
		return $this->sandbox() ? self::SANDBOX_API : self::LIVE_API;
	}

	/**
	 * Whether sandbox mode is on.
	 *
	 * @return bool
	 */
	private function sandbox(): bool {
		return (bool) $this->setting( 'sandbox' );
	}

	/**
	 * Returns a setting as a trimmed string.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function string_setting( string $key ): string {
		$value = $this->setting( $key );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
