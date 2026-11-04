<?php
/**
 * Uninstall routine.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Core;

use PublicLMS\Capabilities\Roles;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Taxonomies\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Removes plugin data when the plugin is deleted.
 *
 * Learner records, orders and certificates are business data, so they are
 * kept unless the site owner opts in, either with the PUBLICLMS_REMOVE_ALL_DATA
 * constant or the `publiclms_remove_data_on_uninstall` option set to 'yes'.
 * See docs/ARCHITECTURE.md, ADR-021.
 */
final class Uninstaller {

	/**
	 * Uninstalls on every site of a network, or on the current site.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return;
		}

		$site_ids = get_sites(
			array(
				'fields'                 => 'ids',
				'number'                 => 0,
				'update_site_meta_cache' => false,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::uninstall_site();
			restore_current_blog();
		}
	}

	/**
	 * Uninstalls on the current site.
	 *
	 * @return void
	 */
	private static function uninstall_site(): void {
		delete_option( Installer::FLUSH_OPTION );

		if ( ! self::should_remove_data() ) {
			return;
		}

		self::delete_content();
		self::drop_tables();
		self::delete_private_files();
		Roles::uninstall();

		delete_option( Installer::DB_VERSION_OPTION );
		delete_option( Installer::VERSION_OPTION );
		delete_option( 'publiclms_remove_data_on_uninstall' );
		delete_option( 'publiclms_dashboard_page_id' );
		delete_option( 'publiclms_instructor_page_id' );
		delete_option( \PublicLMS\Files\PrivateFiles::TOKEN_OPTION );
	}

	/**
	 * Deletes the private uploads folder of this site (assignment files).
	 *
	 * @return void
	 */
	private static function delete_private_files(): void {
		$dir = ( new \PublicLMS\Files\PrivateFiles() )->base_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item instanceof \SplFileInfo ) {
				if ( $item->isDir() ) {
					rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the plugin's own private folder on opted-in uninstall.
				} else {
					wp_delete_file( $item->getPathname() );
				}
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Same as above.
	}

	/**
	 * Whether the site owner opted in to removing all data.
	 *
	 * @return bool
	 */
	private static function should_remove_data(): bool {
		if ( defined( 'PUBLICLMS_REMOVE_ALL_DATA' ) ) {
			return (bool) constant( 'PUBLICLMS_REMOVE_ALL_DATA' );
		}
		return 'yes' === get_option( 'publiclms_remove_data_on_uninstall', 'no' );
	}

	/**
	 * Deletes every post of the plugin's post types and every term of its taxonomies.
	 *
	 * Post types and taxonomies are not registered during uninstall, so posts
	 * are removed with wp_delete_post() by ID and terms with direct queries.
	 *
	 * @return void
	 */
	private static function delete_content(): void {
		global $wpdb;

		foreach ( PostTypes::KEYS as $post_type ) {
			do {
				$deleted = 0;
				$ids     = get_posts(
					array(
						'post_type'        => $post_type,
						'post_status'      => array_keys( get_post_stati() ),
						'numberposts'      => 100,
						'fields'           => 'ids',
						'suppress_filters' => true,
					)
				);
				foreach ( $ids as $id ) {
					if ( wp_delete_post( (int) $id, true ) ) {
						++$deleted;
					}
				}
			} while ( $deleted > 0 );
		}

		foreach ( Taxonomies::KEYS as $taxonomy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Taxonomies are not registered during uninstall.
			$term_ids = $wpdb->get_col(
				$wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same as above.
			$wpdb->delete( $wpdb->term_taxonomy, array( 'taxonomy' => $taxonomy ), array( '%s' ) );
			foreach ( $term_ids as $term_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same as above.
				$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $term_id ), array( '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same as above.
				$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $term_id ), array( '%d' ) );
			}
		}
	}

	/**
	 * Drops the plugin's tables.
	 *
	 * @return void
	 */
	private static function drop_tables(): void {
		global $wpdb;

		foreach ( Installer::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping our own tables on uninstall.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
