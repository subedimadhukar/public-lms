<?php
/**
 * Block editor assets.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the editor-only script: the course panel on lessons, the
 * `publiclms/course-field` bindings source and the course query variation.
 */
final class EditorAssets {

	/**
	 * Script handle.
	 */
	const HANDLE = 'publiclms-editor';

	/**
	 * Hooks the enqueue.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the script in every block editor.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$asset_file = PUBLICLMS_PATH . 'build/editor/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script( self::HANDLE, PUBLICLMS_URL . 'build/editor/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'public-lms', PUBLICLMS_PATH . 'languages' );
	}
}
