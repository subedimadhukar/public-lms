<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Data is kept unless the site owner opted in to removing it.
 * See includes/Core/Uninstaller.php.
 *
 * @package PublicLMS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'PUBLICLMS_PATH' ) ) {
	define( 'PUBLICLMS_PATH', plugin_dir_path( __FILE__ ) );
}

if ( is_readable( PUBLICLMS_PATH . 'vendor/autoload.php' ) ) {
	require_once PUBLICLMS_PATH . 'vendor/autoload.php';
} else {
	foreach ( array( 'Core/Installer', 'Core/Uninstaller', 'Capabilities/Capabilities', 'Capabilities/Roles', 'PostTypes/PostTypes', 'Taxonomies/Taxonomies' ) as $publiclms_class_file ) {
		require_once PUBLICLMS_PATH . 'includes/' . $publiclms_class_file . '.php';
	}
}

\PublicLMS\Core\Uninstaller::uninstall();
