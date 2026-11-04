/**
 * Adds existing questions from the bank to a quiz.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	Modal,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { notifyError } from '../components/notices';
import { QUESTION_TYPES } from './question-editor';

/**
 * The dialog.
 *
 * @param {Object}                          props
 * @param {number}                          props.quizId  Quiz the questions are for (its questions are left out).
 * @param {(ids: Array<number>) => Promise} props.onAdd   Adds the chosen IDs.
 * @param {() => void}                      props.onClose Closes the dialog.
 */
export default function BankPicker( { quizId, onAdd, onClose } ) {
	const [ search, setSearch ] = useState( '' );
	const [ type, setType ] = useState( '' );
	const [ items, setItems ] = useState( null );
	const [ chosen, setChosen ] = useState( [] );
	const [ isAdding, setIsAdding ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		setItems( null );
		const timer = setTimeout( () => {
			apiFetch( {
				path: addQueryArgs( '/publiclms/v1/questions', {
					search,
					type,
					quiz: quizId,
					per_page: 50,
				} ),
			} )
				.then( ( data ) => ! cancelled && setItems( data ) )
				.catch( ( error ) => {
					notifyError( error );
					setItems( [] );
				} );
		}, 250 );
		return () => {
			cancelled = true;
			clearTimeout( timer );
		};
	}, [ search, type, quizId ] );

	const add = async () => {
		setIsAdding( true );
		try {
			await onAdd( chosen );
			onClose();
		} catch ( error ) {
			notifyError( error );
			setIsAdding( false );
		}
	};

	return (
		<Modal
			title={ __( 'Add from the question bank', 'public-lms' ) }
			onRequestClose={ onClose }
			className="publiclms-bank-picker"
			size="medium"
		>
			<div className="publiclms-bank-picker__filters">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search questions', 'public-lms' ) }
					value={ search }
					onChange={ setSearch }
				/>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Type', 'public-lms' ) }
					value={ type }
					options={ [
						{ value: '', label: __( 'All types', 'public-lms' ) },
						...Object.entries( QUESTION_TYPES ).map(
							( [ value, label ] ) => ( { value, label } )
						),
					] }
					onChange={ setType }
				/>
			</div>
			{ items === null && <Spinner /> }
			{ items?.length === 0 && (
				<p>{ __( 'No other questions found.', 'public-lms' ) }</p>
			) }
			{ items?.length > 0 && (
				<ul className="publiclms-bank-picker__list">
					{ items.map( ( item ) => (
						<li key={ item.id }>
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ decodeEntities( item.title ) }
								help={ QUESTION_TYPES[ item.type ] }
								checked={ chosen.includes( item.id ) }
								onChange={ ( checked ) =>
									setChosen( ( current ) =>
										checked
											? [ ...current, item.id ]
											: current.filter(
													( id ) => id !== item.id
												)
									)
								}
							/>
						</li>
					) ) }
				</ul>
			) }
			<div className="publiclms-form__actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'public-lms' ) }
				</Button>
				<Button
					variant="primary"
					disabled={ ! chosen.length || isAdding }
					isBusy={ isAdding }
					onClick={ add }
				>
					{ chosen.length
						? sprintf(
								/* translators: %d: number of questions. */
								_n(
									'Add %d question',
									'Add %d questions',
									chosen.length,
									'public-lms'
								),
								chosen.length
							)
						: __( 'Add questions', 'public-lms' ) }
				</Button>
			</div>
		</Modal>
	);
}
