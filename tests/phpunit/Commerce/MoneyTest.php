<?php
/**
 * Money tests.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Commerce;

use PublicLMS\Commerce\Money;
use WP_UnitTestCase;

/**
 * Currencies, minor units and formatting (ADR-045).
 */
class MoneyTest extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( Money::OPTION );
		remove_all_filters( 'publiclms_format_price_with_intl' );
		parent::tear_down();
	}

	public function test_no_currency_until_the_owner_chooses_one() {
		$this->assertSame( '', Money::currency() );
		update_option( Money::OPTION, 'xyz' );
		$this->assertSame( '', Money::currency(), 'Unknown codes are ignored.' );
		update_option( Money::OPTION, 'kwd' );
		$this->assertSame( 'KWD', Money::currency() );
	}

	public function test_minor_units_follow_iso_4217() {
		$this->assertSame( 0, Money::decimals( 'JPY' ) );
		$this->assertSame( 0, Money::decimals( 'KRW' ) );
		$this->assertSame( 0, Money::decimals( 'VND' ) );
		$this->assertSame( 3, Money::decimals( 'KWD' ) );
		$this->assertSame( 3, Money::decimals( 'BHD' ) );
		$this->assertSame( 2, Money::decimals( 'EUR' ) );
		$this->assertSame( 2, Money::decimals( 'INR' ) );
		$this->assertSame( 1235.0, Money::round( 1234.6, 'JPY' ) );
		$this->assertSame( 1.235, Money::round( 1.23456, 'KWD' ) );
	}

	public function test_every_listed_code_is_three_letters_and_unique() {
		$this->assertSame( count( Money::CURRENCIES ), count( array_unique( Money::CURRENCIES ) ) );
		foreach ( Money::CURRENCIES as $code ) {
			$this->assertMatchesRegularExpression( '/^[A-Z]{3}$/', $code );
		}
		$this->assertContains( 'XCG', Money::CURRENCIES );
		$this->assertNotContains( 'HRK', Money::CURRENCIES, 'Withdrawn currencies are left out.' );
	}

	public function test_fallback_formatting_without_intl() {
		add_filter( 'publiclms_format_price_with_intl', '__return_false' );
		$this->assertSame( '$49', Money::format( 49, 'USD', 'en_US' ) );
		$this->assertSame( '$49.50', Money::format( 49.5, 'USD', 'en_US' ) );
		$this->assertSame( "49.50\u{00A0}€", Money::format( 49.5, 'EUR', 'de_DE' ), 'German puts the symbol after the number.' );
		$this->assertSame( "KWD\u{00A0}1.250", Money::format( 1.25, 'KWD', 'ar' ), 'Three-decimal currencies keep three digits.' );
		$this->assertSame( '¥5,000', Money::format( 5000.4, 'JPY', 'ja' ), 'Zero-decimal currencies round to whole units.' );
		$this->assertSame( '49', Money::format( 49, '', 'en_US' ), 'No currency prints the number alone.' );
	}

	public function test_intl_formatting_when_available() {
		if ( ! class_exists( '\NumberFormatter' ) ) {
			$this->markTestSkipped( 'The intl extension is not installed.' );
		}
		$this->assertSame( '$49.50', Money::format( 49.5, 'USD', 'en_US' ) );
		// With full ICU data German formats as "49,50 €"; with English-only data the table gives "49.50 €".
		$this->assertMatchesRegularExpression( '/^49[.,]50\s€$/u', Money::format( 49.5, 'EUR', 'de_DE' ) );
		$this->assertStringContainsString( '5,000', Money::format( 5000, 'JPY', 'ja_JP' ) );
		$this->assertStringContainsString( '₹', Money::format( 1500, 'INR', 'hi_IN' ) );
	}

	public function test_formatted_prices_can_be_filtered() {
		add_filter(
			'publiclms_format_price',
			static function ( $formatted, $amount, $currency ) {
				return $currency . ':' . $amount;
			},
			10,
			3
		);
		$this->assertSame( 'CHF:12', Money::format( 12, 'CHF', 'de_CH' ) );
		remove_all_filters( 'publiclms_format_price' );
	}
}
