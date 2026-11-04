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

	const REASON_ALREADY_ENROLLED  = 'already_enrolled';
	const REASON_ENROLLMENT_CLOSED = 'enrollment_closed';
	const REASON_PAYMENT_REQUIRED  = 'payment_required';

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
	 * Returns a sentence explaining the decision to a learner, or '' when allowed.
	 *
	 * @return string
	 */
	public function message(): string {
		switch ( $this->reason ) {
			case self::REASON_NONE:
				return '';
			case self::REASON_LOGGED_OUT:
				return __( 'Log in and enrol in this course to open this lesson.', 'public-lms' );
			case self::REASON_NOT_ENROLLED:
				return __( 'Enrol in this course to open this lesson.', 'public-lms' );
			case self::REASON_EXPIRED:
				return __( 'Your access to this course has ended.', 'public-lms' );
			case self::REASON_PREREQUISITE:
				return __( 'Complete the earlier steps of this course first.', 'public-lms' );
			case self::REASON_DRIP:
				if ( null !== $this->available_at ) {
					/* translators: %s: date the item becomes available. */
					return sprintf( __( 'This opens on %s.', 'public-lms' ), wp_date( (string) get_option( 'date_format' ), $this->available_at ) );
				}
				return __( 'This is not available yet.', 'public-lms' );
			case self::REASON_NOT_PUBLISHED:
				return __( 'This is not available.', 'public-lms' );
			case self::REASON_ATTEMPTS_LIMIT:
				return __( 'You have used all your attempts.', 'public-lms' );
			case self::REASON_ALREADY_ENROLLED:
				return __( 'You are already enrolled in this course.', 'public-lms' );
			case self::REASON_ENROLLMENT_CLOSED:
				return __( 'Enrolment in this course is by invitation only.', 'public-lms' );
			case self::REASON_PAYMENT_REQUIRED:
				return __( 'This course is not open for enrolment yet.', 'public-lms' );
		}
		return __( 'You cannot open this.', 'public-lms' );
	}

	/**
	 * Returns the decision as an array for REST responses and Interactivity state.
	 *
	 * @return array{allowed: bool, reason: string, available_at: int|null, message: string}
	 */
	public function to_array(): array {
		return array(
			'allowed'      => $this->allowed,
			'reason'       => $this->reason,
			'available_at' => $this->available_at,
			'message'      => $this->message(),
		);
	}
}
