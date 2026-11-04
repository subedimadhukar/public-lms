/**
 * The learning journey, end to end.
 *
 * Phase 1: an instructor builds a course with two sections and three lessons
 * in the admin app, a student enrols for free, completes a lesson in the
 * player and sees the progress on the dashboard.
 *
 * Phase 2: a student completes a lesson, passes a server-graded quiz in the
 * Interactivity runner (which completes the course), and downloads the
 * certificate PDF from the dashboard.
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

// Theme to run against; CampusInterface when it is mapped into wp-env.
const THEME = process.env.PUBLICLMS_E2E_THEME || 'twentytwentyfive';

/**
 * Returns the WCAG contrast ratio between each text span of an element and
 * the element's own background colour.
 *
 * @param {import('@playwright/test').Locator} locator Element with a solid background.
 * @return {Promise<Array<{text: string, color: string, background: string, ratio: number}>>} One entry per span.
 */
function textContrast( locator ) {
	return locator.evaluate( ( element ) => {
		const channels = ( value ) =>
			value
				.match( /[\d.]+/g )
				.slice( 0, 3 )
				.map( Number );
		const luminance = ( rgb ) => {
			const [ r, g, b ] = rgb.map( ( v ) => {
				const c = v / 255;
				return c <= 0.03928
					? c / 12.92
					: ( ( c + 0.055 ) / 1.055 ) ** 2.4;
			} );
			return 0.2126 * r + 0.7152 * g + 0.0722 * b;
		};
		const background =
			element.ownerDocument.defaultView.getComputedStyle(
				element
			).backgroundColor;
		const bg = luminance( channels( background ) );
		return Array.from( element.querySelectorAll( 'span' ) ).map(
			( span ) => {
				const color =
					span.ownerDocument.defaultView.getComputedStyle(
						span
					).color;
				const fg = luminance( channels( color ) );
				return {
					text: span.textContent.trim(),
					color,
					background,
					ratio:
						( Math.max( fg, bg ) + 0.05 ) /
						( Math.min( fg, bg ) + 0.05 ),
				};
			}
		);
	} );
}

/**
 * Asserts that every text span of the next link is readable on its fill.
 *
 * @param {import('@playwright/test').Locator} next The next link.
 */
async function expectReadable( next ) {
	const entries = await textContrast( next );
	test.info().annotations.push( {
		type: `next link contrast (${ THEME })`,
		description: entries
			.map(
				( entry ) => `${ entry.text }: ${ entry.ratio.toFixed( 2 ) }:1`
			)
			.join( ', ' ),
	} );
	for ( const entry of entries ) {
		expect( entry.color, entry.text ).not.toBe( entry.background );
		expect( entry.ratio, entry.text ).toBeGreaterThanOrEqual( 4.5 );
	}
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

test.describe( 'Learning journey', () => {
	let dashboardUrl = '';
	let studentId = 0;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'public-lms' );
		await requestUtils.activateTheme( THEME );
		await requestUtils.deleteAllUsers();
		await requestUtils.createUser( INSTRUCTOR );
		studentId = ( await requestUtils.createUser( STUDENT ) ).id;
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
		// The admin bundle can take a while on a cold start after a build.
		const addCourse = admin.getByRole( 'button', { name: 'Add course' } );
		await expect( addCourse ).toBeVisible( { timeout: 30_000 } );
		await addCourse.click();
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

		// The filled next link stays readable on any theme that colours links,
		// at rest, on hover and after a reload.
		await expectReadable( next );
		await next.hover();
		await expectReadable( next );
		await page.reload();
		await expect( next ).toHaveClass( /is-ready/ );
		await expectReadable( next );

		// The dashboard shows the course and its progress.
		await page.goto( dashboardUrl );
		await expect(
			page.getByRole( 'heading', { level: 3, name: COURSE } )
		).toBeVisible();
		await expect( page.getByText( '1 of 3 steps · 33%' ) ).toBeVisible();
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

	test( 'a student passes a quiz graded on the server and downloads the certificate', async ( {
		browser,
		requestUtils,
	} ) => {
		const api = ( method, path, data ) =>
			requestUtils.rest( {
				method,
				path: `/publiclms/v1${ path }`,
				data,
			} );

		// A small course: one lesson and a three-question quiz.
		const course = await api( 'POST', '/courses', {
			title: 'Kitchen Garden Basics',
			status: 'publish',
			access_mode: 'closed',
		} );
		const section = await api( 'POST', '/sections', {
			course_id: course.id,
			title: 'Start here',
		} );
		const lesson = await api( 'POST', '/lessons', {
			section_id: section.id,
			title: 'Pick a sunny spot',
		} );
		const quiz = await api( 'POST', '/quizzes', {
			section_id: section.id,
			title: 'Garden check',
			pass_mark: 60,
			max_attempts: 2,
		} );
		await api( 'POST', '/questions', {
			quiz_id: quiz.id,
			type: 'single',
			text: 'How many hours of sun do most vegetables need?',
			options: [
				{ text: 'Six or more', correct: true },
				{ text: 'One or two', correct: false },
			],
			explanation: 'Fruiting vegetables need full sun.',
		} );
		await api( 'POST', '/questions', {
			quiz_id: quiz.id,
			type: 'true_false',
			text: 'Compost improves most soils.',
			answer: true,
		} );
		await api( 'POST', '/questions', {
			quiz_id: quiz.id,
			type: 'short_answer',
			text: 'What do you call a plant that comes back every year?',
			accepted: [ 'Perennial' ],
		} );
		await api( 'POST', `/courses/${ course.id }/enrollments`, {
			user_id: studentId,
		} );

		const studentContext = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await studentContext.newPage();
		await logIn( page, STUDENT );

		await page.goto( lesson.link );
		await page.getByRole( 'button', { name: 'Mark as complete' } ).click();
		await expect(
			page
				.getByRole( 'status' )
				.filter( { hasText: 'Course progress 50%' } )
		).toBeVisible();

		// The quiz runner: questions arrive without answer keys.
		await page.goto( quiz.link );
		await expect(
			page.getByRole( 'heading', { level: 1, name: 'Garden check' } )
		).toBeVisible();
		const attemptResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( `quizzes/${ quiz.id }/attempts` ) &&
				response.request().method() === 'POST'
		);
		await page.getByRole( 'button', { name: 'Start quiz' } ).click();
		const attemptBody = await ( await attemptResponse ).text();
		expect( attemptBody ).not.toContain( '"correct"' );
		expect( attemptBody ).not.toContain( 'Perennial' );

		await expect(
			page.getByRole( 'heading', { level: 2, name: 'Questions' } )
		).toBeFocused();
		await page.getByRole( 'radio', { name: 'Six or more' } ).check();
		await page
			.getByRole( 'group', { name: 'Question 2 of 3' } )
			.getByRole( 'radio', { name: 'True' } )
			.check();
		await page
			.getByRole( 'group', { name: 'Question 3 of 3' } )
			.getByRole( 'textbox', { name: 'Your answer' } )
			.fill( 'perennial' );
		await page.getByRole( 'button', { name: 'Submit answers' } ).click();

		const results = page.getByRole( 'heading', {
			level: 2,
			name: 'Your result',
		} );
		await expect( results ).toBeFocused();
		await expect( page.getByText( 'You passed with 100%.' ) ).toBeVisible();
		await expect(
			page.getByText( 'Fruiting vegetables need full sun.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'progressbar', { name: 'Course progress' } )
		).toHaveAttribute( 'aria-valuenow', '100' );

		// The dashboard: quiz results, then the certificate download.
		await page.goto( dashboardUrl );
		await page.getByRole( 'tab', { name: /Quiz results/ } ).click();
		await expect(
			page.getByRole( 'row', { name: /Garden check.*100%.*Passed/ } )
		).toBeVisible();
		await page.getByRole( 'tab', { name: /Certificates/ } ).click();
		const download = page.getByRole( 'link', {
			name: /Download PDF\s*: Kitchen Garden Basics/,
		} );
		await expect( download ).toBeVisible();
		const pdf = await page.request.get(
			await download.getAttribute( 'href' )
		);
		expect( pdf.status() ).toBe( 200 );
		expect( pdf.headers()[ 'content-type' ] ).toContain(
			'application/pdf'
		);
		expect( ( await pdf.body() ).subarray( 0, 5 ).toString() ).toBe(
			'%PDF-'
		);
		await studentContext.close();
	} );
} );
