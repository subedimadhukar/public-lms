/**
 * Grades one hand-in: the learner's answer and files, a grade and feedback.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Modal,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';

import { notifyError, notifySuccess } from '../components/notices';

/**
 * The dialog.
 *
 * @param {Object}                    props
 * @param {Object}                    props.submission Hand-in from the API.
 * @param {(updated: Object) => void} props.onGraded   Called with the graded hand-in.
 * @param {() => void}                props.onClose    Closes the dialog.
 */
export default function GradingModal( { submission, onGraded, onClose } ) {
	const [ grade, setGrade ] = useState(
		submission.grade === null ? '' : String( submission.grade )
	);
	const [ feedback, setFeedback ] = useState( submission.feedback || '' );
	const [ isSaving, setIsSaving ] = useState( false );

	const save = async ( event ) => {
		event.preventDefault();
		setIsSaving( true );
		try {
			const updated = await apiFetch( {
				path: `/publiclms/v1/assignments/${ submission.assignment_id }/submissions/${ submission.id }/grade`,
				method: 'PUT',
				data: { grade: Number( grade ), feedback },
			} );
			notifySuccess( __( 'Grade saved.', 'public-lms' ) );
			onGraded( updated );
		} catch ( error ) {
			notifyError( error );
			setIsSaving( false );
		}
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: 1: learner name, 2: assignment title. */
				__( 'Grade %1$s: %2$s', 'public-lms' ),
				submission.user.name,
				decodeEntities( submission.assignment )
			) }
			onRequestClose={ onClose }
			className="publiclms-grading-modal"
			size="large"
		>
			<form onSubmit={ save } className="publiclms-question">
				<p className="publiclms-screen__summary">
					{ sprintf(
						/* translators: 1: course title, 2: date, 3: attempt number. */
						__(
							'%1$s · handed in %2$s · attempt %3$d',
							'public-lms'
						),
						decodeEntities( submission.course ),
						new Date( submission.submitted_at ).toLocaleString(),
						submission.attempt
					) }
				</p>
				{ submission.content && (
					<section>
						<h3>{ __( 'Answer', 'public-lms' ) }</h3>
						<div className="publiclms-grading-modal__answer">
							{ submission.content }
						</div>
					</section>
				) }
				{ submission.files.length > 0 && (
					<section>
						<h3>{ __( 'Files', 'public-lms' ) }</h3>
						<ul>
							{ submission.files.map( ( file ) => (
								<li key={ file.id }>
									<a href={ file.url }>{ file.name }</a>
								</li>
							) ) }
						</ul>
					</section>
				) }
				<TextControl
					__next40pxDefaultSize
					type="number"
					min={ 0 }
					max={ submission.max_grade }
					step={ 0.25 }
					label={ sprintf(
						/* translators: %s: highest grade. */
						__( 'Grade (out of %s)', 'public-lms' ),
						submission.max_grade
					) }
					value={ grade }
					onChange={ setGrade }
					required
				/>
				<TextareaControl
					label={ __( 'Feedback for the learner', 'public-lms' ) }
					value={ feedback }
					onChange={ setFeedback }
					rows={ 5 }
				/>
				<div className="publiclms-form__actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'public-lms' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ isSaving }
						disabled={ isSaving || grade === '' }
					>
						{ __( 'Save grade', 'public-lms' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}
