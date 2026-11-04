/**
 * A confirmation modal for destructive actions.
 */
import { Button, Modal } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * @param {Object}                     props
 * @param {string}                     props.title                Modal title.
 * @param {string}                     props.message              What will happen.
 * @param {string}                     props.confirmLabel         Label of the destructive button.
 * @param {() => (Promise<void>|void)} props.onConfirm            Called on confirm.
 * @param {() => void}                 props.onCancel             Called on cancel or close.
 * @param {boolean}                    [props.isDestructive=true] Whether the action destroys something.
 */
export default function ConfirmDialog( {
	title,
	message,
	confirmLabel,
	onConfirm,
	onCancel,
	isDestructive = true,
} ) {
	const [ isBusy, setIsBusy ] = useState( false );

	const confirm = async () => {
		setIsBusy( true );
		try {
			await onConfirm();
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<Modal
			title={ title }
			onRequestClose={ onCancel }
			size="small"
			className="publiclms-confirm"
		>
			<p>{ message }</p>
			<div className="publiclms-confirm__actions">
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'public-lms' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive={ isDestructive }
					isBusy={ isBusy }
					disabled={ isBusy }
					onClick={ confirm }
				>
					{ confirmLabel }
				</Button>
			</div>
		</Modal>
	);
}
