<?php
// Sandbox check: a real Checkout Session in test mode (no payment), then expire it.
update_option( 'publiclms_currency', 'EUR' );
$stripe = get_option( 'publiclms_gateway_stripe', array() );
$stripe['enabled'] = true;
update_option( 'publiclms_gateway_stripe', $stripe, false );
$course = (int) get_posts( array( 'post_type' => 'plms_course', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_plms_access_mode', 'meta_value' => 'paid' ) )[0];
$buyer  = get_user_by( 'login', 'sandbox_buyer' );
if ( ! $buyer ) {
	$id    = wp_insert_user( array( 'user_login' => 'sandbox_buyer', 'user_email' => 'sandbox.buyer@example.org', 'user_pass' => wp_generate_password( 24 ), 'display_name' => 'Sandbox Buyer', 'role' => 'plms_student' ) );
	$buyer = get_user_by( 'id', $id );
}
$service = publiclms()->container()->get( PublicLMS\Commerce\OrderService::class );
publiclms()->container()->get( PublicLMS\Services\EnrollmentService::class )->unenroll( (int) $buyer->ID, $course );
$result = $service->checkout( array( 'courses' => array( $course ), 'gateway' => 'stripe', 'user_id' => (int) $buyer->ID ) );
if ( is_wp_error( $result ) ) {
	echo 'ERROR: ', $result->get_error_message(), "\n";
	return;
}
$order = $service->repository()->find( (int) $result['order']['id'] );
echo 'order #', $order['id'], ' status=', $order['status'], ' total=', $order['total'], ' ', $order['currency'], "\n";
echo 'session=', $order['gateway_order_id'], "\n";
echo 'redirect host=', wp_parse_url( $result['redirect'], PHP_URL_HOST ), "\n";
