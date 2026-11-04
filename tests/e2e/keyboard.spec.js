/**
 * Keyboard focus after learner actions (findings 7 and 10, WCAG 2.4.3).
 *
 * Mark complete keeps focus on its button while the request runs and after
 * it, so the next Tab moves on from there instead of from the top of the
 * page. Handing in an assignment reloads the page with focus on the new
 * "Your hand-in" status.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const LEARNER = {
	username: 'e2e.keyboard',
	email: 'e2e.keyboard@example.test',
	password: 'e2e-keyboard-password',
	firstName: 'Mei',
	roles: [ 'plms_student' ],
};
const THEME = process.env.PUBLICLMS_E2E_THEME || 'twentytwentyfive';

/**
 * Logs a page in through the login form.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {Object}                          user User with username and password.
 */
async function logIn( page, user ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( user.username );
	await page.locator( '#user_pass' ).fill( user.password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL(
		( url ) => ! url.pathname.endsWith( 'wp-login.php' )
	);
}

test.describe( 'Keyboard focus', () => {
	test( 'Mark complete keeps focus, and a hand-in lands on its status', async ( {
		browser,
		requestUtils,
	} ) => {
		const api = ( method, path, data ) =>
			requestUtils.rest( {
				method,
				path: `/publiclms/v1${ path }`,
				data,
			} );
		await requestUtils.activatePlugin( 'public-lms' );
		await requestUtils.activateTheme( THEME );
		await requestUtils.deleteAllUsers();
		const learner = await requestUtils.createUser( LEARNER );

		const course = await api( 'POST', '/courses', {
			title: 'Keyboard Course',
			status: 'publish',
			access_mode: 'closed',
		} );
		const section = await api( 'POST', '/sections', {
			course_id: course.id,
			title: 'Only section',
		} );
		const lesson = await api( 'POST', '/lessons', {
			section_id: section.id,
			title: 'First step',
		} );
		await api( 'POST', '/lessons', {
			section_id: section.id,
			title: 'Second step',
		} );
		const assignment = await api( 'POST', '/assignments', {
			section_id: section.id,
			title: 'Write a short reflection',
			allow_text: true,
			max_files: 0,
		} );
		await api( 'POST', `/courses/${ course.id }/enrollments`, {
			user_id: learner.id,
		} );

		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await logIn( page, LEARNER );

		// Mark complete with the keyboard.
		await page.goto( lesson.link );
		const markComplete = page.getByRole( 'button', {
			name: 'Mark as complete',
		} );
		await markComplete.focus();
		await page.keyboard.press( 'Enter' );
		await expect(
			page
				.getByRole( 'status' )
				.filter( { hasText: /Course progress \d+%/ } )
				.first()
		).toBeVisible();
		const button = page.locator(
			'.wp-block-publiclms-mark-complete button'
		);
		await expect( button ).toBeFocused();
		await expect( button ).not.toHaveAttribute( 'aria-disabled', 'true' );
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( 'body' ) ).not.toBeFocused();

		// Hand in with the keyboard.
		await page.goto( assignment.link );
		await page
			.getByRole( 'textbox', { name: 'Your answer' } )
			.fill( 'Practising with a friend helped most.' );
		await page.getByRole( 'button', { name: 'Hand in' } ).focus();
		await page.keyboard.press( 'Enter' );
		await page.waitForURL( /#publiclms-handin-\d+$/ );
		await expect(
			page.getByRole( 'heading', { level: 2, name: 'Your hand-in' } )
		).toBeFocused();
		await context.close();
		await requestUtils.deleteAllUsers();
	} );
} );
