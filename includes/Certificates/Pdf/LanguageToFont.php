<?php
/**
 * Language-to-font answers for mPDF, for one certificate.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Certificates\Pdf;

use PublicLMS\Vendor\Mpdf\Language\LanguageToFontInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Answers mPDF's "which font for this language?" from the choices
 * `CertificateFonts::plan()` made for the text being printed (ADR-052).
 * A language it was not told about keeps the current font.
 *
 * Load only after the prefixed mPDF (`CertificateRenderer::available()`).
 */
final class LanguageToFont implements LanguageToFontInterface {

	/**
	 * Font key by mPDF language tag.
	 *
	 * @var array<string, string>
	 */
	private $languages;

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $languages Font key by mPDF language tag.
	 */
	public function __construct( array $languages ) {
		$this->languages = array_change_key_case( $languages, CASE_LOWER );
	}

	/**
	 * Returns whether a core font suits the language (never: fonts are embedded) and the font to use.
	 *
	 * @param string $llcc      Language tag, as mPDF marked the run.
	 * @param bool   $adobe_cjk Whether Adobe CJK fonts are on.
	 * @return array{0: bool, 1: string}
	 */
	public function getLanguageOptions( $llcc, $adobe_cjk ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mPDF's interface.
		$font = $this->languages[ strtolower( (string) $llcc ) ] ?? '';
		if ( ! $adobe_cjk && in_array( $font, array( 'gb', 'big5', 'sjis', 'uhc' ), true ) ) {
			$font = '';
		}
		return array( false, $font );
	}
}
