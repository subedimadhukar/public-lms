<?php
/**
 * Renders publiclms/mark-complete, the lesson player's completion control.
 *
 * Shown to enrolled learners who can open the item. The Interactivity API
 * posts to publiclms/v1/progress with the REST nonce from state and
 * announces the result in a polite live region. The visible label is the
 * accessible name; once the item is complete it reads "Completed", and a
 * description explains that selecting it again undoes the completion.
 *
 * @package PublicLMS
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner content.
 * @var WP_Block             $block      Block instance.
 */

use PublicLMS\Blocks\Blocks;

defined( 'ABSPATH' ) || exit;

$publiclms_blocks = Blocks::instance();
$publiclms_item   = $publiclms_blocks->item_id( $block );
$publiclms_course = $publiclms_item > 0 ? $publiclms_blocks->structure->course_id_for( $publiclms_item ) : 0;
if ( $publiclms_course <= 0 || ! is_user_logged_in() ) {
	return;
}
if ( ! $publiclms_blocks->enrollments->is_enrolled( get_current_user_id(), $publiclms_course ) || ! $publiclms_blocks->policy->can_access_item( $publiclms_item )->is_allowed() ) {
	return;
}

$publiclms_progress  = $publiclms_blocks->seed_state( $publiclms_course, $publiclms_item );
$publiclms_completed = isset( ( (array) $publiclms_progress['items'] )[ $publiclms_item ] );
$publiclms_label     = trim( wp_strip_all_tags( (string) ( $attributes['label'] ?? '' ) ) );
$publiclms_label     = '' === $publiclms_label ? __( 'Mark as complete', 'public-lms' ) : $publiclms_label;
$publiclms_done      = trim( wp_strip_all_tags( (string) ( $attributes['completedLabel'] ?? '' ) ) );
$publiclms_done      = '' === $publiclms_done ? __( 'Completed', 'public-lms' ) : $publiclms_done;

$publiclms_context = array(
	'courseId'       => $publiclms_course,
	'itemId'         => $publiclms_item,
	'label'          => $publiclms_label,
	'completedLabel' => $publiclms_done,
	'isBusy'         => false,
	'message'        => '',
);
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="publiclms/learning"
	<?php echo wp_interactivity_data_wp_context( $publiclms_context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
	data-wp-class--is-completed="state.isItemCompleted"
>
	<button
		type="button"
		class="wp-block-publiclms-mark-complete__button wp-element-button"
		<?php echo $publiclms_completed ? 'aria-describedby="' . esc_attr( 'publiclms-undo-' . $publiclms_item ) . '"' : ''; ?>
		data-wp-bind--aria-describedby="state.undoHintId"
		data-wp-bind--disabled="context.isBusy"
		data-wp-bind--aria-busy="context.isBusy"
		data-wp-on--click="actions.toggleComplete"
	>
		<span class="wp-block-publiclms-mark-complete__icon" aria-hidden="true"></span>
		<span data-wp-text="state.completeButtonText"><?php echo esc_html( $publiclms_completed ? $publiclms_done : $publiclms_label ); ?></span>
	</button>
	<span id="<?php echo esc_attr( 'publiclms-undo-' . $publiclms_item ); ?>" class="publiclms-visually-hidden"><?php esc_html_e( 'Select again to mark this lesson as not complete.', 'public-lms' ); ?></span>
	<p class="wp-block-publiclms-mark-complete__status" role="status" aria-live="polite" data-wp-text="context.message"></p>
</div>
