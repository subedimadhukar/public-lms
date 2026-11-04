/**
 * Assignment hand-in, part of the `publiclms/learning` store (ADR-043).
 *
 * Sends the form (typed answer and files) as multipart data to
 * publiclms/v1/assignments/{id}/submissions with the REST nonce, then
 * reloads so the server renders the new status. Limits are checked on the
 * server; its message is shown in the form's live region.
 */
import { getContext, getElement, store } from '@wordpress/interactivity';

const { state } = store( 'publiclms/learning', {
	actions: {
		/**
		 * Hands in the assignment.
		 *
		 * @param {SubmitEvent} event Submit event.
		 */
		*submitAssignment( event ) {
			event.preventDefault();
			const context = getContext();
			if ( context.isBusy ) {
				return;
			}
			const data = new window.FormData( getElement().ref );
			const text = String( data.get( 'content' ) || '' ).trim();
			const files = data
				.getAll( 'files[]' )
				.filter( ( file ) => file && file.size > 0 );
			if ( ! text && ! files.length ) {
				context.message = state.i18n.assignmentNone;
				return;
			}
			context.isBusy = true;
			context.message = '';
			try {
				const response = yield window.fetch(
					`${ state.restUrl }assignments/${ context.assignmentId }/submissions`,
					{
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': state.nonce },
						body: data,
					}
				);
				const body = yield response.json().catch( () => ( {} ) );
				if ( ! response.ok ) {
					throw new Error( body.message || state.i18n.error );
				}
				context.message = state.i18n.handedIn;
				window.location.reload();
			} catch ( error ) {
				context.message = error.message || state.i18n.error;
				context.isBusy = false;
			}
		},
	},
} );
