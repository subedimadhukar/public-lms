<?php
/**
 * Licence provider contract.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Activates, checks and deactivates a licence, and supplies update metadata.
 *
 * The free plugin binds NullLicenseProvider and never branches on a licence
 * (ADR-014, ADR-015). Pro binds an EDD Software Licensing provider on the
 * WPInterface store; Freemius or another service can be swapped in behind
 * this interface without touching Pro's features.
 */
interface LicenseProvider {

	/**
	 * Returns a short identifier, for example 'edd' or 'null'.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Activates a licence key for this site.
	 *
	 * @param string $license_key Licence key as entered by the site owner.
	 * @return LicenseStatus
	 */
	public function activate( string $license_key ): LicenseStatus;

	/**
	 * Deactivates the licence on this site, freeing an activation.
	 *
	 * @return LicenseStatus
	 */
	public function deactivate(): LicenseStatus;

	/**
	 * Returns the licence status, from cache unless a refresh is forced.
	 *
	 * @param bool $force_refresh Whether to ask the licence server now.
	 * @return LicenseStatus
	 */
	public function status( bool $force_refresh = false ): LicenseStatus;

	/**
	 * Returns update metadata for a product, or null when none is available.
	 *
	 * The array follows the shape WordPress expects in the
	 * `update_plugins_{$hostname}` filter: version, package, url, tested,
	 * requires and requires_php.
	 *
	 * @param string $product_slug    Plugin or theme slug, for example 'public-lms-pro'.
	 * @param string $current_version Installed version.
	 * @return array<string, mixed>|null
	 */
	public function update_info( string $product_slug, string $current_version ): ?array;
}
