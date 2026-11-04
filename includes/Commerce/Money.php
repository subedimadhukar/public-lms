<?php
/**
 * Currencies and money formatting.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * The store currency, ISO 4217 minor units and locale-aware prices (ADR-045).
 *
 * The store sells in one currency, chosen by the owner from every active
 * ISO 4217 code; there is no default country or currency, so a store sells
 * nothing until one is chosen. Amounts are stored as DECIMAL(19,4) and
 * rounded to the currency's minor units (0 for JPY or KRW, 3 for KWD or
 * BHD). Prices are formatted for the reader's locale with PHP's intl
 * extension when it is present (symbol position, separators, digits), and
 * with WordPress's number_format_i18n() and a symbol table when it is not.
 */
final class Money {

	/**
	 * Option that holds the store currency.
	 */
	const OPTION = 'publiclms_currency';

	/**
	 * Active ISO 4217 currency codes, without fund and precious-metal codes.
	 */
	const CURRENCIES = array(
		'AED',
		'AFN',
		'ALL',
		'AMD',
		'AOA',
		'ARS',
		'AUD',
		'AWG',
		'AZN',
		'BAM',
		'BBD',
		'BDT',
		'BHD',
		'BIF',
		'BMD',
		'BND',
		'BOB',
		'BRL',
		'BSD',
		'BTN',
		'BWP',
		'BYN',
		'BZD',
		'CAD',
		'CDF',
		'CHF',
		'CLP',
		'CNY',
		'COP',
		'CRC',
		'CUP',
		'CVE',
		'CZK',
		'DJF',
		'DKK',
		'DOP',
		'DZD',
		'EGP',
		'ERN',
		'ETB',
		'EUR',
		'FJD',
		'FKP',
		'GBP',
		'GEL',
		'GHS',
		'GIP',
		'GMD',
		'GNF',
		'GTQ',
		'GYD',
		'HKD',
		'HNL',
		'HTG',
		'HUF',
		'IDR',
		'ILS',
		'INR',
		'IQD',
		'IRR',
		'ISK',
		'JMD',
		'JOD',
		'JPY',
		'KES',
		'KGS',
		'KHR',
		'KMF',
		'KPW',
		'KRW',
		'KWD',
		'KYD',
		'KZT',
		'LAK',
		'LBP',
		'LKR',
		'LRD',
		'LSL',
		'LYD',
		'MAD',
		'MDL',
		'MGA',
		'MKD',
		'MMK',
		'MNT',
		'MOP',
		'MRU',
		'MUR',
		'MVR',
		'MWK',
		'MXN',
		'MYR',
		'MZN',
		'NAD',
		'NGN',
		'NIO',
		'NOK',
		'NPR',
		'NZD',
		'OMR',
		'PAB',
		'PEN',
		'PGK',
		'PHP',
		'PKR',
		'PLN',
		'PYG',
		'QAR',
		'RON',
		'RSD',
		'RUB',
		'RWF',
		'SAR',
		'SBD',
		'SCR',
		'SDG',
		'SEK',
		'SGD',
		'SHP',
		'SLE',
		'SOS',
		'SRD',
		'SSP',
		'STN',
		'SVC',
		'SYP',
		'SZL',
		'THB',
		'TJS',
		'TMT',
		'TND',
		'TOP',
		'TRY',
		'TTD',
		'TWD',
		'TZS',
		'UAH',
		'UGX',
		'USD',
		'UYU',
		'UZS',
		'VED',
		'VES',
		'VND',
		'VUV',
		'WST',
		'XAF',
		'XCD',
		'XCG',
		'XOF',
		'XPF',
		'YER',
		'ZAR',
		'ZMW',
		'ZWG',
	);

	/**
	 * Currencies whose ISO 4217 minor unit is 0.
	 */
	const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/**
	 * Currencies whose ISO 4217 minor unit is 3.
	 */
	const THREE_DECIMAL = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/**
	 * Symbols used when the intl extension is missing; other codes print the code.
	 */
	const SYMBOLS = array(
		'AUD' => 'A$',
		'BRL' => 'R$',
		'CAD' => 'CA$',
		'CHF' => 'CHF',
		'CNY' => 'CN¥',
		'EUR' => '€',
		'GBP' => '£',
		'HKD' => 'HK$',
		'ILS' => '₪',
		'INR' => '₹',
		'JPY' => '¥',
		'KRW' => '₩',
		'MXN' => 'MX$',
		'NZD' => 'NZ$',
		'PHP' => '₱',
		'THB' => '฿',
		'TWD' => 'NT$',
		'UAH' => '₴',
		'USD' => '$',
		'VND' => '₫',
		'XAF' => 'FCFA',
		'XOF' => 'F CFA',
	);

	/**
	 * Languages that print the symbol after the number when the intl extension is missing.
	 */
	const SYMBOL_AFTER = array( 'az', 'be', 'bg', 'bs', 'ca', 'cs', 'da', 'de', 'el', 'es', 'et', 'eu', 'fi', 'fr', 'gl', 'hr', 'hu', 'hy', 'is', 'it', 'ka', 'kk', 'ky', 'lt', 'lv', 'mk', 'nb', 'nn', 'pl', 'pt_PT', 'ro', 'ru', 'sk', 'sl', 'sq', 'sr', 'sv', 'uk', 'uz', 'vi' );

	/**
	 * Returns the store currency, or '' when the owner has not chosen one.
	 *
	 * @return string
	 */
	public static function currency(): string {
		$code = strtoupper( (string) get_option( self::OPTION, '' ) );
		return self::is_currency( $code ) ? $code : '';
	}

	/**
	 * Whether a code is an active ISO 4217 currency.
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function is_currency( string $code ): bool {
		return in_array( strtoupper( $code ), self::CURRENCIES, true );
	}

	/**
	 * Returns the number of minor-unit digits of a currency (ISO 4217).
	 *
	 * @param string $currency Currency code.
	 * @return int
	 */
	public static function decimals( string $currency ): int {
		$currency = strtoupper( $currency );
		if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
			return 0;
		}
		if ( in_array( $currency, self::THREE_DECIMAL, true ) ) {
			return 3;
		}
		return 2;
	}

	/**
	 * Rounds an amount to the currency's minor units.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return float
	 */
	public static function round( float $amount, string $currency ): float {
		return round( $amount, self::decimals( $currency ) );
	}

	/**
	 * Formats an amount for display, for the reader's locale.
	 *
	 * Whole amounts drop their decimals ("$49", "49 €"); others show the
	 * currency's minor units ("$49.50", "KWD 1.250").
	 *
	 * @param float       $amount   Amount.
	 * @param string|null $currency Currency code; the store currency when null.
	 * @param string|null $locale   Locale such as fr_FR; the current locale when null.
	 * @return string
	 */
	public static function format( float $amount, ?string $currency = null, ?string $locale = null ): string {
		$currency = strtoupper( null === $currency ? self::currency() : $currency );
		$locale   = null === $locale ? ( function_exists( 'determine_locale' ) ? determine_locale() : get_locale() ) : $locale;
		$amount   = '' === $currency ? $amount : self::round( $amount, $currency );
		$decimals = abs( $amount - round( $amount ) ) < 0.0005 ? 0 : ( '' === $currency ? 2 : self::decimals( $currency ) );

		$formatted = '' === $currency ? number_format_i18n( $amount, $decimals ) : self::format_intl( $amount, $currency, $locale, $decimals );
		if ( null === $formatted ) {
			$formatted = self::format_fallback( $amount, $currency, $locale, $decimals );
		}

		/**
		 * Filters a formatted price.
		 *
		 * @param string $formatted The formatted price.
		 * @param float  $amount    Amount.
		 * @param string $currency  ISO 4217 code, or '' when the store has none.
		 * @param string $locale    Locale used.
		 */
		return (string) apply_filters( 'publiclms_format_price', $formatted, $amount, $currency, $locale );
	}

	/**
	 * Formats with PHP's intl extension, or returns null when it is missing or fails.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @param string $locale   Locale.
	 * @param int    $decimals Fraction digits.
	 * @return string|null
	 */
	private static function format_intl( float $amount, string $currency, string $locale, int $decimals ): ?string {
		/**
		 * Filters whether prices are formatted with the intl extension when it is present.
		 *
		 * @param bool $use Whether to use intl.
		 */
		if ( ! class_exists( '\NumberFormatter' ) || ! apply_filters( 'publiclms_format_price_with_intl', true ) ) {
			return null;
		}
		$locale    = '' === $locale ? 'en' : $locale;
		$formatter = new \NumberFormatter( $locale, \NumberFormatter::CURRENCY );
		// Some builds ship ICU with English data only and quietly fall back to it; use the table then.
		$valid = (string) $formatter->getLocale( \Locale::VALID_LOCALE );
		$want  = strtolower( (string) preg_replace( '/[_-].*$/', '', $locale ) );
		$got   = strtolower( (string) preg_replace( '/[_-].*$/', '', $valid ) );
		if ( '' === $valid || 'root' === $valid || $want !== $got ) {
			return null;
		}
		$formatter->setAttribute( \NumberFormatter::MIN_FRACTION_DIGITS, $decimals );
		$formatter->setAttribute( \NumberFormatter::MAX_FRACTION_DIGITS, $decimals );
		$result = $formatter->formatCurrency( $amount, $currency );
		return is_string( $result ) && '' !== $result ? $result : null;
	}

	/**
	 * Formats without intl: number_format_i18n(), a symbol or the code, and the language's symbol position.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @param string $locale   Locale.
	 * @param int    $decimals Fraction digits.
	 * @return string
	 */
	private static function format_fallback( float $amount, string $currency, string $locale, int $decimals ): string {
		$number = number_format_i18n( $amount, $decimals );
		$symbol = self::SYMBOLS[ $currency ] ?? $currency;
		$lang   = (string) strtok( $locale, '_' );
		$after  = in_array( $locale, self::SYMBOL_AFTER, true ) || ( 'pt' !== $lang && in_array( $lang, self::SYMBOL_AFTER, true ) );
		if ( $after ) {
			return $number . "\u{00A0}" . $symbol;
		}
		$joined = isset( self::SYMBOLS[ $currency ] ) && 1 === mb_strlen( $symbol );
		return $symbol . ( $joined ? '' : "\u{00A0}" ) . $number;
	}
}
