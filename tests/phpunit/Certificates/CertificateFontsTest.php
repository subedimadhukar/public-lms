<?php
/**
 * Tests for certificate fonts: coverage, the font chain, Site Health and the PDF engine's limits.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Certificates;

use InvalidArgumentException;
use PublicLMS\Certificates\CertificateFonts;
use PublicLMS\Certificates\CertificateHealth;
use PublicLMS\Certificates\CertificateRenderer;
use PublicLMS\Certificates\CertificateService;
use PublicLMS\Certificates\FontCoverage;
use PublicLMS\Certificates\Pdf\LanguageToFont;
use PublicLMS\Certificates\Pdf\LocalFiles;
use PublicLMS\Certificates\Pdf\NoNetwork;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Services\ProgressService;
use PublicLMS\Tests\Rest\RestTestCase;
use PublicLMS\Vendor\Mpdf\PsrHttpMessageShim\Request;

/**
 * @covers \PublicLMS\Certificates\FontCoverage
 * @covers \PublicLMS\Certificates\CertificateFonts
 * @covers \PublicLMS\Certificates\CertificateHealth
 * @covers \PublicLMS\Certificates\CertificateRenderer
 * @covers \PublicLMS\Certificates\Pdf\LanguageToFont
 * @covers \PublicLMS\Certificates\Pdf\LocalFiles
 * @covers \PublicLMS\Certificates\Pdf\NoNetwork
 */
class CertificateFontsTest extends RestTestCase {

	/**
	 * Files to delete after the test.
	 *
	 * @var array<int, string>
	 */
	private $files = array();

	public function set_up() {
		parent::set_up();
		$this->assertTrue( CertificateRenderer::available(), 'The prefixed mPDF is installed (bin/prefix-vendor.sh).' );
		FontCoverage::reset();
	}

	public function tear_down() {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
		FontCoverage::reset();
		parent::tear_down();
	}

	/**
	 * Writes the smallest font FontCoverage accepts: TrueType outlines and a format 4 map of one range.
	 *
	 * @param string $file  Path.
	 * @param int    $first First code point.
	 * @param int    $last  Last code point.
	 * @return string
	 */
	private function fake_font( string $file, int $first, int $last ): string {
		$segments = pack( 'n*', $last, 0xFFFF ) . pack( 'n', 0 ) . pack( 'n*', $first, 0xFFFF ) . pack( 'n*', ( 1 - $first ) & 0xFFFF, 1 ) . pack( 'n*', 0, 0 );
		$format4  = pack( 'nnnnnnn', 4, 14 + strlen( $segments ), 0, 4, 4, 1, 0 ) . $segments;
		$cmap     = pack( 'nn', 0, 1 ) . pack( 'nnN', 3, 1, 12 ) . $format4;
		$glyf     = pack( 'N', 0 );
		$offset   = 12 + 2 * 16;
		$font     = "\x00\x01\x00\x00" . pack( 'nnnn', 2, 32, 1, 0 )
			. 'cmap' . pack( 'NNN', 0, $offset, strlen( $cmap ) )
			. 'glyf' . pack( 'NNN', 0, $offset + strlen( $cmap ), strlen( $glyf ) )
			. $cmap . $glyf;
		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, $font ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->files[] = $file;
		return $file;
	}

	/**
	 * Adds a Font Library family with one face per source URL.
	 *
	 * @param string             $name    Family name.
	 * @param array<int, string> $sources Face source URLs.
	 * @return void
	 */
	private function font_library_family( string $name, array $sources ): void {
		$family = self::factory()->post->create(
			array(
				'post_type'   => 'wp_font_family',
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
		foreach ( $sources as $i => $src ) {
			self::factory()->post->create(
				array(
					'post_type'    => 'wp_font_face',
					'post_status'  => 'publish',
					'post_parent'  => $family,
					'post_content' => wp_json_encode(
						array(
							'fontFamily' => $name,
							'fontStyle'  => 'normal',
							'fontWeight' => 0 === $i ? '400' : '700',
							'src'        => $src,
						)
					),
				)
			);
		}
	}

	public function test_coverage_is_read_from_the_font_files() {
		$arabic = CertificateFonts::noto_dir() . 'NotoSansArabic-Regular.ttf';
		$dejavu = CertificateFonts::dejavu_dir() . 'DejaVuSans.ttf';

		$this->assertTrue( FontCoverage::covers( $arabic, array( 0x0644, 0x064A, 0x0649, 0x06CC, 0x0627 ) ), 'Arabic, with Persian yeh.' );
		$this->assertSame( array( 0x41 ), FontCoverage::missing( $arabic, array( 0x41, 0x0644 ) ), 'Noto Sans Arabic has no Latin letters.' );
		$this->assertTrue( FontCoverage::covers( $dejavu, array( 0x10D0, 0x0531, 0x0416, 0x03A9, 0x1EA1 ) ), 'DejaVu: Georgian, Armenian, Cyrillic, Greek, Vietnamese.' );
		$this->assertSame( array( 0x0D9A, 0x1200 ), FontCoverage::missing( $dejavu, array( 0x0D9A, 0x1200 ) ), 'DejaVu has no Sinhala or Ethiopic.' );

		$dir = trailingslashit( get_temp_dir() ) . 'publiclms-font-test/';
		wp_mkdir_p( $dir );
		foreach ( array(
			'cff.otf'   => "OTTO\x00\x02\x00\x00\x00\x00\x00\x00",
			'web.woff2' => 'wOF2' . str_repeat( "\x00", 40 ),
		) as $name => $bytes ) {
			file_put_contents( $dir . $name, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			$this->files[] = $dir . $name;
			$this->assertFalse( FontCoverage::usable( $dir . $name ), "$name cannot be embedded by mPDF." );
		}
		$this->assertTrue( FontCoverage::usable( $this->fake_font( $dir . 'sinhala.ttf', 0x0D80, 0x0DFF ) ) );
		$this->assertTrue( FontCoverage::covers( $dir . 'sinhala.ttf', array( 0x0D9A, 0x0DCA ) ) );
	}

	public function test_each_script_gets_the_first_font_that_covers_it() {
		$plan = ( new CertificateFonts() )->plan( '<p>ليلى حداد · فاطمه رضایی · प्रिया शर्मा · דוד כהן · สมศักดิ์ · Дмитрий · Ἄννα · Lucía</p>' );

		$this->assertSame( 'notosansarabic', $plan['languages']['und-arab'] );
		$this->assertSame( 'notosansarabic', $plan['languages']['fa'], 'Persian, tagged by mPDF from its letters.' );
		$this->assertSame( 'notosansdevanagari', $plan['languages']['hi'] );
		$this->assertSame( 'notosanshebrew', $plan['languages']['he'] );
		$this->assertSame( 'notosansthai', $plan['languages']['th'] );
		$this->assertSame( '', $plan['languages']['und-cyrl'], 'Cyrillic keeps the stylesheet font (DejaVu).' );
		$this->assertSame( '', $plan['languages']['el'] );
		$this->assertSame( array(), $plan['missing'] );
		$this->assertSame( array(), $plan['viewer'] );
		$this->assertArrayHasKey( 'notosansarabic', $plan['fontdata'] );
		$this->assertArrayNotHasKey( 'notosanstamil', $plan['fontdata'], 'Only the fonts the text needs are loaded.' );

		$cjk = ( new CertificateFonts() )->plan( '王芳 김지우' );
		$this->assertSame( array( 'Han', 'Hangul' ), $cjk['viewer'] );
		$this->assertSame( 'gb', $cjk['languages']['und-hans'] );
		$this->assertSame( 'uhc', $cjk['languages']['ko'] );
		$this->assertSame( 'sjis', ( new CertificateFonts() )->plan( '佐藤 けん' )['languages']['und-hans'], 'Han beside kana is drawn as Japanese.' );

		$this->assertSame( array( 'Sinhala', 'Ethiopic' ), ( new CertificateFonts() )->plan( 'සුනිල් ደጀኔ' )['missing'] );

		$languages = new LanguageToFont( array( 'und-Arab' => 'notosansarabic' ) );
		$this->assertSame( array( false, 'notosansarabic' ), $languages->getLanguageOptions( 'und-arab', true ), 'mPDF asks in lower case.' );
		$this->assertSame( array( false, '' ), $languages->getLanguageOptions( 'en-us', true ) );
		$this->assertSame( array( false, '' ), ( new LanguageToFont( array( 'ko' => 'uhc' ) ) )->getLanguageOptions( 'ko', false ), 'Adobe CJK only when it is on.' );
	}

	public function test_font_library_and_filter_fonts_come_after_the_bundled_ones() {
		$dir      = wp_get_font_dir();
		$sinhala  = $this->fake_font( trailingslashit( $dir['path'] ) . 'plms-test-sinhala-400.ttf', 0x0D80, 0x0DFF );
		$ethiopic = $this->fake_font( trailingslashit( get_temp_dir() ) . 'publiclms-font-test/ethiopic.ttf', 0x1200, 0x137F );
		$this->font_library_family( 'Test Sinhala', array( trailingslashit( $dir['url'] ) . 'plms-test-sinhala-400.ttf', trailingslashit( $dir['url'] ) . 'plms-test-sinhala-700.woff2' ) );
		$this->font_library_family( 'Elsewhere', array( 'https://fonts.example.com/remote.ttf' ) );
		add_filter(
			'publiclms_certificate_fonts',
			static function ( $fonts ) use ( $ethiopic ) {
				$fonts[] = array(
					'name' => 'Test Ethiopic',
					'R'    => $ethiopic,
				);
				$fonts[] = array(
					'name' => 'Not a font',
					'R'    => __FILE__,
				);
				return $fonts;
			}
		);

		$fonts = new CertificateFonts();
		$extra = array_values( $fonts->extra() );
		$this->assertSame( array( 'Test Sinhala', 'Test Ethiopic' ), wp_list_pluck( $extra, 'name' ), 'Font Library TTF first, then the filter; WOFF2, remote and unreadable files are skipped.' );
		$this->assertSame( wp_normalize_path( $sinhala ), $extra[0]['R'] );
		$this->assertArrayNotHasKey( 'B', $extra[0], 'The WOFF2 bold face cannot be embedded.' );

		$plan = $fonts->plan( 'සුනිල් ደጀኔ ليلى' );
		$this->assertSame( array(), $plan['missing'] );
		$sinhala_key = (string) array_search( 'Test Sinhala', wp_list_pluck( $fonts->extra(), 'name' ), true );
		$this->assertSame( $sinhala_key, $plan['languages']['si'] );
		$this->assertSame( 'notosansarabic', $plan['languages']['und-arab'], 'A bundled font still wins for its script.' );
		$this->assertContains( wp_normalize_path( dirname( $sinhala ) ), array_map( 'wp_normalize_path', $plan['font_dirs'] ) );
		$this->assertSame( 'plms-test-sinhala-400.ttf', $plan['fontdata'][ $sinhala_key ]['R'] );
	}

	public function test_certificates_embed_the_font_for_the_learners_script() {
		$renderer = publiclms()->container()->get( CertificateRenderer::class );
		$base     = array(
			'certificate_key' => 'PLMS-TEST-0000-0000',
			'course_title'    => 'Introduction to Statistics',
			'completed_at'    => '2026-11-04 10:00:00',
			'data'            => array(
				'instructor' => 'Дмитрий Иванов',
				'site'       => 'Test site',
			),
		);
		$cases    = array(
			'ليلى حداد'     => 'NotoSansArabic-Bold',
			'प्रिया शर्मा'  => 'NotoSansDevanagari-Bold',
			'சுப்பிரமணியம்' => 'NotoSansTamil-Bold',
			'সুমিত্রা'      => 'NotoSansBengali-Bold',
			'סמשון'         => 'NotoSansHebrew-Bold',
			'สมศักดิ์'      => 'NotoSansThai-Bold',
		);
		foreach ( $cases as $name => $font ) {
			$pdf = $renderer->pdf( array( 'learner_name' => $name ) + $base );
			$this->assertStringStartsWith( '%PDF-', $pdf );
			$this->assertMatchesRegularExpression( '#/BaseFont /[A-Z]{6}\+' . preg_quote( $font, '#' ) . '\b#', $pdf, "$name is printed in $font." );
			$this->assertMatchesRegularExpression( '#/BaseFont /[A-Z]{6}\+DejaVuSans\b#', $pdf, 'Latin and Cyrillic stay in DejaVu.' );
		}
		$pdf = $renderer->pdf( array( 'learner_name' => '김지우' ) + $base );
		$this->assertStringContainsString( '/BaseFont /HYSMyeongJoStd-Medium-Acro', $pdf, 'Korean falls back to the reader’s Adobe CJK font.' );
	}

	public function test_site_health_names_scripts_without_a_font() {
		$health = publiclms()->container()->get( CertificateHealth::class );
		$this->assertSame( 'good', $health->check()['status'], 'The fixture names are Latin.' );

		$learner = self::factory()->user->create( array( 'display_name' => 'සුනිල් පෙරේරා' ) );
		publiclms()->container()->get( EnrollmentRepository::class )->enroll( $learner, $this->fixture['course'] );
		$check = $health->check();
		$this->assertSame( 'recommended', $check['status'] );
		$this->assertSame( array( 'fonts' ), wp_list_pluck( $check['problems'], 'code' ) );
		$this->assertStringContainsString( 'Sinhala', $check['problems'][0]['message'] );
		$this->assertStringContainsString( 'Sinhala', $health->site_health()['description'] );

		$dir = wp_get_font_dir();
		$this->fake_font( trailingslashit( $dir['path'] ) . 'plms-test-sinhala-400.ttf', 0x0D80, 0x0DFF );
		$this->font_library_family( 'Test Sinhala', array( trailingslashit( $dir['url'] ) . 'plms-test-sinhala-400.ttf' ) );
		$this->assertSame( 'good', $health->check()['status'], 'A Font Library font that covers Sinhala clears it.' );

		$tests = $health->tests( array() );
		$this->assertArrayHasKey( 'publiclms_certificates', $tests['direct'] );

		$names = $health->names();
		$this->assertStringContainsString( 'Introduction to Statistics', $names );
		$this->complete_course_for( $this->subscriber );
		$this->assertStringContainsString( 'Lucía Fernández', $health->names() );
	}

	public function test_the_engine_reads_only_local_allowed_files_and_never_the_network() {
		$uploads = wp_upload_dir( null, false );
		$image   = trailingslashit( (string) $uploads['basedir'] ) . 'plms-test-image.txt';
		file_put_contents( $image, 'local' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->files[] = $image;

		$files = new LocalFiles( array( (string) $uploads['basedir'], PUBLICLMS_PATH ) );
		$this->assertSame( 'local', $files->load( $image ) );
		$this->assertSame( 'local', $files->load( 'file://' . $image ) );
		$this->assertSame( '', $files->load( ABSPATH . 'wp-load.php' ), 'Outside the allowed folders reads as empty.' );
		$this->assertSame( '', $files->load( trailingslashit( (string) $uploads['basedir'] ) . '../../wp-load.php' ), 'A path that climbs out reads as empty.' );

		$this->expectException( InvalidArgumentException::class );
		( new NoNetwork() )->sendRequest( new Request( 'GET', 'https://example.com/logo.png' ) );
	}

	/**
	 * Completes the fixture course for a user.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	private function complete_course_for( int $user_id ): void {
		publiclms()->container()->get( EnrollmentRepository::class )->enroll( $user_id, $this->fixture['course'] );
		foreach ( $this->fixture['lessons'] as $lesson ) {
			publiclms()->container()->get( ProgressService::class )->complete_item( $user_id, $lesson );
		}
		$this->assertNotNull( publiclms()->container()->get( CertificateService::class )->issue( $user_id, $this->fixture['course'] ) );
	}
}
