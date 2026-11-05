<?php
/**
 * The file reader mPDF gets, kept inside allowed folders.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Certificates\Pdf;

use PublicLMS\Vendor\Mpdf\File\LocalContentLoaderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Reads images and stylesheets a certificate refers to, but only from the
 * given folders (the uploads folder and the plugin), as Dompdf's chroot did
 * (ADR-052). Anything else reads as empty, which mPDF treats as a missing
 * image.
 *
 * Load only after the prefixed mPDF (`CertificateRenderer::available()`).
 */
final class LocalFiles implements LocalContentLoaderInterface {

	/**
	 * Allowed folders, resolved, with a trailing slash.
	 *
	 * @var array<int, string>
	 */
	private $roots = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $roots Allowed folders.
	 */
	public function __construct( array $roots ) {
		foreach ( $roots as $root ) {
			$real = realpath( $root );
			if ( false !== $real ) {
				$this->roots[] = trailingslashit( wp_normalize_path( $real ) );
			}
		}
	}

	/**
	 * Whether a path is inside an allowed folder.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function allows( string $path ): bool {
		if ( 0 === strpos( $path, 'file://' ) ) {
			$path = substr( $path, 7 );
		}
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) ) {
			return false;
		}
		$real = wp_normalize_path( $real );
		foreach ( $this->roots as $root ) {
			if ( 0 === strpos( $real, $root ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads a file, or returns an empty string outside the allowed folders.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public function load( $path ) {
		$path = (string) $path;
		if ( ! $this->allows( $path ) ) {
			return '';
		}
		$path = 0 === strpos( $path, 'file://' ) ? substr( $path, 7 ) : $path;
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file inside the allowed folders.
	}
}
