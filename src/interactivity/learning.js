/**
 * The `publiclms/learning` Interactivity API store.
 *
 * One store module for every learner block (ADR-035): the lesson player
 * (mark complete), the lesson navigation, the progress bar, the curriculum,
 * the enrol button and the dashboard tabs share the course's progress, so a
 * completion updates them all without a page reload.
 *
 * Server state, seeded by Blocks::seed_state() in PHP:
 * - restUrl, nonce: publiclms/v1 base URL and the wp_rest nonce;
 * - progress[courseId]: { percent, done, total, completed, items: { [itemId]: true } };
 * - enrolled[courseId]: boolean;
 * - next[courseId]: { url, title, isCourse } on item pages;
 * - i18n: translated strings (script modules cannot load translations).
 */
import { getContext, getElement, store } from '@wordpress/interactivity';

const EMPTY = { percent: 0, done: 0, total: 0, completed: false, items: {} };

/**
 * Formats a sprintf-style "%d%% complete" string.
 *
 * @param {string} template Translated template.
 * @param {number} value    Number.
 * @return {string} Formatted string.
 */
const formatPercent = ( template, value ) =>
	( template || '%d%%' )
		.replace( '%d', String( value ) )
		.replace( '%%', '%' );

const { state } = store( 'publiclms/learning', {
	state: {
		get course() {
			const { courseId } = getContext();
			return state.progress?.[ courseId ] || EMPTY;
		},
		get percent() {
			return state.course.percent;
		},
		get percentWidth() {
			return `${ state.course.percent }%`;
		},
		get percentLabel() {
			return formatPercent( state.i18n?.percent, state.course.percent );
		},
		get progressSummary() {
			const { done, total } = state.course;
			return `${ done } / ${ total }`;
		},
		get isEnrolled() {
			const { courseId } = getContext();
			return !! state.enrolled?.[ courseId ];
		},
		get isItemCompleted() {
			const { itemId } = getContext();
			return !! state.course.items?.[ itemId ];
		},
		get isItemLocked() {
			const { locked, courseId } = getContext();
			return !! locked && ! state.enrolled?.[ courseId ];
		},
		get isCourseComplete() {
			return !! state.course.completed;
		},
		get undoHintId() {
			const { itemId } = getContext();
			return state.isItemCompleted ? `publiclms-undo-${ itemId }` : null;
		},
		get completeButtonText() {
			const { label, completedLabel } = getContext();
			return state.isItemCompleted ? completedLabel : label;
		},
		get nextLink() {
			const { courseId } = getContext();
			return state.next?.[ courseId ] || null;
		},
		get nextUrl() {
			return state.nextLink?.url || '';
		},
		get nextTitle() {
			return state.nextLink?.title || '';
		},
		get nextIsCourse() {
			return !! state.nextLink?.isCourse;
		},
		get isSelectedTab() {
			// The ref is not attached yet while the page hydrates; attributes are.
			const { attributes } = getElement();
			return getContext().selected === attributes?.[ 'data-tab' ];
		},
		get isTabHidden() {
			return ! state.isSelectedTab;
		},
		get tabIndex() {
			return state.isSelectedTab ? 0 : -1;
		},
	},
	actions: {
		/**
		 * Marks the current item complete, or not complete when it already is.
		 */
		*toggleComplete() {
			const context = getContext();
			if ( context.isBusy ) {
				return;
			}
			context.isBusy = true;
			context.message = '';
			const wasCompleted = state.isItemCompleted;
			try {
				const data = yield post( 'progress', {
					item_id: context.itemId,
					status: wasCompleted ? 'incomplete' : 'completed',
				} );
				const progress = state.progress[ context.courseId ] || {
					...EMPTY,
				};
				const items = { ...( progress.items || {} ) };
				if ( data.item_completed ) {
					items[ context.itemId ] = true;
				} else {
					delete items[ context.itemId ];
				}
				state.progress[ context.courseId ] = {
					percent: data.percent,
					done: data.items_completed,
					total: data.items_total,
					completed: data.completed,
					items,
				};
				if ( data.item_completed && state.next ) {
					state.next[ context.courseId ] = data.next_item
						? {
								url: data.next_item.url,
								title: data.next_item.title,
								isCourse: false,
							}
						: {
								url: data.course_url,
								title: data.completed
									? state.i18n.courseComplete
									: state.i18n.backToCourse,
								isCourse: true,
							};
				}
				context.message = data.message;
			} catch ( error ) {
				context.message = error.message || state.i18n.error;
			} finally {
				context.isBusy = false;
			}
		},

		/**
		 * Enrols the current user in a free course without leaving the page.
		 *
		 * @param {Event} event Submit event of the no-JS fallback form.
		 */
		*enroll( event ) {
			event.preventDefault();
			const context = getContext();
			if ( context.isBusy ) {
				return;
			}
			context.isBusy = true;
			context.message = '';
			try {
				const data = yield post( 'enrollments/me', {
					course_id: context.courseId,
				} );
				state.enrolled[ context.courseId ] = true;
				context.enrolled = true;
				context.url = data.course?.resume_url || context.url;
				context.message = state.i18n.enrolled;
			} catch ( error ) {
				context.message = error.message || state.i18n.error;
			} finally {
				context.isBusy = false;
			}
		},

		/**
		 * Selects a dashboard tab.
		 */
		selectTab() {
			const context = getContext();
			const { ref } = getElement();
			context.selected = ref.dataset.tab;
		},

		/**
		 * Arrow, Home and End keys move between dashboard tabs (WAI-ARIA tabs pattern).
		 *
		 * @param {KeyboardEvent} event Key event.
		 */
		onTabKeydown( event ) {
			const keys = [ 'ArrowLeft', 'ArrowRight', 'Home', 'End' ];
			if ( ! keys.includes( event.key ) ) {
				return;
			}
			event.preventDefault();
			const tabs = Array.from(
				event.currentTarget
					.closest( '[role="tablist"]' )
					.querySelectorAll( '[role="tab"]' )
			);
			const index = tabs.indexOf( event.currentTarget );
			let next = index;
			if ( event.key === 'ArrowRight' ) {
				next = ( index + 1 ) % tabs.length;
			} else if ( event.key === 'ArrowLeft' ) {
				next = ( index - 1 + tabs.length ) % tabs.length;
			} else if ( event.key === 'Home' ) {
				next = 0;
			} else if ( event.key === 'End' ) {
				next = tabs.length - 1;
			}
			getContext().selected = tabs[ next ].dataset.tab;
			tabs[ next ].focus();
		},
	},
} );

/**
 * Posts JSON to a publiclms/v1 route with the REST nonce.
 *
 * @param {string} path Route under publiclms/v1.
 * @param {Object} body Request body.
 * @return {Promise<Object>} Response data.
 */
async function post( path, body ) {
	const response = await fetch( state.restUrl + path, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': state.nonce,
		},
		body: JSON.stringify( body ),
	} );
	const data = await response.json().catch( () => ( {} ) );
	if ( ! response.ok ) {
		throw new Error( data.message || state.i18n.error );
	}
	return data;
}
