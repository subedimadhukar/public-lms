<?php
/**
 * Buyers without an account.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce;

use PublicLMS\Capabilities\Roles;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Finds or creates the account a guest's paid order enrols (ADR-046).
 *
 * An account is created only once the order is paid, never at checkout.
 * The buyer gets WordPress's own "set your password" email. Courses bought
 * with an email that already has an account go to that account.
 */
class Customers {

	/**
	 * Returns the user ID for an email, creating a learner account when none exists.
	 *
	 * @param string $email Billing email.
	 * @param string $name  Name the buyer gave, for the display name.
	 * @return int|WP_Error
	 */
	public function resolve( string $email, string $name = '' ) {
		$email = sanitize_email( $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'publiclms_customer_email', __( 'The order has no valid email address.', 'public-lms' ) );
		}
		$user = get_user_by( 'email', $email );
		if ( false !== $user ) {
			return (int) $user->ID;
		}

		$login = $this->unique_login( $email );
		$name  = trim( wp_strip_all_tags( $name ) );
		$args  = array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24, true, true ),
			'display_name' => '' === $name ? $login : $name,
			'nickname'     => '' === $name ? $login : $name,
			'role'         => null !== get_role( Roles::STUDENT ) ? Roles::STUDENT : (string) get_option( 'default_role', 'subscriber' ),
		);
		/**
		 * Filters the account created for a guest's paid order.
		 *
		 * @param array<string, mixed> $args  wp_insert_user() arguments.
		 * @param string               $email Billing email.
		 */
		$args    = (array) apply_filters( 'publiclms_new_customer_args', $args, $email );
		$user_id = wp_insert_user( $args );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		wp_new_user_notification( (int) $user_id, null, 'user' );

		/**
		 * Fires after an account is created for a guest's paid order.
		 *
		 * @param int    $user_id New user ID.
		 * @param string $email   Billing email.
		 */
		do_action( 'publiclms_customer_created', (int) $user_id, $email );
		return (int) $user_id;
	}

	/**
	 * Builds a free username from the email's local part.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private function unique_login( string $email ): string {
		$base  = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base  = '' === $base ? 'learner' : substr( $base, 0, 50 );
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			++$i;
			$login = $base . $i;
		}
		return $login;
	}
}
