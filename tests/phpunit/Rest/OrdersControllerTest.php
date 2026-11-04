<?php
/**
 * Order, coupon and payment settings routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Rest;

use PublicLMS\Commerce\CheckoutPage;
use PublicLMS\Commerce\Gateways\Gateways;
use PublicLMS\Commerce\Money;
use PublicLMS\PostTypes\Meta;

/**
 * POST /orders/checkout, GET /orders, order actions, coupons, payment settings.
 */
class OrdersControllerTest extends RestTestCase {

	/**
	 * Paid course.
	 *
	 * @var int
	 */
	private $course;

	public function set_up() {
		parent::set_up();
		update_option( Money::OPTION, 'GBP' );
		update_option( CheckoutPage::OPTION, self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		update_option( CheckoutPage::GUEST_OPTION, 'yes' );
		update_option( 'publiclms_gateway_offline', array( 'enabled' => true ) );
		$this->course = $this->fixture['course'];
		update_post_meta( $this->course, Meta::ACCESS_MODE, 'paid' );
		update_post_meta( $this->course, Meta::PRICE, '20' );
	}

	public function tear_down() {
		delete_option( Money::OPTION );
		delete_option( 'publiclms_gateway_offline' );
		remove_all_filters( 'publiclms_rate_limit' );
		parent::tear_down();
	}

	/**
	 * Places an offline order.
	 *
	 * @param int $user User, or 0 for a guest.
	 * @return array<string, mixed>
	 */
	private function place( int $user ): array {
		$response = $this->request(
			'POST',
			'/orders/checkout',
			array(
				'courses' => array( $this->course ),
				'gateway' => 'offline',
				'email'   => 'guest.buyer@example.org',
				'name'    => 'Guest Buyer',
			),
			$user
		);
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	public function test_checkout_as_learner_and_as_guest() {
		$mine = $this->place( $this->subscriber );
		$this->assertSame( 'on-hold', $mine['order']['status'] );
		$this->assertArrayNotHasKey( 'billing_email', $mine['order'], 'Buyers do not get the admin view.' );

		$guest = $this->place( 0 );
		$this->assertSame( 'on-hold', $guest['order']['status'] );
	}

	public function test_guest_checkout_can_be_switched_off() {
		update_option( CheckoutPage::GUEST_OPTION, 'no' );
		$response = $this->request(
			'POST',
			'/orders/checkout',
			array(
				'courses' => array( $this->course ),
				'gateway' => 'offline',
				'email'   => 'x@example.org',
				'name'    => 'X',
			)
		);
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_listing_is_scoped_to_the_buyer() {
		$this->place( $this->subscriber );
		$this->place( 0 );
		$own = $this->request( 'GET', '/orders', array(), $this->subscriber );
		$this->assertCount( 1, $own->get_data() );
		$all = $this->request( 'GET', '/orders', array(), $this->manager );
		$this->assertCount( 2, $all->get_data() );
		$this->assertSame( 'guest.buyer@example.org', $all->get_data()[0]['billing_email'] );
		$this->assertSame( 401, $this->request( 'GET', '/orders' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/orders/counts', array(), $this->subscriber )->get_status() );
	}

	public function test_only_order_managers_act_on_orders() {
		$order = $this->place( $this->subscriber )['order'];
		$this->assertSame( 403, $this->request( 'POST', '/orders/' . $order['id'] . '/complete', array(), $this->subscriber )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/orders/' . $order['id'] . '/complete', array(), $this->instructor )->get_status() );

		$paid = $this->request( 'POST', '/orders/' . $order['id'] . '/complete', array(), $this->manager );
		$this->assertSame( 'completed', $paid->get_data()['status'] );
		$this->assertTrue( publiclms()->container()->get( \PublicLMS\Repositories\EnrollmentRepository::class )->is_enrolled( $this->subscriber, $this->course ) );

		$refund = $this->request( 'POST', '/orders/' . $order['id'] . '/refund', array( 'manual' => true ), $this->manager );
		$this->assertSame( 'refunded', $refund->get_data()['status'] );
		$this->assertSame( 409, $this->request( 'POST', '/orders/' . $order['id'] . '/cancel', array(), $this->manager )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/orders/' . $order['id'], array(), $this->other_instructor )->get_status() );
	}

	public function test_checkout_is_rate_limited() {
		add_filter(
			'publiclms_rate_limit',
			static function ( $limit, $action ) {
				return 'order_checkout' === $action ? 1 : $limit;
			},
			10,
			2
		);
		$this->place( $this->subscriber );
		$second = $this->request(
			'POST',
			'/orders/checkout',
			array(
				'courses' => array( $this->course ),
				'gateway' => 'offline',
			),
			$this->subscriber
		);
		$this->assertSame( 429, $second->get_status() );
		$this->assertNotEmpty( $second->get_headers()['Retry-After'] );
	}

	public function test_quote_shows_the_coupon() {
		$this->request(
			'POST',
			'/coupons',
			array(
				'code'   => 'HALF',
				'type'   => 'percent',
				'amount' => 50,
			),
			$this->manager
		);
		$quote = $this->request(
			'POST',
			'/orders/quote',
			array(
				'courses' => array( $this->course ),
				'coupon'  => 'half',
			)
		);
		$this->assertSame( 200, $quote->get_status() );
		$this->assertSame( 10.0, $quote->get_data()['total'] );
		$this->assertSame( 'HALF', $quote->get_data()['coupon'] );
	}

	public function test_coupons_need_order_managers() {
		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/coupons',
				array(
					'code'   => 'X',
					'amount' => 5,
				),
				$this->instructor
			)->get_status()
		);
		$created = $this->request(
			'POST',
			'/coupons',
			array(
				'code'        => 'WELCOME',
				'type'        => 'fixed',
				'amount'      => 5,
				'usage_limit' => 3,
			),
			$this->manager
		);
		$this->assertSame( 201, $created->get_status() );
		$id = $created->get_data()['id'];
		$this->assertSame( 3, $this->request( 'GET', '/coupons/' . $id, array(), $this->manager )->get_data()['usage_limit'] );
		$this->assertFalse( $this->request( 'PATCH', '/coupons/' . $id, array( 'active' => false ), $this->manager )->get_data()['active'] );
		$this->assertTrue( $this->request( 'DELETE', '/coupons/' . $id, array(), $this->manager )->get_data()['deleted'] );
	}

	public function test_payment_settings_never_return_secrets() {
		$gateways = new Gateways( array( new \PublicLMS\Tests\Commerce\FakeGateway() ) );
		add_filter(
			'publiclms_rest_controllers',
			static function ( $controllers ) use ( $gateways ) {
				foreach ( $controllers as $index => $controller ) {
					if ( $controller instanceof \PublicLMS\Rest\PaymentsController ) {
						$controllers[ $index ] = new \PublicLMS\Rest\PaymentsController( $gateways );
					}
				}
				return $controllers;
			}
		);
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild the REST server with the test controller.
		rest_get_server();

		$this->assertSame( 403, $this->request( 'GET', '/settings/payments', array(), $this->instructor )->get_status() );
		$saved = $this->request(
			'POST',
			'/settings/payments',
			array(
				'currency' => 'JPY',
				'gateways' => array(
					'fake' => array(
						'enabled' => true,
						'secret'  => 'sk_test_do_not_echo',
						'label'   => 'Hello',
					),
				),
			),
			$this->manager
		);
		$this->assertSame( 200, $saved->get_status() );
		$body = (string) wp_json_encode( $saved->get_data() );
		$this->assertStringNotContainsString( 'sk_test_do_not_echo', $body );
		$fake = $saved->get_data()['gateways'][0];
		$this->assertTrue( $fake['settings']['secret']['is_set'] );
		$this->assertSame( '', $fake['settings']['secret']['value'] );
		$this->assertSame( 'Hello', $fake['settings']['label']['value'] );
		$this->assertSame( 'JPY', $saved->get_data()['currency'] );

		$this->assertSame( 400, $this->request( 'POST', '/settings/payments', array( 'currency' => 'ABC' ), $this->manager )->get_status() );

		define( 'PUBLICLMS_TEST_FAKE_SECRET', 'from-config' );
		$after = $this->request( 'GET', '/settings/payments', array(), $this->manager )->get_data()['gateways'][0];
		$this->assertTrue( $after['settings']['secret']['from_config'] );
		$this->assertStringNotContainsString( 'from-config', (string) wp_json_encode( $after ) );
		$this->request( 'POST', '/settings/payments', array( 'gateways' => array( 'fake' => array( 'secret' => 'ignored' ) ) ), $this->manager );
		$this->assertSame( 'sk_test_do_not_echo', get_option( 'publiclms_gateway_fake' )['secret'], 'A field set in configuration is not saved over.' );
		delete_option( 'publiclms_gateway_fake' );
	}
}
