<?php
/**
 * Renders publiclms/lesson-navigation.
 *
 * Previous and next links in curriculum order. The next link is bound to
 * the shared store: after "Mark complete" it points to the next lesson the
 * learner has not finished, or back to the course when everything is done,
 * without a page reload.
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
if ( $publiclms_course <= 0 ) {
	return;
}

$publiclms_blocks->seed_state( $publiclms_course, $publiclms_item );
$publiclms_adjacent = $publiclms_blocks->structure->adjacent_items( $publiclms_item );
$publiclms_next     = $publiclms_blocks->next_link( $publiclms_course, $publiclms_item );
$publiclms_titles   = ! empty( $attributes['showTitles'] );
$publiclms_prev_lbl = trim( wp_strip_all_tags( (string) ( $attributes['previousLabel'] ?? '' ) ) );
$publiclms_prev_lbl = '' === $publiclms_prev_lbl ? __( 'Previous', 'public-lms' ) : $publiclms_prev_lbl;
$publiclms_next_lbl = trim( wp_strip_all_tags( (string) ( $attributes['nextLabel'] ?? '' ) ) );
$publiclms_next_lbl = '' === $publiclms_next_lbl ? __( 'Next', 'public-lms' ) : $publiclms_next_lbl;

$publiclms_context = array(
	'courseId' => $publiclms_course,
	'itemId'   => $publiclms_item,
);
?>
<nav <?php echo get_block_wrapper_attributes( array( 'aria-label' => __( 'Lesson navigation', 'public-lms' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="publiclms/learning"
	<?php echo wp_interactivity_data_wp_context( $publiclms_context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
>
	<?php if ( $publiclms_adjacent['previous'] > 0 ) : ?>
		<a class="wp-block-publiclms-lesson-navigation__link is-previous" href="<?php echo esc_url( (string) get_permalink( $publiclms_adjacent['previous'] ) ); ?>" rel="prev">
			<span class="wp-block-publiclms-lesson-navigation__label"><?php echo esc_html( $publiclms_prev_lbl ); ?></span>
			<?php if ( $publiclms_titles ) : ?>
				<span class="wp-block-publiclms-lesson-navigation__title"><?php echo esc_html( get_the_title( $publiclms_adjacent['previous'] ) ); ?></span>
			<?php endif; ?>
		</a>
	<?php else : ?>
		<span class="wp-block-publiclms-lesson-navigation__spacer"></span>
	<?php endif; ?>

	<a
		class="wp-block-publiclms-lesson-navigation__link is-next<?php echo $publiclms_next['isCourse'] ? ' is-course' : ''; ?>"
		href="<?php echo esc_url( $publiclms_next['url'] ); ?>"
		<?php echo $publiclms_next['isCourse'] ? '' : 'rel="next"'; ?>
		data-wp-bind--href="state.nextUrl"
		data-wp-class--is-course="state.nextIsCourse"
		data-wp-class--is-ready="state.isItemCompleted"
	>
		<span class="wp-block-publiclms-lesson-navigation__label"><?php echo esc_html( $publiclms_next_lbl ); ?></span>
		<?php if ( $publiclms_titles ) : ?>
			<span class="wp-block-publiclms-lesson-navigation__title" data-wp-text="state.nextTitle"><?php echo esc_html( $publiclms_next['title'] ); ?></span>
		<?php endif; ?>
	</a>
</nav>
