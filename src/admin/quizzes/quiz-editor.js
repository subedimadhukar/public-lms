/**
 * The quiz editor of the course builder: settings and the ordered question
 * list, with new questions and questions from the bank (ADR-042).
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	DropdownMenu,
	Notice,
	Panel,
	PanelBody,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	arrowDown,
	arrowLeft,
	arrowUp,
	external,
	moreVertical,
	pencil,
	trash,
} from '@wordpress/icons';
import { addQueryArgs } from '@wordpress/url';

import { boot } from '../boot';
import { notifyError, notifySuccess } from '../components/notices';
import BankPicker from './bank-picker';
import QuestionEditor, { QUESTION_TYPES } from './question-editor';

const SETTINGS = [
	'time_limit',
	'max_attempts',
	'pass_mark',
	'question_count',
	'shuffle_questions',
	'shuffle_answers',
	'show_results',
];

/**
 * The quiz's settings form.
 *
 * @param {Object}                    props
 * @param {Object}                    props.quiz   Quiz from the API.
 * @param {(data: Object) => Promise} props.onSave Saves changed settings.
 */
function QuizSettingsPanel( { quiz, onSave } ) {
	const [ edits, setEdits ] = useState( {} );
	const [ isSaving, setIsSaving ] = useState( false );
	const value = ( key ) => ( key in edits ? edits[ key ] : quiz[ key ] );
	const set = ( key ) => ( next ) =>
		setEdits( ( current ) => ( { ...current, [ key ]: next } ) );
	const number = ( key ) => ( next ) => set( key )( Number( next ) || 0 );

	const save = async ( event ) => {
		event.preventDefault();
		setIsSaving( true );
		try {
			await onSave( edits );
			setEdits( {} );
			notifySuccess( __( 'Quiz settings saved.', 'public-lms' ) );
		} catch ( error ) {
			notifyError( error );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<form onSubmit={ save } className="publiclms-builder__settings">
			<Panel header={ __( 'Quiz settings', 'public-lms' ) }>
				<PanelBody>
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						max={ 100 }
						label={ __( 'Pass mark (%)', 'public-lms' ) }
						help={ __(
							'Passing completes the quiz in the learner’s progress.',
							'public-lms'
						) }
						value={ value( 'pass_mark' ) }
						onChange={ number( 'pass_mark' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						max={ 1440 }
						label={ __( 'Time limit in minutes', 'public-lms' ) }
						help={ __( '0 for no time limit.', 'public-lms' ) }
						value={ value( 'time_limit' ) }
						onChange={ number( 'time_limit' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						max={ 100 }
						label={ __( 'Attempts allowed', 'public-lms' ) }
						help={ __( '0 for unlimited attempts.', 'public-lms' ) }
						value={ value( 'max_attempts' ) }
						onChange={ number( 'max_attempts' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						label={ __( 'Questions per attempt', 'public-lms' ) }
						help={ __(
							'Draws this many questions at random from the list for each attempt. 0 uses every question.',
							'public-lms'
						) }
						value={ value( 'question_count' ) }
						onChange={ number( 'question_count' ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Shuffle question order', 'public-lms' ) }
						checked={ !! value( 'shuffle_questions' ) }
						onChange={ set( 'shuffle_questions' ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Shuffle answer options', 'public-lms' ) }
						checked={ !! value( 'shuffle_answers' ) }
						onChange={ set( 'shuffle_answers' ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show right or wrong per question after submitting',
							'public-lms'
						) }
						help={ __(
							'Learners see which answers were right and your explanations, never the answer key.',
							'public-lms'
						) }
						checked={ !! value( 'show_results' ) }
						onChange={ set( 'show_results' ) }
					/>
					<div className="publiclms-form__actions">
						<Button
							variant="primary"
							type="submit"
							isBusy={ isSaving }
							disabled={
								isSaving || ! Object.keys( edits ).length
							}
						>
							{ __( 'Save settings', 'public-lms' ) }
						</Button>
					</div>
				</PanelBody>
			</Panel>
		</form>
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {number} props.courseId Course ID.
 * @param {number} props.quizId   Quiz ID.
 */
export default function QuizEditor( { courseId, quizId } ) {
	const [ quiz, setQuiz ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ isPicking, setIsPicking ] = useState( false );
	const builderUrl = addQueryArgs( boot.appUrl, { course: courseId } );

	const load = useCallback( () => {
		apiFetch( {
			path: `/publiclms/v1/quizzes/${ quizId }?context=edit`,
		} )
			.then( setQuiz )
			.catch( setError );
	}, [ quizId ] );

	useEffect( load, [ load ] );

	const patch = async ( data ) => {
		const next = await apiFetch( {
			path: `/publiclms/v1/quizzes/${ quizId }`,
			method: 'PATCH',
			data,
		} );
		setQuiz( next );
		return next;
	};

	const ids = ( quiz?.questions || [] ).map( ( question ) => question.id );
	const saveOrder = async ( next, message ) => {
		try {
			await patch( { questions: next } );
			notifySuccess( message );
		} catch ( saveError ) {
			notifyError( saveError );
		}
	};
	const move = ( index, delta ) => {
		const next = [ ...ids ];
		const [ id ] = next.splice( index, 1 );
		next.splice( index + delta, 0, id );
		saveOrder( next, __( 'Question order saved.', 'public-lms' ) );
	};

	if ( error ) {
		return (
			<div className="publiclms-screen">
				<Notice status="error" isDismissible={ false }>
					{ error.message ||
						__( 'This quiz could not be loaded.', 'public-lms' ) }
				</Notice>
				<Button variant="link" href={ builderUrl }>
					{ __( 'Back to the course', 'public-lms' ) }
				</Button>
			</div>
		);
	}
	if ( ! quiz ) {
		return (
			<div className="publiclms-screen publiclms-screen--loading">
				<Spinner />
			</div>
		);
	}

	const settings = Object.fromEntries(
		SETTINGS.map( ( key ) => [ key, quiz[ key ] ] )
	);

	return (
		<div className="publiclms-screen publiclms-builder publiclms-quiz-editor">
			<div className="publiclms-screen__header">
				<Button
					variant="tertiary"
					icon={ arrowLeft }
					href={ builderUrl }
					className="publiclms-builder__back"
				>
					{ __( 'Course builder', 'public-lms' ) }
				</Button>
				<h2 className="publiclms-screen__title">
					{ decodeEntities( quiz.title ) ||
						__( '(no title)', 'public-lms' ) }
				</h2>
				<div className="publiclms-screen__header-actions">
					{ quiz.edit_url && (
						<Button variant="secondary" href={ quiz.edit_url }>
							{ __( 'Edit introduction', 'public-lms' ) }
						</Button>
					) }
					<Button
						variant="tertiary"
						icon={ external }
						iconPosition="right"
						href={ quiz.link }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'View quiz', 'public-lms' ) }
					</Button>
				</div>
			</div>
			<div className="publiclms-builder__layout">
				<section
					className="publiclms-builder__curriculum"
					aria-labelledby="publiclms-questions-heading"
				>
					<h3
						id="publiclms-questions-heading"
						className="publiclms-builder__heading"
					>
						{ __( 'Questions', 'public-lms' ) }
					</h3>
					<p className="publiclms-screen__summary">
						{ sprintf(
							/* translators: 1: number of questions, 2: questions per attempt. */
							_n(
								'%1$d question in the list; each attempt shows %2$d.',
								'%1$d questions in the list; each attempt shows %2$d.',
								ids.length,
								'public-lms'
							),
							ids.length,
							quiz.question_total
						) }
					</p>
					{ ids.length === 0 ? (
						<p className="publiclms-builder__empty">
							{ __(
								'No questions yet. Write a new one or add some from the question bank.',
								'public-lms'
							) }
						</p>
					) : (
						<ol className="publiclms-question-list">
							{ quiz.questions.map( ( question, index ) => (
								<li
									key={ question.id }
									className="publiclms-question-list__item"
								>
									<span className="publiclms-question-list__title">
										{ decodeEntities( question.title ) ||
											__( '(no text)', 'public-lms' ) }
									</span>
									<span className="publiclms-badge">
										{ QUESTION_TYPES[ question.type ] }
									</span>
									<span className="publiclms-builder__count">
										{ sprintf(
											/* translators: %s: points. */
											_n(
												'%s point',
												'%s points',
												question.points,
												'public-lms'
											),
											question.points
										) }
									</span>
									{ question.can_edit && (
										<Button
											variant="tertiary"
											size="compact"
											icon={ pencil }
											onClick={ () =>
												setEditing( question.id )
											}
										>
											{ __( 'Edit', 'public-lms' ) }
										</Button>
									) }
									<DropdownMenu
										icon={ moreVertical }
										label={ sprintf(
											/* translators: %s: question text. */
											__(
												'Actions for question: %s',
												'public-lms'
											),
											decodeEntities( question.title )
										) }
										toggleProps={ { size: 'compact' } }
										controls={ [
											{
												title: __(
													'Move up',
													'public-lms'
												),
												icon: arrowUp,
												isDisabled: index === 0,
												onClick: () =>
													move( index, -1 ),
											},
											{
												title: __(
													'Move down',
													'public-lms'
												),
												icon: arrowDown,
												isDisabled:
													index === ids.length - 1,
												onClick: () => move( index, 1 ),
											},
											{
												title: __(
													'Remove from quiz',
													'public-lms'
												),
												icon: trash,
												onClick: () =>
													saveOrder(
														ids.filter(
															( id ) =>
																id !==
																question.id
														),
														__(
															'Question removed from the quiz. It stays in the question bank.',
															'public-lms'
														)
													),
											},
										] }
									/>
								</li>
							) ) }
						</ol>
					) }
					<div className="publiclms-builder__add-items">
						<Button
							variant="secondary"
							onClick={ () => setEditing( 'new' ) }
						>
							{ __( 'New question', 'public-lms' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => setIsPicking( true ) }
						>
							{ __( 'Add from question bank', 'public-lms' ) }
						</Button>
					</div>
				</section>
				<aside className="publiclms-builder__aside">
					<QuizSettingsPanel
						key={ JSON.stringify( settings ) }
						quiz={ quiz }
						onSave={ patch }
					/>
				</aside>
			</div>
			{ editing && (
				<QuestionEditor
					questionId={ editing === 'new' ? 0 : editing }
					quizId={ quizId }
					onClose={ () => setEditing( null ) }
					onSaved={ () => {
						setEditing( null );
						load();
					} }
				/>
			) }
			{ isPicking && (
				<BankPicker
					quizId={ quizId }
					onClose={ () => setIsPicking( false ) }
					onAdd={ async ( chosen ) => {
						await patch( { questions: [ ...ids, ...chosen ] } );
						notifySuccess(
							__( 'Questions added to the quiz.', 'public-lms' )
						);
					} }
				/>
			) }
		</div>
	);
}
