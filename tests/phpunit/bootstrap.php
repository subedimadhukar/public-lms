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

/*
 * The test suite empties WordPress's own tables before the run but not the
 * plugin's. The Playwright journey shares this database (the wp-env tests
 * site), so start from empty plugin tables too; user IDs restart after the
 * reset and would otherwise match a previous run's enrolments.
 */
foreach ( \PublicLMS\Core\Installer::tables() as $publiclms_table ) {
	$GLOBALS['wpdb']->query( 'TRUNCATE TABLE ' . esc_sql( $publiclms_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Test bootstrap; table names come from the installer.
}
