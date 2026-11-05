<?php
/**
 * Tests that the patched mPDF loads every bundled Noto font and shapes each script.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Certificates;

use PublicLMS\Certificates\CertificateRenderer;
use PublicLMS\Vendor\Mpdf\Mpdf;
use WP_UnitTestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- mPDF's own properties.

/**
 * Guards tools/prefix/mpdf-8.3.1-publiclms.patch (ADR-052).
 *
 * Without the patch, five of the six Noto fonts do not load, and Farsi yeh
 * prints blank. The expected glyph IDs below were taken from the patched
 * engine and checked by eye against a browser rendering of the same strings
 * with the same fonts (HarfBuzz). They are the font's own glyph IDs in
 * logical order, as `hb-shape --no-glyph-names` would print them, so a
 * change in the patch or in mPDF that alters shaping fails here.
 *
 * @covers \PublicLMS\Certificates\CertificateRenderer
 */
class ShapingTest extends WP_UnitTestCase {

	/**
	 * Shaping cases: font key, text, glyph IDs, and what they show.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<int, int>}>
	 */
	public function shaping_cases(): array {
		return array(
			'Arabic: lam, yeh as dotless body and dots mark, alef maksura' => array( 'notosansarabic', 'ليلى', array( 72, 18, 318, 70, 104 ) ),
			'Persian: initial Farsi yeh is body plus dots, then keheh' => array( 'notosansarabic', 'یک', array( 21, 318, 63 ) ),
			'Persian: medial yeh with dots, final yeh without' => array( 'notosansarabic', 'رضایی', array( 30, 41, 282, 9, 21, 318, 104 ) ),
			'Urdu: heh goal and hamza on yeh'       => array( 'notosansarabic', 'عائشہ', array( 49, 9, 19, 297, 36, 292, 89 ) ),
			'Hebrew: final nun'                     => array( 'notosanshebrew', 'כהן', array( 52, 42, 24 ) ),
			'Devanagari: shri ligature and ii sign' => array( 'notosansdevanagari', 'श्री', array( 317, 33 ) ),
			'Devanagari: i sign moves before ka'    => array( 'notosansdevanagari', 'कि', array( 545, 56 ) ),
			'Devanagari: ksha conjunct'             => array( 'notosansdevanagari', 'क्ष', array( 90 ) ),
			'Bengali: tra conjunct and aa sign'     => array( 'notosansbengali', 'ত্রা', array( 154, 59 ) ),
			'Tamil: kaa and pulli on ra'            => array( 'notosanstamil', 'கார்', array( 18, 41, 90 ) ),
			'Thai: marks stacked on sara i'         => array( 'notosansthai', 'ศักดิ์', array( 108, 45, 29, 12, 92, 114 ) ),
		);
	}

	public function set_up() {
		parent::set_up();
		$this->assertTrue( CertificateRenderer::available(), 'The prefixed mPDF is installed (composer prefix).' );
	}

	/**
	 * Returns an engine with every bundled Noto family loaded.
	 *
	 * @return Mpdf
	 */
	private function engine(): Mpdf {
		$renderer = publiclms()->container()->get( CertificateRenderer::class );
		return $renderer->engine( '<p>ليلى דוד प्रिया সুমিত্রা கார்த்திக் สมศักดิ์</p>' );
	}

	/**
	 * Shapes text in a font and returns the glyph IDs.
	 *
	 * @param Mpdf   $mpdf  Engine.
	 * @param string $font  mPDF font key.
	 * @param string $style '' or 'B'.
	 * @param string $text  Text.
	 * @return array<int, int|string>
	 */
	private function glyphs( Mpdf $mpdf, string $font, string $style, string $text ): array {
		$mpdf->SetFont( $font, $style );
		$otl    = ( function () {
			return $this->otl; // phpcs:ignore -- mPDF keeps its shaper private.
		} )->call( $mpdf );
		$shaped = $otl->applyOTL( $text, $mpdf->CurrentFont['useOTL'] );

		// mPDF maps each glyph to a code point (private use for unencoded glyphs); invert that map.
		$map      = (string) $mpdf->CurrentFont['glyphIDtoUni'];
		$to_glyph = array();
		for ( $glyph = 0, $count = (int) ( strlen( $map ) / 3 ); $glyph < $count; $glyph++ ) {
			$code = ( ord( $map[ $glyph * 3 ] ) << 16 ) | ( ord( $map[ $glyph * 3 + 1 ] ) << 8 ) | ord( $map[ $glyph * 3 + 2 ] );
			if ( $code && ! isset( $to_glyph[ $code ] ) ) {
				$to_glyph[ $code ] = $glyph;
			}
		}
		$glyphs = array();
		foreach ( (array) preg_split( '//u', (string) $shaped, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$code     = (int) mb_ord( (string) $char, 'UTF-8' );
			$glyphs[] = $to_glyph[ $code ] ?? sprintf( 'U+%04X', $code );
		}
		return $glyphs;
	}

	public function test_characters_a_script_font_lacks_fall_back_to_dejavu() {
		$mpdf = $this->engine();
		$this->assertTrue( $mpdf->useSubstitutions );
		$this->assertSame( array( 'dejavusans' ), $mpdf->backupSubsFont );
		$mpdf->WriteHTML( '<p>فاطمه · رضایی</p>' );
		$pdf = $mpdf->Output( '', 'S' );
		$this->assertMatchesRegularExpression( '#/BaseFont /[A-Z]{6}\+NotoSansArabic-Regular\b#', $pdf );
		$this->assertMatchesRegularExpression( '#/BaseFont /[A-Z]{6}\+DejaVuSans\b#', $pdf, 'The middle dot, which Noto Sans Arabic lacks, comes from DejaVu Sans.' );
	}

	public function test_every_bundled_noto_face_loads_through_the_patched_reader() {
		$mpdf = $this->engine();
		foreach ( array( 'arabic', 'hebrew', 'devanagari', 'bengali', 'tamil', 'thai' ) as $family ) {
			foreach ( array( '', 'B' ) as $style ) {
				$mpdf->SetFont( 'notosans' . $family, $style );
				$this->assertSame( 'notosans' . $family . $style, $mpdf->CurrentFont['fontkey'], "Noto Sans $family $style loads." );
				$this->assertNotEmpty( $mpdf->CurrentFont['useOTL'], 'OpenType layout is on.' );
				$this->assertNotEmpty( $mpdf->CurrentFont['glyphIDtoUni'] );
			}
		}
	}

	/**
	 * @dataProvider shaping_cases
	 *
	 * @param string          $font     Font key.
	 * @param string          $text     Text.
	 * @param array<int, int> $expected Glyph IDs.
	 */
	public function test_each_script_is_shaped( string $font, string $text, array $expected ) {
		$mpdf = $this->engine();
		$this->assertSame( $expected, $this->glyphs( $mpdf, $font, '', $text ), 'Regular.' );
		$this->assertSame( $expected, $this->glyphs( $mpdf, $font, 'B', $text ), 'Bold (same glyph order as Regular).' );
	}
}
