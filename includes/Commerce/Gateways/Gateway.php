<?php
/**
 * Payment gateway contract.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce\Gateways;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * A way to pay for an order (ADR-011, ADR-047).
 *
 * Gateways only talk to their provider and translate what it says; they
 * never change orders or enrolments themselves. Every method that learns
 * about a payment returns payment results, arrays the order service
 * applies, so a webhook, the buyer's return and an admin action all take
 * the same path:
 *
 *     array(
 *         'event'            => 'paid' | 'pending' | 'failed' | 'cancelled' | 'refunded',
 *         'order_id'         => int,    // or
 *         'gateway_order_id' => string, // the provider's order or session ID
 *         'transaction_id'   => string, // payment, capture or refund ID
 *         'amount'           => float,
 *         'currency'         => string,
 *         'full'             => bool,   // refunds: the whole order
 *         'message'          => string, // shown in the order's history
 *     )
 *
 * Only hosted or redirect flows are allowed: no card or account data ever
 * reaches the site.
 */
interface Gateway {

	/**
	 * Returns the gateway ID, for example 'stripe'.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Returns the name buyers see at checkout.
	 *
	 * @return string
	 */
	public function title(): string;

	/**
	 * Returns the name in the admin, for example "Stripe".
	 *
	 * @return string
	 */
	public function admin_title(): string;

	/**
	 * Returns the short text buyers see under the gateway's name.
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * Whether the owner switched the gateway on.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool;

	/**
	 * Whether every setting the gateway needs is present.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Whether the gateway can take payments in a currency.
	 *
	 * @param string $currency ISO 4217 code.
	 * @return bool
	 */
	public function supports_currency( string $currency ): bool;

	/**
	 * Whether the gateway refunds through its provider ('refunds') or takes webhooks ('webhooks').
	 *
	 * @param string $feature Feature.
	 * @return bool
	 */
	public function supports( string $feature ): bool;

	/**
	 * Starts paying for a new order.
	 *
	 * @param array<string, mixed>             $order      Order row.
	 * @param array<int, array<string, mixed>> $items      Line items.
	 * @param string                           $return_url Where the provider sends the buyer back (the order view).
	 * @param string                           $cancel_url Where the provider sends a buyer who gives up.
	 * @return array{redirect: string, gateway_order_id: string, status: string}|WP_Error
	 */
	public function start( array $order, array $items, string $return_url, string $cancel_url );

	/**
	 * Checks the provider when the buyer comes back, for flows that finish on return.
	 *
	 * @param array<string, mixed>  $order  Order row.
	 * @param array<string, string> $params Query arguments of the return URL.
	 * @return array<int, array<string, mixed>>|WP_Error Payment results.
	 */
	public function confirm( array $order, array $params );

	/**
	 * Refunds an order in full through the provider.
	 *
	 * @param array<string, mixed> $order   Order row.
	 * @param array<string, mixed> $payment The order's payment transaction.
	 * @return array<int, array<string, mixed>>|WP_Error Payment results.
	 */
	public function refund( array $order, array $payment );

	/**
	 * Verifies a webhook request and translates it.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request with the raw body.
	 * @return array<int, array<string, mixed>>|WP_Error Payment results, empty for events the plugin ignores.
	 */
	public function webhook( $request );

	/**
	 * Returns the settings fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array;

	/**
	 * Returns the settings for the admin; secrets are never included, only whether they are set.
	 *
	 * @return array<string, mixed>
	 */
	public function public_settings(): array;

	/**
	 * Saves settings from the admin. Fields set in configuration are left alone.
	 *
	 * @param array<string, mixed> $values Values by field key.
	 * @return void
	 */
	public function update_settings( array $values ): void;
}
