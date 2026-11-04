<?php
/**
 * Order service tests.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Commerce;

use PublicLMS\Commerce\CheckoutPage;
use PublicLMS\Commerce\Money;
use PublicLMS\Repositories\OrderRepository;

/**
 * Checkout, payment results, completion and refunds (ADR-046).
 */
class OrderServiceTest extends CommerceTestCase {

	public function test_quote_prices_on_the_server_with_the_sale_price() {
		$course = $this->paid_course( '49', '39.5' );
		$quote  = $this->service->quote( array( $course ), '', 0, '' );
		$this->assertIsArray( $quote );
		$this->assertSame( 'EUR', $quote['currency'] );
		$this->assertSame( 39.5, $quote['total'] );
		$this->assertSame( 'Data Analysis with Spreadsheets', $quote['items'][0]['name'] );
	}

	public function test_nothing_is_sold_without_a_currency() {
		delete_option( Money::OPTION );
		$quote = $this->service->quote( array( $this->paid_course() ), '', 0, '' );
		$this->assertWPError( $quote );
		$this->assertSame( 'publiclms_no_currency', $quote->get_error_code() );
	}

	public function test_free_and_unpublished_courses_are_not_for_sale() {
		$free  = $this->create_course();
		$draft = $this->paid_course();
		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 'publiclms_not_for_sale', $this->service->quote( array( $free ), '', 0, '' )->get_error_code() );
		$this->assertSame( 'publiclms_not_for_sale', $this->service->quote( array( $draft ), '', 0, '' )->get_error_code() );
	}

	public function test_zero_decimal_currency_rounds_the_price() {
		update_option( Money::OPTION, 'JPY' );
		$quote = $this->service->quote( array( $this->paid_course( '4999.6' ) ), '', 0, '' );
		$this->assertSame( 5000.0, $quote['total'] );
	}

	public function test_offline_order_waits_then_mark_paid_enrols_once() {
		$course = $this->paid_course();
		$user   = self::factory()->user->create( array( 'user_email' => 'learner@example.org' ) );
		$result = $this->service->checkout(
			array(
				'courses' => array( $course ),
				'gateway' => 'offline',
				'user_id' => $user,
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( OrderRepository::STATUS_ON_HOLD, $result['order']['status'] );
		$this->assertStringContainsString( 'order=', $result['redirect'] );
		$this->assertFalse( $this->enrollments->is_enrolled( $user, $course ) );

		$mail = tests_retrieve_phpmailer_instance();
		$this->assertStringContainsString( 'Transfer to account 123', (string) $mail->get_sent( 0 )->body, 'The buyer gets the instructions.' );

		$order_id = (int) $result['order']['id'];
		$paid     = $this->service->mark_paid( $order_id );
		$this->assertSame( OrderRepository::STATUS_COMPLETED, $paid['status'] );
		$row = $this->enrollments->find_by_user_and_course( $user, $course );
		$this->assertSame( 'order', $row['source'] );
		$this->assertSame( $order_id, (int) $row['source_id'] );

		$this->assertFalse( $this->service->complete( $order_id ), 'Completing twice does nothing.' );
		$this->assertWPError( $this->service->mark_paid( $order_id ) );
		$this->assertCount( 1, $this->orders->transactions( $order_id ) );
	}

	public function test_guest_order_creates_the_account_only_once_paid() {
		$course = $this->paid_course();
		$result = $this->service->checkout(
			array(
				'courses' => array( $course ),
				'gateway' => 'offline',
				'email'   => 'amara.okafor@example.org',
				'name'    => 'Amara Okafor',
			)
		);
		$this->assertIsArray( $result );
		$this->assertFalse( get_user_by( 'email', 'amara.okafor@example.org' ), 'No account before payment.' );

		$this->service->mark_paid( (int) $result['order']['id'] );
		$user = get_user_by( 'email', 'amara.okafor@example.org' );
		$this->assertNotFalse( $user );
		$this->assertSame( 'Amara Okafor', $user->display_name );
		$this->assertTrue( $this->enrollments->is_enrolled( (int) $user->ID, $course ) );
		$this->assertSame( (int) $user->ID, (int) $this->orders->find( (int) $result['order']['id'] )['user_id'] );
	}

	public function test_guest_checkout_needs_a_name_and_email_and_can_be_switched_off() {
		$course = $this->paid_course();
		$this->assertSame(
			'rest_invalid_param',
			$this->service->checkout(
				array(
					'courses' => array( $course ),
					'gateway' => 'offline',
					'email'   => 'a@example.org',
				)
			)->get_error_code()
		);
		update_option( CheckoutPage::GUEST_OPTION, 'no' );
		$this->assertSame(
			'publiclms_rest_logged_out',
			$this->service->checkout(
				array(
					'courses' => array( $course ),
					'gateway' => 'offline',
					'email'   => 'a@example.org',
					'name'    => 'A',
				)
			)->get_error_code()
		);
	}

	public function test_already_enrolled_buyers_are_stopped() {
		$course = $this->paid_course();
		$user   = self::factory()->user->create();
		publiclms()->container()->get( \PublicLMS\Services\EnrollmentService::class )->enroll( $user, $course, 'manual' );
		$quote = $this->service->quote( array( $course ), '', $user, '' );
		$this->assertSame( 'publiclms_already_enrolled', $quote->get_error_code() );
	}

	public function test_redirect_gateway_completes_on_a_matching_payment() {
		$course = $this->paid_course();
		$user   = self::factory()->user->create();
		$result = $this->service->checkout(
			array(
				'courses' => array( $course ),
				'gateway' => 'fake',
				'user_id' => $user,
			)
		);
		$this->assertStringStartsWith( 'https://pay.example.org/session/', $result['redirect'] );
		$order = $this->orders->find( (int) $result['order']['id'] );
		$this->assertSame( OrderRepository::STATUS_PENDING, $order['status'] );

		$paid = $this->fake->make(
			'paid',
			array(
				'gateway_order_id' => $order['gateway_order_id'],
				'transaction_id'   => 'pay_1',
				'amount'           => 49.0,
				'currency'         => 'eur',
			)
		);
		$this->assertSame( 1, $this->service->apply( $this->fake, array( $paid ) ) );
		$this->assertSame( 1, $this->service->apply( $this->fake, array( $paid ) ), 'A retried webhook is recognised.' );
		$this->assertSame( OrderRepository::STATUS_COMPLETED, $this->orders->find( (int) $order['id'] )['status'] );
		$this->assertCount( 1, $this->orders->transactions( (int) $order['id'] ), 'The payment is recorded once.' );
		$this->assertTrue( $this->enrollments->is_enrolled( $user, $course ) );
	}

	public function test_a_payment_that_does_not_match_the_total_puts_the_order_on_hold() {
		$user   = self::factory()->user->create();
		$course = $this->paid_course();
		$result = $this->service->checkout(
			array(
				'courses' => array( $course ),
				'gateway' => 'fake',
				'user_id' => $user,
			)
		);
		$order  = $this->orders->find( (int) $result['order']['id'] );
		$this->service->apply(
			$this->fake,
			array(
				$this->fake->make(
					'paid',
					array(
						'gateway_order_id' => $order['gateway_order_id'],
						'transaction_id'   => 'pay_2',
						'amount'           => 1.0,
						'currency'         => 'EUR',
					)
				),
			)
		);
		$this->assertSame( OrderRepository::STATUS_ON_HOLD, $this->orders->find( (int) $order['id'] )['status'] );
		$this->assertFalse( $this->enrollments->is_enrolled( $user, $course ) );
	}

	public function test_results_for_another_gateways_order_are_ignored() {
		$result  = $this->service->checkout(
			array(
				'courses' => array( $this->paid_course() ),
				'gateway' => 'offline',
				'user_id' => self::factory()->user->create(),
			)
		);
		$applied = $this->service->apply( $this->fake, array( $this->fake->make( 'paid', array( 'order_id' => (int) $result['order']['id'] ) ) ) );
		$this->assertSame( 0, $applied );
		$this->assertSame( OrderRepository::STATUS_ON_HOLD, $this->orders->find( (int) $result['order']['id'] )['status'] );
	}

	public function test_refund_through_the_gateway_closes_only_the_orders_enrolments() {
		$user   = self::factory()->user->create();
		$course = $this->paid_course();
		$other  = $this->create_course();
		publiclms()->container()->get( \PublicLMS\Services\EnrollmentService::class )->enroll( $user, $other, 'manual' );
		$result = $this->service->checkout(
			array(
				'courses' => array( $course ),
				'gateway' => 'fake',
				'user_id' => $user,
			)
		);
		$order  = $this->orders->find( (int) $result['order']['id'] );
		$this->service->apply(
			$this->fake,
			array(
				$this->fake->make(
					'paid',
					array(
						'gateway_order_id' => $order['gateway_order_id'],
						'transaction_id'   => 'pay_3',
						'amount'           => 49.0,
						'currency'         => 'EUR',
					)
				),
			)
		);
		$this->fake->results = array(
			$this->fake->make(
				'refunded',
				array(
					'order_id'       => (int) $order['id'],
					'transaction_id' => 're_1',
					'amount'         => 49.0,
					'currency'       => 'EUR',
				)
			),
		);
		$refunded            = $this->service->refund( (int) $order['id'] );
		$this->assertSame( OrderRepository::STATUS_REFUNDED, $refunded['status'] );
		$this->assertFalse( $this->enrollments->is_enrolled( $user, $course ) );
		$this->assertTrue( $this->enrollments->is_enrolled( $user, $other ), 'Access from elsewhere stays.' );
	}

	public function test_partial_refund_keeps_access() {
		$user   = self::factory()->user->create();
		$course = $this->paid_course();
		$result = $this->service->checkout(
			array(
				'courses' => array( $course ),
				'gateway' => 'fake',
				'user_id' => $user,
			)
		);
		$order  = $this->orders->find( (int) $result['order']['id'] );
		$this->service->apply( $this->fake, array( $this->fake->make( 'paid', array( 'gateway_order_id' => $order['gateway_order_id'], 'transaction_id' => 'pay_4', 'amount' => 49.0, 'currency' => 'EUR' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->service->apply( $this->fake, array( $this->fake->make( 'refunded', array( 'gateway_order_id' => $order['gateway_order_id'], 'transaction_id' => 're_2', 'amount' => 10.0, 'full' => false ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( OrderRepository::STATUS_COMPLETED, $this->orders->find( (int) $order['id'] )['status'] );
		$this->assertTrue( $this->enrollments->is_enrolled( $user, $course ) );
	}

	public function test_full_coupon_completes_without_a_gateway() {
		$course = $this->paid_course();
		$this->coupon(
			array(
				'code'   => 'GIFT100',
				'type'   => 'percent',
				'amount' => 100,
			)
		);
		$user   = self::factory()->user->create();
		$result = $this->service->checkout(
			array(
				'courses' => array( $course ),
				'coupon'  => 'gift100',
				'user_id' => $user,
			)
		);
		$this->assertSame( OrderRepository::STATUS_COMPLETED, $result['order']['status'] );
		$this->assertSame( 'free', $result['order']['gateway'] );
		$this->assertTrue( $this->enrollments->is_enrolled( $user, $course ) );
	}

	public function test_gateway_must_take_the_currency() {
		$this->fake->currencies = array( 'USD' );
		$result                 = $this->service->checkout(
			array(
				'courses' => array( $this->paid_course() ),
				'gateway' => 'fake',
				'user_id' => self::factory()->user->create(),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
	}

	public function test_buyer_cancel_only_cancels_pending_orders() {
		$result = $this->service->checkout(
			array(
				'courses' => array( $this->paid_course() ),
				'gateway' => 'fake',
				'user_id' => self::factory()->user->create(),
			)
		);
		$order  = $this->orders->find( (int) $result['order']['id'] );
		$this->service->buyer_cancelled( $order['order_key'] );
		$this->assertSame( OrderRepository::STATUS_CANCELLED, $this->orders->find( (int) $order['id'] )['status'] );
	}

	public function test_enrol_button_points_paid_courses_at_checkout() {
		$course = $this->paid_course( '25' );
		$user   = self::factory()->user->create();
		$state  = publiclms()->container()->get( \PublicLMS\Courses\CourseFields::class )->enroll_state( $course, $user );
		$this->assertSame( 'paid', $state['state'] );
		$this->assertSame( CheckoutPage::url( $course ), $state['url'] );
		wp_set_current_user( 0 );
		$guest = publiclms()->container()->get( \PublicLMS\Courses\CourseFields::class )->enroll_state( $course, 0 );
		$this->assertSame( CheckoutPage::url( $course ), $guest['url'], 'Guests go straight to checkout when guest checkout is on.' );
	}
}
