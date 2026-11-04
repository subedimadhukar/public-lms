<?php
/**
 * A gateway for tests.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Commerce;

use PublicLMS\Commerce\Gateways\AbstractGateway;

/**
 * Redirects to a fixed URL, refunds through itself and takes webhooks whose
 * results the test sets. Its secret may come from a test constant.
 */
final class FakeGateway extends AbstractGateway {

	/**
	 * Results the next webhook or refund returns.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $results = array();

	/**
	 * Currencies it takes; empty for all.
	 *
	 * @var array<int, string>
	 */
	public $currencies = array();

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'fake';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function title(): string {
		return 'Fake pay';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function admin_title(): string {
		return 'Fake';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function description(): string {
		return 'For tests.';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function declared_fields(): array {
		return array(
			'secret' => array(
				'label'    => 'Secret',
				'type'     => 'secret',
				'constant' => 'PUBLICLMS_TEST_FAKE_SECRET',
			),
			'label'  => array(
				'label' => 'Label',
				'type'  => 'text',
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
		return empty( $this->currencies ) || in_array( $currency, $this->currencies, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $feature Feature.
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed>             $order      Order.
	 * @param array<int, array<string, mixed>> $items      Items.
	 * @param string                           $return_url Return.
	 * @param string                           $cancel_url Cancel.
	 * @return array{redirect: string, gateway_order_id: string, status: string}
	 */
	public function start( array $order, array $items, string $return_url, string $cancel_url ) {
		return array(
			'redirect'         => 'https://pay.example.org/session/' . $order['order_key'],
			'gateway_order_id' => 'sess_' . $order['id'],
			'status'           => 'pending',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $order   Order.
	 * @param array<string, mixed> $payment Payment.
	 * @return array<int, array<string, mixed>>
	 */
	public function refund( array $order, array $payment ) {
		return $this->results;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<int, array<string, mixed>>
	 */
	public function webhook( $request ) {
		return $this->results;
	}

	/**
	 * Builds a result through the protected helper.
	 *
	 * @param string               $event   Event.
	 * @param array<string, mixed> $details Details.
	 * @return array<string, mixed>
	 */
	public function make( string $event, array $details ): array {
		return $this->result( $event, $details );
	}
}
