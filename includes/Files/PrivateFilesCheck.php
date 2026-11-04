<?php
/**
 * Checks that private uploads cannot be downloaded directly.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Files;

use PublicLMS\Capabilities\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A Site Health test and a Submissions screen notice for the private folder
 * (ADR-043, decision 2 of phase 2).
 *
 * The deny rules the folder carries work on Apache and IIS; nginx ignores
 * them. The test writes a canary file into the private folder, asks the
 * site for it over HTTP (a loopback request), and deletes it:
 * - the canary comes back (200 with its content): critical, with the nginx
 *   rule that closes the folder;
 * - 401, 403 or 404: good;
 * - the request fails or answers something else: "couldn't check", never
 *   "safe".
 * A folder moved outside the uploads folder (PUBLICLMS_PRIVATE_DIR or the
 * `publiclms_private_dir` filter) has no URL and passes. The result is kept
 * for twelve hours for the Submissions screen; Site Health always checks
 * afresh.
 */
final class PrivateFilesCheck {

	/**
	 * Transient that keeps the last result.
	 */
	const TRANSIENT = 'publiclms_private_files_check';

	/**
	 * Canary file name.
	 */
	const CANARY = 'publiclms-canary.txt';

	/**
	 * Private files.
	 *
	 * @var PrivateFiles
	 */
	private $files;

	/**
	 * Constructor.
	 *
	 * @param PrivateFiles $files Private files.
	 */
	public function __construct( PrivateFiles $files ) {
		$this->files = $files;
	}

	/**
	 * Hooks the test and the notice.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Adds the Site Health test.
	 *
	 * @param array<string, mixed> $tests Tests.
	 * @return array<string, mixed>
	 */
	public function tests( $tests ): array {
		$tests                                      = (array) $tests;
		$tests['direct']['publiclms_private_files'] = array(
			'label' => __( 'Public LMS private uploads', 'public-lms' ),
			'test'  => array( $this, 'site_health' ),
		);
		return $tests;
	}

	/**
	 * Runs the check.
	 *
	 * @return array{status: string, code: int, message: string}
	 */
	public function check(): array {
		$dir     = $this->files->base_dir();
		$uploads = wp_upload_dir( null, false );
		$basedir = trailingslashit( (string) $uploads['basedir'] );
		if ( '' === $dir ) {
			return $this->remember( 'unknown', 0, __( 'The private folder could not be created.', 'public-lms' ) );
		}
		if ( 0 !== strpos( $dir, $basedir ) ) {
			return $this->remember( 'outside', 0, '' );
		}

		$token = wp_generate_password( 32, false, false );
		$file  = $dir . self::CANARY;
		if ( false === file_put_contents( $file, $token ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A short-lived canary in the plugin's own folder.
			return $this->remember( 'unknown', 0, __( 'A test file could not be written to the private folder.', 'public-lms' ) );
		}
		$url      = trailingslashit( (string) $uploads['baseurl'] ) . substr( $dir, strlen( $basedir ) ) . self::CANARY;
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter for loopback requests.
			)
		);
		wp_delete_file( $file );

		if ( is_wp_error( $response ) ) {
			return $this->remember( 'unknown', 0, $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code && false !== strpos( (string) wp_remote_retrieve_body( $response ), $token ) ) {
			return $this->remember( 'served', $code, '' );
		}
		if ( in_array( $code, array( 401, 403, 404 ), true ) ) {
			return $this->remember( 'protected', $code, '' );
		}
		/* translators: %d: HTTP status code. */
		return $this->remember( 'unknown', $code, sprintf( __( 'The site answered with HTTP status %d.', 'public-lms' ), $code ) );
	}

	/**
	 * Returns the Site Health result.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health(): array {
		$result = $this->check();
		$base   = array(
			'label'       => __( 'Learners’ uploaded files are private', 'public-lms' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'public-lms' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Public LMS keeps assignment uploads in a folder that only its permission-checked download route reads.', 'public-lms' ) . '</p>',
			'actions'     => '',
			'test'        => 'publiclms_private_files',
		);
		if ( 'served' === $result['status'] ) {
			$base['status']         = 'critical';
			$base['badge']['color'] = 'red';
			$base['label']          = __( 'Learners’ uploaded files can be downloaded directly', 'public-lms' );
			$base['description']    = $this->served_description();
		} elseif ( 'unknown' === $result['status'] ) {
			$base['status']      = 'recommended';
			$base['label']       = __( 'Public LMS could not check that learners’ uploads are private', 'public-lms' );
			$base['description'] = '<p>' . esc_html__( 'The site could not request a test file from the private uploads folder, so it is not known whether the web server blocks it. Uploads are stored under random names without extensions, but ask your host to confirm that the folder is closed to the web.', 'public-lms' ) . '</p>'
				. ( '' === $result['message'] ? '' : '<p>' . esc_html( $result['message'] ) . '</p>' )
				. $this->rule_markup();
		}
		return $base;
	}

	/**
	 * Shows the problem on the Submissions screen.
	 *
	 * @return void
	 */
	public function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || '_page_publiclms-submissions' !== substr( (string) $screen->id, -strlen( '_page_publiclms-submissions' ) ) || ! current_user_can( Capabilities::VIEW_REPORTS ) ) {
			return;
		}
		$result = get_transient( self::TRANSIENT );
		$result = is_array( $result ) ? $result : $this->check();
		if ( 'served' === $result['status'] ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Learners’ uploaded files can be downloaded directly.', 'public-lms' ) . '</strong></p>' . wp_kses_post( $this->served_description() ) . '</div>';
		} elseif ( 'unknown' === $result['status'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Public LMS could not check that learners’ uploaded files are private. See Tools → Site Health for what to ask your host.', 'public-lms' ) . '</p></div>';
		}
	}

	/**
	 * Returns the nginx rule that closes the folder, as HTML.
	 *
	 * @return string
	 */
	public function rule_markup(): string {
		$uploads = wp_upload_dir( null, false );
		$path    = untrailingslashit( (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH ) ) . '/publiclms-private/';
		$rule    = "location ^~ {$path} {\n    deny all;\n    return 404;\n}";
		return '<p>' . esc_html__( 'On nginx, add this to the server block, then reload nginx:', 'public-lms' ) . '</p><pre><code>' . esc_html( $rule ) . '</code></pre>';
	}

	/**
	 * Explains the problem and the fix.
	 *
	 * @return string
	 */
	private function served_description(): string {
		return '<p>' . esc_html__( 'A test file in the private uploads folder could be downloaded by its address. The folder’s deny rules work on Apache and IIS, but this web server ignores them (nginx does). The files have random names, but they should not be reachable at all.', 'public-lms' ) . '</p>'
			. $this->rule_markup()
			. '<p>' . esc_html__( 'Or move the folder outside the web root with the PUBLICLMS_PRIVATE_DIR constant in wp-config.php.', 'public-lms' ) . '</p>';
	}

	/**
	 * Keeps a result for the Submissions screen.
	 *
	 * @param string $status  served, protected, outside or unknown.
	 * @param int    $code    HTTP status.
	 * @param string $message Detail.
	 * @return array{status: string, code: int, message: string}
	 */
	private function remember( string $status, int $code, string $message ): array {
		$result = array(
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
		);
		set_transient( self::TRANSIENT, $result, 12 * HOUR_IN_SECONDS );
		return $result;
	}
}
