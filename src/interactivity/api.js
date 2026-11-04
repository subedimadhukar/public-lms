/**
 * REST helpers for the `publiclms/learning` store modules: JSON requests to
 * publiclms/v1 with the REST nonce from server state.
 */
import { store } from '@wordpress/interactivity';

const { state } = store( 'publiclms/learning' );

/**
 * Sends a request to a publiclms/v1 route.
 *
 * @param {string} method HTTP method.
 * @param {string} path   Route under publiclms/v1.
 * @param {Object} [body] JSON body.
 * @return {Promise<Object>} Response data.
 */
export async function request( method, path, body ) {
	const response = await fetch( state.restUrl + path, {
		method,
		credentials: 'same-origin',
		headers: {
			...( body ? { 'Content-Type': 'application/json' } : {} ),
			'X-WP-Nonce': state.nonce,
		},
		body: body ? JSON.stringify( body ) : undefined,
	} );
	const data = await response.json().catch( () => ( {} ) );
	if ( ! response.ok ) {
		const error = new Error( data.message || state.i18n?.error );
		error.status = response.status;
		error.data = data;
		throw error;
	}
	return data;
}

/**
 * Posts JSON to a publiclms/v1 route.
 *
 * @param {string} path Route under publiclms/v1.
 * @param {Object} body Request body.
 * @return {Promise<Object>} Response data.
 */
export const post = ( path, body ) => request( 'POST', path, body || {} );

/**
 * Reads a publiclms/v1 route.
 *
 * @param {string} path Route under publiclms/v1.
 * @return {Promise<Object>} Response data.
 */
export const get = ( path ) => request( 'GET', path );

/**
 * Formats a template with %s or %d placeholders, or %1$s style ones, in order.
 *
 * @param {string}             template Translated template.
 * @param {...(string|number)} values   Values.
 * @return {string} Formatted string.
 */
export function format( template, ...values ) {
	let index = 0;
	return String( template || '' )
		.replace( /%(\d+)\$[sd]/g, ( match, position ) =>
			String( values[ Number( position ) - 1 ] ?? '' )
		)
		.replace( /%[sd]/g, () => String( values[ index++ ] ?? '' ) )
		.replace( /%%/g, '%' );
}
