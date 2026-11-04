<?php
/**
 * Renders publiclms/course-progress for an enrolled learner.
 *
 * A progressbar whose value, text and fill width are bound to the shared
 * store, so it moves as soon as a lesson is marked complete. Visitors who
 * are not enrolled see nothing. The bar, ring and text looks are block style
 * variations (is-style-bar, is-style-ring, is-style-text), so a theme can
 * restyle each one in theme.json.
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
$publiclms_course = $publiclms_blocks->course_id( $attributes, $block );
if ( $publiclms_course <= 0 || ! is_user_logged_in() || ! $publiclms_blocks->enrollments->is_enrolled( get_current_user_id(), $publiclms_course ) ) {
	return;
}

$publiclms_progress = $publiclms_blocks->seed_state( $publiclms_course, $publiclms_blocks->item_id( $block ) );
$publiclms_percent  = (int) $publiclms_progress['percent'];
$publiclms_style    = 'bar';
if ( preg_match( '/\bis-style-(ring|text)\b/', (string) ( $attributes['className'] ?? '' ), $publiclms_match ) ) {
	$publiclms_style = $publiclms_match[1];
}
$publiclms_label = Blocks::percent_label( $publiclms_percent );
$publiclms_wrap  = get_block_wrapper_attributes(
	array(
		'style' => '--publiclms-progress:' . $publiclms_percent . '%;',
	)
);
?>
<div <?php echo $publiclms_wrap; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="publiclms/learning"
	<?php echo wp_interactivity_data_wp_context( array( 'courseId' => $publiclms_course ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
	data-wp-style----publiclms-progress="state.percentWidth"
>
	<div
		class="wp-block-publiclms-course-progress__track"
		role="progressbar"
		aria-label="<?php esc_attr_e( 'Course progress', 'public-lms' ); ?>"
		aria-valuemin="0"
		aria-valuemax="100"
		aria-valuenow="<?php echo esc_attr( (string) $publiclms_percent ); ?>"
		aria-valuetext="<?php echo esc_attr( $publiclms_label ); ?>"
		data-wp-bind--aria-valuenow="state.percent"
		data-wp-bind--aria-valuetext="state.percentLabel"
	>
		<?php if ( 'text' !== $publiclms_style ) : ?>
			<span class="wp-block-publiclms-course-progress__fill"></span>
		<?php endif; ?>
	</div>
	<p class="wp-block-publiclms-course-progress__label">
		<span data-wp-text="state.percentLabel"><?php echo esc_html( $publiclms_label ); ?></span>
		<span class="wp-block-publiclms-course-progress__count" data-wp-text="state.progressSummary"><?php echo esc_html( $publiclms_progress['done'] . ' / ' . $publiclms_progress['total'] ); ?></span>
	</p>
</div>
