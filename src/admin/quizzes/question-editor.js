/**
 * The question editor: one dialog for all six question types (ADR-042).
 * The answer key is edited here and saved over publiclms/v1/questions;
 * learners never receive it.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	Modal,
	RadioControl,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { trash } from '@wordpress/icons';

import { notifyError, notifySuccess } from '../components/notices';

export const QUESTION_TYPES = {
	single: __( 'Single choice', 'public-lms' ),
	multiple: __( 'Multiple choice', 'public-lms' ),
	true_false: __( 'True or false', 'public-lms' ),
	fill_blank: __( 'Fill in the blank', 'public-lms' ),
	short_answer: __( 'Short answer', 'public-lms' ),
	matching: __( 'Matching', 'public-lms' ),
};

const EMPTY = {
	type: 'single',
	text: '',
	points: 1,
	explanation: '',
	options: [
		{ text: '', correct: true },
		{ text: '', correct: false },
	],
	answer: true,
	template: '',
	accepted: [],
	pairs: [
		{ prompt: '', match: '' },
		{ prompt: '', match: '' },
	],
};

/**
 * Editable list of answer options for single and multiple choice.
 *
 * @param {Object}                   props
 * @param {Array}                    props.options  Options.
 * @param {boolean}                  props.multiple Whether several may be correct.
 * @param {(options: Array) => void} props.onChange Receives the new list.
 */
function OptionsField( { options, multiple, onChange } ) {
	const groupId = useInstanceId( OptionsField, 'publiclms-correct' );
	const update = ( index, patch ) =>
		onChange(
			options.map( ( option, i ) => {
				if ( i === index ) {
					return { ...option, ...patch };
				}
				// Single choice: marking one correct unmarks the others.
				return ! multiple && patch.correct
					? { ...option, correct: false }
					: option;
			} )
		);
	return (
		<fieldset className="publiclms-question__list">
			<legend>{ __( 'Answers', 'public-lms' ) }</legend>
			{ options.map( ( option, index ) => (
				<div className="publiclms-question__row" key={ index }>
					{ multiple ? (
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __( 'Correct', 'public-lms' ) }
							checked={ !! option.correct }
							onChange={ ( checked ) =>
								update( index, { correct: checked } )
							}
						/>
					) : (
						<span className="publiclms-question__correct">
							<input
								type="radio"
								id={ `${ groupId }-${ index }` }
								name={ groupId }
								checked={ !! option.correct }
								onChange={ () =>
									update( index, { correct: true } )
								}
							/>
							<label htmlFor={ `${ groupId }-${ index }` }>
								{ __( 'Correct', 'public-lms' ) }
							</label>
						</span>
					) }
					<TextControl
						__next40pxDefaultSize
						label={ sprintf(
							/* translators: %d: answer number. */
							__( 'Answer %d', 'public-lms' ),
							index + 1
						) }
						hideLabelFromVision
						placeholder={ sprintf(
							/* translators: %d: answer number. */
							__( 'Answer %d', 'public-lms' ),
							index + 1
						) }
						value={ option.text }
						onChange={ ( text ) => update( index, { text } ) }
					/>
					<Button
						icon={ trash }
						label={ __( 'Remove answer', 'public-lms' ) }
						disabled={ options.length <= 2 }
						onClick={ () =>
							onChange(
								options.filter( ( _, i ) => i !== index )
							)
						}
					/>
				</div>
			) ) }
			<Button
				variant="secondary"
				disabled={ options.length >= 10 }
				onClick={ () =>
					onChange( [ ...options, { text: '', correct: false } ] )
				}
			>
				{ __( 'Add answer', 'public-lms' ) }
			</Button>
		</fieldset>
	);
}

/**
 * Editable list of matching pairs.
 *
 * @param {Object}                 props
 * @param {Array}                  props.pairs    Pairs.
 * @param {(pairs: Array) => void} props.onChange Receives the new list.
 */
function PairsField( { pairs, onChange } ) {
	const update = ( index, patch ) =>
		onChange(
			pairs.map( ( pair, i ) =>
				i === index ? { ...pair, ...patch } : pair
			)
		);
	return (
		<fieldset className="publiclms-question__list">
			<legend>{ __( 'Pairs', 'public-lms' ) }</legend>
			{ pairs.map( ( pair, index ) => (
				<div className="publiclms-question__row" key={ index }>
					<TextControl
						__next40pxDefaultSize
						label={ sprintf(
							/* translators: %d: pair number. */
							__( 'Prompt %d', 'public-lms' ),
							index + 1
						) }
						value={ pair.prompt }
						onChange={ ( prompt ) => update( index, { prompt } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						label={ sprintf(
							/* translators: %d: pair number. */
							__( 'Match %d', 'public-lms' ),
							index + 1
						) }
						value={ pair.match }
						onChange={ ( match ) => update( index, { match } ) }
					/>
					<Button
						icon={ trash }
						label={ __( 'Remove pair', 'public-lms' ) }
						disabled={ pairs.length <= 2 }
						onClick={ () =>
							onChange( pairs.filter( ( _, i ) => i !== index ) )
						}
					/>
				</div>
			) ) }
			<Button
				variant="secondary"
				disabled={ pairs.length >= 10 }
				onClick={ () =>
					onChange( [ ...pairs, { prompt: '', match: '' } ] )
				}
			>
				{ __( 'Add pair', 'public-lms' ) }
			</Button>
		</fieldset>
	);
}

/**
 * The dialog.
 *
 * @param {Object}                     props
 * @param {number}                     [props.questionId] Question to edit; omit to create one.
 * @param {number}                     [props.quizId]     Quiz a new question is added to.
 * @param {(question: Object) => void} props.onSaved      Called with the saved question.
 * @param {() => void}                 props.onClose      Closes the dialog.
 */
export default function QuestionEditor( {
	questionId,
	quizId,
	onSaved,
	onClose,
} ) {
	const [ question, setQuestion ] = useState( questionId ? null : EMPTY );
	const [ isSaving, setIsSaving ] = useState( false );
	const close = useRef( onClose );
	close.current = onClose;

	useEffect( () => {
		if ( ! questionId ) {
			return;
		}
		apiFetch( { path: `/publiclms/v1/questions/${ questionId }` } )
			.then( ( data ) =>
				setQuestion( {
					...EMPTY,
					...data,
					options: data.options?.length
						? data.options
						: EMPTY.options,
					pairs: data.pairs?.length ? data.pairs : EMPTY.pairs,
				} )
			)
			.catch( ( error ) => {
				notifyError( error );
				close.current();
			} );
	}, [ questionId ] );

	const set = ( key ) => ( value ) =>
		setQuestion( ( current ) => ( { ...current, [ key ]: value } ) );

	const save = async ( event ) => {
		event.preventDefault();
		setIsSaving( true );
		const data = {
			type: question.type,
			text: question.text,
			points: Number( question.points ) || 0,
			explanation: question.explanation,
			options: question.options,
			answer: !! question.answer,
			template: question.template,
			accepted: question.accepted,
			pairs: question.pairs,
		};
		try {
			const saved = await apiFetch( {
				path: questionId
					? `/publiclms/v1/questions/${ questionId }`
					: '/publiclms/v1/questions',
				method: questionId ? 'PATCH' : 'POST',
				data: questionId ? data : { ...data, quiz_id: quizId },
			} );
			notifySuccess( __( 'Question saved.', 'public-lms' ) );
			onSaved( saved );
		} catch ( error ) {
			notifyError( error );
			setIsSaving( false );
		}
	};

	const title = questionId
		? __( 'Edit question', 'public-lms' )
		: __( 'New question', 'public-lms' );

	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			className="publiclms-question-modal"
			size="large"
		>
			{ ! question ? (
				<Spinner />
			) : (
				<form onSubmit={ save } className="publiclms-question">
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Type', 'public-lms' ) }
						value={ question.type }
						options={ Object.entries( QUESTION_TYPES ).map(
							( [ value, label ] ) => ( { value, label } )
						) }
						onChange={ set( 'type' ) }
					/>
					<TextareaControl
						label={
							question.type === 'fill_blank'
								? __( 'Instructions (optional)', 'public-lms' )
								: __( 'Question', 'public-lms' )
						}
						value={ question.text }
						onChange={ set( 'text' ) }
						rows={ 3 }
						required={ question.type !== 'fill_blank' }
					/>
					{ ( question.type === 'single' ||
						question.type === 'multiple' ) && (
						<OptionsField
							options={ question.options }
							multiple={ question.type === 'multiple' }
							onChange={ set( 'options' ) }
						/>
					) }
					{ question.type === 'true_false' && (
						<RadioControl
							label={ __( 'The right answer', 'public-lms' ) }
							selected={ question.answer ? 'true' : 'false' }
							options={ [
								{
									value: 'true',
									label: __( 'True', 'public-lms' ),
								},
								{
									value: 'false',
									label: __( 'False', 'public-lms' ),
								},
							] }
							onChange={ ( value ) =>
								set( 'answer' )( value === 'true' )
							}
						/>
					) }
					{ question.type === 'fill_blank' && (
						<TextareaControl
							label={ __( 'Sentence with blanks', 'public-lms' ) }
							help={ __(
								'Wrap each missing word in double braces: The capital of Nepal is {{Kathmandu}}. Separate other accepted answers with |, as in {{colour|color}}. Case and spacing are ignored.',
								'public-lms'
							) }
							value={ question.template }
							onChange={ set( 'template' ) }
							rows={ 3 }
							required
						/>
					) }
					{ question.type === 'short_answer' && (
						<TextareaControl
							label={ __( 'Accepted answers', 'public-lms' ) }
							help={ __(
								'One per line. Case and spacing are ignored.',
								'public-lms'
							) }
							value={ ( question.accepted || [] ).join( '\n' ) }
							onChange={ ( value ) =>
								set( 'accepted' )( value.split( '\n' ) )
							}
							rows={ 3 }
							required
						/>
					) }
					{ question.type === 'matching' && (
						<PairsField
							pairs={ question.pairs }
							onChange={ set( 'pairs' ) }
						/>
					) }
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						step={ 0.5 }
						label={ __( 'Points', 'public-lms' ) }
						value={ question.points }
						onChange={ set( 'points' ) }
					/>
					<TextareaControl
						label={ __( 'Explanation', 'public-lms' ) }
						help={ __(
							'Shown with the result after the learner submits, when the quiz shows results. It is not shown before. If learners can try again, explain why without giving the answer away.',
							'public-lms'
						) }
						value={ question.explanation }
						onChange={ set( 'explanation' ) }
						rows={ 2 }
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
							{ __( 'Save question', 'public-lms' ) }
						</Button>
					</div>
				</form>
			) }
		</Modal>
	);
}
