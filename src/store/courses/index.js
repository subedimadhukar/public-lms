/**
 * The `publiclms/courses` data store.
 *
 * Courses, their curricula and paginated course lists, read from and written
 * to the publiclms/v1 REST API. Lists are keyed by their query (including
 * the cursor), so each page is cached separately and every write clears them.
 */
import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, register } from '@wordpress/data';
import { addQueryArgs } from '@wordpress/url';

export const STORE_NAME = 'publiclms/courses';

const API = '/publiclms/v1';

/**
 * REST bases of the curriculum item types.
 */
export const ITEM_BASES = {
	lesson: 'lessons',
	quiz: 'quizzes',
	assignment: 'assignments',
};

const DEFAULT_STATE = {
	lists: {},
	items: {},
	curricula: {},
};

/**
 * Returns a stable key for a list query.
 *
 * @param {Object} query Query arguments.
 * @return {string} Key.
 */
export function queryKey( query ) {
	return JSON.stringify(
		Object.keys( query )
			.sort()
			.reduce( ( acc, key ) => ( { ...acc, [ key ]: query[ key ] } ), {} )
	);
}

function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case 'RECEIVE_COURSES':
			return {
				...state,
				items: action.courses.reduce(
					( acc, course ) => ( {
						...acc,
						[ course.id ]: { ...acc[ course.id ], ...course },
					} ),
					state.items
				),
			};
		case 'RECEIVE_COURSE_LIST':
			return {
				...state,
				lists: {
					...state.lists,
					[ action.key ]: {
						ids: action.ids,
						total: action.total,
						nextCursor: action.nextCursor,
					},
				},
			};
		case 'RECEIVE_CURRICULUM':
			return {
				...state,
				curricula: {
					...state.curricula,
					[ action.courseId ]: action.curriculum,
				},
			};
		case 'REMOVE_COURSE': {
			const items = { ...state.items };
			delete items[ action.id ];
			return { ...state, items, lists: {} };
		}
		case 'CLEAR_LISTS':
			return { ...state, lists: {} };
	}
	return state;
}

const selectors = {
	/**
	 * Returns a page of courses: items, total and the cursor of the next page.
	 *
	 * @param {Object} state Store state.
	 * @param {Object} query List query.
	 * @return {Object|undefined} The page, or undefined until loaded.
	 */
	getCourseList( state, query ) {
		const list = state.lists[ queryKey( query ) ];
		if ( ! list ) {
			return undefined;
		}
		return {
			items: list.ids
				.map( ( id ) => state.items[ id ] )
				.filter( Boolean ),
			total: list.total,
			nextCursor: list.nextCursor,
		};
	},
	getCourse( state, id ) {
		return state.items[ id ];
	},
	getCurriculum( state, courseId ) {
		return state.curricula[ courseId ];
	},
};

const actions = {
	receiveCourses( courses ) {
		return { type: 'RECEIVE_COURSES', courses };
	},
	receiveCurriculum( courseId, curriculum ) {
		return { type: 'RECEIVE_CURRICULUM', courseId, curriculum };
	},

	/**
	 * Clears every cached list so the next read refetches.
	 */
	invalidateLists:
		() =>
		( { dispatch } ) => {
			dispatch( { type: 'CLEAR_LISTS' } );
			dispatch.invalidateResolutionForStoreSelector( 'getCourseList' );
		},

	createCourse:
		( data ) =>
		async ( { dispatch } ) => {
			const course = await apiFetch( {
				path: `${ API }/courses`,
				method: 'POST',
				data,
			} );
			dispatch.receiveCourses( [ course ] );
			dispatch.invalidateLists();
			return course;
		},

	updateCourse:
		( id, data ) =>
		async ( { dispatch } ) => {
			const course = await apiFetch( {
				path: `${ API }/courses/${ id }`,
				method: 'PATCH',
				data,
			} );
			dispatch.receiveCourses( [ course ] );
			dispatch.invalidateLists();
			return course;
		},

	trashCourse:
		( id ) =>
		async ( { dispatch } ) => {
			await apiFetch( {
				path: `${ API }/courses/${ id }`,
				method: 'DELETE',
			} );
			dispatch( { type: 'REMOVE_COURSE', id } );
			dispatch.invalidateResolutionForStoreSelector( 'getCourseList' );
		},

	refreshCurriculum:
		( courseId ) =>
		async ( { dispatch } ) => {
			const curriculum = await apiFetch( {
				path: `${ API }/courses/${ courseId }/curriculum`,
			} );
			dispatch.receiveCurriculum( courseId, curriculum );
			dispatch.invalidateLists();
			return curriculum;
		},

	addSection:
		( courseId, title ) =>
		async ( { dispatch } ) => {
			const section = await apiFetch( {
				path: `${ API }/sections`,
				method: 'POST',
				data: { course_id: courseId, title },
			} );
			await dispatch.refreshCurriculum( courseId );
			return section;
		},

	updateSection:
		( courseId, sectionId, data ) =>
		async ( { dispatch } ) => {
			const section = await apiFetch( {
				path: `${ API }/sections/${ sectionId }`,
				method: 'PATCH',
				data,
			} );
			await dispatch.refreshCurriculum( courseId );
			return section;
		},

	deleteSection:
		( courseId, sectionId ) =>
		async ( { dispatch } ) => {
			await apiFetch( {
				path: `${ API }/sections/${ sectionId }`,
				method: 'DELETE',
			} );
			await dispatch.refreshCurriculum( courseId );
		},

	addItem:
		( courseId, sectionId, type, title ) =>
		async ( { dispatch } ) => {
			const item = await apiFetch( {
				path: `${ API }/${ ITEM_BASES[ type ] }`,
				method: 'POST',
				data: { section_id: sectionId, title },
			} );
			await dispatch.refreshCurriculum( courseId );
			return item;
		},

	updateItem:
		( courseId, type, itemId, data ) =>
		async ( { dispatch } ) => {
			const item = await apiFetch( {
				path: `${ API }/${ ITEM_BASES[ type ] }/${ itemId }`,
				method: 'PATCH',
				data,
			} );
			await dispatch.refreshCurriculum( courseId );
			return item;
		},

	deleteItem:
		( courseId, type, itemId ) =>
		async ( { dispatch } ) => {
			await apiFetch( {
				path: `${ API }/${ ITEM_BASES[ type ] }/${ itemId }`,
				method: 'DELETE',
			} );
			await dispatch.refreshCurriculum( courseId );
		},

	addLesson:
		( courseId, sectionId, title ) =>
		( { dispatch } ) =>
			dispatch.addItem( courseId, sectionId, 'lesson', title ),

	updateLesson:
		( courseId, lessonId, data ) =>
		( { dispatch } ) =>
			dispatch.updateItem( courseId, 'lesson', lessonId, data ),

	deleteLesson:
		( courseId, lessonId ) =>
		( { dispatch } ) =>
			dispatch.deleteItem( courseId, 'lesson', lessonId ),

	/**
	 * Saves a new curriculum order. The new order shows at once; on failure the
	 * server's order is restored and the error is rethrown for a notice.
	 *
	 * @param {number} courseId Course ID.
	 * @param {Array}  sections Sections in order, each with its items in order.
	 */
	saveOrder:
		( courseId, sections ) =>
		async ( { dispatch, select: storeSelect } ) => {
			const previous = storeSelect.getCurriculum( courseId );
			dispatch.receiveCurriculum( courseId, { ...previous, sections } );
			try {
				const curriculum = await apiFetch( {
					path: `${ API }/courses/${ courseId }/curriculum`,
					method: 'PUT',
					data: {
						sections: sections.map( ( section ) => ( {
							id: section.id,
							items: section.items.map( ( item ) => item.id ),
						} ) ),
					},
				} );
				dispatch.receiveCurriculum( courseId, curriculum );
			} catch ( error ) {
				await dispatch.refreshCurriculum( courseId );
				throw error;
			}
		},
};

const resolvers = {
	getCourseList:
		( query ) =>
		async ( { dispatch } ) => {
			const response = await apiFetch( {
				path: addQueryArgs( `${ API }/courses`, query ),
				parse: false,
			} );
			const courses = await response.json();
			dispatch.receiveCourses( courses );
			dispatch( {
				type: 'RECEIVE_COURSE_LIST',
				key: queryKey( query ),
				ids: courses.map( ( course ) => course.id ),
				total: Number( response.headers.get( 'X-WP-Total' ) || 0 ),
				nextCursor:
					response.headers.get( 'X-PublicLMS-Next-Cursor' ) || '',
			} );
		},

	getCourse:
		( id ) =>
		async ( { dispatch } ) => {
			const course = await apiFetch( {
				path: `${ API }/courses/${ id }?context=edit`,
			} );
			dispatch.receiveCourses( [ course ] );
			dispatch.receiveCurriculum( id, course.curriculum );
		},

	getCurriculum:
		( courseId ) =>
		async ( { dispatch } ) => {
			const curriculum = await apiFetch( {
				path: `${ API }/courses/${ courseId }/curriculum`,
			} );
			dispatch.receiveCurriculum( courseId, curriculum );
		},
};

export const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
	resolvers,
} );

register( store );
