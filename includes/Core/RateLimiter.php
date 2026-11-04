<?php
/**
 * A fixed-window rate limiter.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Counts actions per key in a time window and refuses the ones over the
 * limit. Used for quiz starts and submissions (ADR-016) and for assignment
 * uploads; reviews, questions and checkout reuse it in part B.
 *
 * Counters live in transients, which use the object cache when a persistent
 * one is installed and the options table otherwise. Limits pass through the
 * `publiclms_rate_limit` filter so a site can loosen or tighten them.
 */
class RateLimiter {

	/**
	 * Records one action and says whether it is allowed.
	 *
	 * @param string $action  Action name, for example 'quiz_start'.
	 * @param string $subject Who and on what, for example "{user}:{quiz}".
	 * @param int    $limit   Actions allowed per window.
	 * @param int    $window  Window length in seconds.
	 * @return int 0 when allowed, otherwise the seconds until the window resets.
	 */
	public function hit( string $action, string $subject, int $limit, int $window ): int {
		/**
		 * Filters a rate limit.
		 *
		 * @param int    $limit   Actions allowed per window; 0 disables the limit.
		 * @param string $action  Action name.
		 * @param int    $window  Window length in seconds.
		 * @param string $subject Who and on what.
		 */
		$limit = (int) apply_filters( 'publiclms_rate_limit', $limit, $action, $window, $subject );
		if ( $limit <= 0 ) {
			return 0;
		}
		$key   = 'publiclms_rl_' . md5( $action . '|' . $subject );
		$now   = time();
		$state = get_transient( $key );
		if ( ! is_array( $state ) || (int) ( $state['reset'] ?? 0 ) <= $now ) {
			$state = array(
				'count' => 0,
				'reset' => $now + $window,
			);
		}
		if ( (int) $state['count'] >= $limit ) {
			return max( 1, (int) $state['reset'] - $now );
		}
		++$state['count'];
		set_transient( $key, $state, max( 1, (int) $state['reset'] - $now ) );
		return 0;
	}

	/**
	 * Clears a counter.
	 *
	 * @param string $action  Action name.
	 * @param string $subject Who and on what.
	 * @return void
	 */
	public function reset( string $action, string $subject ): void {
		delete_transient( 'publiclms_rl_' . md5( $action . '|' . $subject ) );
	}
}
