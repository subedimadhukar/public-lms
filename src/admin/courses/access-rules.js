/**
 * The "Access rules" dialog of the course builder: drip (days after
 * enrolment, or a date) for sections and items, and prerequisite steps for
 * items (ADR-040). Course prerequisites live in the course settings panel.
 */
import {
	Button,
	CheckboxControl,
	Modal,
	RadioControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';

import { notifyError, notifySuccess } from '../components/notices';

/**
 * Returns the drip mode of a section or item.
 *
 * @param {Object} entity Section or item with drip_days and drip_date.
 * @return {string} 'none', 'days' or 'date'.
 */
function modeOf( entity ) {
	if ( entity.drip_date ) {
		return 'date';
	}
	return entity.drip_days > 0 ? 'days' : 'none';
}

/**
 * Describes the access rules of a section or item in a few words, for a badge.
 *
 * @param {Object} entity Section or item.
 * @return {string} Summary, or '' when it has no rules.
 */
export function accessSummary( entity ) {
	const parts = [];
	if ( entity.drip_days > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: number of days. */
				_n(
					'Opens %d day after enrolment',
					'Opens %d days after enrolment',
					entity.drip_days,
					'public-lms'
				),
				entity.drip_days
			)
		);
	}
	if ( entity.drip_date ) {
		parts.push(
			sprintf(
				/* translators: %s: date and time. */
				__( 'Opens %s', 'public-lms' ),
				entity.drip_date.replace( 'T', ' ' ).slice( 0, 16 )
			)
		);
	}
	if ( entity.prerequisites?.length ) {
		parts.push(
			sprintf(
				/* translators: %d: number of steps. */
				_n(
					'Needs %d step first',
					'Needs %d steps first',
					entity.prerequisites.length,
					'public-lms'
				),
				entity.prerequisites.length
			)
		);
	}
	return parts.join( ' · ' );
}

/**
 * The dialog.
 *
 * @param {Object}                          props
 * @param {Object}                          props.entity   Section or item being edited.
 * @param {string}                          props.kind     'section' or 'item'.
 * @param {Array}                           props.sections The curriculum sections, for the prerequisite list.
 * @param {(data: Object) => Promise<void>} props.onSave   Saves the changed fields.
 * @param {() => void}                      props.onClose  Closes the dialog.
 */
export default function AccessRulesModal( {
	entity,
	kind,
	sections,
	onSave,
	onClose,
} ) {
	const [ mode, setMode ] = useState( modeOf( entity ) );
	const [ days, setDays ] = useState( entity.drip_days || 7 );
	const [ date, setDate ] = useState(
		( entity.drip_date || '' ).slice( 0, 16 )
	);
	const [ required, setRequired ] = useState( entity.prerequisites || [] );
	const [ isSaving, setIsSaving ] = useState( false );
	const title =
		decodeEntities( entity.title ) || __( '(no title)', 'public-lms' );

	const save = async ( event ) => {
		event.preventDefault();
		setIsSaving( true );
		const data = {
			drip_days: mode === 'days' ? Number( days ) || 0 : 0,
			drip_date: mode === 'date' && date ? `${ date }:00` : '',
		};
		if ( kind === 'item' ) {
			data.prerequisites = required;
		}
		try {
			await onSave( data );
			notifySuccess( __( 'Access rules saved.', 'public-lms' ) );
			onClose();
		} catch ( error ) {
			notifyError( error );
			setIsSaving( false );
		}
	};

	const toggle = ( id, checked ) =>
		setRequired( ( current ) =>
			checked
				? [ ...current, id ]
				: current.filter( ( other ) => other !== id )
		);

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: section or lesson title. */
				__( 'Access rules: %s', 'public-lms' ),
				title
			) }
			onRequestClose={ onClose }
			className="publiclms-access-modal"
		>
			<form onSubmit={ save }>
				<RadioControl
					label={ __( 'When does it open?', 'public-lms' ) }
					help={
						kind === 'section'
							? __(
									'Applies to every step in the section, on top of each step’s own rules.',
									'public-lms'
								)
							: __(
									'If the section has its own rule too, the later time wins.',
									'public-lms'
								)
					}
					selected={ mode }
					options={ [
						{
							value: 'none',
							label: __(
								'As soon as the learner enrols',
								'public-lms'
							),
						},
						{
							value: 'days',
							label: __(
								'A number of days after enrolment',
								'public-lms'
							),
						},
						{
							value: 'date',
							label: __( 'On a date', 'public-lms' ),
						},
					] }
					onChange={ setMode }
				/>
				{ mode === 'days' && (
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 1 }
						max={ 3650 }
						label={ __( 'Days after enrolment', 'public-lms' ) }
						value={ days }
						onChange={ setDays }
						required
					/>
				) }
				{ mode === 'date' && (
					<TextControl
						__next40pxDefaultSize
						type="datetime-local"
						label={ __( 'Opens on (site time)', 'public-lms' ) }
						value={ date }
						onChange={ setDate }
						required
					/>
				) }
				{ kind === 'item' && (
					<fieldset className="publiclms-access-modal__prerequisites">
						<legend>
							{ __( 'Complete these first', 'public-lms' ) }
						</legend>
						{ sections.map( ( section ) => {
							const others = section.items.filter(
								( item ) => item.id !== entity.id
							);
							if ( ! others.length ) {
								return null;
							}
							return (
								<div
									key={ section.id }
									className="publiclms-access-modal__section"
								>
									<p className="publiclms-access-modal__section-title">
										{ decodeEntities( section.title ) }
									</p>
									{ others.map( ( item ) => (
										<CheckboxControl
											__nextHasNoMarginBottom
											key={ item.id }
											label={
												decodeEntities( item.title ) ||
												__( '(no title)', 'public-lms' )
											}
											checked={ required.includes(
												item.id
											) }
											onChange={ ( checked ) =>
												toggle( item.id, checked )
											}
										/>
									) ) }
								</div>
							);
						} ) }
					</fieldset>
				) }
				<div className="publiclms-form__actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'public-lms' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ isSaving }
						disabled={ isSaving }
					>
						{ __( 'Save rules', 'public-lms' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}
