/**
 * Small inline forms used by the course builder: rename and add.
 */
import { Button, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { notifyError } from '../components/notices';

/**
 * A one-field form that saves on Enter and cancels on Escape.
 *
 * @param {Object}                                  props
 * @param {string}                                  props.label       Field label (visually hidden).
 * @param {string}                                  props.initial     Starting value.
 * @param {string}                                  props.submitLabel Submit button label.
 * @param {(value: string) => (Promise<void>|void)} props.onSubmit    Receives the trimmed value.
 * @param {() => void}                              props.onCancel    Called on cancel.
 */
export function InlineForm( {
	label,
	initial = '',
	submitLabel,
	onSubmit,
	onCancel,
} ) {
	const [ value, setValue ] = useState( initial );
	const [ isBusy, setIsBusy ] = useState( false );

	const submit = async ( event ) => {
		event.preventDefault();
		const trimmed = value.trim();
		if ( ! trimmed ) {
			return;
		}
		setIsBusy( true );
		try {
			await onSubmit( trimmed );
		} catch ( error ) {
			notifyError( error );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<form className="publiclms-inline-form" onSubmit={ submit }>
			<TextControl
				__next40pxDefaultSize
				label={ label }
				hideLabelFromVision
				value={ value }
				onChange={ setValue }
				onKeyDown={ ( event ) => {
					if ( event.key === 'Escape' ) {
						event.stopPropagation();
						onCancel();
					}
				} }
				// eslint-disable-next-line jsx-a11y/no-autofocus -- The form opens on an explicit user action.
				autoFocus
			/>
			<Button
				variant="primary"
				type="submit"
				isBusy={ isBusy }
				disabled={ isBusy || ! value.trim() }
			>
				{ submitLabel }
			</Button>
			<Button variant="tertiary" onClick={ onCancel }>
				{ __( 'Cancel', 'public-lms' ) }
			</Button>
		</form>
	);
}

/**
 * A button that turns into an InlineForm for adding a section or lesson.
 *
 * @param {Object}                           props
 * @param {string}                           props.buttonLabel Button label.
 * @param {string}                           props.fieldLabel  Field label.
 * @param {(title: string) => Promise<void>} props.onAdd       Receives the title.
 */
export function AddInline( { buttonLabel, fieldLabel, onAdd } ) {
	const [ isOpen, setIsOpen ] = useState( false );
	if ( ! isOpen ) {
		return (
			<Button
				variant="secondary"
				className="publiclms-add-inline"
				onClick={ () => setIsOpen( true ) }
			>
				{ buttonLabel }
			</Button>
		);
	}
	return (
		<InlineForm
			label={ fieldLabel }
			submitLabel={ __( 'Add', 'public-lms' ) }
			onSubmit={ async ( title ) => {
				await onAdd( title );
				setIsOpen( false );
			} }
			onCancel={ () => setIsOpen( false ) }
		/>
	);
}
