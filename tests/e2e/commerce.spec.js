/**
 * Selling a course, end to end (phase 2 part B, ADR-046).
 *
 * A learner buys a paid course from its "Buy" button with a coupon and an
 * offline payment; the order waits on hold until the owner marks it paid,
 * which enrols the learner. A guest buys with a name and an email, and gets
 * an account once paid. No payment provider is involved, so the journey
 * runs in CI without keys.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

// Test accounts for this spec only; created and deleted by the spec.
const BUYER = {
	username: 'e2e.buyer',
	email: 'e2e.buyer@example.test',
	password: 'e2e-buyer-password',
	firstName: 'Noor',
	roles: [ 'plms_student' ],
};
const GUEST = { name: 'Mateo Rossi', email: 'e2e.guest@example.test' };
const COURSE = 'Beekeeping for Beginners';
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

test.describe( 'Selling a course', () => {
	let course = null;
	let dashboardUrl = '';
	let api = null;

	test.beforeAll( async ( { requestUtils } ) => {
		api = ( method, path, data ) =>
			requestUtils.rest( {
				method,
				path: `/publiclms/v1${ path }`,
				// Query arguments go in params: the tests site uses plain permalinks (?rest_route=).
				...( method === 'GET' ? { params: data } : { data } ),
			} );
		await requestUtils.activatePlugin( 'public-lms' );
		await requestUtils.activateTheme( THEME );
		await requestUtils.deleteAllUsers();
		await requestUtils.createUser( BUYER );

		await api( 'POST', '/settings/payments', {
			currency: 'EUR',
			guest_checkout: true,
			gateways: {
				offline: {
					enabled: true,
					title: 'Bank transfer',
					instructions:
						'Transfer the total to account 12345 with your order number.',
				},
			},
		} );
		await api( 'POST', '/settings/payments/checkout-page' );
		dashboardUrl = ( await api( 'POST', '/settings/dashboard-page' ) )
			.dashboard_page_url;

		course = await api( 'POST', '/courses', {
			title: COURSE,
			status: 'publish',
			access_mode: 'paid',
			price: '25',
		} );
		const section = await api( 'POST', '/sections', {
			course_id: course.id,
			title: 'The hive',
		} );
		await api( 'POST', '/lessons', {
			section_id: section.id,
			title: 'Inside a colony',
		} );
		// Leftovers from an earlier run: coupon codes are unique.
		for ( const coupon of await api( 'GET', '/coupons' ) ) {
			if ( coupon.code === 'WELCOME10' ) {
				await api( 'DELETE', `/coupons/${ coupon.id }` );
			}
		}
		await api( 'POST', '/coupons', {
			code: 'WELCOME10',
			type: 'percent',
			amount: 10,
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
	} );

	test( 'a learner buys with a coupon and an offline payment, and is enrolled once it is marked paid', async ( {
		browser,
	} ) => {
		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await logIn( page, BUYER );

		await page.goto( course.link );
		await page
			.getByRole( 'link', { name: /Buy for €25/ } )
			.first()
			.click();
		await expect(
			page.getByRole( 'heading', { level: 2, name: 'Your order' } )
		).toBeVisible();
		const total = page.getByRole( 'row', { name: /Total/ } );
		await expect( total ).toContainText( '€25' );

		await page
			.getByRole( 'textbox', { name: 'Coupon code' } )
			.fill( 'welcome10' );
		await page.getByRole( 'button', { name: 'Apply' } ).click();
		await expect( page.getByText( 'Coupon applied.' ) ).toBeVisible();
		await expect( total ).toContainText( '€22.50' );

		await expect(
			page.getByRole( 'radio', { name: /Bank transfer/ } )
		).toBeChecked();
		await page.getByRole( 'button', { name: 'Place order' } ).click();
		await page.waitForURL( /[?&]order=/ );
		await expect(
			page.getByText( 'Your order is waiting for your payment' )
		).toBeVisible();
		await expect(
			page.getByText( 'Transfer the total to account 12345' )
		).toBeVisible();

		// The owner marks it paid in Orders.
		const orders = await api( 'GET', '/orders', { search: BUYER.email } );
		// Newest first; earlier runs may have left orders for the same email.
		expect( orders[ 0 ].customer.name ).toBe( BUYER.firstName );
		expect( orders[ 0 ].status ).toBe( 'on-hold' );
		expect( orders[ 0 ].total ).toBe( 22.5 );
		const paid = await api(
			'POST',
			`/orders/${ orders[ 0 ].id }/complete`
		);
		expect( paid.status ).toBe( 'completed' );

		await page.reload();
		await expect(
			page.getByText( 'Your payment is confirmed' )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Start learning' } )
		).toBeVisible();

		await page.goto( dashboardUrl );
		await page.getByRole( 'tab', { name: /Orders/ } ).click();
		await expect(
			page.getByRole( 'row', { name: /Order #\d+.*€22\.50.*Paid/ } )
		).toBeVisible();
		await context.close();
	} );

	test( 'a guest buys with a name and an email, and gets an account once paid', async ( {
		browser,
	} ) => {
		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await page.goto( course.link );
		await page
			.getByRole( 'link', { name: /Buy for €25/ } )
			.first()
			.click();

		await page.getByRole( 'button', { name: 'Place order' } ).click();
		await expect(
			page.getByRole( 'textbox', { name: 'Full name' } )
		).toBeFocused();

		await page
			.getByRole( 'textbox', { name: 'Full name' } )
			.fill( GUEST.name );
		await page
			.getByRole( 'textbox', { name: 'Email' } )
			.fill( GUEST.email );
		await page.getByRole( 'button', { name: 'Place order' } ).click();
		await page.waitForURL( /[?&]order=/ );
		await expect(
			page.getByText( 'Your order is waiting for your payment' )
		).toBeVisible();

		const orders = await api( 'GET', '/orders', { search: GUEST.email } );
		expect( orders[ 0 ].customer.id ).toBe( 0 );
		const paid = await api(
			'POST',
			`/orders/${ orders[ 0 ].id }/complete`
		);
		expect( paid.customer.id ).toBeGreaterThan( 0 );
		expect( paid.customer.name ).toBe( GUEST.name );

		await page.reload();
		await expect(
			page.getByText( 'Your payment is confirmed' )
		).toBeVisible();
		await expect( page.getByText( GUEST.email ) ).toBeVisible();
		await context.close();
	} );
} );
