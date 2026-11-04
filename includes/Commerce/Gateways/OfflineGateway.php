<?php
/**
 * Offline payments.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce\Gateways;

use PublicLMS\Repositories\OrderRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Pay outside the site: bank transfer, cash, a local payment app, an
 * invoice. The order waits "on hold" with the owner's instructions, and the
 * owner marks it paid in Orders, which enrols the learner (ADR-047).
 */
final class OfflineGateway extends AbstractGateway {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'offline';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function admin_title(): string {
		return __( 'Offline payment', 'public-lms' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function title(): string {
		$title = trim( (string) $this->setting( 'title' ) );
		return '' === $title ? __( 'Pay offline', 'public-lms' ) : $title;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Place the order now and pay as described in the instructions. Your course opens once the payment is confirmed.', 'public-lms' );
	}

	/**
	 * Returns the payment instructions shown after ordering and in the email.
	 *
	 * @return string
	 */
	public function instructions(): string {
		return trim( (string) $this->setting( 'instructions' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function declared_fields(): array {
		return array(
			'title'        => array(
				'label'   => __( 'Name at checkout', 'public-lms' ),
				'type'    => 'text',
				'default' => '',
				'help'    => __( 'For example "Bank transfer" or "Pay at the front desk". Leave empty for "Pay offline".', 'public-lms' ),
			),
			'instructions' => array(
				'label'   => __( 'Payment instructions', 'public-lms' ),
				'type'    => 'textarea',
				'default' => '',
				'help'    => __( 'Shown after ordering and in the order email: how and where to pay, and what reference to use.', 'public-lms' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
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
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed>             $order      Order.
	 * @param array<int, array<string, mixed>> $items      Items.
	 * @param string                           $return_url Order view.
	 * @param string                           $cancel_url Checkout.
	 * @return array{redirect: string, gateway_order_id: string, status: string}
	 */
	public function start( array $order, array $items, string $return_url, string $cancel_url ) {
		return array(
			'redirect'         => $return_url,
			'gateway_order_id' => '',
			'status'           => OrderRepository::STATUS_ON_HOLD,
		);
	}
}
