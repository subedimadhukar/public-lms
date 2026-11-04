<?php
/**
 * WooCommerce bridge tests, with stand-ins for WooCommerce's objects.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Commerce;

use PublicLMS\Commerce\WooCommerce\Bridge;
use PublicLMS\Services\EnrollmentService;

/**
 * Enrolment from paid WooCommerce orders and closing on refunds (ADR-051).
 */
class WooCommerceBridgeTest extends CommerceTestCase {

	/**
	 * Bridge.
	 *
	 * @var Bridge
	 */
	private $bridge;

	/**
	 * Product post linked to two courses.
	 *
	 * @var int
	 */
	private $product;

	/**
	 * Linked courses.
	 *
	 * @var array<int, int>
	 */
	private $courses;

	public function set_up() {
		parent::set_up();
		$this->bridge  = publiclms()->container()->get( Bridge::class );
		$this->courses = array( $this->paid_course( '30' ), $this->paid_course( '40' ) );
		$this->product = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		$this->bridge->link( $this->product, $this->courses );
	}

	public function test_link_records_both_directions_and_unlinks() {
		$this->assertSame( $this->courses, $this->bridge->product_courses( $this->product ) );
		$this->assertSame( $this->product, (int) get_post_meta( $this->courses[0], Bridge::COURSE_META, true ) );
		$this->bridge->link( $this->product, array( $this->courses[1] ) );
		$this->assertSame( '', get_post_meta( $this->courses[0], Bridge::COURSE_META, true ) );
		$this->assertSame( array( $this->courses[1] ), $this->bridge->product_courses( $this->product ) );
	}

	public function test_paid_order_enrols_the_customer_once() {
		$user  = self::factory()->user->create();
		$order = new FakeWooOrder( 501, $user, 'buyer@example.org', array( $this->product ) );
		$this->assertSame( $this->courses, $this->bridge->enroll_order( $order ) );
		$this->assertSame( array(), $this->bridge->enroll_order( $order ), 'Processing then completed enrols once.' );
		foreach ( $this->courses as $course ) {
			$row = $this->enrollments->find_by_user_and_course( $user, $course );
			$this->assertSame( 'woocommerce', $row['source'] );
			$this->assertSame( 501, (int) $row['source_id'] );
		}
		$this->assertCount( 1, $order->notes );
	}

	public function test_guest_order_creates_the_account() {
		$order = new FakeWooOrder( 502, 0, 'lucia.fernandez@example.org', array( $this->product ) );
		$this->bridge->enroll_order( $order );
		$user = get_user_by( 'email', 'lucia.fernandez@example.org' );
		$this->assertNotFalse( $user );
		$this->assertSame( 'Lucía Fernández', $user->display_name );
		$this->assertTrue( $this->enrollments->is_enrolled( (int) $user->ID, $this->courses[0] ) );
	}

	public function test_refund_closes_only_this_orders_access() {
		$user  = self::factory()->user->create();
		$extra = $this->create_course();
		publiclms()->container()->get( EnrollmentService::class )->enroll( $user, $extra, 'manual' );
		publiclms()->container()->get( EnrollmentService::class )->enroll( $user, $this->courses[1], 'manual' );
		$order = new FakeWooOrder( 503, $user, 'buyer@example.org', array( $this->product ) );
		$this->bridge->enroll_order( $order );
		$this->assertSame( array( $this->courses[0] ), $this->bridge->revoke_order( $order ), 'Course 2 came from a manual enrolment first, so it stays.' );
		$this->assertFalse( $this->enrollments->is_enrolled( $user, $this->courses[0] ) );
		$this->assertTrue( $this->enrollments->is_enrolled( $user, $this->courses[1] ) );
		$this->assertTrue( $this->enrollments->is_enrolled( $user, $extra ) );
	}

	public function test_orders_without_linked_products_do_nothing() {
		$order = new FakeWooOrder( 504, self::factory()->user->create(), 'x@example.org', array( 999999 ) );
		$this->assertSame( array(), $this->bridge->enroll_order( $order ) );
		$this->assertSame( array(), $order->notes );
	}

	public function test_saving_a_product_with_courses_sells_it_individually() {
		$product                                  = new class( $this->product ) {
			/**
			 * Product ID.
			 *
			 * @var int
			 */
			public $id;

			/**
			 * Sold individually.
			 *
			 * @var bool
			 */
			public $single = false;

			/**
			 * Constructor.
			 *
			 * @param int $id Product ID.
			 */
			public function __construct( int $id ) {
				$this->id = $id;
			}

			/**
			 * Product ID.
			 *
			 * @return int
			 */
			public function get_id() {
				return $this->id;
			}

			/**
			 * Sets sold individually.
			 *
			 * @param bool $value Value.
			 * @return void
			 */
			public function set_sold_individually( $value ) {
				$this->single = (bool) $value;
			}
		};
		$_POST['publiclms_product_courses_nonce'] = wp_create_nonce( 'publiclms_product_courses' );
		$_POST['publiclms_courses']               = array( (string) $this->courses[0] );
		$this->bridge->save_product( $product );
		unset( $_POST['publiclms_product_courses_nonce'], $_POST['publiclms_courses'] );
		$this->assertTrue( $product->single );
		$this->assertSame( array( $this->courses[0] ), $this->bridge->product_courses( $this->product ) );
	}

	public function test_bridge_is_off_by_default_and_needs_woocommerce() {
		$this->assertFalse( Bridge::enabled() );
		$this->assertFalse( Bridge::woocommerce_active(), 'The tests site has no WooCommerce.' );
		update_option( Bridge::OPTION, 'yes' );
		$this->assertSame( 'https://example.org/checkout/', $this->bridge->purchase_url( 'https://example.org/checkout/', $this->courses[0] ), 'Without WooCommerce the native checkout keeps the course.' );
		$this->assertTrue( $this->bridge->purchasable( true, $this->courses[0] ) );
		delete_option( Bridge::OPTION );
	}
}
