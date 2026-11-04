<?php
/**
 * Licence status value object.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * The state of a licence as reported by a LicenseProvider.
 */
final class LicenseStatus {

	const VALID         = 'valid';
	const INVALID       = 'invalid';
	const EXPIRED       = 'expired';
	const DISABLED      = 'disabled';
	const SITE_INACTIVE = 'site_inactive';
	const INACTIVE      = 'inactive';

	/**
	 * One of the status constants.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Expiry as a Unix timestamp, or null for lifetime or unknown.
	 *
	 * @var int|null
	 */
	private $expires_at;

	/**
	 * Activations left, or null when unlimited or unknown.
	 *
	 * @var int|null
	 */
	private $activations_left;

	/**
	 * Human-readable message from the provider, already translated.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Constructor.
	 *
	 * @param string   $status           One of the status constants.
	 * @param int|null $expires_at       Expiry timestamp.
	 * @param int|null $activations_left Activations left.
	 * @param string   $message          Message for the site owner.
	 */
	public function __construct( string $status, ?int $expires_at = null, ?int $activations_left = null, string $message = '' ) {
		$this->status           = $status;
		$this->expires_at       = $expires_at;
		$this->activations_left = $activations_left;
		$this->message          = $message;
	}

	/**
	 * Returns the status for a site with no licence.
	 *
	 * @return LicenseStatus
	 */
	public static function inactive(): LicenseStatus {
		return new self( self::INACTIVE );
	}

	/**
	 * Returns the status string.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Whether the licence is valid for this site.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return self::VALID === $this->status;
	}

	/**
	 * Returns the expiry timestamp, or null.
	 *
	 * @return int|null
	 */
	public function expires_at(): ?int {
		return $this->expires_at;
	}

	/**
	 * Returns the activations left, or null.
	 *
	 * @return int|null
	 */
	public function activations_left(): ?int {
		return $this->activations_left;
	}

	/**
	 * Returns the provider's message.
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}
}
