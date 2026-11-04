<?php
/**
 * The checkout page.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The page that holds the `publiclms/checkout` block (option
 * `publiclms_checkout_page_id`). With `?course=` it sells that course; with
 * `?order=` and the order key it shows the order to whoever holds the link
 * (ADR-046).
 */
final class CheckoutPage {

	/**
	 * Option that holds the page ID.
	 */
	const OPTION = 'publiclms_checkout_page_id';

	/**
	 * Option: whether buyers may check out without an account ('yes' or 'no').
	 */
	const GUEST_OPTION = 'publiclms_guest_checkout';

	/**
	 * Returns the checkout page ID, or 0.
	 *
	 * @return int
	 */
	public static function page_id(): int {
		$id = (int) get_option( self::OPTION, 0 );
		return $id > 0 && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	/**
	 * Returns the checkout URL for a course, or '' when there is no checkout page.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public static function url( int $course_id ): string {
		$page = self::page_id();
		return $page > 0 ? add_query_arg( 'course', $course_id, (string) get_permalink( $page ) ) : '';
	}

	/**
	 * Returns the URL that shows an order to the holder of its key.
	 *
	 * @param string $order_key Order key.
	 * @return string
	 */
	public static function order_url( string $order_key ): string {
		$page = self::page_id();
		$base = $page > 0 ? (string) get_permalink( $page ) : home_url( '/' );
		return add_query_arg( 'order', rawurlencode( $order_key ), $base );
	}

	/**
	 * Whether guests may buy without logging in.
	 *
	 * @return bool
	 */
	public static function guests_allowed(): bool {
		return 'no' !== get_option( self::GUEST_OPTION, 'yes' );
	}

	/**
	 * Creates a published checkout page with the checkout block and selects it.
	 *
	 * The slug is `course-checkout`, never `checkout`, so the page cannot take
	 * WooCommerce's address on a site that runs both; with WooCommerce
	 * active the title says "Course checkout" so the two pages differ in menus.
	 *
	 * @return int|WP_Error Page ID.
	 */
	public static function create() {
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => class_exists( 'WooCommerce' ) ? __( 'Course checkout', 'public-lms' ) : __( 'Checkout', 'public-lms' ),
				'post_name'    => 'course-checkout',
				'post_content' => '<!-- wp:publiclms/checkout /-->',
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}
		update_option( self::OPTION, (int) $page_id );
		return (int) $page_id;
	}
}
