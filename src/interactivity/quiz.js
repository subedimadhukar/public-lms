/**
 * The quiz runner, part of the `publiclms/learning` store (ADR-035, ADR-042).
 *
 * Server state, seeded by src/blocks/quiz/render.php:
 * - quiz[quizId]: { phase: intro|running|done, attemptId, questions, answers,
 *   results, timeLeft, message, notice, busy, confirmUnanswered, score, passed,
 *   canRetake, courseId, nextUrl };
 * - i18n.quiz: translated strings.
 *
 * Questions arrive from POST quizzes/{id}/attempts without answer keys and
 * render with data-wp-each. Answers autosave; submitting grades on the
 * server, and the response says right or wrong per question, never the key.
 */
import { getContext, getElement, store } from '@wordpress/interactivity';

import { format, get, post } from './api';

const timers = {};
const saveTimers = {};

/**
 * Returns the quiz the current element belongs to.
 *
 * @return {Object} Quiz state.
 */
function currentQuiz() {
	const { quizId } = getContext();
	return state.quiz?.[ quizId ] || {};
}

/**
 * Returns the answer to the question in context.
 *
 * @return {string|boolean|Array|Object|undefined} Answer.
 */
function currentAnswer() {
	const { question } = getContext();
	return currentQuiz().answers?.[ question?.id ];
}

/**
 * Writes an answer for the question in context and schedules an autosave.
 *
 * @param {string|boolean|Array|Object} value Answer.
 */
function setAnswer( value ) {
	const { quizId, question } = getContext();
	const quiz = state.quiz[ quizId ];
	quiz.answers = { ...quiz.answers, [ question.id ]: value };
	quiz.confirmUnanswered = false;
	window.clearTimeout( saveTimers[ quizId ] );
	saveTimers[ quizId ] = window.setTimeout( () => {
		post( `quizzes/${ quizId }/attempts/${ quiz.attemptId }/answers`, {
			answers: quiz.answers,
		} ).catch( () => {} );
	}, 1200 );
}

/**
 * Formats seconds as m:ss or h:mm:ss.
 *
 * @param {number} seconds Seconds.
 * @return {string} Time.
 */
function clock( seconds ) {
	const s = Math.max( 0, Math.floor( seconds ) );
	const h = Math.floor( s / 3600 );
	const m = Math.floor( ( s % 3600 ) / 60 );
	const rest = String( s % 60 ).padStart( 2, '0' );
	return h > 0
		? `${ h }:${ String( m ).padStart( 2, '0' ) }:${ rest }`
		: `${ m }:${ rest }`;
}

/**
 * Prepares questions from the API for rendering: labels, input names and
 * flags per type.
 *
 * @param {Array}  questions Questions from the attempt view.
 * @param {number} quizId    Quiz ID.
 * @return {Array} Questions.
 */
function prepare( questions, quizId ) {
	const total = questions.length;
	return questions.map( ( question ) => ( {
		...question,
		key: `q${ quizId }-${ question.id }`,
		tfKey: `q${ quizId }-${ question.id }-tf`,
		label: format( state.i18n.quiz.questionNumber, question.number, total ),
		isSingle: question.type === 'single',
		isMultiple: question.type === 'multiple',
		isTrueFalse: question.type === 'true_false',
		isFillBlank: question.type === 'fill_blank',
		isShortAnswer: question.type === 'short_answer',
		isMatching: question.type === 'matching',
		segments: ( question.segments || [] ).map( ( segment ) => ( {
			...segment,
			isBlank: segment.type === 'blank',
			label: format( state.i18n.quiz.blank, ( segment.index ?? 0 ) + 1 ),
		} ) ),
		prompts: ( question.prompts || [] ).map( ( prompt ) => ( {
			...prompt,
			inputId: `q${ quizId }-${ question.id }-${ prompt.id }`,
		} ) ),
		hasResult: false,
		isCorrect: false,
		isWrong: false,
		resultLabel: '',
		resultPoints: '',
		explanation: '',
	} ) );
}

/**
 * Counts questions without an answer.
 *
 * @param {Object} quiz Quiz state.
 * @return {number} Unanswered questions.
 */
function unanswered( quiz ) {
	return quiz.questions.filter( ( question ) => {
		const answer = quiz.answers[ question.id ];
		if ( answer === undefined || answer === null || answer === '' ) {
			return true;
		}
		if ( Array.isArray( answer ) ) {
			return ! answer.some( ( value ) => String( value ).trim() !== '' );
		}
		return typeof answer === 'object' && ! Object.keys( answer ).length;
	} ).length;
}

/**
 * Moves focus to an element of the quiz once the DOM has updated.
 *
 * @param {Element} root     The quiz block.
 * @param {string}  selector Element to focus.
 */
function focusLater( root, selector ) {
	window.setTimeout( () => root?.querySelector( selector )?.focus(), 50 );
}

/**
 * Refreshes the shared course progress after a quiz changed it.
 *
 * @param {number} courseId Course ID.
 */
async function refreshProgress( courseId ) {
	if ( ! courseId || ! state.progress ) {
		return;
	}
	try {
		const data = await get( `courses/${ courseId }/progress/me` );
		state.progress[ courseId ] = {
			percent: data.percent,
			done: data.items_completed,
			total: data.items_total,
			completed: data.completed,
			items: Object.fromEntries(
				( data.completed_items || [] ).map( ( id ) => [ id, true ] )
			),
		};
	} catch {
		// The progress bar keeps its old value; the next page load corrects it.
	}
}

/**
 * Shows an attempt's questions and starts its timer.
 *
 * @param {number} quizId Quiz ID.
 * @param {Object} data   Attempt view from the API.
 */
function run( quizId, data ) {
	const quiz = state.quiz[ quizId ];
	quiz.attemptId = data.id;
	quiz.questions = prepare( data.questions || [], quizId );
	quiz.answers = { ...( data.answers || {} ) };
	quiz.results = null;
	quiz.confirmUnanswered = false;
	quiz.notice = '';
	quiz.phase = 'running';
	window.clearInterval( timers[ quizId ] );
	if ( typeof data.time_left === 'number' ) {
		const ends = Date.now() + data.time_left * 1000;
		quiz.timeLeft = data.time_left;
		timers[ quizId ] = window.setInterval( () => {
			const left = Math.max(
				0,
				Math.round( ( ends - Date.now() ) / 1000 )
			);
			quiz.timeLeft = left;
			if ( left === 300 || left === 60 ) {
				quiz.notice = format(
					state.i18n.quiz.timeWarning,
					Math.round( left / 60 )
				);
			}
			if ( left <= 0 ) {
				window.clearInterval( timers[ quizId ] );
				quiz.notice = state.i18n.quiz.timeUp;
				submit( quizId );
			}
		}, 1000 );
	} else {
		quiz.timeLeft = null;
	}
}

/**
 * Submits the current attempt and shows the results.
 *
 * @param {number}  quizId Quiz ID.
 * @param {Element} [root] The quiz block, to move focus to the results.
 */
async function submit( quizId, root ) {
	const quiz = state.quiz[ quizId ];
	if ( quiz.busy || quiz.phase !== 'running' ) {
		return;
	}
	quiz.busy = true;
	window.clearTimeout( saveTimers[ quizId ] );
	try {
		const data = await post(
			`quizzes/${ quizId }/attempts/${ quiz.attemptId }/submit`,
			{ answers: quiz.answers }
		);
		window.clearInterval( timers[ quizId ] );
		const byId = Object.fromEntries(
			( data.questions || [] ).map( ( result ) => [ result.id, result ] )
		);
		quiz.questions = quiz.questions.map( ( question ) => {
			const result = byId[ question.id ];
			if ( ! result ) {
				return question;
			}
			return {
				...question,
				hasResult: true,
				isCorrect: !! result.correct,
				isWrong: ! result.correct,
				resultLabel: result.correct
					? state.i18n.quiz.correct
					: state.i18n.quiz.incorrect,
				resultPoints: format(
					state.i18n.quiz.points,
					result.points,
					result.max_points
				),
				explanation: result.explanation || '',
			};
		} );
		quiz.score = format(
			state.i18n.quiz.score,
			Math.round( data.percent ?? 0 )
		);
		quiz.passed = !! data.passed;
		quiz.verdict = data.message || '';
		quiz.message = '';
		quiz.notice = '';
		quiz.canRetake = !! data.learner?.can_attempt?.allowed;
		quiz.phase = 'done';
		focusLater( root, '.wp-block-publiclms-quiz__results-title' );
		if ( data.passed ) {
			refreshProgress( quiz.courseId );
		}
	} catch ( error ) {
		quiz.message = error.message || state.i18n.error;
	} finally {
		quiz.busy = false;
	}
}

const { state } = store( 'publiclms/learning', {
	state: {
		get quizPhase() {
			return currentQuiz().phase || 'intro';
		},
		get quizIsIntro() {
			return state.quizPhase === 'intro';
		},
		get quizIsRunning() {
			return state.quizPhase === 'running';
		},
		get quizIsDone() {
			return state.quizPhase === 'done';
		},
		get quizIsBusy() {
			return !! currentQuiz().busy;
		},
		get quizQuestions() {
			return currentQuiz().questions || [];
		},
		get quizMessage() {
			return currentQuiz().message || '';
		},
		get quizNotice() {
			return currentQuiz().notice || '';
		},
		get quizHasTimer() {
			return (
				state.quizIsRunning &&
				typeof currentQuiz().timeLeft === 'number'
			);
		},
		get quizTimeText() {
			return format(
				state.i18n.quiz.timeLeft,
				clock( currentQuiz().timeLeft || 0 )
			);
		},
		get quizVerdict() {
			return currentQuiz().verdict || '';
		},
		get quizScore() {
			return currentQuiz().score || '';
		},
		get quizPassed() {
			return !! currentQuiz().passed;
		},
		get quizCanRetake() {
			return !! currentQuiz().canRetake;
		},
		get quizSubmitLabel() {
			return currentQuiz().confirmUnanswered
				? state.i18n.quiz.submitAnyway
				: state.i18n.quiz.submit;
		},
		get isOptionChecked() {
			const { question, option } = getContext();
			const answer = currentAnswer();
			return question.type === 'multiple'
				? Array.isArray( answer ) && answer.includes( option.id )
				: answer === option.id;
		},
		get isTrueChecked() {
			return currentAnswer() === true;
		},
		get isFalseChecked() {
			return currentAnswer() === false;
		},
		get blankValue() {
			const { segment } = getContext();
			const answer = currentAnswer();
			return ( Array.isArray( answer ) && answer[ segment.index ] ) || '';
		},
		get textValue() {
			const answer = currentAnswer();
			return typeof answer === 'string' ? answer : '';
		},
		get isMatchSelected() {
			const { prompt, match } = getContext();
			return ( currentAnswer() || {} )[ prompt.id ] === match.id;
		},
		get questionLocked() {
			return currentQuiz().phase !== 'running' || !! currentQuiz().busy;
		},
	},
	actions: {
		/**
		 * Starts an attempt, or resumes the one in progress.
		 */
		*startQuiz() {
			const { quizId } = getContext();
			const quiz = state.quiz[ quizId ];
			const root = getElement().ref?.closest(
				'.wp-block-publiclms-quiz'
			);
			if ( quiz.busy ) {
				return;
			}
			quiz.busy = true;
			quiz.message = '';
			try {
				const data = yield post( `quizzes/${ quizId }/attempts` );
				run( quizId, data );
				focusLater( root, '.wp-block-publiclms-quiz__form-title' );
			} catch ( error ) {
				quiz.message = error.message || state.i18n.error;
			} finally {
				quiz.busy = false;
			}
		},

		/**
		 * Hands the attempt in; asks once more when questions are unanswered.
		 *
		 * @param {Event} event Submit event.
		 */
		*submitQuiz( event ) {
			event.preventDefault();
			const { quizId } = getContext();
			const quiz = state.quiz[ quizId ];
			const root = getElement().ref?.closest(
				'.wp-block-publiclms-quiz'
			);
			const missing = unanswered( quiz );
			if ( missing > 0 && ! quiz.confirmUnanswered ) {
				quiz.confirmUnanswered = true;
				quiz.notice = format( state.i18n.quiz.unanswered, missing );
				return;
			}
			yield submit( quizId, root );
		},

		/**
		 * Records a choice (single, multiple or true/false).
		 *
		 * @param {Event} event Change event.
		 */
		answerChoice( event ) {
			const { question } = getContext();
			const { value, checked } = event.target;
			if ( question.type === 'true_false' ) {
				setAnswer( value === 'true' );
			} else if ( question.type === 'multiple' ) {
				const current = Array.isArray( currentAnswer() )
					? currentAnswer()
					: [];
				setAnswer(
					checked
						? [ ...current, value ]
						: current.filter( ( id ) => id !== value )
				);
			} else {
				setAnswer( value );
			}
		},

		/**
		 * Records one blank of a fill-in-the-blank question.
		 *
		 * @param {Event} event Input event.
		 */
		answerBlank( event ) {
			const { question, segment } = getContext();
			const count = question.segments.filter( ( s ) => s.isBlank ).length;
			const current = Array.isArray( currentAnswer() )
				? [ ...currentAnswer() ]
				: Array( count ).fill( '' );
			current[ segment.index ] = event.target.value;
			setAnswer( current );
		},

		/**
		 * Records a short answer.
		 *
		 * @param {Event} event Input event.
		 */
		answerText( event ) {
			setAnswer( event.target.value );
		},

		/**
		 * Records a match for one prompt.
		 *
		 * @param {Event} event Change event.
		 */
		answerMatch( event ) {
			const { prompt } = getContext();
			const current = { ...( currentAnswer() || {} ) };
			if ( event.target.value ) {
				current[ prompt.id ] = event.target.value;
			} else {
				delete current[ prompt.id ];
			}
			setAnswer( current );
		},

		/**
		 * Goes back to the start panel for another attempt.
		 */
		retakeQuiz() {
			const { quizId } = getContext();
			const quiz = state.quiz[ quizId ];
			quiz.phase = 'intro';
			quiz.questions = [];
			quiz.message = '';
			quiz.notice = '';
		},
	},
	callbacks: {
		/**
		 * Writes the server-sanitised question HTML (wp_kses_post) into its element.
		 */
		renderQuestionHtml() {
			const { question } = getContext();
			const { ref } = getElement();
			if ( ref && question ) {
				ref.innerHTML = question.text || '';
			}
		},

		/**
		 * Writes the server-sanitised explanation HTML into its element.
		 */
		renderExplanationHtml() {
			const { question } = getContext();
			const { ref } = getElement();
			if ( ref && question ) {
				ref.innerHTML = question.explanation || '';
			}
		},
	},
} );
