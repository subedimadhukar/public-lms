<?php
/**
 * Renders publiclms/curriculum.
 *
 * Sections are native <details> elements when collapsible, so the accordion
 * is keyboard operable without JavaScript. Completion ticks and lock states
 * are bound to the shared store, so marking a lesson complete in the player
 * updates the sidebar curriculum at once.
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
if ( $publiclms_course <= 0 || ! $publiclms_blocks->policy->can_view_course( $publiclms_course ) ) {
	return;
}

$publiclms_current  = $publiclms_blocks->item_id( $block );
$publiclms_progress = $publiclms_blocks->seed_state( $publiclms_course, $publiclms_current );
$publiclms_done     = (array) $publiclms_progress['items'];
$publiclms_sections = $publiclms_blocks->structure->get_public_structure( $publiclms_course )['sections'];
$publiclms_level    = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 3 ) ) );
$publiclms_collapse = ! empty( $attributes['collapsible'] );
$publiclms_locks    = ! empty( $attributes['showLockedState'] );

if ( empty( $publiclms_sections ) ) {
	printf(
		'<div %1$s><p class="wp-block-publiclms-curriculum__empty">%2$s</p></div>',
		get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes().
		esc_html__( 'The curriculum for this course is being prepared.', 'public-lms' )
	);
	return;
}
?>
<nav <?php echo get_block_wrapper_attributes( array( 'aria-label' => __( 'Course curriculum', 'public-lms' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="publiclms/learning"
	<?php echo wp_interactivity_data_wp_context( array( 'courseId' => $publiclms_course ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
>
	<ol class="wp-block-publiclms-curriculum__sections">
		<?php
		foreach ( $publiclms_sections as $publiclms_index => $publiclms_section ) :
			$publiclms_items    = (array) $publiclms_section['items'];
			$publiclms_has_open = $publiclms_current > 0 && in_array( $publiclms_current, array_map( 'intval', wp_list_pluck( $publiclms_items, 'id' ) ), true );
			$publiclms_title    = wp_strip_all_tags( get_the_title( (int) $publiclms_section['id'] ) );
			$publiclms_count    = sprintf(
				/* translators: %d: number of lessons in the section. */
				_n( '%d lesson', '%d lessons', count( $publiclms_items ), 'public-lms' ),
				count( $publiclms_items )
			);
			?>
			<li class="wp-block-publiclms-curriculum__section">
				<?php if ( $publiclms_collapse ) : ?>
					<details class="wp-block-publiclms-curriculum__details"<?php echo ( 0 === $publiclms_index && 0 === $publiclms_current ) || $publiclms_has_open ? ' open' : ''; ?>>
					<summary class="wp-block-publiclms-curriculum__summary">
						<span class="wp-block-publiclms-curriculum__section-title" role="heading" aria-level="<?php echo esc_attr( (string) $publiclms_level ); ?>"><?php echo esc_html( $publiclms_title ); ?></span>
						<span class="wp-block-publiclms-curriculum__count"><?php echo esc_html( $publiclms_count ); ?></span>
					</summary>
				<?php else : ?>
					<?php printf( '<h%1$d class="wp-block-publiclms-curriculum__section-title">%2$s</h%1$d>', (int) $publiclms_level, esc_html( $publiclms_title ) ); ?>
				<?php endif; ?>
				<ol class="wp-block-publiclms-curriculum__items">
					<?php
					foreach ( $publiclms_items as $publiclms_item ) :
						$publiclms_item_id = (int) $publiclms_item['id'];
						$publiclms_access  = $publiclms_blocks->policy->can_access_item( $publiclms_item_id );
						$publiclms_locked  = $publiclms_locks && ! $publiclms_access->is_allowed();
						// Locks that enrolling removes; drip and prerequisites stay locked (phase 2).
						$publiclms_soft    = in_array( $publiclms_access->reason(), array( 'logged_out', 'not_enrolled' ), true );
						$publiclms_is_done = isset( $publiclms_done[ $publiclms_item_id ] );
						$publiclms_classes = array( 'wp-block-publiclms-curriculum__item' );
						if ( $publiclms_is_done ) {
							$publiclms_classes[] = 'is-completed';
						}
						if ( $publiclms_locked ) {
							$publiclms_classes[] = 'is-locked';
						}
						if ( $publiclms_item_id === $publiclms_current ) {
							$publiclms_classes[] = 'is-current';
						}
						$publiclms_item_context = array(
							'itemId' => $publiclms_item_id,
							'locked' => $publiclms_locked && $publiclms_soft,
						);
						?>
						<li class="<?php echo esc_attr( implode( ' ', $publiclms_classes ) ); ?>"
							<?php echo wp_interactivity_data_wp_context( $publiclms_item_context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
							data-wp-class--is-completed="state.isItemCompleted"
							<?php echo $publiclms_locked && $publiclms_soft ? 'data-wp-class--is-locked="state.isItemLocked"' : ''; ?>
						>
							<span class="wp-block-publiclms-curriculum__status" aria-hidden="true"></span>
							<?php if ( $publiclms_locked && ! $publiclms_soft ) : ?>
								<span class="wp-block-publiclms-curriculum__title"><?php echo esc_html( get_the_title( $publiclms_item_id ) ); ?></span>
							<?php else : ?>
								<a class="wp-block-publiclms-curriculum__title" href="<?php echo esc_url( (string) get_permalink( $publiclms_item_id ) ); ?>"<?php echo $publiclms_item_id === $publiclms_current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( get_the_title( $publiclms_item_id ) ); ?></a>
							<?php endif; ?>
							<span class="publiclms-visually-hidden" data-wp-bind--hidden="!state.isItemCompleted"<?php echo $publiclms_is_done ? '' : ' hidden'; ?>><?php esc_html_e( '(completed)', 'public-lms' ); ?></span>
							<?php if ( $publiclms_locked ) : ?>
								<span class="publiclms-visually-hidden"<?php echo $publiclms_soft ? ' data-wp-bind--hidden="!state.isItemLocked"' : ''; ?>><?php esc_html_e( '(locked)', 'public-lms' ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $publiclms_item['preview'] ) && $publiclms_locks && ! $publiclms_blocks->enrollments->is_enrolled( get_current_user_id(), $publiclms_course ) ) : ?>
								<span class="wp-block-publiclms-curriculum__badge"><?php esc_html_e( 'Preview', 'public-lms' ); ?></span>
							<?php endif; ?>
							<?php if ( (int) $publiclms_item['duration'] > 0 ) : ?>
								<span class="wp-block-publiclms-curriculum__duration"><?php echo esc_html( $publiclms_blocks->fields->format_duration( (int) $publiclms_item['duration'] ) ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
				<?php if ( $publiclms_collapse ) : ?>
					</details>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</nav>
