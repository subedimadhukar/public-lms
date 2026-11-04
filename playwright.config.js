/**
 * Playwright configuration for the end-to-end tests in tests/e2e.
 *
 * Extends the `@wordpress/scripts` defaults (admin login in global setup,
 * Chromium, wp-env as the web server). Locally, point it at the wp-env tests
 * site with WP_BASE_URL, for example WP_BASE_URL=http://localhost:8921.
 */
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = {
	...baseConfig,
	testDir: './tests/e2e',
};
