<?php
/**
 * Certificate PDF rendering.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Certificates;

use PublicLMS\Certificates\Pdf\LanguageToFont;
use PublicLMS\Certificates\Pdf\LocalFiles;
use PublicLMS\Certificates\Pdf\NoNetwork;
use PublicLMS\Vendor\Mpdf\Container\SimpleContainer;
use PublicLMS\Vendor\Mpdf\Mpdf;
use PublicLMS\Vendor\Mpdf\Output\Destination;
use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a certificate: the template's blocks with the certificate's
 * values bound in, wrapped in a print stylesheet, turned into a PDF by the
 * prefixed, patched mPDF (ADR-013, ADR-044, ADR-052).
 *
 * The engine shapes complex scripts (Arabic joining, Indic conjuncts and vowel
 * signs, Thai marks) with the fonts' OpenType tables and lays out mixed
 * right-to-left and left-to-right text with the Unicode bidi algorithm.
 * `CertificateFonts` picks a font for each script in the text. Nothing is
 * fetched from the network, and images and stylesheets are read only from
 * the uploads folder and the plugin (PRD §5). Preset classes the blocks
 * print (colours and font sizes) are written out as plain values, because
 * mPDF does not resolve CSS custom properties or clamp().
 */
class CertificateRenderer {

	/**
	 * Template.
	 *
	 * @var CertificateTemplate
	 */
	private $template;

	/**
	 * Fonts.
	 *
	 * @var CertificateFonts
	 */
	private $fonts;

	/**
	 * Constructor.
	 *
	 * @param CertificateTemplate   $template Template.
	 * @param CertificateFonts|null $fonts    Fonts.
	 */
	public function __construct( CertificateTemplate $template, ?CertificateFonts $fonts = null ) {
		$this->template = $template;
		$this->fonts    = $fonts ?? new CertificateFonts();
	}

	/**
	 * Whether the PDF library and the PHP extension it cannot work without are available.
	 *
	 * GD is not checked here: without it text still prints, and only some
	 * images (transparent PNG, GIF, WebP) are left out. Site Health reports it.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		if ( ! class_exists( Mpdf::class ) && is_readable( PUBLICLMS_PATH . 'vendor-prefixed/autoload.php' ) ) {
			require_once PUBLICLMS_PATH . 'vendor-prefixed/autoload.php';
		}
		return class_exists( Mpdf::class ) && function_exists( 'mb_substr' );
	}

	/**
	 * Renders the certificate's HTML document.
	 *
	 * @param array<string, mixed> $certificate Certificate row.
	 * @return string
	 */
	public function html( array $certificate ): string {
		$post    = $this->template->post();
		$content = null === $post ? CertificateTemplate::default_content() : (string) $post->post_content;

		CertificateFields::set_current( $certificate );
		$body = do_blocks( $content );
		CertificateFields::set_current( null );

		$body = wp_kses_post( $body );
		$body = $this->localise_images( $body );

		// No lang on <html>: mPDF would give the whole page that language's font, and a font
		// for one script often has no Latin. mPDF tags each run of text by its script instead.
		return '<!DOCTYPE html><html dir="' . ( is_rtl() ? 'rtl' : 'ltr' ) . '"><head><meta charset="utf-8"><title>'
			. esc_html( (string) $certificate['course_title'] ) . '</title><style>' . $this->css() . '</style></head><body><div class="publiclms-certificate">'
			. $body . '</div></body></html>';
	}

	/**
	 * Renders the certificate as PDF bytes.
	 *
	 * @param array<string, mixed> $certificate Certificate row.
	 * @return string
	 * @throws RuntimeException When the PDF library is missing or fails.
	 */
	public function pdf( array $certificate ): string {
		if ( ! self::available() ) {
			throw new RuntimeException( 'The PDF library is not installed.' );
		}
		$html    = $this->html( $certificate );
		$plan    = $this->fonts->plan( $html );
		$uploads = wp_upload_dir( null, false );
		$temp    = self::temp_dir();

		$config = array(
			'mode'             => 'utf-8',
			'format'           => 'A4-L',
			'orientation'      => 'L',
			'tempDir'          => $temp,
			'fontDir'          => $plan['font_dirs'],
			'fontdata'         => $plan['fontdata'],
			'default_font'     => 'dejavusans',
			'autoScriptToLang' => true,
			'autoLangToFont'   => true,
			'autoArabic'       => true,
			'autoVietnamese'   => true,
			'baseScript'       => 1,
			'useAdobeCJK'      => true,
			'biDirectional'    => true,
			'languageToFont'   => new LanguageToFont( $plan['languages'] ),
			'showImageErrors'  => false,
			'debug'            => false,
		);

		/**
		 * Filters the mPDF configuration for certificates (paper size, margins, fonts).
		 *
		 * @param array<string, mixed> $config      mPDF configuration.
		 * @param array<string, mixed> $certificate Certificate row.
		 */
		$config = (array) apply_filters( 'publiclms_certificate_pdf_options', $config, $certificate );

		$container = new SimpleContainer(
			array(
				'httpClient'         => new NoNetwork(),
				'localContentLoader' => new LocalFiles( array( (string) $uploads['basedir'], PUBLICLMS_PATH ) ),
			)
		);

		try {
			$mpdf = new Mpdf( $config, $container );
			$mpdf->SetDirectionality( is_rtl() ? 'rtl' : 'ltr' );
			$mpdf->SetTitle( (string) $certificate['course_title'] );
			$mpdf->SetCreator( 'Public LMS' );
			$mpdf->WriteHTML( $html );
			return (string) $mpdf->Output( '', Destination::STRING_RETURN );
		} catch ( Throwable $error ) {
			throw new RuntimeException( 'The certificate PDF could not be made: ' . $error->getMessage(), 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Never printed; the REST controller answers with its own message.
		}
	}

	/**
	 * Returns mPDF's working folder for this site and plugin version (font caches).
	 *
	 * A new version gets a fresh folder, so fonts are re-read with the mPDF patches it ships.
	 *
	 * @return string
	 */
	public static function temp_dir(): string {
		$dir = trailingslashit( get_temp_dir() ) . 'publiclms-mpdf-' . substr( md5( ABSPATH . '|' . get_current_blog_id() ), 0, 10 ) . '/' . PUBLICLMS_VERSION;
		wp_mkdir_p( $dir );
		return $dir;
	}

	/**
	 * Returns sample values for previewing the template.
	 *
	 * @return array<string, mixed>
	 */
	public static function sample(): array {
		return array(
			'certificate_key' => 'PLMS-SAMP-LE00-0000',
			/* translators: Sample learner name in the certificate preview; use a name common in your language. */
			'learner_name'    => __( 'Alex Morgan', 'public-lms' ),
			'course_title'    => __( 'Bookkeeping Basics for Small Businesses', 'public-lms' ),
			'completed_at'    => gmdate( 'Y-m-d H:i:s' ),
			'data'            => array(
				'instructor' => wp_get_current_user()->display_name,
				'site'       => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
			),
		);
	}

	/**
	 * Returns the print stylesheet: the frame, the type scale and the presets as plain values.
	 *
	 * @return string
	 */
	public function css(): string {
		$css = '@page { margin: 14mm; }
body { margin: 0; font-family: dejavusans, sans-serif; color: #1e1e1e; font-size: 12pt; }
.publiclms-certificate { border: 3pt double #1e3a8a; padding: 18mm 20mm; height: 140mm; text-align: center; }
.publiclms-certificate p, .publiclms-certificate h1, .publiclms-certificate h2 { margin: 0 0 4mm; }
.has-text-align-center { text-align: center; } .has-text-align-left { text-align: left; } .has-text-align-right { text-align: right; }
.publiclms-certificate__site { font-size: 11pt; letter-spacing: 2pt; text-transform: uppercase; color: #3c434a; }
.publiclms-certificate__title { font-family: dejavuserif, serif; font-size: 30pt; font-weight: bold; color: #1e3a8a; margin-bottom: 8mm; }
.publiclms-certificate__lead { font-size: 12pt; color: #3c434a; }
.publiclms-certificate__name { font-family: dejavuserif, serif; font-size: 26pt; font-weight: bold; }
.publiclms-certificate__course { font-size: 18pt; font-weight: bold; }
.publiclms-certificate__date { font-size: 12pt; }
.publiclms-certificate__rule { border: 0; border-top: 1pt solid #8c8f94; width: 40%; margin: 6mm auto; }
.publiclms-certificate__instructor { font-size: 11pt; }
.publiclms-certificate__id { font-size: 9pt; color: #50575e; margin-top: 6mm; }
img { max-width: 100%; height: auto; }
' . $this->preset_css();

		/**
		 * Filters the certificate stylesheet.
		 *
		 * Fonts for other scripts come from the Font Library or the
		 * `publiclms_certificate_fonts` filter, not from @font-face here.
		 *
		 * @param string $css CSS.
		 */
		return (string) apply_filters( 'publiclms_certificate_css', $css );
	}

	/**
	 * Writes the theme's colour and font-size presets as plain CSS for the classes blocks print.
	 *
	 * @return string
	 */
	private function preset_css(): string {
		$css      = '';
		$settings = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings() : array();
		foreach ( (array) ( $settings['color']['palette']['theme'] ?? array() ) as $color ) {
			$slug  = _wp_to_kebab_case( (string) ( $color['slug'] ?? '' ) );
			$value = (string) ( $color['color'] ?? '' );
			if ( '' !== $slug && preg_match( '/^#[0-9a-f]{3,8}$|^rgba?\([\d.,\s%]+\)$/i', $value ) ) {
				$css .= ".has-{$slug}-color{color:{$value};}.has-{$slug}-background-color{background-color:{$value};}";
			}
		}
		foreach ( (array) ( $settings['typography']['fontSizes']['theme'] ?? array() ) as $size ) {
			$slug  = _wp_to_kebab_case( (string) ( $size['slug'] ?? '' ) );
			$value = (string) ( $size['size'] ?? '' );
			if ( ! empty( $size['fluid']['max'] ) ) {
				$value = (string) $size['fluid']['max'];
			} elseif ( preg_match( '/clamp\([^,]+,[^,]+,\s*([^)]+)\)/', $value, $match ) ) {
				$value = trim( $match[1] );
			}
			if ( '' !== $slug && preg_match( '/^[\d.]+(px|rem|em|pt|%)$/', $value ) ) {
				$css .= ".has-{$slug}-font-size{font-size:{$value};}";
			}
		}
		return $css;
	}

	/**
	 * Points media library image URLs at their files, so mPDF reads them locally.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function localise_images( string $html ): string {
		$uploads = wp_upload_dir( null, false );
		$base    = (string) $uploads['baseurl'];
		$dir     = (string) $uploads['basedir'];
		return (string) preg_replace_callback(
			'#(<img[^>]+src=")([^"]+)(")#i',
			static function ( $found ) use ( $base, $dir ) {
				$src = html_entity_decode( $found[2] );
				if ( 0 === strpos( $src, $base ) && false === strpos( $src, '..' ) ) {
					$path = $dir . substr( $src, strlen( $base ) );
					return $found[1] . esc_attr( $path ) . $found[3];
				}
				// Anything else would be fetched from the network; leave it out.
				return $found[1] . $found[3];
			},
			$html
		);
	}
}
