<?php
/**
 * Licence provider for the free plugin.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Does nothing and contacts no server.
 *
 * Bound by default so code can depend on LicenseProvider without the free
 * plugin ever phoning home, which the plugin directory forbids.
 */
final class NullLicenseProvider implements LicenseProvider {

	/**
	 * Returns the provider identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'null';
	}

	/**
	 * Does nothing; the free plugin has no licence.
	 *
	 * @param string $license_key Licence key, ignored.
	 * @return LicenseStatus
	 */
	public function activate( string $license_key ): LicenseStatus {
		unset( $license_key );
		return LicenseStatus::inactive();
	}

	/**
	 * Does nothing; the free plugin has no licence.
	 *
	 * @return LicenseStatus
	 */
	public function deactivate(): LicenseStatus {
		return LicenseStatus::inactive();
	}

	/**
	 * Always reports an inactive licence.
	 *
	 * @param bool $force_refresh Ignored.
	 * @return LicenseStatus
	 */
	public function status( bool $force_refresh = false ): LicenseStatus {
		unset( $force_refresh );
		return LicenseStatus::inactive();
	}

	/**
	 * Never offers updates; the free plugin updates from WordPress.org.
	 *
	 * @param string $product_slug    Ignored.
	 * @param string $current_version Ignored.
	 * @return array<string, mixed>|null
	 */
	public function update_info( string $product_slug, string $current_version ): ?array {
		unset( $product_slug, $current_version );
		return null;
	}
}
