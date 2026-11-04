/**
 * The `publiclms/students` data store.
 *
 * Paginated learner lists and single learners from /publiclms/v1/students.
 */
import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, register } from '@wordpress/data';
import { addQueryArgs } from '@wordpress/url';

import { queryKey } from '../courses';

export const STORE_NAME = 'publiclms/students';

const API = '/publiclms/v1';

const DEFAULT_STATE = {
	lists: {},
	items: {},
	details: {},
};

function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case 'RECEIVE_STUDENT_LIST':
			return {
				...state,
				items: action.students.reduce(
					( acc, student ) => ( { ...acc, [ student.id ]: student } ),
					state.items
				),
				lists: {
					...state.lists,
					[ action.key ]: {
						ids: action.students.map( ( student ) => student.id ),
						total: action.total,
						nextCursor: action.nextCursor,
					},
				},
			};
		case 'RECEIVE_STUDENT':
			return {
				...state,
				details: {
					...state.details,
					[ action.student.id ]: action.student,
				},
			};
	}
	return state;
}

const selectors = {
	getStudentList( state, query ) {
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
	getStudent( state, id ) {
		return state.details[ id ];
	},
};

const resolvers = {
	getStudentList:
		( query ) =>
		async ( { dispatch } ) => {
			const response = await apiFetch( {
				path: addQueryArgs( `${ API }/students`, query ),
				parse: false,
			} );
			const students = await response.json();
			dispatch( {
				type: 'RECEIVE_STUDENT_LIST',
				key: queryKey( query ),
				students,
				total: Number( response.headers.get( 'X-WP-Total' ) || 0 ),
				nextCursor:
					response.headers.get( 'X-PublicLMS-Next-Cursor' ) || '',
			} );
		},
	getStudent:
		( id ) =>
		async ( { dispatch } ) => {
			const student = await apiFetch( {
				path: `${ API }/students/${ id }`,
			} );
			dispatch( { type: 'RECEIVE_STUDENT', student } );
		},
};

export const store = createReduxStore( STORE_NAME, {
	reducer,
	actions: {},
	selectors,
	resolvers,
} );

register( store );
