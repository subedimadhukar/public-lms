/**
 * The phase 1 alpha journey, end to end:
 * an instructor builds a course with two sections and three lessons in the
 * admin app, a student enrols for free, completes a lesson in the player and
 * sees the progress on the dashboard.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

// Test accounts for this spec only; created and deleted by the spec.
const INSTRUCTOR = {
	username: 'e2e.instructor',
	email: 'e2e.instructor@example.test',
	password: 'e2e-instructor-password',
	firstName: 'Ines',
	roles: [ 'plms_instructor' ],
};
const STUDENT = {
	username: 'e2e.student',
	email: 'e2e.student@example.test',
	password: 'e2e-student-password',
	firstName: 'Sam',
	roles: [ 'plms_student' ],
};
const COURSE = 'Composting at Home';

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

/**
 * Adds a section in the course builder.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          title Section title.
 */
async function addSection( page, title ) {
	await page.getByRole( 'button', { name: 'Add section' } ).click();
	await page.getByRole( 'textbox', { name: 'Section title' } ).fill( title );
	await page.getByRole( 'button', { name: 'Add', exact: true } ).click();
	await expect(
		page.getByRole( 'heading', { level: 3, name: title } )
	).toBeVisible();
}

/**
 * Adds a lesson to a section in the course builder.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {string}                          section Section title.
 * @param {string}                          title   Lesson title.
 */
async function addLesson( page, section, title ) {
	const card = page.getByRole( 'listitem', { name: section } );
	await card.getByRole( 'button', { name: 'Add lesson' } ).click();
	await card.getByRole( 'textbox', { name: 'Lesson title' } ).fill( title );
	await card.getByRole( 'button', { name: 'Add', exact: true } ).click();
	await expect( card.getByText( title, { exact: true } ) ).toBeVisible();
}

test.describe( 'Phase 1 learning journey', () => {
	let dashboardUrl = '';

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'public-lms' );
		await requestUtils.activateTheme( 'twentytwentyfive' );
		await requestUtils.deleteAllUsers();
		await requestUtils.createUser( INSTRUCTOR );
		await requestUtils.createUser( STUDENT );
		const settings = await requestUtils.rest( {
			method: 'POST',
			path: '/publiclms/v1/settings/dashboard-page',
		} );
		dashboardUrl = settings.dashboard_page_url;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
	} );

	test( 'an instructor builds a course and a student enrols, completes a lesson and sees progress', async ( {
		browser,
	} ) => {
		// The instructor builds the course in the admin app.
		const instructorContext = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const admin = await instructorContext.newPage();
		await logIn( admin, INSTRUCTOR );
		await admin.goto( '/wp-admin/admin.php?page=publiclms' );
		await admin.getByRole( 'button', { name: 'Add course' } ).click();
		await admin
			.getByRole( 'textbox', { name: 'Course title' } )
			.fill( COURSE );
		await admin
			.getByRole( 'button', { name: 'Create and open builder' } )
			.click();
		await expect(
			admin.getByRole( 'heading', { level: 2, name: COURSE } )
		).toBeVisible();

		await addSection( admin, 'Getting started' );
		await addSection( admin, 'Keeping it healthy' );
		await addLesson( admin, 'Getting started', 'Choosing a bin' );
		await addLesson( admin, 'Getting started', 'Greens and browns' );
		await addLesson( admin, 'Keeping it healthy', 'Turning and watering' );

		await admin
			.getByRole( 'combobox', { name: 'Status' } )
			.selectOption( 'publish' );
		await admin.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect(
			admin.locator( '.components-snackbar__content', {
				hasText: 'Course settings saved.',
			} )
		).toBeVisible();
		const courseUrl = await admin
			.getByRole( 'link', { name: 'View course' } )
			.getAttribute( 'href' );
		await instructorContext.close();

		// The student enrols for free on the course page.
		const studentContext = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await studentContext.newPage();
		await logIn( page, STUDENT );
		await page.goto( courseUrl );
		await expect(
			page.getByRole( 'heading', { level: 1, name: COURSE } )
		).toBeVisible();
		await page
			.getByRole( 'button', { name: 'Enrol for free' } )
			.first()
			.click();
		await expect(
			page
				.getByText( 'You are enrolled. Your first lesson is ready.' )
				.first()
		).toBeVisible();
		await page
			.getByRole( 'link', { name: 'Start learning' } )
			.first()
			.click();

		// The lesson player: mark complete updates progress without a reload.
		await expect(
			page.getByRole( 'heading', { level: 1, name: 'Choosing a bin' } )
		).toBeVisible();
		const timing = await page.evaluate( () => {
			const [ nav ] = performance.getEntriesByType( 'navigation' );
			return {
				ttfb: Math.round( nav.responseStart - nav.requestStart ),
				domContentLoaded: Math.round(
					nav.domContentLoadedEventEnd - nav.startTime
				),
				load: Math.round( nav.loadEventEnd - nav.startTime ),
			};
		} );
		test.info().annotations.push( {
			type: 'lesson page timing (ms)',
			description: JSON.stringify( timing ),
		} );
		expect( timing.ttfb ).toBeLessThan( 1500 );

		const navigatedAway = page
			.waitForEvent( 'framenavigated', { timeout: 3000 } )
			.catch( () => null );
		await page.getByRole( 'button', { name: 'Mark as complete' } ).click();
		await expect(
			page
				.getByRole( 'status' )
				.filter( { hasText: 'Course progress 33%' } )
		).toBeVisible();
		expect( await navigatedAway ).toBeNull();
		await expect(
			page.getByRole( 'progressbar', { name: 'Course progress' } )
		).toHaveAttribute( 'aria-valuenow', '33' );
		await expect(
			page.getByRole( 'button', { name: 'Completed' } )
		).toBeVisible();
		const next = page.getByRole( 'link', {
			name: /Next.*Greens and browns/,
		} );
		await expect( next ).toHaveClass( /is-ready/ );

		// The dashboard shows the course and its progress.
		await page.goto( dashboardUrl );
		await expect(
			page.getByRole( 'heading', { level: 3, name: COURSE } )
		).toBeVisible();
		await expect( page.getByText( '1 of 3 lessons · 33%' ) ).toBeVisible();
		await expect(
			page.getByRole( 'progressbar', { name: `Progress in ${ COURSE }` } )
		).toHaveAttribute( 'aria-valuenow', '33' );

		// The dashboard tabs work from the keyboard.
		await page.getByRole( 'tab', { name: /In progress/ } ).focus();
		await page.keyboard.press( 'ArrowRight' );
		await expect(
			page.getByRole( 'tab', { name: /Completed/ } )
		).toBeFocused();
		await expect(
			page.getByRole( 'tab', { name: /Completed/ } )
		).toHaveAttribute( 'aria-selected', 'true' );
		await studentContext.close();
	} );
} );
