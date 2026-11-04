<?php
/**
 * Renders publiclms/assignment: the learner's hand-in status and the form.
 *
 * The status (waiting for a grade, or the grade with feedback and the
 * handed-in files, downloaded through the permission-checked REST route) is
 * rendered on the server. The form posts the typed answer and files to
 * publiclms/v1/assignments/{id}/submissions with the REST nonce, then
 * reloads so the new status shows. Limits come from the assignment and are
 * enforced on the server; the form only describes them (ADR-043).
 *
 * @package PublicLMS
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner content.
 * @var WP_Block             $block      Block instance.
 */

use PublicLMS\Access\ContentGate;
use PublicLMS\Assignments\AssignmentSettings;
use PublicLMS\Blocks\Blocks;
use PublicLMS\Files\PrivateFiles;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\SubmissionRepository;
use PublicLMS\Services\AssignmentService;

defined( 'ABSPATH' ) || exit;

$publiclms_blocks     = Blocks::instance();
$publiclms_assignment = isset( $attributes['assignmentId'] ) ? absint( $attributes['assignmentId'] ) : 0;
$publiclms_assignment = $publiclms_assignment > 0 ? $publiclms_assignment : $publiclms_blocks->item_id( $block );
if ( $publiclms_assignment <= 0 || PostTypes::ASSIGNMENT !== get_post_type( $publiclms_assignment ) ) {
	return;
}

$publiclms_access = $publiclms_blocks->policy->can_access_item( $publiclms_assignment );
if ( ! $publiclms_access->is_allowed() ) {
	if ( isset( Blocks::$locked_notice_shown[ $publiclms_assignment ] ) ) {
		return;
	}
	printf(
		'<div %1$s>%2$s</div>',
		get_block_wrapper_attributes( array( 'class' => 'is-locked' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes().
		ContentGate::locked_notice( $publiclms_access ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in locked_notice().
	);
	return;
}

$publiclms_user = get_current_user_id();
if ( $publiclms_user <= 0 ) {
	printf(
		'<div %1$s><p><a class="wp-element-button" href="%2$s">%3$s</a></p></div>',
		get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes().
		esc_url( wp_login_url( (string) get_permalink( $publiclms_assignment ) ) ),
		esc_html__( 'Log in to hand in this assignment', 'public-lms' )
	);
	return;
}

/**
 * The assignment service.
 *
 * @var AssignmentService $publiclms_service
 */
$publiclms_service  = publiclms()->container()->get( AssignmentService::class );
$publiclms_settings = AssignmentSettings::get( $publiclms_assignment );
$publiclms_current  = publiclms()->container()->get( SubmissionRepository::class )->current( $publiclms_user, $publiclms_assignment );
$publiclms_can      = $publiclms_service->can_submit( $publiclms_user, $publiclms_assignment );
$publiclms_course   = $publiclms_blocks->structure->course_id_for( $publiclms_assignment );
if ( $publiclms_course > 0 ) {
	$publiclms_blocks->seed_state( $publiclms_course, $publiclms_assignment );
}
wp_interactivity_state(
	Blocks::STORE,
	array(
		'restUrl' => esc_url_raw( rest_url( 'publiclms/v1/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'i18n'    => array(
			'error'          => __( 'That did not work. Check your connection and try again.', 'public-lms' ),
			'handedIn'       => __( 'Handed in. Updating the page…', 'public-lms' ),
			'assignmentNone' => __( 'Add your answer or a file before handing in.', 'public-lms' ),
		),
	)
);

$publiclms_uid      = wp_unique_id( 'publiclms-assignment-' );
$publiclms_prepared = null === $publiclms_current ? null : $publiclms_service->prepare( $publiclms_current );
$publiclms_status   = '';
if ( null !== $publiclms_prepared ) {
	if ( SubmissionRepository::STATUS_GRADED === $publiclms_prepared['status'] ) {
		$publiclms_status = $publiclms_prepared['passed'] ? 'is-passed' : 'is-failed';
	} else {
		$publiclms_status = 'is-waiting';
	}
}
$publiclms_types = strtoupper( implode( ', ', $publiclms_settings['file_types'] ) );
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="publiclms/learning"
	<?php
	echo wp_interactivity_data_wp_context( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context().
		array(
			'assignmentId' => $publiclms_assignment,
			'isBusy'       => false,
			'message'      => '',
		)
	);
	?>
>
	<?php if ( null !== $publiclms_prepared ) : ?>
		<section class="wp-block-publiclms-assignment__status <?php echo esc_attr( $publiclms_status ); ?>" aria-labelledby="<?php echo esc_attr( $publiclms_uid . '-status' ); ?>">
			<h2 id="<?php echo esc_attr( $publiclms_uid . '-status' ); ?>" class="wp-block-publiclms-assignment__status-title"><?php esc_html_e( 'Your hand-in', 'public-lms' ); ?></h2>
			<?php if ( SubmissionRepository::STATUS_GRADED === $publiclms_prepared['status'] ) : ?>
				<p class="wp-block-publiclms-assignment__grade">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: grade, 2: highest grade. */
							__( '%1$s of %2$s', 'public-lms' ),
							Blocks::number( (float) $publiclms_prepared['grade'] ),
							Blocks::number( (float) $publiclms_prepared['max_grade'] )
						)
					);
					?>
				</p>
				<p><?php echo esc_html( $publiclms_prepared['passed'] ? __( 'Passed', 'public-lms' ) : __( 'Not passed yet', 'public-lms' ) ); ?></p>
				<?php if ( '' !== trim( $publiclms_prepared['feedback'] ) ) : ?>
					<div class="wp-block-publiclms-assignment__feedback">
						<p><strong><?php esc_html_e( 'Feedback', 'public-lms' ); ?></strong></p>
						<?php echo wp_kses_post( wpautop( $publiclms_prepared['feedback'] ) ); ?>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<p class="wp-block-publiclms-assignment__waiting"><?php esc_html_e( 'Waiting for a grade', 'public-lms' ); ?></p>
			<?php endif; ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: date and time. */
						__( 'Handed in %s', 'public-lms' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( (string) $publiclms_current['submitted_at'] . ' UTC' ) )
					)
				);
				?>
			</p>
			<?php if ( ! empty( $publiclms_prepared['files'] ) ) : ?>
				<ul class="wp-block-publiclms-assignment__files">
					<?php foreach ( $publiclms_prepared['files'] as $publiclms_file ) : ?>
						<li><a href="<?php echo esc_url( $publiclms_file['url'] ); ?>"><?php echo esc_html( $publiclms_file['name'] ); ?></a> <span>(<?php echo esc_html( (string) size_format( (int) $publiclms_file['size'] ) ); ?>)</span></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( '' !== trim( $publiclms_prepared['content'] ) ) : ?>
				<details class="wp-block-publiclms-assignment__answer">
					<summary><?php esc_html_e( 'Your answer', 'public-lms' ); ?></summary>
					<?php echo wp_kses_post( wpautop( $publiclms_prepared['content'] ) ); ?>
				</details>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( $publiclms_can['allowed'] ) : ?>
		<form class="wp-block-publiclms-assignment__form" method="post" enctype="multipart/form-data" data-wp-on--submit="actions.submitAssignment" aria-labelledby="<?php echo esc_attr( $publiclms_uid . '-form' ); ?>">
			<h2 id="<?php echo esc_attr( $publiclms_uid . '-form' ); ?>" class="wp-block-publiclms-assignment__status-title">
				<?php echo esc_html( null === $publiclms_prepared ? __( 'Hand in your work', 'public-lms' ) : __( 'Hand in again', 'public-lms' ) ); ?>
			</h2>
			<?php if ( null !== $publiclms_prepared && SubmissionRepository::STATUS_SUBMITTED === $publiclms_prepared['status'] ) : ?>
				<p class="wp-block-publiclms-assignment__hint"><?php esc_html_e( 'Handing in again replaces the work waiting for a grade.', 'public-lms' ); ?></p>
			<?php endif; ?>
			<?php if ( $publiclms_settings['allow_text'] ) : ?>
				<label class="wp-block-publiclms-assignment__field">
					<?php esc_html_e( 'Your answer', 'public-lms' ); ?>
					<textarea name="content" rows="8"></textarea>
				</label>
			<?php endif; ?>
			<?php if ( $publiclms_settings['max_files'] > 0 ) : ?>
				<label class="wp-block-publiclms-assignment__field">
					<?php echo esc_html( _n( 'File', 'Files', $publiclms_settings['max_files'], 'public-lms' ) ); ?>
					<input type="file" name="files[]" <?php echo $publiclms_settings['max_files'] > 1 ? 'multiple' : ''; ?> accept="<?php echo esc_attr( PrivateFiles::accept( $publiclms_settings['file_types'] ) ); ?>" aria-describedby="<?php echo esc_attr( $publiclms_uid . '-hint' ); ?>" />
				</label>
				<p id="<?php echo esc_attr( $publiclms_uid . '-hint' ); ?>" class="wp-block-publiclms-assignment__hint">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of files, 2: size such as 10 MB, 3: list of file types. */
							_n( 'Up to %1$d file of %2$s. Accepted: %3$s.', 'Up to %1$d files of %2$s each. Accepted: %3$s.', $publiclms_settings['max_files'], 'public-lms' ),
							$publiclms_settings['max_files'],
							(string) size_format( AssignmentSettings::max_bytes( $publiclms_assignment ) ),
							$publiclms_types
						)
					);
					?>
				</p>
			<?php endif; ?>
			<p>
				<button type="submit" class="wp-element-button" data-wp-bind--disabled="context.isBusy" data-wp-bind--aria-busy="context.isBusy"><?php esc_html_e( 'Hand in', 'public-lms' ); ?></button>
			</p>
			<p class="wp-block-publiclms-assignment__message" role="status" aria-live="polite" data-wp-text="context.message"></p>
			<noscript><p><?php esc_html_e( 'Handing in needs JavaScript. Turn it on to upload your work.', 'public-lms' ); ?></p></noscript>
		</form>
	<?php elseif ( null === $publiclms_prepared ) : ?>
		<p class="wp-block-publiclms-assignment__hint"><?php echo esc_html( $publiclms_can['message'] ); ?></p>
	<?php endif; ?>
</div>
