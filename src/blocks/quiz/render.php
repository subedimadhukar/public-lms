<?php
/**
 * Renders publiclms/quiz, the quiz runner.
 *
 * The server renders the start panel (facts, best score, the start, resume
 * or retake button). Questions arrive from POST quizzes/{id}/attempts with
 * no answer keys and render through data-wp-each; answers autosave, the
 * timer counts down and submits at zero, and the server grades the attempt
 * (ADR-016, ADR-042). One question per fieldset, labelled, keyboard
 * operable; the result heading takes focus after submitting.
 *
 * @package PublicLMS
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner content.
 * @var WP_Block             $block      Block instance.
 */

use PublicLMS\Access\ContentGate;
use PublicLMS\Blocks\Blocks;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Quizzes\QuizSettings;
use PublicLMS\Services\QuizService;

defined( 'ABSPATH' ) || exit;

$publiclms_blocks = Blocks::instance();
$publiclms_quiz   = isset( $attributes['quizId'] ) ? absint( $attributes['quizId'] ) : 0;
$publiclms_quiz   = $publiclms_quiz > 0 ? $publiclms_quiz : $publiclms_blocks->item_id( $block );
if ( $publiclms_quiz <= 0 || PostTypes::QUIZ !== get_post_type( $publiclms_quiz ) ) {
	return;
}

$publiclms_access = $publiclms_blocks->policy->can_access_item( $publiclms_quiz );
$publiclms_course = $publiclms_blocks->structure->course_id_for( $publiclms_quiz );
if ( ! $publiclms_access->is_allowed() ) {
	printf(
		'<div %1$s>%2$s</div>',
		get_block_wrapper_attributes( array( 'class' => 'is-locked' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes().
		ContentGate::locked_notice( $publiclms_access ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in locked_notice().
	);
	return;
}

/**
 * The quiz service.
 *
 * @var QuizService $publiclms_service
 */
$publiclms_service  = publiclms()->container()->get( QuizService::class );
$publiclms_user     = get_current_user_id();
$publiclms_settings = QuizSettings::get( $publiclms_quiz );
$publiclms_summary  = $publiclms_service->learner_summary( $publiclms_user, $publiclms_quiz );
$publiclms_total    = $publiclms_service->question_total( $publiclms_quiz );
$publiclms_next     = $publiclms_course > 0 ? $publiclms_blocks->next_link( $publiclms_course, $publiclms_quiz ) : null;
$publiclms_can      = ! empty( $publiclms_summary['can_attempt']['allowed'] ) || $publiclms_summary['in_progress'] > 0;

if ( $publiclms_course > 0 ) {
	$publiclms_blocks->seed_state( $publiclms_course, $publiclms_quiz );
}

if ( $publiclms_summary['in_progress'] > 0 ) {
	$publiclms_start = __( 'Resume quiz', 'public-lms' );
} elseif ( $publiclms_summary['attempts_used'] > 0 ) {
	$publiclms_start = __( 'Retake quiz', 'public-lms' );
} else {
	$publiclms_start = __( 'Start quiz', 'public-lms' );
}

wp_interactivity_state(
	Blocks::STORE,
	array_merge(
		array(
			'quiz'    => array(
				(string) $publiclms_quiz => array(
					'phase'             => 'intro',
					'attemptId'         => 0,
					'courseId'          => $publiclms_course,
					'questions'         => array(),
					'answers'           => (object) array(),
					'results'           => null,
					'timeLeft'          => null,
					'message'           => '',
					'notice'            => '',
					'verdict'           => '',
					'busy'              => false,
					'confirmUnanswered' => false,
					'score'             => '',
					'passed'            => false,
					'canRetake'         => false,
				),
			),
			'restUrl' => esc_url_raw( rest_url( 'publiclms/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => array(
				'error' => __( 'That did not work. Check your connection and try again.', 'public-lms' ),
				'quiz'  => array(
					/* translators: 1: question number, 2: number of questions. */
					'questionNumber' => __( 'Question %1$d of %2$d', 'public-lms' ),
					/* translators: %d: blank number. */
					'blank'          => __( 'Blank %d', 'public-lms' ),
					/* translators: %s: time left, for example 4:59. */
					'timeLeft'       => __( 'Time left: %s', 'public-lms' ),
					/* translators: %d: minutes left. */
					'timeWarning'    => __( '%d minutes left.', 'public-lms' ),
					'timeUp'         => __( 'Time is up. Your answers are being submitted.', 'public-lms' ),
					/* translators: %d: number of unanswered questions. */
					'unanswered'     => __( '%d questions have no answer. Select the button again to hand in anyway.', 'public-lms' ),
					'submit'         => __( 'Submit answers', 'public-lms' ),
					'submitAnyway'   => __( 'Submit anyway', 'public-lms' ),
					'correct'        => __( 'Correct', 'public-lms' ),
					'incorrect'      => __( 'Not correct', 'public-lms' ),
					/* translators: 1: points scored, 2: points available. */
					'points'         => __( '%1$s of %2$s points', 'public-lms' ),
					/* translators: %d: score as a percentage. */
					'score'          => __( '%d%%', 'public-lms' ),
				),
			),
		),
		Blocks::derived_state()
	)
);

$publiclms_facts = array(
	__( 'Questions', 'public-lms' )  => number_format_i18n( $publiclms_total ),
	__( 'Time limit', 'public-lms' ) => $publiclms_settings['time_limit'] > 0
		/* translators: %d: minutes. */
		? sprintf( _n( '%d minute', '%d minutes', $publiclms_settings['time_limit'], 'public-lms' ), $publiclms_settings['time_limit'] )
		: __( 'None', 'public-lms' ),
	/* translators: %d: pass mark as a percentage. */
	__( 'Pass mark', 'public-lms' )  => sprintf( __( '%d%%', 'public-lms' ), $publiclms_settings['pass_mark'] ),
	__( 'Attempts', 'public-lms' )   => null === $publiclms_summary['attempts_left']
		? __( 'Unlimited', 'public-lms' )
		/* translators: 1: attempts left, 2: attempts allowed. */
		: sprintf( __( '%1$d of %2$d left', 'public-lms' ), $publiclms_summary['attempts_left'], $publiclms_settings['max_attempts'] ),
);
$publiclms_uid   = wp_unique_id( 'publiclms-quiz-' );
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="publiclms/learning"
	<?php
	echo wp_interactivity_data_wp_context( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context().
		array(
			'quizId'   => $publiclms_quiz,
			'courseId' => $publiclms_course,
		)
	);
	?>
>
	<div class="wp-block-publiclms-quiz__intro" data-wp-bind--hidden="!state.quizIsIntro">
		<?php if ( ! empty( $attributes['showFacts'] ) ) : ?>
		<dl class="wp-block-publiclms-quiz__facts">
			<?php foreach ( $publiclms_facts as $publiclms_label => $publiclms_value ) : ?>
				<div class="wp-block-publiclms-quiz__fact">
					<dt><?php echo esc_html( $publiclms_label ); ?></dt>
					<dd><?php echo esc_html( $publiclms_value ); ?></dd>
				</div>
			<?php endforeach; ?>
		</dl>
		<?php endif; ?>
		<?php if ( null !== $publiclms_summary['best_percent'] ) : ?>
			<p class="wp-block-publiclms-quiz__best<?php echo $publiclms_summary['passed'] ? ' is-passed' : ''; ?>">
				<?php
				echo esc_html(
					$publiclms_summary['passed']
						/* translators: %s: best score as a percentage. */
						? sprintf( __( 'Your best score: %s%%. You passed.', 'public-lms' ), number_format_i18n( (float) $publiclms_summary['best_percent'], 0 ) )
						/* translators: %s: best score as a percentage. */
						: sprintf( __( 'Your best score: %s%%. Not passed yet.', 'public-lms' ), number_format_i18n( (float) $publiclms_summary['best_percent'], 0 ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( $publiclms_user <= 0 ) : ?>
			<p><a class="wp-element-button" href="<?php echo esc_url( wp_login_url( (string) get_permalink( $publiclms_quiz ) ) ); ?>"><?php esc_html_e( 'Log in to take this quiz', 'public-lms' ); ?></a></p>
		<?php elseif ( 0 === $publiclms_total ) : ?>
			<p class="wp-block-publiclms-quiz__note"><?php esc_html_e( 'This quiz has no questions yet.', 'public-lms' ); ?></p>
		<?php elseif ( ! $publiclms_can ) : ?>
			<p class="wp-block-publiclms-quiz__note"><?php echo esc_html( (string) $publiclms_summary['can_attempt']['message'] ); ?></p>
		<?php else : ?>
			<button type="button" class="wp-block-publiclms-quiz__start wp-element-button" data-wp-on--click="actions.startQuiz" data-wp-bind--disabled="state.quizIsBusy" data-wp-bind--aria-busy="state.quizIsBusy"><?php echo esc_html( $publiclms_start ); ?></button>
			<noscript><p><?php esc_html_e( 'Quizzes need JavaScript. Turn it on to take this quiz.', 'public-lms' ); ?></p></noscript>
		<?php endif; ?>
	</div>

	<div class="wp-block-publiclms-quiz__results" hidden data-wp-bind--hidden="!state.quizIsDone" data-wp-class--is-passed="state.quizPassed">
		<h2 class="wp-block-publiclms-quiz__results-title" tabindex="-1"><?php esc_html_e( 'Your result', 'public-lms' ); ?></h2>
		<p class="wp-block-publiclms-quiz__score" data-wp-text="state.quizScore"></p>
		<p class="wp-block-publiclms-quiz__verdict" data-wp-text="state.quizVerdict"></p>
		<p class="wp-block-publiclms-quiz__actions">
			<button type="button" class="wp-element-button" hidden data-wp-bind--hidden="!state.quizCanRetake" data-wp-on--click="actions.retakeQuiz"><?php esc_html_e( 'Try again', 'public-lms' ); ?></button>
			<?php if ( null !== $publiclms_next ) : ?>
				<a class="wp-block-publiclms-quiz__continue" href="<?php echo esc_url( $publiclms_next['url'] ); ?>"><?php echo esc_html( $publiclms_next['isCourse'] ? __( 'Back to course', 'public-lms' ) : __( 'Continue', 'public-lms' ) ); ?></a>
			<?php endif; ?>
		</p>
	</div>

	<form class="wp-block-publiclms-quiz__form" hidden data-wp-bind--hidden="state.quizIsIntro" data-wp-on--submit="actions.submitQuiz" novalidate aria-labelledby="<?php echo esc_attr( $publiclms_uid . '-title' ); ?>">
		<h2 id="<?php echo esc_attr( $publiclms_uid . '-title' ); ?>" class="wp-block-publiclms-quiz__form-title" tabindex="-1"><?php esc_html_e( 'Questions', 'public-lms' ); ?></h2>
		<p class="wp-block-publiclms-quiz__timer" role="timer" hidden data-wp-bind--hidden="!state.quizHasTimer" data-wp-text="state.quizTimeText"></p>
		<ol class="wp-block-publiclms-quiz__questions">
			<template data-wp-each--question="state.quizQuestions" data-wp-each-key="context.question.key">
				<li class="wp-block-publiclms-quiz__question" data-wp-class--is-correct="context.question.isCorrect" data-wp-class--is-wrong="context.question.isWrong">
					<fieldset class="wp-block-publiclms-quiz__fieldset">
						<legend class="wp-block-publiclms-quiz__legend" data-wp-text="context.question.label"></legend>
						<div class="wp-block-publiclms-quiz__text" data-wp-watch="callbacks.renderQuestionHtml"></div>

						<ul class="wp-block-publiclms-quiz__options" data-wp-bind--hidden="!context.question.isSingle">
							<template data-wp-each--option="context.question.options" data-wp-each-key="context.option.id">
								<li><label class="wp-block-publiclms-quiz__option"><input type="radio" data-wp-bind--name="context.question.key" data-wp-bind--value="context.option.id" data-wp-bind--checked="state.isOptionChecked" data-wp-bind--disabled="state.questionLocked" data-wp-on--change="actions.answerChoice" /> <span data-wp-text="context.option.text"></span></label></li>
							</template>
						</ul>

						<ul class="wp-block-publiclms-quiz__options" data-wp-bind--hidden="!context.question.isMultiple">
							<template data-wp-each--option="context.question.options" data-wp-each-key="context.option.id">
								<li><label class="wp-block-publiclms-quiz__option"><input type="checkbox" data-wp-bind--value="context.option.id" data-wp-bind--checked="state.isOptionChecked" data-wp-bind--disabled="state.questionLocked" data-wp-on--change="actions.answerChoice" /> <span data-wp-text="context.option.text"></span></label></li>
							</template>
						</ul>

						<ul class="wp-block-publiclms-quiz__options" data-wp-bind--hidden="!context.question.isTrueFalse">
							<li><label class="wp-block-publiclms-quiz__option"><input type="radio" value="true" data-wp-bind--name="context.question.tfKey" data-wp-bind--checked="state.isTrueChecked" data-wp-bind--disabled="state.questionLocked" data-wp-on--change="actions.answerChoice" /> <?php esc_html_e( 'True', 'public-lms' ); ?></label></li>
							<li><label class="wp-block-publiclms-quiz__option"><input type="radio" value="false" data-wp-bind--name="context.question.tfKey" data-wp-bind--checked="state.isFalseChecked" data-wp-bind--disabled="state.questionLocked" data-wp-on--change="actions.answerChoice" /> <?php esc_html_e( 'False', 'public-lms' ); ?></label></li>
						</ul>

						<p class="wp-block-publiclms-quiz__sentence" data-wp-bind--hidden="!context.question.isFillBlank">
							<template data-wp-each--segment="context.question.segments">
								<span><span data-wp-bind--hidden="context.segment.isBlank" data-wp-text="context.segment.text"></span><input type="text" class="wp-block-publiclms-quiz__blank" autocomplete="off" data-wp-bind--hidden="!context.segment.isBlank" data-wp-bind--aria-label="context.segment.label" data-wp-bind--value="state.blankValue" data-wp-bind--disabled="state.questionLocked" data-wp-on--input="actions.answerBlank" /></span>
							</template>
						</p>

						<p data-wp-bind--hidden="!context.question.isShortAnswer">
							<label class="wp-block-publiclms-quiz__short"><span><?php esc_html_e( 'Your answer', 'public-lms' ); ?></span> <input type="text" autocomplete="off" data-wp-bind--value="state.textValue" data-wp-bind--disabled="state.questionLocked" data-wp-on--input="actions.answerText" /></label>
						</p>

						<div class="wp-block-publiclms-quiz__pairs" data-wp-bind--hidden="!context.question.isMatching">
							<template data-wp-each--prompt="context.question.prompts" data-wp-each-key="context.prompt.id">
								<div class="wp-block-publiclms-quiz__pair">
									<label data-wp-bind--for="context.prompt.inputId" data-wp-text="context.prompt.text"></label>
									<select data-wp-bind--id="context.prompt.inputId" data-wp-bind--disabled="state.questionLocked" data-wp-on--change="actions.answerMatch">
										<option value=""><?php esc_html_e( 'Choose…', 'public-lms' ); ?></option>
										<template data-wp-each--match="context.question.matches" data-wp-each-key="context.match.id">
											<option data-wp-bind--value="context.match.id" data-wp-bind--selected="state.isMatchSelected" data-wp-text="context.match.text"></option>
										</template>
									</select>
								</div>
							</template>
						</div>

						<div class="wp-block-publiclms-quiz__feedback" data-wp-bind--hidden="!context.question.hasResult">
							<p class="wp-block-publiclms-quiz__verdict-line"><strong data-wp-text="context.question.resultLabel"></strong> <span data-wp-text="context.question.resultPoints"></span></p>
							<div class="wp-block-publiclms-quiz__explanation" data-wp-watch="callbacks.renderExplanationHtml"></div>
						</div>
					</fieldset>
				</li>
			</template>
		</ol>
		<p class="wp-block-publiclms-quiz__notice" role="status" aria-live="polite" data-wp-text="state.quizNotice"></p>
		<button type="submit" class="wp-block-publiclms-quiz__submit wp-element-button" data-wp-bind--hidden="!state.quizIsRunning" data-wp-bind--disabled="state.quizIsBusy" data-wp-bind--aria-busy="state.quizIsBusy" data-wp-text="state.quizSubmitLabel"><?php esc_html_e( 'Submit answers', 'public-lms' ); ?></button>
	</form>
	<p class="wp-block-publiclms-quiz__message" role="alert" data-wp-text="state.quizMessage"></p>
</div>
