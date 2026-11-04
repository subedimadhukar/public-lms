<?php
/**
 * Order emails.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce;

use PublicLMS\Commerce\Gateways\Gateway;
use PublicLMS\Commerce\Gateways\OfflineGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text emails about an order, in the buyer's language (ADR-046).
 *
 * Buyers with an account get their profile language; guests get the
 * language the site showed them at checkout. Dates and prices are formatted
 * in that language. The owner gets an email for every order that waits for
 * an offline payment. `publiclms_send_order_email` can stop any of them.
 */
class OrderEmails {

	/**
	 * Emails the buyer (and the owner) about an order waiting for an offline payment.
	 *
	 * @param array<string, mixed>             $order   Order.
	 * @param array<int, array<string, mixed>> $items   Line items.
	 * @param Gateway                          $gateway Gateway.
	 * @return void
	 */
	public function on_hold( array $order, array $items, Gateway $gateway ): void {
		$instructions = $gateway instanceof OfflineGateway ? $gateway->instructions() : '';
		$this->to_buyer(
			'on-hold',
			$order,
			$items,
			static function ( array $order, string $summary ) use ( $instructions ) {
				return array(
					/* translators: 1: site name, 2: order number. */
					sprintf( __( '[%1$s] Order #%2$d: how to pay', 'public-lms' ), self::site_name(), (int) $order['id'] ),
					__( 'Thank you for your order. It is waiting for your payment.', 'public-lms' ) . "\n\n"
						. ( '' === $instructions ? '' : $instructions . "\n\n" )
						. $summary . "\n\n"
						. __( 'Your course opens as soon as the payment is confirmed.', 'public-lms' ),
				);
			}
		);

		$admin = (string) get_option( 'admin_email' );
		if ( '' !== $admin && $this->allowed( 'admin-on-hold', $order ) ) {
			$switched = switch_to_locale( get_locale() );
			$subject  = sprintf( /* translators: 1: site name, 2: order number. */ __( '[%1$s] New order #%2$d waiting for payment', 'public-lms' ), self::site_name(), (int) $order['id'] );
			$body     = sprintf( /* translators: %s: buyer's email. */ __( 'A new order from %s is waiting for an offline payment.', 'public-lms' ), (string) $order['billing_email'] ) . "\n\n"
				. $this->summary( $order, $items ) . "\n\n"
				. __( 'When the money arrives, open the order in Public LMS → Orders and mark it paid. That enrols the learner.', 'public-lms' ) . "\n"
				. admin_url( 'admin.php?page=publiclms-orders&order=' . (int) $order['id'] );
			wp_mail( $admin, $subject, $body );
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Emails the buyer that the order is paid, with links to the courses.
	 *
	 * @param array<string, mixed>             $order Order.
	 * @param array<int, array<string, mixed>> $items Line items.
	 * @return void
	 */
	public function completed( array $order, array $items ): void {
		$this->to_buyer(
			'completed',
			$order,
			$items,
			function ( array $order, string $summary ) use ( $items ) {
				$links = array();
				foreach ( $items as $item ) {
					if ( 'course' === $item['item_type'] ) {
						$links[] = $item['name'] . "\n" . get_permalink( (int) $item['object_id'] );
					}
				}
				$dashboard = (int) get_option( 'publiclms_dashboard_page_id', 0 );
				return array(
					/* translators: 1: site name, 2: order number. */
					sprintf( __( '[%1$s] Order #%2$d is complete', 'public-lms' ), self::site_name(), (int) $order['id'] ),
					__( 'Thank you! Your payment is confirmed and your courses are open:', 'public-lms' ) . "\n\n"
						. implode( "\n\n", $links ) . "\n\n"
						. $summary
						. ( $dashboard > 0 ? "\n\n" . __( 'All your courses:', 'public-lms' ) . ' ' . get_permalink( $dashboard ) : '' ),
				);
			}
		);
	}

	/**
	 * Emails the buyer that the order was refunded.
	 *
	 * @param array<string, mixed>             $order Order.
	 * @param array<int, array<string, mixed>> $items Line items.
	 * @return void
	 */
	public function refunded( array $order, array $items ): void {
		$this->to_buyer(
			'refunded',
			$order,
			$items,
			static function ( array $order, string $summary ) {
				return array(
					/* translators: 1: site name, 2: order number. */
					sprintf( __( '[%1$s] Order #%2$d was refunded', 'public-lms' ), self::site_name(), (int) $order['id'] ),
					__( 'Your order has been refunded. Depending on how you paid, the money can take a few days to reach you.', 'public-lms' ) . "\n\n" . $summary,
				);
			}
		);
	}

	/**
	 * Sends one email to the buyer in their language.
	 *
	 * @param string                           $type    Email type.
	 * @param array<string, mixed>             $order   Order.
	 * @param array<int, array<string, mixed>> $items   Items.
	 * @param callable                         $compose Returns array( subject, body ) given the order and the summary.
	 * @return void
	 */
	private function to_buyer( string $type, array $order, array $items, callable $compose ): void {
		$email = (string) $order['billing_email'];
		if ( '' === $email || ! $this->allowed( $type, $order ) ) {
			return;
		}
		$user_id                = (int) $order['user_id'];
		$switched               = $user_id > 0 && function_exists( 'switch_to_user_locale' )
			? switch_to_user_locale( $user_id )
			: switch_to_locale( '' === (string) ( $order['meta']['locale'] ?? '' ) ? get_locale() : (string) $order['meta']['locale'] );
		list( $subject, $body ) = $compose( $order, $this->summary( $order, $items ) );
		wp_mail( $email, wp_specialchars_decode( (string) $subject ), (string) $body );
		if ( $switched ) {
			restore_previous_locale();
		}
	}

	/**
	 * Whether an email should be sent.
	 *
	 * @param string               $type  on-hold, completed, refunded or admin-on-hold.
	 * @param array<string, mixed> $order Order.
	 * @return bool
	 */
	private function allowed( string $type, array $order ): bool {
		/**
		 * Filters whether Public LMS sends an order email.
		 *
		 * @param bool                 $send  Whether to send it. Default true.
		 * @param string               $type  on-hold, completed, refunded or admin-on-hold.
		 * @param array<string, mixed> $order Order.
		 */
		return (bool) apply_filters( 'publiclms_send_order_email', true, $type, $order );
	}

	/**
	 * Lists the order's lines, discount, total and date in the current language.
	 *
	 * @param array<string, mixed>             $order Order.
	 * @param array<int, array<string, mixed>> $items Items.
	 * @return string
	 */
	private function summary( array $order, array $items ): string {
		$currency = (string) $order['currency'];
		/* translators: %d: order number. */
		$lines = array( sprintf( __( 'Order #%d', 'public-lms' ), (int) $order['id'] ) );
		foreach ( $items as $item ) {
			$lines[] = $item['name'] . ': ' . Money::format( (float) $item['subtotal'], $currency );
		}
		if ( (float) $order['discount_total'] > 0 ) {
			/* translators: 1: coupon code, 2: discount amount. */
			$lines[] = sprintf( __( 'Coupon %1$s: −%2$s', 'public-lms' ), (string) $order['coupon_code'], Money::format( (float) $order['discount_total'], $currency ) );
		}
		/* translators: %s: order total. */
		$lines[] = sprintf( __( 'Total: %s', 'public-lms' ), Money::format( (float) $order['total'], $currency ) );
		/* translators: %s: date and time of the order. */
		$lines[] = sprintf( __( 'Date: %s', 'public-lms' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( (string) $order['created_at'] . ' UTC' ) ) );
		$lines[] = CheckoutPage::order_url( (string) $order['order_key'] );
		return implode( "\n", $lines );
	}

	/**
	 * Returns the site name as plain text.
	 *
	 * @return string
	 */
	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}
}
