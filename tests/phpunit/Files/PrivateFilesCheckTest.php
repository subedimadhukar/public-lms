<?php
/**
 * Private uploads Site Health test (decision 2).
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Files;

use PublicLMS\Files\PrivateFiles;
use PublicLMS\Files\PrivateFilesCheck;
use PublicLMS\Tests\TestCase;
use WP_Error;

/**
 * Served, blocked, and impossible to check.
 */
class PrivateFilesCheckTest extends TestCase {

	/**
	 * The check.
	 *
	 * @var PrivateFilesCheck
	 */
	private $check;

	/**
	 * URLs requested.
	 *
	 * @var array<int, string>
	 */
	private $requested = array();

	public function set_up() {
		parent::set_up();
		$this->check = new PrivateFilesCheck( new PrivateFiles() );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_transient( PrivateFilesCheck::TRANSIENT );
		parent::tear_down();
	}

	/**
	 * Answers the loopback request.
	 *
	 * @param callable $answer Gets the URL, returns a response array or WP_Error.
	 * @return void
	 */
	private function answer( callable $answer ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $answer ) {
				$this->requested[] = $url;
				return $answer( $url );
			},
			10,
			3
		);
	}

	/**
	 * Builds a response.
	 *
	 * @param int    $code Status.
	 * @param string $body Body.
	 * @return array<string, mixed>
	 */
	private function response( int $code, string $body = '' ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function test_a_served_canary_is_critical_and_names_the_nginx_rule() {
		$this->answer(
			function ( $url ) {
				$uploads = wp_upload_dir( null, false );
				$path    = $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );
				return $this->response( 200, (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test web server.
			}
		);
		$result = $this->check->site_health();
		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'location ^~ ', $result['description'] );
		$this->assertStringContainsString( '/publiclms-private/', $result['description'] );
		$this->assertStringEndsWith( '/' . PrivateFilesCheck::CANARY, $this->requested[0] );
		$this->assertFileDoesNotExist( ( new PrivateFiles() )->base_dir() . PrivateFilesCheck::CANARY, 'The canary is deleted.' );
		$this->assertSame( 'served', get_transient( PrivateFilesCheck::TRANSIENT )['status'] );
	}

	public function test_a_blocked_canary_is_good() {
		$this->answer(
			function () {
				return $this->response( 403, 'Forbidden' );
			}
		);
		$this->assertSame( 'good', $this->check->site_health()['status'] );
	}

	public function test_a_failed_loopback_is_never_reported_safe() {
		$this->answer(
			static function () {
				return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
			}
		);
		$result = $this->check->site_health();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'could not check', strtolower( $result['label'] ) );
		$this->assertStringContainsString( 'cURL error 7', $result['description'] );
	}

	public function test_a_200_without_the_canary_is_not_called_safe_either() {
		$this->answer(
			function () {
				return $this->response( 200, '<html>a custom 404 page</html>' );
			}
		);
		$this->assertSame( 'recommended', $this->check->site_health()['status'] );
	}

	public function test_a_folder_outside_uploads_passes() {
		$outside = trailingslashit( get_temp_dir() ) . 'publiclms-outside-' . wp_generate_password( 6, false );
		add_filter(
			'publiclms_private_dir',
			static function () use ( $outside ) {
				return $outside;
			}
		);
		$this->answer(
			function () {
				$this->fail( 'No request is needed for a folder without a URL.' );
			}
		);
		$this->assertSame( 'good', $this->check->site_health()['status'] );
		remove_all_filters( 'publiclms_private_dir' );
	}

	public function test_it_is_registered_with_site_health() {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter.
		$this->assertArrayHasKey( 'publiclms_private_files', $tests['direct'] );
	}
}
