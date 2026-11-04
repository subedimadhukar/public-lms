<?php
/**
 * Shared gateway settings and HTTP.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce\Gateways;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage with configuration precedence, and a JSON HTTP helper.
 *
 * Settings live in the option `publiclms_gateway_{id}`. A field may name a
 * constant; when that constant is defined (in wp-config.php, or wp-env's
 * config), it wins over the stored value, the admin shows the field as "set
 * in configuration" and cannot change it. Secret fields are write-only: the
 * admin learns whether one is set, never its value (ADR-047).
 */
abstract class AbstractGateway implements Gateway {

	/**
	 * Returns the fields the subclass declares. Each field: label, type
	 * (toggle, text, textarea, secret, select), and optional default,
	 * constant, help and options.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract protected function declared_fields(): array;

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array_merge(
			array(
				'enabled' => array(
					'label'   => __( 'Offer this payment method at checkout', 'public-lms' ),
					'type'    => 'toggle',
					'default' => false,
				),
			),
			$this->declared_fields()
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->setting( 'enabled' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function webhook( $request ) {
		return new WP_Error( 'publiclms_webhook_unsupported', __( 'This payment method takes no webhooks.', 'public-lms' ), array( 'status' => 404 ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed>  $order  Order.
	 * @param array<string, string> $params Return arguments.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function confirm( array $order, array $params ) {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $order   Order.
	 * @param array<string, mixed> $payment Payment.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function refund( array $order, array $payment ) {
		return new WP_Error( 'publiclms_refund_unsupported', __( 'This payment method cannot refund automatically. Return the money yourself, then mark the order refunded.', 'public-lms' ), array( 'status' => 400 ) );
	}

	/**
	 * Returns a setting: its constant when defined, else the stored value, else the default.
	 *
	 * @param string $key Field key.
	 * @return mixed
	 */
	public function setting( string $key ) {
		$fields = $this->fields();
		$field  = $fields[ $key ] ?? array();
		if ( $this->from_config( $key ) ) {
			return constant( (string) $field['constant'] );
		}
		$stored = get_option( $this->option_name(), array() );
		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}
		return $field['default'] ?? '';
	}

	/**
	 * Whether a field's constant is defined and not empty.
	 *
	 * @param string $key Field key.
	 * @return bool
	 */
	public function from_config( string $key ): bool {
		$fields   = $this->fields();
		$constant = (string) ( $fields[ $key ]['constant'] ?? '' );
		if ( '' === $constant || ! defined( $constant ) ) {
			return false;
		}
		$value = constant( $constant );
		return null !== $value && '' !== $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function public_settings(): array {
		$out = array();
		foreach ( $this->fields() as $key => $field ) {
			$value = $this->setting( $key );
			$entry = array(
				'from_config' => $this->from_config( $key ),
			);
			if ( 'secret' === $field['type'] ) {
				$entry['is_set'] = is_string( $value ) && '' !== $value;
				$entry['value']  = '';
			} elseif ( 'toggle' === $field['type'] ) {
				$entry['value'] = (bool) $value;
			} else {
				$entry['value'] = is_scalar( $value ) ? (string) $value : '';
			}
			$out[ $key ] = $entry;
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Secret fields: a non-empty string replaces the stored value, false
	 * removes it, anything else keeps it.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return void
	 */
	public function update_settings( array $values ): void {
		$stored = get_option( $this->option_name(), array() );
		$stored = is_array( $stored ) ? $stored : array();
		foreach ( $this->fields() as $key => $field ) {
			if ( ! array_key_exists( $key, $values ) || $this->from_config( $key ) ) {
				continue;
			}
			$value = $values[ $key ];
			switch ( $field['type'] ) {
				case 'toggle':
					$stored[ $key ] = (bool) $value;
					break;
				case 'secret':
					if ( false === $value ) {
						unset( $stored[ $key ] );
					} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
						$stored[ $key ] = trim( $value );
					}
					break;
				case 'textarea':
					$stored[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				case 'select':
					$options        = array_keys( (array) ( $field['options'] ?? array() ) );
					$stored[ $key ] = in_array( (string) $value, $options, true ) ? (string) $value : (string) ( $field['default'] ?? '' );
					break;
				default:
					$stored[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		update_option( $this->option_name(), $stored, false );
	}

	/**
	 * Returns the option that stores the settings.
	 *
	 * @return string
	 */
	public function option_name(): string {
		return 'publiclms_gateway_' . $this->id();
	}

	/**
	 * Returns the webhook URL the provider should call.
	 *
	 * @return string
	 */
	public function webhook_url(): string {
		return rest_url( 'publiclms/v1/webhooks/' . $this->id() );
	}

	/**
	 * Sends a request and decodes the JSON answer.
	 *
	 * Credentials travel only in the headers given here; nothing about the
	 * request or the answer is logged.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Body.
	 * @return array{status: int, body: array<string, mixed>}|WP_Error
	 */
	protected function http( string $method, string $url, array $headers, ?string $body = null ) {
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => 30,
				'redirection' => 0,
				'user-agent'  => 'PublicLMS/' . ( defined( 'PUBLICLMS_VERSION' ) ? PUBLICLMS_VERSION : '0' ) . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'publiclms_gateway_http', __( 'The payment provider could not be reached. Please try again.', 'public-lms' ), array( 'status' => 502 ) );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => is_array( $decoded ) ? $decoded : array(),
		);
	}

	/**
	 * Builds a payment result.
	 *
	 * @param string               $event   paid, pending, failed, cancelled or refunded.
	 * @param array<string, mixed> $details Other keys (order_id, gateway_order_id, payment_id, transaction_id, amount, currency, full, message).
	 * @return array<string, mixed>
	 */
	protected function result( string $event, array $details ): array {
		return array_merge(
			array(
				'event'            => $event,
				'order_id'         => 0,
				'gateway_order_id' => '',
				'payment_id'       => '',
				'transaction_id'   => '',
				'amount'           => 0.0,
				'currency'         => '',
				'full'             => true,
				'message'          => '',
			),
			$details
		);
	}
}
