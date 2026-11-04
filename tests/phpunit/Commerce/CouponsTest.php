<?php
/**
 * Coupon tests.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Commerce;

use PublicLMS\Commerce\Coupons;
use PublicLMS\Commerce\Money;

/**
 * Discount codes (ADR-050).
 */
class CouponsTest extends CommerceTestCase {

	/**
	 * Coupons.
	 *
	 * @var Coupons
	 */
	private $coupons;

	public function set_up() {
		parent::set_up();
		$this->coupons = publiclms()->container()->get( Coupons::class );
	}

	public function test_percentage_and_fixed_discounts() {
		$course = $this->paid_course( '49' );
		$this->coupon(
			array(
				'code'   => 'QUARTER',
				'type'   => 'percent',
				'amount' => 25,
			)
		);
		$this->coupon(
			array(
				'code'   => 'TEN',
				'type'   => 'fixed',
				'amount' => 10,
			)
		);
		$this->coupon(
			array(
				'code'   => 'HUGE',
				'type'   => 'fixed',
				'amount' => 500,
			)
		);
		$this->assertSame( 36.75, $this->service->quote( array( $course ), 'quarter', 0, '' )['total'] );
		$this->assertSame( 39.0, $this->service->quote( array( $course ), 'TEN', 0, '' )['total'] );
		$this->assertSame( 0.0, $this->service->quote( array( $course ), 'HUGE', 0, '' )['total'], 'A fixed discount never goes below zero.' );
	}

	public function test_codes_match_in_any_case_and_script() {
		$this->coupon(
			array(
				'code'   => 'Ünïcode-ДРУГ',
				'type'   => 'percent',
				'amount' => 10,
			)
		);
		$course = $this->paid_course( '100' );
		$this->assertSame( 90.0, $this->service->quote( array( $course ), 'ünïcode-друг', 0, '' )['total'] );
	}

	public function test_fixed_discount_spreads_over_lines_exactly() {
		$first  = $this->paid_course( '30' );
		$second = $this->paid_course( '70' );
		$this->coupon(
			array(
				'code'   => 'SPLIT',
				'type'   => 'fixed',
				'amount' => 33.33,
			)
		);
		$quote = $this->service->quote( array( $first, $second ), 'SPLIT', 0, '' );
		$this->assertSame( 33.33, $quote['discount'] );
		$this->assertEqualsWithDelta( 33.33, $quote['items'][0]['discount'] + $quote['items'][1]['discount'], 0.00001 );
		$this->assertSame( 66.67, $quote['total'] );
	}

	public function test_zero_decimal_currency_rounds_discounts() {
		update_option( Money::OPTION, 'JPY' );
		$this->coupon(
			array(
				'code'   => 'FIFTEEN',
				'type'   => 'percent',
				'amount' => 15,
			)
		);
		$quote = $this->service->quote( array( $this->paid_course( '4999' ) ), 'FIFTEEN', 0, '' );
		$this->assertSame( 750.0, $quote['discount'] );
		$this->assertSame( 4249.0, $quote['total'] );
	}

	public function test_course_limits_dates_and_pause() {
		$course = $this->paid_course();
		$other  = $this->paid_course();
		$this->coupon(
			array(
				'code'    => 'ONLYOTHER',
				'type'    => 'percent',
				'amount'  => 10,
				'courses' => array( $other ),
			)
		);
		$this->coupon(
			array(
				'code'    => 'OLD',
				'type'    => 'percent',
				'amount'  => 10,
				'expires' => '2001-01-01',
			)
		);
		$this->coupon(
			array(
				'code'   => 'LATER',
				'type'   => 'percent',
				'amount' => 10,
				'starts' => '2999-01-01',
			)
		);
		$this->coupon(
			array(
				'code'   => 'PAUSED',
				'type'   => 'percent',
				'amount' => 10,
				'active' => false,
			)
		);
		$this->assertSame( 'publiclms_coupon_not_applicable', $this->service->quote( array( $course ), 'ONLYOTHER', 0, '' )->get_error_code() );
		$this->assertSame( 'publiclms_coupon_expired', $this->service->quote( array( $course ), 'OLD', 0, '' )->get_error_code() );
		$this->assertSame( 'publiclms_coupon_not_started', $this->service->quote( array( $course ), 'LATER', 0, '' )->get_error_code() );
		$this->assertSame( 'publiclms_coupon_invalid', $this->service->quote( array( $course ), 'PAUSED', 0, '' )->get_error_code() );
		$this->assertSame( 'publiclms_coupon_invalid', $this->service->quote( array( $course ), 'NOPE', 0, '' )->get_error_code() );
	}

	public function test_usage_limits_count_paid_and_waiting_orders() {
		$this->coupon(
			array(
				'code'        => 'ONCE',
				'type'        => 'percent',
				'amount'      => 10,
				'usage_limit' => 2,
				'user_limit'  => 1,
			)
		);
		$buyer  = self::factory()->user->create();
		$result = $this->service->checkout(
			array(
				'courses' => array( $this->paid_course() ),
				'gateway' => 'offline',
				'coupon'  => 'once',
				'user_id' => $buyer,
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'publiclms_coupon_user_limit', $this->service->quote( array( $this->paid_course() ), 'ONCE', $buyer, '' )->get_error_code() );

		$this->service->checkout(
			array(
				'courses' => array( $this->paid_course() ),
				'gateway' => 'offline',
				'coupon'  => 'ONCE',
				'user_id' => self::factory()->user->create(),
			)
		);
		$this->assertSame( 'publiclms_coupon_used_up', $this->service->quote( array( $this->paid_course() ), 'ONCE', self::factory()->user->create(), '' )->get_error_code() );
	}

	public function test_codes_are_unique_and_validated() {
		$this->coupon(
			array(
				'code'   => 'SAME',
				'type'   => 'percent',
				'amount' => 10,
			)
		);
		$this->assertSame( 'publiclms_coupon_exists', $this->coupons->save( array( 'code' => 'same', 'amount' => 5 ) )->get_error_code() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertWPError( $this->coupons->save( array( 'code' => 'two words', 'amount' => 5 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertWPError( $this->coupons->save( array( 'code' => 'TOOMUCH', 'type' => 'percent', 'amount' => 120 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}
}
