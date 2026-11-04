<?php
/**
 * Checkout requests outside the REST API.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce;

use PublicLMS\Rest\OrdersController;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout page's server side (ADR-046):
 *
 * - The buyer's return from a provider (`?order=` with the provider's
 *   arguments) asks the gateway about the payment, then redirects to the
 *   clean order URL so a reload never repeats it.
 * - A buyer who gives up at the provider comes back with `?cancelled=` and
 *   the pending order is cancelled.
 * - Without JavaScript the checkout form posts to admin-post.php
 *   (`publiclms_checkout`), which does what POST /orders/checkout does.
 * - The checkout page is never cached.
 */
final class CheckoutHandler {

	/**
	 * Return arguments providers add, removed after the return is handled.
	 */
	const RETURN_ARGS = array( 'session_id', 'token', 'PayerID', 'paymentId' );

	/**
	 * Order service.
	 *
	 * @var OrderService
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param OrderService $service Order service.
	 */
	public function __construct( OrderService $service ) {
		$this->service = $service;
	}

	/**
	 * Hooks the handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'on_checkout_page' ), 5 );
		add_action( 'admin_post_publiclms_checkout', array( $this, 'handle_form' ) );
		add_action( 'admin_post_nopriv_publiclms_checkout', array( $this, 'handle_form' ) );
	}

	/**
	 * Handles returns and cancellations on the checkout page, and turns caching off there.
	 *
	 * @return void
	 */
	public function on_checkout_page(): void {
		$page = CheckoutPage::page_id();
		if ( $page <= 0 || ! is_page( $page ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard constant read by page-cache plugins.
		}
		nocache_headers();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Provider redirects carry no nonce; the order key and the provider's own IDs are checked instead.
		if ( isset( $_GET['cancelled'] ) ) {
			$this->service->buyer_cancelled( sanitize_text_field( wp_unslash( $_GET['cancelled'] ) ) );
		}
		if ( ! isset( $_GET['order'] ) ) {
			return;
		}
		$params = array();
		foreach ( self::RETURN_ARGS as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) {
				$params[ $arg ] = sanitize_text_field( wp_unslash( $_GET[ $arg ] ) );
			}
		}
		$key = sanitize_text_field( wp_unslash( $_GET['order'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( empty( $params ) ) {
			return;
		}
		$order = $this->service->repository()->find_by_key( $key );
		if ( null !== $order ) {
			$this->service->handle_return( $order, $params );
		}
		wp_safe_redirect( CheckoutPage::order_url( $key ) );
		exit;
	}

	/**
	 * Handles the checkout form without JavaScript.
	 *
	 * @return void
	 */
	public function handle_form(): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'publiclms_checkout' ) ) {
			wp_die( esc_html__( 'The link has expired. Go back, reload the page and try again.', 'public-lms' ), '', array( 'response' => 403 ) );
		}
		$courses = isset( $_POST['courses'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['courses'] ) ) : array();
		$coupon  = isset( $_POST['coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon'] ) ) : '';
		$back    = CheckoutPage::url( (int) ( $courses[0] ?? 0 ) );

		if ( isset( $_POST['apply_coupon'] ) ) {
			wp_safe_redirect( '' === $coupon ? $back : add_query_arg( 'coupon', rawurlencode( $coupon ), $back ) );
			exit;
		}

		$result = $this->service->checkout(
			array(
				'courses' => $courses,
				'gateway' => isset( $_POST['gateway'] ) ? sanitize_key( wp_unslash( $_POST['gateway'] ) ) : '',
				'coupon'  => $coupon,
				'email'   => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				'name'    => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'user_id' => get_current_user_id(),
				'ip'      => OrdersController::client_ip(),
			)
		);
		if ( is_wp_error( $result ) ) {
			$token = strtolower( wp_generate_password( 12, false, false ) );
			set_transient( 'publiclms_checkout_notice_' . $token, $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			$back = add_query_arg( 'notice', $token, '' === $coupon ? $back : add_query_arg( 'coupon', rawurlencode( $coupon ), $back ) );
			wp_safe_redirect( $back );
			exit;
		}
		// Hosted payment pages live on the provider's domain.
		wp_redirect( $result['redirect'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The URL comes from the gateway or the checkout page, never from the request.
		exit;
	}

	/**
	 * Returns and forgets a notice left by the form handler.
	 *
	 * @param string $token Token from the URL.
	 * @return string
	 */
	public static function take_notice( string $token ): string {
		if ( '' === $token || ! preg_match( '/^[a-z0-9]{12}$/', $token ) ) {
			return '';
		}
		$notice = get_transient( 'publiclms_checkout_notice_' . $token );
		delete_transient( 'publiclms_checkout_notice_' . $token );
		return is_string( $notice ) ? $notice : '';
	}
}
