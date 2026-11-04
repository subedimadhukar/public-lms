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
 *
 * The lesson video (publiclms/video) reports watch progress here through the
 * players' postMessage events, without loading any provider script
 * (ADR-041), and completes the lesson through the same progress update as
 * the mark-complete button.
 */
import { getContext, getElement, store } from '@wordpress/interactivity';

import { post } from './api';
import './quiz';

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

/**
 * Applies a progress response to the shared state: the course's progress,
 * the item's completion and the next link.
 *
 * @param {number} courseId Course ID.
 * @param {number} itemId   Item ID.
 * @param {Object} data     Response of POST progress.
 */
function applyProgress( courseId, itemId, data ) {
	const progress = state.progress[ courseId ] || { ...EMPTY };
	const items = { ...( progress.items || {} ) };
	if ( data.item_completed ) {
		items[ itemId ] = true;
	} else {
		delete items[ itemId ];
	}
	state.progress[ courseId ] = {
		percent: data.percent,
		done: data.items_completed,
		total: data.items_total,
		completed: data.completed,
		items,
	};
	if ( data.item_completed && state.next ) {
		state.next[ courseId ] = data.next_item
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
}

/**
 * Watch-progress readers for the embedded players. Each subscribes to its
 * player's events with postMessage and reports { time, duration }.
 */
const PLAYERS = {
	youtube: {
		subscribe( frame, id ) {
			frame.contentWindow?.postMessage(
				JSON.stringify( { event: 'listening', id, channel: 'widget' } ),
				'*'
			);
		},
		read( message ) {
			const info = message?.info;
			if ( ! info || typeof info.currentTime !== 'number' ) {
				return null;
			}
			return { time: info.currentTime, duration: info.duration || 0 };
		},
	},
	vimeo: {
		subscribe( frame, id, origin ) {
			frame.contentWindow?.postMessage(
				JSON.stringify( {
					method: 'addEventListener',
					value: 'timeupdate',
				} ),
				origin
			);
		},
		read( message ) {
			if ( message?.event !== 'timeupdate' || ! message.data ) {
				return null;
			}
			return {
				time: Number( message.data.seconds ) || 0,
				duration: Number( message.data.duration ) || 0,
			};
		},
	},
	bunny: {
		subscribe( frame, id, origin ) {
			frame.contentWindow?.postMessage(
				JSON.stringify( {
					context: 'player.js',
					version: '0.0.11',
					method: 'addEventListener',
					value: 'timeupdate',
					listener: id,
				} ),
				origin
			);
		},
		read( message ) {
			if (
				message?.context !== 'player.js' ||
				message.event !== 'timeupdate' ||
				! message.value
			) {
				return null;
			}
			return {
				time: Number( message.value.seconds ) || 0,
				duration: Number( message.value.duration ) || 0,
			};
		},
	},
};

/**
 * Counts watched seconds from a stream of playback positions. A jump of
 * more than two seconds (a seek) adds nothing; each second counts once.
 *
 * @return {(time: number, duration: number) => number} Takes a position, returns the share watched (0 to 100).
 */
function watchCounter() {
	const seen = new Set();
	let last = null;
	return ( time, duration ) => {
		if ( last !== null && time > last && time - last <= 2 ) {
			for ( let second = Math.floor( last ); second < time; second++ ) {
				seen.add( second );
			}
		}
		last = time;
		const total = Math.max( 1, Math.floor( duration ) );
		return duration > 0 ? Math.min( 100, ( seen.size / total ) * 100 ) : 0;
	};
}

/**
 * Marks an item complete after its video was watched, unless it already is.
 *
 * @param {Object} context The video block's context.
 */
async function completeFromVideo( context ) {
	if (
		state.progress?.[ context.courseId ]?.items?.[ context.itemId ] ||
		! state.enrolled?.[ context.courseId ]
	) {
		return;
	}
	try {
		const data = await post( 'progress', {
			item_id: context.itemId,
			status: 'completed',
		} );
		applyProgress( context.courseId, context.itemId, data );
		context.message = state.i18n.videoComplete || data.message;
	} catch ( error ) {
		context.track = true;
		context.message = error.message || state.i18n.error;
	}
}

let videoCount = 0;

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
				applyProgress( context.courseId, context.itemId, data );
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
	callbacks: {
		/**
		 * Starts watch tracking on a lesson video.
		 */
		initVideo() {
			const context = getContext();
			const { ref } = getElement();
			if ( ! context.track || ! ref ) {
				return;
			}
			const count = watchCounter();
			const onPosition = ( time, duration ) => {
				if ( ! context.track ) {
					return;
				}
				const watched = Math.floor( count( time, duration ) );
				if ( watched !== context.watched ) {
					context.watched = watched;
				}
				if ( watched >= context.required ) {
					context.track = false;
					completeFromVideo( context );
				}
			};

			const video = ref.querySelector( 'video' );
			if ( video ) {
				video.addEventListener( 'timeupdate', () =>
					onPosition( video.currentTime, video.duration || 0 )
				);
				return;
			}

			const frame = ref.querySelector( 'iframe' );
			const player = PLAYERS[ context.provider ];
			if ( ! frame || ! player ) {
				return;
			}
			const id = `publiclms-video-${ ++videoCount }`;
			let heard = false;
			window.addEventListener( 'message', ( event ) => {
				if ( event.source !== frame.contentWindow ) {
					return;
				}
				let message = event.data;
				if ( typeof message === 'string' ) {
					try {
						message = JSON.parse( message );
					} catch {
						return;
					}
				}
				if ( ! heard ) {
					heard = true;
					// Vimeo and Bunny announce "ready"; subscribe again so the listener sticks.
					player.subscribe( frame, id, context.origin );
				}
				const position = player.read( message );
				if ( position ) {
					onPosition( position.time, position.duration );
				}
			} );
			const subscribe = () =>
				player.subscribe( frame, id, context.origin );
			frame.addEventListener( 'load', subscribe );
			// YouTube only reports after hearing from the page; keep asking until it answers.
			let attempts = 0;
			const timer = window.setInterval( () => {
				if ( heard || ++attempts > 20 ) {
					window.clearInterval( timer );
					return;
				}
				subscribe();
			}, 500 );
		},
	},
} );
