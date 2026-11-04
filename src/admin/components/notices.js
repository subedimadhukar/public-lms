/**
 * Snackbar notices for the admin app, and a helper to report REST errors.
 */
import { SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect, dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

export function Notices() {
	const notices = useSelect(
		( select ) =>
			select( noticesStore )
				.getNotices()
				.filter( ( notice ) => notice.type === 'snackbar' ),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );
	return (
		<SnackbarList
			className="publiclms-admin__snackbars"
			notices={ notices }
			onRemove={ removeNotice }
		/>
	);
}

/**
 * Shows a success snackbar.
 *
 * @param {string} message Message.
 */
export function notifySuccess( message ) {
	dispatch( noticesStore ).createSuccessNotice( message, {
		type: 'snackbar',
	} );
}

/**
 * Shows an error snackbar for a failed request.
 *
 * @param {Object|Error} error REST error or exception.
 */
export function notifyError( error ) {
	dispatch( noticesStore ).createErrorNotice(
		error?.message ||
			__( 'Something went wrong. Please try again.', 'public-lms' ),
		{ type: 'snackbar', explicitDismiss: true }
	);
}
