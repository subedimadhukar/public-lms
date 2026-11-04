<?php
/**
 * Renders publiclms/enroll-button.
 *
 * Learners who can enrol get a form that posts to admin-post.php with a
 * nonce (works without JavaScript); the Interactivity API turns it into an
 * in-place REST enrolment. Enrolled learners get a "Continue" link, logged
 * out visitors a login link, and paid courses a "Buy" link to the checkout
 * (or, for guests when guest checkout is on, straight to it).
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
if ( $publiclms_course <= 0 ) {
	return;
}

$publiclms_blocks->seed_state( $publiclms_course );
$publiclms_state  = $publiclms_blocks->fields->enroll_state( $publiclms_course );
$publiclms_custom = isset( $attributes['label'] ) ? trim( wp_strip_all_tags( (string) $attributes['label'] ) ) : '';
$publiclms_label  = 'can_enroll' === $publiclms_state['state'] && '' !== $publiclms_custom ? $publiclms_custom : $publiclms_state['label'];
$publiclms_price  = ! empty( $attributes['showPrice'] ) ? $publiclms_blocks->fields->price_label( $publiclms_course ) : '';
$publiclms_wrap   = get_block_wrapper_attributes( array( 'class' => 'is-state-' . sanitize_html_class( $publiclms_state['state'] ) ) );

$publiclms_context = array(
	'courseId' => $publiclms_course,
	'enrolled' => false,
	'isBusy'   => false,
	'message'  => '',
	'url'      => $publiclms_state['url'],
);
?>
<div <?php echo $publiclms_wrap; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="publiclms/learning"
	<?php echo wp_interactivity_data_wp_context( $publiclms_context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
>
	<?php if ( '' !== $publiclms_price ) : ?>
		<p class="wp-block-publiclms-enroll-button__price"><?php echo esc_html( $publiclms_price ); ?></p>
	<?php endif; ?>

	<?php if ( 'can_enroll' === $publiclms_state['state'] ) : ?>
		<form
			class="wp-block-publiclms-enroll-button__form"
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			data-wp-on--submit="actions.enroll"
			data-wp-bind--hidden="context.enrolled"
		>
			<input type="hidden" name="action" value="publiclms_enroll" />
			<input type="hidden" name="course_id" value="<?php echo esc_attr( (string) $publiclms_course ); ?>" />
			<?php wp_nonce_field( 'publiclms_enroll_' . $publiclms_course, '_wpnonce', false ); ?>
			<button
				type="submit"
				class="wp-block-publiclms-enroll-button__button wp-element-button"
				data-wp-bind--aria-disabled="context.isBusy"
				data-wp-bind--aria-busy="context.isBusy"
			><?php echo esc_html( $publiclms_label ); ?></button>
		</form>
		<a
			class="wp-block-publiclms-enroll-button__button wp-element-button"
			href="<?php echo esc_url( $publiclms_state['url'] ); ?>"
			hidden
			data-wp-bind--hidden="!context.enrolled"
			data-wp-bind--href="context.url"
			data-wp-text="state.i18n.startLearning"
		><?php esc_html_e( 'Start learning', 'public-lms' ); ?></a>
		<p class="wp-block-publiclms-enroll-button__message" role="status" aria-live="polite" data-wp-text="context.message"></p>
	<?php elseif ( 'prerequisite' === $publiclms_state['state'] ) : ?>
		<?php $publiclms_decision = $publiclms_blocks->policy->can_enroll( $publiclms_course ); ?>
		<p class="wp-block-publiclms-enroll-button__note"><?php echo esc_html( $publiclms_decision->message() ); ?></p>
		<?php foreach ( $publiclms_decision->requires() as $publiclms_required ) : ?>
			<a class="wp-block-publiclms-enroll-button__button wp-element-button" href="<?php echo esc_url( (string) get_permalink( $publiclms_required ) ); ?>">
				<?php
				/* translators: %s: course title. */
				echo esc_html( sprintf( __( 'Go to %s', 'public-lms' ), wp_strip_all_tags( get_the_title( $publiclms_required ) ) ) );
				?>
			</a>
		<?php endforeach; ?>
	<?php elseif ( in_array( $publiclms_state['state'], array( 'enrolled', 'completed', 'logged_out', 'paid' ), true ) && ( 'paid' !== $publiclms_state['state'] || get_permalink( $publiclms_course ) !== $publiclms_state['url'] ) ) : ?>
		<a class="wp-block-publiclms-enroll-button__button wp-element-button" href="<?php echo esc_url( $publiclms_state['url'] ); ?>"><?php echo esc_html( $publiclms_label ); ?></a>
	<?php else : ?>
		<p class="wp-block-publiclms-enroll-button__note"><?php echo esc_html( $publiclms_label ); ?></p>
	<?php endif; ?>
</div>
