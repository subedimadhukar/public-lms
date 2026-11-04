<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * Under wp-env, WP_TESTS_DIR is set inside the tests containers. Elsewhere,
 * point WP_TESTS_DIR at a checkout of wordpress-develop's tests/phpunit.
 *
 * @package PublicLMS
 */

$publiclms_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $publiclms_tests_dir ) {
	$publiclms_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $publiclms_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress test suite in {$publiclms_tests_dir}. Set WP_TESTS_DIR or run `npm run test:php`.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Composer autoloads the PHPUnit Polyfills, which the WordPress test suite detects.
require_once $publiclms_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/public-lms.php';
	}
);

require $publiclms_tests_dir . '/includes/bootstrap.php';
