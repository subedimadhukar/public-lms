<?php
/**
 * Private file storage.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Files;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores learner uploads where no URL reaches them (ADR-043).
 *
 * Files go to `{uploads}/publiclms-private/{site token}/{Y}/{m}/{random}`:
 * outside the media library, under a folder with deny rules for Apache
 * (.htaccess) and IIS (web.config) and an empty index.php, with a random
 * site token in the path and a random 32-character name with no extension.
 * Even on a server that ignores the deny rules (nginx), a file is not
 * guessable and is never executed or sniffed as HTML, because it has no
 * extension. Sites can move the folder outside the web root with the
 * PUBLICLMS_PRIVATE_DIR constant or the `publiclms_private_dir` filter.
 *
 * Every download passes a permission-checked REST route that streams the
 * file with its stored name and type (Rest\FileResponse).
 *
 * Uploads are checked three ways: the extension against the assignment's
 * list (itself limited to SAFE_TYPES), the size against the assignment's
 * limit and the server's, and the content against the extension with
 * wp_check_filetype_and_ext().
 */
class PrivateFiles {

	/**
	 * Extensions learners may upload, with their MIME types. No HTML, SVG,
	 * scripts or executables.
	 */
	const SAFE_TYPES = array(
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'odt'  => 'application/vnd.oasis.opendocument.text',
		'rtf'  => 'application/rtf',
		'txt'  => 'text/plain',
		'csv'  => 'text/csv',
		'xls'  => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
		'ppt'  => 'application/vnd.ms-powerpoint',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'odp'  => 'application/vnd.oasis.opendocument.presentation',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'mp3'  => 'audio/mpeg',
		'm4a'  => 'audio/mp4',
		'wav'  => 'audio/wav',
		'mp4'  => 'video/mp4',
		'mov'  => 'video/quicktime',
		'webm' => 'video/webm',
		'zip'  => 'application/zip',
	);

	/**
	 * Option holding the random folder token of this site.
	 */
	const TOKEN_OPTION = 'publiclms_private_token';

	/**
	 * Returns the private folder, creating it with its guards when missing.
	 *
	 * @return string Absolute path with a trailing slash, or '' when it cannot be created.
	 */
	public function base_dir(): string {
		$parent = '';
		if ( defined( 'PUBLICLMS_PRIVATE_DIR' ) && '' !== (string) constant( 'PUBLICLMS_PRIVATE_DIR' ) ) {
			$dir = trailingslashit( (string) constant( 'PUBLICLMS_PRIVATE_DIR' ) );
		} else {
			$uploads = wp_upload_dir( null, false );
			$token   = (string) get_option( self::TOKEN_OPTION, '' );
			if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
				$token = bin2hex( random_bytes( 16 ) );
				update_option( self::TOKEN_OPTION, $token, false );
			}
			$parent = trailingslashit( (string) $uploads['basedir'] ) . 'publiclms-private/';
			$dir    = $parent . $token . '/';
		}

		/**
		 * Filters the private files folder. Return an absolute path outside the
		 * web root to keep uploads off the web server entirely.
		 *
		 * @param string $dir Folder with a trailing slash.
		 */
		$dir = trailingslashit( (string) apply_filters( 'publiclms_private_dir', $dir ) );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$this->guard( $dir );
		if ( '' !== $parent && 0 === strpos( $dir, $parent ) ) {
			$this->guard( $parent );
		}
		return $dir;
	}

	/**
	 * Validates and stores one uploaded file.
	 *
	 * @param array<string, mixed> $file       One entry of $_FILES: name, tmp_name, size, error.
	 * @param array<int, string>   $extensions Allowed extensions (intersected with SAFE_TYPES).
	 * @param int                  $max_bytes  Largest allowed size.
	 * @return array{id: string, name: string, path: string, size: int, type: string}|WP_Error
	 */
	public function store( array $file, array $extensions, int $max_bytes ) {
		$name  = sanitize_file_name( wp_basename( (string) ( $file['name'] ?? '' ) ) );
		$tmp   = (string) ( $file['tmp_name'] ?? '' );
		$size  = (int) ( $file['size'] ?? 0 );
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( UPLOAD_ERR_OK !== $error ) {
			return $this->refuse(
				in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true )
					/* translators: %s: file name. */
					? sprintf( __( '%s is larger than the server accepts.', 'public-lms' ), $name )
					/* translators: %s: file name. */
					: sprintf( __( '%s did not upload completely. Try again.', 'public-lms' ), $name )
			);
		}
		if ( '' === $tmp || ! is_readable( $tmp ) || ! $this->is_upload( $tmp ) ) {
			return $this->refuse( __( 'The upload could not be read.', 'public-lms' ) );
		}
		$size = $size > 0 ? $size : (int) filesize( $tmp );
		if ( $size <= 0 ) {
			/* translators: %s: file name. */
			return $this->refuse( sprintf( __( '%s is empty.', 'public-lms' ), $name ) );
		}
		if ( $size > $max_bytes ) {
			/* translators: 1: file name, 2: size limit such as 10 MB. */
			return $this->refuse( sprintf( __( '%1$s is larger than %2$s.', 'public-lms' ), $name, size_format( $max_bytes ) ) );
		}

		$allowed = array_intersect_key( self::SAFE_TYPES, array_flip( array_map( 'strtolower', $extensions ) ) );
		$mimes   = array();
		foreach ( $allowed as $extension => $mime ) {
			$mimes[ $extension ] = $mime;
		}
		$check = wp_check_filetype_and_ext( $tmp, $name, $mimes );
		$ext   = strtolower( (string) ( $check['ext'] ?? '' ) );
		$type  = (string) ( $check['type'] ?? '' );
		if ( '' === $ext || '' === $type || ! isset( $allowed[ $ext ] ) ) {
			/* translators: 1: file name, 2: list of file types. */
			return $this->refuse( sprintf( __( '%1$s is not an accepted file type. Accepted: %2$s.', 'public-lms' ), $name, strtoupper( implode( ', ', array_keys( $allowed ) ) ) ) );
		}
		if ( ! empty( $check['proper_filename'] ) ) {
			$name = sanitize_file_name( (string) $check['proper_filename'] );
		}

		$base = $this->base_dir();
		if ( '' === $base ) {
			return new WP_Error( 'publiclms_upload_storage', __( 'The site cannot store uploads right now. Ask the site owner to check the uploads folder.', 'public-lms' ), array( 'status' => 500 ) );
		}
		$relative = gmdate( 'Y/m/' ) . bin2hex( random_bytes( 16 ) );
		$target   = $base . $relative;
		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return new WP_Error( 'publiclms_upload_storage', __( 'The upload could not be saved.', 'public-lms' ), array( 'status' => 500 ) );
		}
		$this->guard( trailingslashit( dirname( $target ) ) );
		$moved = is_uploaded_file( $tmp ) ? move_uploaded_file( $tmp, $target ) : copy( $tmp, $target );
		if ( ! $moved ) {
			return new WP_Error( 'publiclms_upload_storage', __( 'The upload could not be saved.', 'public-lms' ), array( 'status' => 500 ) );
		}
		chmod( $target, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private file: owner read and write, group read.

		return array(
			'id'   => 'f' . strtolower( wp_generate_password( 11, false, false ) ),
			'name' => '' === $name ? 'file.' . $ext : $name,
			'path' => $relative,
			'size' => $size,
			'type' => $type,
		);
	}

	/**
	 * Returns the absolute path of a stored file, or '' when it is missing or outside the folder.
	 *
	 * @param string $relative Path as stored.
	 * @return string
	 */
	public function path( string $relative ): string {
		if ( ! preg_match( '#^\d{4}/\d{2}/[a-f0-9]{32}$#', $relative ) ) {
			return '';
		}
		$base = $this->base_dir();
		$path = $base . $relative;
		$real = realpath( $path );
		$root = realpath( $base );
		if ( false === $real || false === $root || 0 !== strpos( $real, $root ) || ! is_file( $real ) ) {
			return '';
		}
		return $real;
	}

	/**
	 * Deletes a stored file.
	 *
	 * @param string $relative Path as stored.
	 * @return bool
	 */
	public function delete( string $relative ): bool {
		$path = $this->path( $relative );
		return '' !== $path && wp_delete_file_from_directory( $path, $this->base_dir() );
	}

	/**
	 * Returns the extensions an upload field accepts, as an `accept` attribute value.
	 *
	 * @param array<int, string> $extensions Extensions.
	 * @return string
	 */
	public static function accept( array $extensions ): string {
		$list = array();
		foreach ( $extensions as $extension ) {
			if ( isset( self::SAFE_TYPES[ $extension ] ) ) {
				$list[] = '.' . $extension;
			}
		}
		return implode( ',', $list );
	}

	/**
	 * Whether a temporary file came from this request's upload.
	 *
	 * Tests and imports pass local files; they opt in with the
	 * `publiclms_private_files_allow_local` filter.
	 *
	 * @param string $tmp Temporary path.
	 * @return bool
	 */
	protected function is_upload( string $tmp ): bool {
		if ( is_uploaded_file( $tmp ) ) {
			return true;
		}
		/**
		 * Filters whether a local file (not from this request's upload) may be stored.
		 *
		 * @param bool   $allow Default false.
		 * @param string $tmp   Path.
		 */
		return (bool) apply_filters( 'publiclms_private_files_allow_local', false, $tmp );
	}

	/**
	 * Writes the deny rules and an empty index into a folder once.
	 *
	 * @param string $dir Folder with a trailing slash.
	 * @return void
	 */
	private function guard( string $dir ): void {
		$files = array(
			'.htaccess'  => "# Public LMS private files: no direct access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $file => $body ) {
			if ( ! file_exists( $dir . $file ) && wp_is_writable( $dir ) ) {
				file_put_contents( $dir . $file, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Guard files written once into the plugin's private folder.
			}
		}
	}

	/**
	 * Returns an upload refusal.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private function refuse( string $message ): WP_Error {
		return new WP_Error( 'publiclms_upload_refused', $message, array( 'status' => 400 ) );
	}
}
