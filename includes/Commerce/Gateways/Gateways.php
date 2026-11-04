<?php
/**
 * Gateway registry.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * The gateways the store knows: Offline, Stripe and PayPal in free, plus any
 * an add-on registers through `publiclms_gateways` (ADR-047).
 */
class Gateways {

	/**
	 * Gateways by ID, built on first use.
	 *
	 * @var array<string, Gateway>|null
	 */
	private $gateways = null;

	/**
	 * Core gateways.
	 *
	 * @var array<int, Gateway>
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param array<int, Gateway> $core Core gateways.
	 */
	public function __construct( array $core ) {
		$this->core = $core;
	}

	/**
	 * Returns every gateway by ID.
	 *
	 * @return array<string, Gateway>
	 */
	public function all(): array {
		if ( null === $this->gateways ) {
			/**
			 * Filters the payment gateways. Add-ons append Gateway instances.
			 *
			 * @param array<int, Gateway> $gateways Gateways.
			 */
			$list           = (array) apply_filters( 'publiclms_gateways', $this->core );
			$this->gateways = array();
			foreach ( $list as $gateway ) {
				if ( $gateway instanceof Gateway ) {
					$this->gateways[ $gateway->id() ] = $gateway;
				}
			}
		}
		return $this->gateways;
	}

	/**
	 * Returns a gateway by ID.
	 *
	 * @param string $id Gateway ID.
	 * @return Gateway|null
	 */
	public function get( string $id ): ?Gateway {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Returns the gateways buyers can use now: switched on, set up, and taking the currency.
	 *
	 * @param string $currency ISO 4217 code.
	 * @return array<string, Gateway>
	 */
	public function available( string $currency ): array {
		return array_filter(
			$this->all(),
			static function ( Gateway $gateway ) use ( $currency ) {
				return '' !== $currency && $gateway->is_enabled() && $gateway->is_configured() && $gateway->supports_currency( $currency );
			}
		);
	}
}
