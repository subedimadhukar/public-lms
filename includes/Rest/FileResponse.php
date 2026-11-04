<?php
/**
 * A REST response that streams a file.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a file (a path, or bytes in memory) through the REST stack so the
 * route's permission callback runs as for any JSON route; `serve()`, hooked
 * to `rest_pre_serve_request`, then sends the bytes instead of JSON.
 *
 * Used for assignment files, the gradebook CSV and certificate PDFs. Headers:
 * the stored content type, `Content-Disposition` with the stored file name
 * (RFC 6266), `X-Content-Type-Options: nosniff`, and `Cache-Control: private,
 * no-store` because every file belongs to one learner.
 */
class FileResponse extends WP_REST_Response {

	/**
	 * Absolute path of the file, or '' when the body is in memory.
	 *
	 * @var string
	 */
	public $file_path = '';

	/**
	 * Bytes to send when there is no path.
	 *
	 * @var string
	 */
	public $body = '';

	/**
	 * File name offered to the browser.
	 *
	 * @var string
	 */
	public $file_name = 'download';

	/**
	 * Content type.
	 *
	 * @var string
	 */
	public $mime = 'application/octet-stream';

	/**
	 * 'attachment' to download, 'inline' to open in the browser.
	 *
	 * @var string
	 */
	public $disposition = 'attachment';

	/**
	 * Builds a response for a file on disk.
	 *
	 * @param string $path        Absolute path.
	 * @param string $name        File name offered to the browser.
	 * @param string $mime        Content type.
	 * @param string $disposition 'attachment' or 'inline'.
	 * @return FileResponse
	 */
	public static function from_path( string $path, string $name, string $mime, string $disposition = 'attachment' ): FileResponse {
		$response              = new self( array( 'file' => $name ) );
		$response->file_path   = $path;
		$response->file_name   = $name;
		$response->mime        = $mime;
		$response->disposition = $disposition;
		return $response;
	}

	/**
	 * Builds a response for bytes in memory.
	 *
	 * @param string $body        Bytes.
	 * @param string $name        File name offered to the browser.
	 * @param string $mime        Content type.
	 * @param string $disposition 'attachment' or 'inline'.
	 * @return FileResponse
	 */
	public static function from_string( string $body, string $name, string $mime, string $disposition = 'attachment' ): FileResponse {
		$response              = new self( array( 'file' => $name ) );
		$response->body        = $body;
		$response->file_name   = $name;
		$response->mime        = $mime;
		$response->disposition = $disposition;
		return $response;
	}

	/**
	 * Returns the size in bytes.
	 *
	 * @return int
	 */
	public function length(): int {
		return '' !== $this->file_path ? (int) filesize( $this->file_path ) : strlen( $this->body );
	}

	/**
	 * Returns the bytes (reads the file when the body is a path).
	 *
	 * @return string
	 */
	public function contents(): string {
		if ( '' === $this->file_path ) {
			return $this->body;
		}
		$contents = file_get_contents( $this->file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local private file.
		return false === $contents ? '' : $contents;
	}

	/**
	 * Sends the file instead of JSON. Hooked to `rest_pre_serve_request`.
	 *
	 * @param bool  $served Whether the request was already served.
	 * @param mixed $result The response.
	 * @return bool
	 */
	public static function serve( $served, $result ): bool {
		if ( $served || ! $result instanceof self || $result->get_status() >= 300 ) {
			return (bool) $served;
		}
		$name  = str_replace( array( '"', "\r", "\n" ), '', $result->file_name );
		$ascii = preg_replace( '/[^\x20-\x7e]/', '_', $name );
		if ( ! headers_sent() ) {
			header( 'Content-Type: ' . $result->mime );
			header( 'Content-Length: ' . $result->length() );
			header( 'Content-Disposition: ' . ( 'inline' === $result->disposition ? 'inline' : 'attachment' ) . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: private, no-store, max-age=0' );
		}
		if ( '' !== $result->file_path ) {
			readfile( $result->file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a private file after the permission check.
		} else {
			echo $result->body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file body (PDF or CSV), sent with its own content type.
		}
		return true;
	}
}
