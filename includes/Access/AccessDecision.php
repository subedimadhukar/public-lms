<?php
/**
 * Access decision value object.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Access;

defined( 'ABSPATH' ) || exit;

/**
 * The answer to "can this user open this item", with the reason when not.
 *
 * Blocks and templates use the reason to explain a locked item ("unlocks on
 * 12 December", "complete Lesson 3 first") instead of a bare refusal.
 */
final class AccessDecision {

	const REASON_NONE           = '';
	const REASON_LOGGED_OUT     = 'logged_out';
	const REASON_NOT_ENROLLED   = 'not_enrolled';
	const REASON_EXPIRED        = 'expired';
	const REASON_PREREQUISITE   = 'prerequisite';
	const REASON_DRIP           = 'drip';
	const REASON_NOT_PUBLISHED  = 'not_published';
	const REASON_ATTEMPTS_LIMIT = 'attempts_limit';

	/**
	 * Whether access is granted.
	 *
	 * @var bool
	 */
	private $allowed;

	/**
	 * Machine-readable reason when access is denied; one of the REASON_* constants.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Unix timestamp when a drip-locked item opens, or null.
	 *
	 * @var int|null
	 */
	private $available_at;

	/**
	 * Constructor. Use allow() or deny().
	 *
	 * @param bool     $allowed      Whether access is granted.
	 * @param string   $reason       Reason when denied.
	 * @param int|null $available_at When a drip-locked item opens.
	 */
	private function __construct( bool $allowed, string $reason, ?int $available_at ) {
		$this->allowed      = $allowed;
		$this->reason       = $reason;
		$this->available_at = $available_at;
	}

	/**
	 * Grants access.
	 *
	 * @return AccessDecision
	 */
	public static function allow(): AccessDecision {
		return new self( true, self::REASON_NONE, null );
	}

	/**
	 * Denies access.
	 *
	 * @param string   $reason       One of the REASON_* constants.
	 * @param int|null $available_at When a drip-locked item opens, as a Unix timestamp.
	 * @return AccessDecision
	 */
	public static function deny( string $reason, ?int $available_at = null ): AccessDecision {
		return new self( false, $reason, $available_at );
	}

	/**
	 * Whether access is granted.
	 *
	 * @return bool
	 */
	public function is_allowed(): bool {
		return $this->allowed;
	}

	/**
	 * Returns the reason access was denied, or an empty string.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Returns when a drip-locked item opens, or null.
	 *
	 * @return int|null
	 */
	public function available_at(): ?int {
		return $this->available_at;
	}

	/**
	 * Returns the decision as an array for REST responses and Interactivity state.
	 *
	 * @return array{allowed: bool, reason: string, available_at: int|null}
	 */
	public function to_array(): array {
		return array(
			'allowed'      => $this->allowed,
			'reason'       => $this->reason,
			'available_at' => $this->available_at,
		);
	}
}
