<?php
/**
 * Renders publiclms/checkout.
 *
 * With `?order={key}` it shows that order: its status, lines and totals,
 * and the offline payment instructions while it waits. With `?course={id}`
 * (or `?courses=1,2`) it sells those courses: prices come from the server,
 * a coupon can be applied, guests give their name and email, and the
 * buyer picks a payment method. The form posts to admin-post.php without
 * JavaScript; the Interactivity API sends it to POST /orders/checkout and
 * follows the redirect to the provider or the order (ADR-046).
 *
 * @package PublicLMS
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner content.
 * @var WP_Block             $block      Block instance.
 */

use PublicLMS\Blocks\Blocks;
use PublicLMS\Capabilities\Capabilities;
use PublicLMS\Commerce\CheckoutHandler;
use PublicLMS\Commerce\CheckoutPage;
use PublicLMS\Commerce\Gateways\OfflineGateway;
use PublicLMS\Commerce\Money;
use PublicLMS\Commerce\OrderService;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\OrderRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The order service.
 *
 * @var OrderService $publiclms_service
 */
$publiclms_service = publiclms()->container()->get( OrderService::class );
$publiclms_uid     = wp_unique_id( 'publiclms-checkout-' );

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only page arguments; the order key is the order's own secret.
$publiclms_order_key = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
$publiclms_courses   = array();
if ( isset( $_GET['courses'] ) ) {
	$publiclms_courses = array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['courses'] ) ) ) );
} elseif ( isset( $_GET['course'] ) ) {
	$publiclms_courses = array( absint( $_GET['course'] ) );
}
$publiclms_coupon    = isset( $_GET['coupon'] ) ? sanitize_text_field( wp_unslash( $_GET['coupon'] ) ) : '';
$publiclms_notice    = isset( $_GET['notice'] ) ? CheckoutHandler::take_notice( sanitize_key( wp_unslash( $_GET['notice'] ) ) ) : '';
$publiclms_cancelled = isset( $_GET['cancelled'] );
// phpcs:enable WordPress.Security.NonceVerification.Recommended
$publiclms_courses = array_values( array_unique( array_filter( $publiclms_courses ) ) );

/*
 * The order view.
 */
if ( '' !== $publiclms_order_key ) {
	$publiclms_order = $publiclms_service->repository()->find_by_key( $publiclms_order_key );
	echo '<div ' . get_block_wrapper_attributes( array( 'class' => 'is-order-view' ) ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes().
	if ( null === $publiclms_order ) {
		echo '<p>' . esc_html__( 'We could not find this order. Check the link in your email.', 'public-lms' ) . '</p></div>';
		return;
	}
	$publiclms_view   = $publiclms_service->prepare( $publiclms_order );
	$publiclms_status = (string) $publiclms_view['status'];
	$publiclms_first  = (int) ( $publiclms_view['items'][0]['id'] ?? 0 );
	$publiclms_retry  = $publiclms_first > 0 ? CheckoutPage::url( $publiclms_first ) : '';
	$publiclms_notes  = array(
		OrderRepository::STATUS_COMPLETED => array( 'is-success', __( 'Thank you! Your payment is confirmed and your courses are open.', 'public-lms' ) ),
		OrderRepository::STATUS_ON_HOLD   => array( 'is-warning', __( 'Thank you! Your order is waiting for your payment. Your courses open as soon as it is confirmed.', 'public-lms' ) ),
		OrderRepository::STATUS_PENDING   => array( 'is-warning', __( 'We are waiting for the payment provider to confirm your payment. Reload this page in a minute.', 'public-lms' ) ),
		OrderRepository::STATUS_FAILED    => array( 'is-danger', __( 'The payment did not go through. No money was taken for this order.', 'public-lms' ) ),
		OrderRepository::STATUS_CANCELLED => array( 'is-danger', __( 'This order was cancelled.', 'public-lms' ) ),
		OrderRepository::STATUS_REFUNDED  => array( 'is-warning', __( 'This order was refunded.', 'public-lms' ) ),
	);
	$publiclms_note   = $publiclms_notes[ $publiclms_status ] ?? array( '', '' );
	$publiclms_time   = strtotime( (string) $publiclms_view['created_at'] );
	$publiclms_gate   = $publiclms_service->gateways()->get( (string) $publiclms_order['gateway'] );
	?>
	<div class="wp-block-publiclms-checkout__notice <?php echo esc_attr( $publiclms_note[0] ); ?>" role="status">
		<p><?php echo esc_html( $publiclms_note[1] ); ?></p>
		<?php if ( OrderRepository::STATUS_ON_HOLD === $publiclms_status && $publiclms_gate instanceof OfflineGateway && '' !== $publiclms_gate->instructions() ) : ?>
			<div class="wp-block-publiclms-checkout__instructions"><?php echo wp_kses_post( wpautop( esc_html( $publiclms_gate->instructions() ) ) ); ?></div>
		<?php endif; ?>
		<?php if ( OrderRepository::STATUS_COMPLETED === $publiclms_status && ! is_user_logged_in() ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: the buyer's email address. */
					esc_html__( 'Log in with %s to start learning. New here? We emailed you a link to set your password.', 'public-lms' ),
					'<strong>' . esc_html( (string) $publiclms_order['billing_email'] ) . '</strong>'
				);
				?>
			</p>
			<p><a class="wp-element-button" href="<?php echo esc_url( wp_login_url( (string) ( $publiclms_view['items'][0]['url'] ?? home_url( '/' ) ) ) ); ?>"><?php esc_html_e( 'Log in', 'public-lms' ); ?></a></p>
		<?php endif; ?>
		<?php if ( in_array( $publiclms_status, array( OrderRepository::STATUS_FAILED, OrderRepository::STATUS_CANCELLED ), true ) && '' !== $publiclms_retry ) : ?>
			<p><a class="wp-element-button" href="<?php echo esc_url( $publiclms_retry ); ?>"><?php esc_html_e( 'Try again', 'public-lms' ); ?></a></p>
		<?php endif; ?>
	</div>
	<h2 id="<?php echo esc_attr( $publiclms_uid . '-order' ); ?>" class="wp-block-publiclms-checkout__title">
		<?php
		/* translators: %d: order number. */
		echo esc_html( sprintf( __( 'Order #%d', 'public-lms' ), (int) $publiclms_view['id'] ) );
		?>
	</h2>
	<dl class="wp-block-publiclms-checkout__facts">
		<div><dt><?php esc_html_e( 'Date', 'public-lms' ); ?></dt><dd><?php echo esc_html( false === $publiclms_time ? '' : (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $publiclms_time ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Status', 'public-lms' ); ?></dt><dd><?php echo esc_html( (string) $publiclms_view['status_label'] ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Payment method', 'public-lms' ); ?></dt><dd><?php echo esc_html( null === $publiclms_gate ? (string) $publiclms_view['gateway_title'] : $publiclms_gate->title() ); ?></dd></div>
	</dl>
	<table class="wp-block-publiclms-checkout__lines" aria-labelledby="<?php echo esc_attr( $publiclms_uid . '-order' ); ?>">
		<thead>
			<tr><th scope="col"><?php esc_html_e( 'Course', 'public-lms' ); ?></th><th scope="col"><?php esc_html_e( 'Price', 'public-lms' ); ?></th></tr>
		</thead>
		<tbody>
			<?php foreach ( $publiclms_view['items'] as $publiclms_item ) : ?>
				<tr>
					<th scope="row">
						<?php if ( OrderRepository::STATUS_COMPLETED === $publiclms_status && '' !== $publiclms_item['url'] ) : ?>
							<a href="<?php echo esc_url( (string) $publiclms_item['url'] ); ?>"><?php echo esc_html( (string) $publiclms_item['name'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( (string) $publiclms_item['name'] ); ?>
						<?php endif; ?>
					</th>
					<td><?php echo esc_html( (string) $publiclms_item['subtotal_formatted'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
		<tfoot>
			<?php if ( (float) $publiclms_view['discount_total'] > 0 ) : ?>
				<tr>
					<th scope="row">
						<?php
						/* translators: %s: coupon code. */
						echo esc_html( sprintf( __( 'Coupon %s', 'public-lms' ), (string) $publiclms_view['coupon_code'] ) );
						?>
					</th>
					<td>−<?php echo esc_html( (string) $publiclms_view['discount_formatted'] ); ?></td>
				</tr>
			<?php endif; ?>
			<tr class="is-total"><th scope="row"><?php esc_html_e( 'Total', 'public-lms' ); ?></th><td><?php echo esc_html( (string) $publiclms_view['total_formatted'] ); ?></td></tr>
		</tfoot>
	</table>
	<?php if ( OrderRepository::STATUS_COMPLETED === $publiclms_status && is_user_logged_in() && get_current_user_id() === (int) $publiclms_order['user_id'] && '' !== (string) ( $publiclms_view['items'][0]['url'] ?? '' ) ) : ?>
		<p><a class="wp-element-button" href="<?php echo esc_url( (string) $publiclms_view['items'][0]['url'] ); ?>"><?php esc_html_e( 'Start learning', 'public-lms' ); ?></a></p>
	<?php endif; ?>
	</div>
	<?php
	return;
}

/*
 * The checkout form.
 */
$publiclms_wrapper = get_block_wrapper_attributes( array( 'class' => 'is-checkout' ) );
if ( empty( $publiclms_courses ) ) {
	$publiclms_archive = get_post_type_archive_link( PostTypes::COURSE );
	printf(
		'<div %1$s><p>%2$s</p>%3$s</div>',
		$publiclms_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes().
		esc_html__( 'Choose a course to buy first.', 'public-lms' ),
		false === $publiclms_archive ? '' : '<p><a class="wp-element-button" href="' . esc_url( $publiclms_archive ) . '">' . esc_html__( 'Browse courses', 'public-lms' ) . '</a></p>'
	);
	return;
}

$publiclms_user         = get_current_user_id();
$publiclms_coupon_error = '';
$publiclms_quote        = $publiclms_service->quote( $publiclms_courses, $publiclms_coupon, $publiclms_user, '' );
if ( is_wp_error( $publiclms_quote ) && '' !== $publiclms_coupon && 0 === strpos( (string) $publiclms_quote->get_error_code(), 'publiclms_coupon' ) ) {
	$publiclms_coupon_error = $publiclms_quote->get_error_message();
	$publiclms_coupon       = '';
	$publiclms_quote        = $publiclms_service->quote( $publiclms_courses, '', $publiclms_user, '' );
}
if ( is_wp_error( $publiclms_quote ) ) {
	printf(
		'<div %1$s><div class="wp-block-publiclms-checkout__notice is-danger" role="alert"><p>%2$s</p></div><p><a class="wp-element-button" href="%3$s">%4$s</a></p></div>',
		$publiclms_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes().
		esc_html( $publiclms_quote->get_error_message() ),
		esc_url( (string) get_permalink( $publiclms_courses[0] ) ),
		esc_html__( 'Back to the course', 'public-lms' )
	);
	return;
}

$publiclms_currency = $publiclms_quote['currency'];
$publiclms_gateways = $publiclms_service->gateways()->available( $publiclms_currency );
$publiclms_only     = array_filter( array_map( 'strval', (array) ( $attributes['gateways'] ?? array() ) ) );
if ( ! empty( $publiclms_only ) ) {
	$publiclms_gateways = array_intersect_key( $publiclms_gateways, array_flip( $publiclms_only ) );
}
$publiclms_needs_pay = $publiclms_quote['total'] > 0;
$publiclms_is_guest  = $publiclms_user <= 0;
$publiclms_login     = wp_login_url( CheckoutPage::url( $publiclms_courses[0] ) );
$publiclms_coupons   = ! empty( $attributes['showCoupon'] ) && (bool) get_posts(
	array(
		'post_type'      => PostTypes::COUPON,
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);

wp_interactivity_state(
	Blocks::STORE,
	array(
		'restUrl' => esc_url_raw( rest_url( 'publiclms/v1/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'i18n'    => array(
			'error'         => __( 'That did not work. Check your connection and try again.', 'public-lms' ),
			'redirecting'   => __( 'Order placed. Taking you to the next step…', 'public-lms' ),
			'couponApplied' => __( 'Coupon applied.', 'public-lms' ),
			'couponRemoved' => __( 'Coupon removed.', 'public-lms' ),
		),
	)
);
$publiclms_context = array(
	'courses'       => $publiclms_courses,
	'coupon'        => $publiclms_quote['coupon'],
	'total'         => Money::format( $publiclms_quote['total'], $publiclms_currency ),
	'discount'      => '−' . Money::format( $publiclms_quote['discount'], $publiclms_currency ),
	'hasDiscount'   => $publiclms_quote['discount'] > 0,
	'needsPayment'  => $publiclms_needs_pay,
	'isBusy'        => false,
	'message'       => $publiclms_notice,
	'couponMessage' => $publiclms_coupon_error,
);
?>
<div <?php echo $publiclms_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="publiclms/learning"
	<?php echo wp_interactivity_data_wp_context( $publiclms_context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
>
	<?php if ( $publiclms_cancelled ) : ?>
		<div class="wp-block-publiclms-checkout__notice is-warning" role="status"><p><?php esc_html_e( 'The payment was cancelled. Nothing was charged. You can try again below.', 'public-lms' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $publiclms_is_guest && ! CheckoutPage::guests_allowed() ) : ?>
		<p><?php esc_html_e( 'Please log in or create an account to buy this course.', 'public-lms' ); ?></p>
		<p><a class="wp-element-button" href="<?php echo esc_url( $publiclms_login ); ?>"><?php esc_html_e( 'Log in', 'public-lms' ); ?></a></p>
	</div>
		<?php
		return;
	endif;
	?>

	<form class="wp-block-publiclms-checkout__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wp-on--submit="actions.placeOrder" novalidate>
		<input type="hidden" name="action" value="publiclms_checkout" />
		<?php wp_nonce_field( 'publiclms_checkout', '_wpnonce', false ); ?>
		<?php foreach ( $publiclms_courses as $publiclms_course ) : ?>
			<input type="hidden" name="courses[]" value="<?php echo esc_attr( (string) $publiclms_course ); ?>" />
		<?php endforeach; ?>

		<section class="wp-block-publiclms-checkout__summary" aria-labelledby="<?php echo esc_attr( $publiclms_uid . '-summary' ); ?>">
			<h2 id="<?php echo esc_attr( $publiclms_uid . '-summary' ); ?>" class="wp-block-publiclms-checkout__title"><?php esc_html_e( 'Your order', 'public-lms' ); ?></h2>
			<table class="wp-block-publiclms-checkout__lines">
				<thead class="publiclms-visually-hidden">
					<tr><th scope="col"><?php esc_html_e( 'Course', 'public-lms' ); ?></th><th scope="col"><?php esc_html_e( 'Price', 'public-lms' ); ?></th></tr>
				</thead>
				<tbody>
					<?php foreach ( $publiclms_quote['items'] as $publiclms_item ) : ?>
						<tr>
							<th scope="row"><a href="<?php echo esc_url( (string) get_permalink( (int) $publiclms_item['object_id'] ) ); ?>"><?php echo esc_html( (string) $publiclms_item['name'] ); ?></a></th>
							<td><?php echo esc_html( Money::format( (float) $publiclms_item['subtotal'], $publiclms_currency ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr <?php echo $publiclms_quote['discount'] > 0 ? '' : 'hidden'; ?> data-wp-bind--hidden="!context.hasDiscount">
						<th scope="row"><?php esc_html_e( 'Discount', 'public-lms' ); ?></th>
						<td data-wp-text="context.discount"><?php echo esc_html( $publiclms_context['discount'] ); ?></td>
					</tr>
					<tr class="is-total">
						<th scope="row"><?php esc_html_e( 'Total', 'public-lms' ); ?></th>
						<td data-wp-text="context.total"><?php echo esc_html( $publiclms_context['total'] ); ?></td>
					</tr>
				</tfoot>
			</table>
		</section>

		<?php if ( $publiclms_coupons ) : ?>
			<div class="wp-block-publiclms-checkout__coupon">
				<label for="<?php echo esc_attr( $publiclms_uid . '-coupon' ); ?>"><?php esc_html_e( 'Coupon code', 'public-lms' ); ?></label>
				<div class="wp-block-publiclms-checkout__coupon-row">
					<input type="text" id="<?php echo esc_attr( $publiclms_uid . '-coupon' ); ?>" name="coupon" value="<?php echo esc_attr( $publiclms_quote['coupon'] ); ?>" autocomplete="off" aria-describedby="<?php echo esc_attr( $publiclms_uid . '-coupon-message' ); ?>" />
					<button type="submit" name="apply_coupon" value="1" class="wp-block-publiclms-checkout__apply wp-element-button" data-wp-on--click="actions.applyCoupon" data-wp-bind--aria-disabled="context.isBusy"><?php esc_html_e( 'Apply', 'public-lms' ); ?></button>
				</div>
				<p id="<?php echo esc_attr( $publiclms_uid . '-coupon-message' ); ?>" class="wp-block-publiclms-checkout__hint" role="status" data-wp-text="context.couponMessage"><?php echo esc_html( $publiclms_coupon_error ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $publiclms_is_guest ) : ?>
			<fieldset class="wp-block-publiclms-checkout__details">
				<legend><?php esc_html_e( 'Your details', 'public-lms' ); ?></legend>
				<p class="wp-block-publiclms-checkout__hint">
					<?php esc_html_e( 'Already have an account?', 'public-lms' ); ?>
					<a href="<?php echo esc_url( $publiclms_login ); ?>"><?php esc_html_e( 'Log in', 'public-lms' ); ?></a>
				</p>
				<label class="wp-block-publiclms-checkout__field">
					<?php esc_html_e( 'Full name', 'public-lms' ); ?>
					<input type="text" name="name" required autocomplete="name" aria-describedby="<?php echo esc_attr( $publiclms_uid . '-name-hint' ); ?>" />
				</label>
				<p id="<?php echo esc_attr( $publiclms_uid . '-name-hint' ); ?>" class="wp-block-publiclms-checkout__hint"><?php esc_html_e( 'As it should appear on your certificates.', 'public-lms' ); ?></p>
				<label class="wp-block-publiclms-checkout__field">
					<?php esc_html_e( 'Email', 'public-lms' ); ?>
					<input type="email" name="email" required autocomplete="email" aria-describedby="<?php echo esc_attr( $publiclms_uid . '-email-hint' ); ?>" />
				</label>
				<p id="<?php echo esc_attr( $publiclms_uid . '-email-hint' ); ?>" class="wp-block-publiclms-checkout__hint"><?php esc_html_e( 'We create your account with this address once you have paid, and send your course access there.', 'public-lms' ); ?></p>
			</fieldset>
		<?php endif; ?>

		<?php if ( empty( $publiclms_gateways ) && $publiclms_needs_pay ) : ?>
			<div class="wp-block-publiclms-checkout__notice is-danger" role="alert">
				<p><?php esc_html_e( 'This site cannot take payments yet.', 'public-lms' ); ?></p>
				<?php if ( current_user_can( Capabilities::MANAGE_ORDERS ) ) : ?>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=publiclms-payments' ) ); ?>"><?php esc_html_e( 'Set up a payment method', 'public-lms' ); ?></a></p>
				<?php endif; ?>
			</div>
		<?php elseif ( ! empty( $publiclms_gateways ) ) : ?>
			<fieldset class="wp-block-publiclms-checkout__gateways" <?php echo $publiclms_needs_pay ? '' : 'hidden'; ?> data-wp-bind--hidden="!context.needsPayment">
				<legend><?php esc_html_e( 'Payment method', 'public-lms' ); ?></legend>
				<?php $publiclms_first_gateway = true; ?>
				<?php foreach ( $publiclms_gateways as $publiclms_gateway ) : ?>
					<label class="wp-block-publiclms-checkout__gateway">
						<input type="radio" name="gateway" value="<?php echo esc_attr( $publiclms_gateway->id() ); ?>" <?php checked( $publiclms_first_gateway ); ?> />
						<span class="wp-block-publiclms-checkout__gateway-title"><?php echo esc_html( $publiclms_gateway->title() ); ?></span>
						<span class="wp-block-publiclms-checkout__hint"><?php echo esc_html( $publiclms_gateway->description() ); ?></span>
					</label>
					<?php $publiclms_first_gateway = false; ?>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>

		<p class="wp-block-publiclms-checkout__actions">
			<button type="submit" class="wp-block-publiclms-checkout__submit wp-element-button" data-wp-bind--aria-disabled="context.isBusy" data-wp-bind--aria-busy="context.isBusy" <?php disabled( empty( $publiclms_gateways ) && $publiclms_needs_pay ); ?>><?php esc_html_e( 'Place order', 'public-lms' ); ?></button>
		</p>
		<p class="wp-block-publiclms-checkout__message" role="alert" data-wp-text="context.message"><?php echo esc_html( $publiclms_notice ); ?></p>
	</form>
</div>
