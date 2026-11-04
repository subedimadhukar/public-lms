<?php
/**
 * Certificate PDF rendering.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Certificates;

use PublicLMS\Vendor\Dompdf\Dompdf;
use PublicLMS\Vendor\Dompdf\Options;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a certificate: the template's blocks with the certificate's
 * values bound in, wrapped in a print stylesheet, turned into a PDF by the
 * prefixed Dompdf (ADR-013, ADR-044).
 *
 * Dompdf runs with remote loading off and a chroot of the uploads folder
 * and the plugin, so a template can show media library images but nothing
 * is fetched from the network (PRD §5). Preset classes the blocks print
 * (colours and font sizes) are written out as plain values, because Dompdf
 * does not resolve CSS custom properties or clamp(). Fonts: DejaVu Sans and
 * Serif ship with Dompdf and cover Latin, Greek and Cyrillic; scripts such
 * as Devanagari need a font added through `publiclms_certificate_css`.
 */
class CertificateRenderer {

	/**
	 * Template.
	 *
	 * @var CertificateTemplate
	 */
	private $template;

	/**
	 * Constructor.
	 *
	 * @param CertificateTemplate $template Template.
	 */
	public function __construct( CertificateTemplate $template ) {
		$this->template = $template;
	}

	/**
	 * Whether the PDF library is available.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		if ( ! class_exists( Dompdf::class ) && is_readable( PUBLICLMS_PATH . 'vendor-prefixed/autoload.php' ) ) {
			require_once PUBLICLMS_PATH . 'vendor-prefixed/autoload.php';
		}
		return class_exists( Dompdf::class );
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

		return '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><title>'
			. esc_html( (string) $certificate['course_title'] ) . '</title><style>' . $this->css() . '</style></head><body><div class="publiclms-certificate">'
			. $body . '</div></body></html>';
	}

	/**
	 * Renders the certificate as PDF bytes.
	 *
	 * @param array<string, mixed> $certificate Certificate row.
	 * @return string
	 * @throws RuntimeException When the PDF library is missing.
	 */
	public function pdf( array $certificate ): string {
		if ( ! self::available() ) {
			throw new RuntimeException( 'The PDF library is not installed.' );
		}
		$uploads = wp_upload_dir( null, false );
		$temp    = get_temp_dir();
		$options = new Options();
		$options->setIsRemoteEnabled( false );
		$options->setIsPhpEnabled( false );
		$options->setIsJavascriptEnabled( false );
		$options->setIsHtml5ParserEnabled( true );
		$options->setDefaultFont( 'DejaVu Sans' );
		$options->setChroot( array( (string) $uploads['basedir'], PUBLICLMS_PATH ) );
		$options->setTempDir( $temp );
		$options->setFontCache( $temp );
		$options->setDefaultPaperSize( 'a4' );
		$options->setDefaultPaperOrientation( 'landscape' );

		/**
		 * Filters the Dompdf options for certificates (paper size, fonts, DPI).
		 *
		 * @param Options              $options     Dompdf options.
		 * @param array<string, mixed> $certificate Certificate row.
		 */
		$options = apply_filters( 'publiclms_certificate_pdf_options', $options, $certificate );

		$dompdf = new Dompdf( $options instanceof Options ? $options : new Options() );
		$dompdf->loadHtml( $this->html( $certificate ), 'UTF-8' );
		$dompdf->render();
		return (string) $dompdf->output();
	}

	/**
	 * Returns sample values for previewing the template.
	 *
	 * @return array<string, mixed>
	 */
	public static function sample(): array {
		return array(
			'certificate_key' => 'PLMS-SAMP-LE00-0000',
			'learner_name'    => __( 'Sita Gurung', 'public-lms' ),
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
body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #1e1e1e; font-size: 12pt; }
.publiclms-certificate { border: 3pt double #1e3a8a; padding: 18mm 20mm; height: 140mm; text-align: center; }
.publiclms-certificate p, .publiclms-certificate h1, .publiclms-certificate h2 { margin: 0 0 4mm; }
.has-text-align-center { text-align: center; } .has-text-align-left { text-align: left; } .has-text-align-right { text-align: right; }
.publiclms-certificate__site { font-size: 11pt; letter-spacing: 2pt; text-transform: uppercase; color: #3c434a; }
.publiclms-certificate__title { font-family: "DejaVu Serif", serif; font-size: 30pt; font-weight: bold; color: #1e3a8a; margin-bottom: 8mm; }
.publiclms-certificate__lead { font-size: 12pt; color: #3c434a; }
.publiclms-certificate__name { font-family: "DejaVu Serif", serif; font-size: 26pt; font-weight: bold; }
.publiclms-certificate__course { font-size: 18pt; font-weight: bold; }
.publiclms-certificate__date { font-size: 12pt; }
.publiclms-certificate__rule { border: 0; border-top: 1pt solid #8c8f94; width: 40%; margin: 6mm auto; }
.publiclms-certificate__instructor { font-size: 11pt; }
.publiclms-certificate__id { font-size: 9pt; color: #50575e; margin-top: 6mm; }
img { max-width: 100%; height: auto; }
' . $this->preset_css();

		/**
		 * Filters the certificate stylesheet. Add @font-face rules for other scripts here.
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
	 * Points media library image URLs at their files, so Dompdf reads them locally.
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
				if ( 0 === strpos( $src, $base ) ) {
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
