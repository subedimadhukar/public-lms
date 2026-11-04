/**
 * Course grids on a course page (finding 11).
 *
 * The Course Grid variation's "related courses" and "hide enrolled" settings
 * must apply on the front end. The course on screen is the newest course, so
 * a plain "newest courses" query would list it; related courses must not.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const LEARNER = {
	username: 'e2e.catalogue',
	email: 'e2e.catalogue@example.test',
	password: 'e2e-catalogue-password',
	firstName: 'Ravi',
	roles: [ 'plms_student' ],
};
const THEME = process.env.PUBLICLMS_E2E_THEME || 'twentytwentyfive';

/**
 * Returns Course Grid markup with one of the variation's settings on.
 *
 * @param {string} setting publiclmsRelated or publiclmsHideEnrolled.
 * @param {string} label   Accessible name for the grid's list.
 * @return {string} Block markup.
 */
function grid( setting, label ) {
	return (
		`<!-- wp:group {"tagName":"section","ariaLabel":"${ label }"} --><section class="wp-block-group" aria-label="${ label }">` +
		`<!-- wp:query {"queryId":3,"query":{"perPage":3,"postType":"plms_course","order":"desc","orderBy":"date","inherit":false,"${ setting }":true},"namespace":"publiclms/course-query"} -->` +
		'<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div>' +
		'<!-- /wp:query --></section><!-- /wp:group -->'
	);
}

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

test.describe( 'Course grids on a course page', () => {
	test( 'related courses leave out the course on screen, and hide-enrolled leaves out enrolled courses', async ( {
		browser,
		requestUtils,
	} ) => {
		await requestUtils.activatePlugin( 'public-lms' );
		await requestUtils.activateTheme( THEME );
		await requestUtils.deleteAllUsers();
		const learner = await requestUtils.createUser( LEARNER );
		const stamp = Date.now();
		const course = ( title, secondsAgo, content = '' ) =>
			requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/plms_course',
				data: {
					title,
					status: 'publish',
					content,
					date: new Date( stamp - secondsAgo * 1000 ).toISOString(),
				},
			} );

		const enrolled = await course( `Grid course enrolled ${ stamp }`, 3 );
		await course( `Grid course open ${ stamp }`, 2 );
		const current = await course(
			`Grid course on screen ${ stamp }`,
			1,
			grid( 'publiclmsRelated', 'Related courses' ) +
				grid( 'publiclmsHideEnrolled', 'Courses to start' )
		);
		await requestUtils.rest( {
			method: 'POST',
			path: `/publiclms/v1/courses/${ enrolled.id }/enrollments`,
			data: { user_id: learner.id },
		} );

		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await logIn( page, LEARNER );
		await page.goto( current.link );

		const related = page.getByRole( 'region', { name: 'Related courses' } );
		await expect(
			related.getByText( `Grid course enrolled ${ stamp }` )
		).toBeVisible();
		await expect(
			related.getByText( `Grid course on screen ${ stamp }` )
		).toHaveCount( 0 );

		const open = page.getByRole( 'region', { name: 'Courses to start' } );
		await expect(
			open.getByText( `Grid course open ${ stamp }` )
		).toBeVisible();
		await expect(
			open.getByText( `Grid course enrolled ${ stamp }` )
		).toHaveCount( 0 );
		await context.close();
		await requestUtils.deleteAllUsers();
	} );
} );
