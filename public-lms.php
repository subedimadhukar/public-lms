<?php
/**
 * Plugin Name:       Public LMS
 * Plugin URI:        https://wpinterface.com/plugins/public-lms/
 * Description:       A block-native learning management system for any block theme: courses, lessons, quizzes, assignments, gradebook and certificates.
 * Version:           0.2.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Author:            WPInterface
 * Author URI:        https://wpinterface.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       public-lms
 * Domain Path:       /languages
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;

define( 'PUBLICLMS_VERSION', '0.2.0' );
define( 'PUBLICLMS_FILE', __FILE__ );
define( 'PUBLICLMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'PUBLICLMS_URL', plugin_dir_url( __FILE__ ) );
define( 'PUBLICLMS_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Composer's autoloader is used when present (release zips and developer
 * checkouts after `composer install`). A source checkout without vendor/,
 * for example a Playground git:directory install, falls back to a minimal
 * PSR-4 loader for the plugin's own classes. See docs/ARCHITECTURE.md, ADR-022.
 */
if ( is_readable( PUBLICLMS_PATH . 'vendor/autoload.php' ) ) {
	require_once PUBLICLMS_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class_name ) {
			$prefix = 'PublicLMS\\';
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}
			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = PUBLICLMS_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

// Action Scheduler registers itself and resolves the newest bundled copy on plugins_loaded.
if ( is_readable( PUBLICLMS_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once PUBLICLMS_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

register_activation_hook( __FILE__, array( 'PublicLMS\\Core\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PublicLMS\\Core\\Installer', 'deactivate' ) );

/**
 * Returns the plugin instance.
 *
 * Pro and add-ons should wait for the `publiclms_loaded` action, which passes
 * the same instance, before calling this.
 *
 * @return \PublicLMS\Core\Plugin
 */
function publiclms() {
	return \PublicLMS\Core\Plugin::instance();
}

add_action( 'plugins_loaded', array( 'PublicLMS\\Core\\Plugin', 'boot' ), 5 );
