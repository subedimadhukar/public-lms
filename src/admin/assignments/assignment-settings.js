/**
 * The assignment settings dialog of the course builder (ADR-043): points,
 * pass grade, typed answers and file limits. The server enforces them.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	Modal,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';

import { STORE_NAME } from '../../store/courses';
import { notifyError, notifySuccess } from '../components/notices';

const FILE_TYPES = [
	'pdf',
	'doc',
	'docx',
	'odt',
	'rtf',
	'txt',
	'csv',
	'xls',
	'xlsx',
	'ods',
	'ppt',
	'pptx',
	'odp',
	'jpg',
	'jpeg',
	'png',
	'gif',
	'webp',
	'mp3',
	'm4a',
	'wav',
	'mp4',
	'mov',
	'webm',
	'zip',
];

const KEYS = [
	'points',
	'pass_points',
	'allow_text',
	'max_files',
	'max_file_size',
	'file_types',
	'resubmit',
];

/**
 * The dialog.
 *
 * @param {Object}     props
 * @param {number}     props.courseId Course ID.
 * @param {Object}     props.item     The assignment row of the curriculum.
 * @param {() => void} props.onClose  Closes the dialog.
 */
export default function AssignmentSettings( { courseId, item, onClose } ) {
	const [ values, setValues ] = useState( null );
	const [ isSaving, setIsSaving ] = useState( false );
	const { updateItem } = useDispatch( STORE_NAME );

	useEffect( () => {
		apiFetch( {
			path: `/publiclms/v1/assignments/${ item.id }?context=edit`,
		} )
			.then( ( data ) =>
				setValues(
					Object.fromEntries(
						KEYS.map( ( key ) => [ key, data[ key ] ] )
					)
				)
			)
			.catch( notifyError );
	}, [ item.id ] );

	const set = ( key ) => ( value ) =>
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );

	const save = async ( event ) => {
		event.preventDefault();
		setIsSaving( true );
		try {
			await updateItem( courseId, 'assignment', item.id, {
				...values,
				points: Number( values.points ) || 0,
				pass_points: Number( values.pass_points ) || 0,
				max_files: Number( values.max_files ) || 0,
				max_file_size: Number( values.max_file_size ) || 1,
			} );
			notifySuccess( __( 'Assignment settings saved.', 'public-lms' ) );
			onClose();
		} catch ( error ) {
			notifyError( error );
			setIsSaving( false );
		}
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: assignment title. */
				__( 'Assignment settings: %s', 'public-lms' ),
				decodeEntities( item.title )
			) }
			onRequestClose={ onClose }
			className="publiclms-access-modal"
		>
			{ ! values ? (
				<Spinner />
			) : (
				<form onSubmit={ save }>
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						step={ 0.5 }
						label={ __( 'Highest grade (points)', 'public-lms' ) }
						value={ values.points }
						onChange={ set( 'points' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						step={ 0.5 }
						label={ __( 'Pass grade', 'public-lms' ) }
						help={ __(
							'0 completes the assignment as soon as it is handed in. Otherwise it completes when you give at least this grade.',
							'public-lms'
						) }
						value={ values.pass_points }
						onChange={ set( 'pass_points' ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Learners can type an answer',
							'public-lms'
						) }
						checked={ !! values.allow_text }
						onChange={ set( 'allow_text' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						max={ 10 }
						label={ __( 'Files per hand-in', 'public-lms' ) }
						help={ __(
							'0 for a typed answer only.',
							'public-lms'
						) }
						value={ values.max_files }
						onChange={ set( 'max_files' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 1 }
						label={ __( 'Largest file (MB)', 'public-lms' ) }
						help={ __(
							'The server’s own upload limit applies too.',
							'public-lms'
						) }
						value={ values.max_file_size }
						onChange={ set( 'max_file_size' ) }
					/>
					<fieldset className="publiclms-access-modal__prerequisites">
						<legend>
							{ __( 'Accepted file types', 'public-lms' ) }
						</legend>
						<div className="publiclms-file-types">
							{ FILE_TYPES.map( ( type ) => (
								<CheckboxControl
									__nextHasNoMarginBottom
									key={ type }
									label={ type.toUpperCase() }
									checked={ values.file_types.includes(
										type
									) }
									onChange={ ( checked ) =>
										set( 'file_types' )(
											checked
												? [ ...values.file_types, type ]
												: values.file_types.filter(
														( other ) =>
															other !== type
													)
										)
									}
								/>
							) ) }
						</div>
					</fieldset>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Learners can hand in again before grading or after a failing grade',
							'public-lms'
						) }
						checked={ !! values.resubmit }
						onChange={ set( 'resubmit' ) }
					/>
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
							{ __( 'Save settings', 'public-lms' ) }
						</Button>
					</div>
				</form>
			) }
		</Modal>
	);
}
