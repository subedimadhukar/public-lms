/**
 * Build configuration.
 *
 * Extends `@wordpress/scripts` (run with --experimental-modules) with:
 * - the admin app (build/admin/index.js), which uses DataViews' /wp build;
 * - the editor-only script for the bindings source and the course query
 *   variation (build/editor/index.js);
 * - the shared Interactivity API store module used by every learner block
 *   (build/interactivity/learning.js, registered as `@publiclms/learning`).
 *
 * Block scripts, styles, block.json and render.php are discovered from
 * src/blocks/ by `@wordpress/scripts`.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const [ scriptConfig, moduleConfig ] = Array.isArray( defaultConfig )
	? defaultConfig
	: [ defaultConfig ];

const withEntries = ( config, extra ) => ( {
	...config,
	entry: async () => {
		const base =
			typeof config.entry === 'function'
				? await config.entry()
				: config.entry;
		const existing = Object.fromEntries(
			Object.entries( extra ).filter( ( [ , file ] ) =>
				fs.existsSync( file )
			)
		);
		return { ...base, ...existing };
	},
} );

module.exports = [
	withEntries( scriptConfig, {
		'admin/index': path.resolve( __dirname, 'src/admin/index.js' ),
		'editor/index': path.resolve( __dirname, 'src/editor/index.js' ),
	} ),
	moduleConfig &&
		withEntries( moduleConfig, {
			'interactivity/learning': path.resolve(
				__dirname,
				'src/interactivity/learning.js'
			),
		} ),
].filter( Boolean );
