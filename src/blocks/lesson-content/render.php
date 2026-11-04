<?php
/**
 * Renders publiclms/lesson-content.
 *
 * Learners who can open the item get its content: the stored HTML passes
 * through wp_kses_post() before blocks render, so script in lesson HTML
 * never reaches the learner view while embeds (which render from block
 * markup) still work. Everyone else gets a locked notice with the reason and
 * the way in: log in, or enrol on the course page.
 *
 * Lesson files (the lesson's `_plms_attachments`) are listed under the
 * content, only for learners who can open the lesson.
 *
 * @package PublicLMS
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner content.
 * @var WP_Block             $block      Block instance.
 */

use PublicLMS\Blocks\Blocks;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Rest\LessonsController;

defined( 'ABSPATH' ) || exit;

$publiclms_blocks = Blocks::instance();
$publiclms_item   = $publiclms_blocks->item_id( $block );
if ( $publiclms_item <= 0 ) {
	return;
}

// Guard against a lesson rendering itself, as core/post-content does.
if ( isset( Blocks::$rendering[ $publiclms_item ] ) ) {
	return;
}

$publiclms_decision = $publiclms_blocks->policy->can_access_item( $publiclms_item );
$publiclms_course   = $publiclms_blocks->structure->course_id_for( $publiclms_item );

if ( ! $publiclms_decision->is_allowed() ) {
	Blocks::$locked_notice_shown[ $publiclms_item ] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A static class property, not a global.
	$publiclms_custom                               = trim( wp_strip_all_tags( (string) ( $attributes['lockedMessage'] ?? '' ) ) );
	$publiclms_reason                               = $publiclms_decision->reason();
	?>
	<div <?php echo get_block_wrapper_attributes( array( 'class' => 'is-locked' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>
		<div class="publiclms-locked-notice">
			<p class="publiclms-locked-notice__message"><?php echo esc_html( '' !== $publiclms_custom ? $publiclms_custom : $publiclms_decision->message() ); ?></p>
			<?php if ( 'logged_out' === $publiclms_reason ) : ?>
				<p><a class="wp-element-button" href="<?php echo esc_url( wp_login_url( (string) get_permalink( $publiclms_item ) ) ); ?>"><?php esc_html_e( 'Log in', 'public-lms' ); ?></a></p>
			<?php endif; ?>
			<?php if ( $publiclms_course > 0 && in_array( $publiclms_reason, array( 'logged_out', 'not_enrolled', 'expired' ), true ) ) : ?>
				<p><a href="<?php echo esc_url( (string) get_permalink( $publiclms_course ) ); ?>"><?php esc_html_e( 'View the course', 'public-lms' ); ?></a></p>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return;
}

$publiclms_post = get_post( $publiclms_item );
if ( ! $publiclms_post instanceof WP_Post ) {
	return;
}

Blocks::$rendering[ $publiclms_item ] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A static class property, not a global.
$publiclms_html                       = wp_kses_post( $publiclms_post->post_content );
$publiclms_html                       = (string) apply_filters( 'the_content', str_replace( ']]>', ']]&gt;', $publiclms_html ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
unset( Blocks::$rendering[ $publiclms_item ] );
$publiclms_files = ! empty( $attributes['showAttachments'] ) && PostTypes::LESSON === $publiclms_post->post_type ? LessonsController::attachments( $publiclms_item ) : array();
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'entry-content' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>
	<?php echo $publiclms_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Lesson HTML, passed through wp_kses_post() before the_content. ?>
	<?php if ( ! empty( $publiclms_files ) ) : ?>
		<section class="wp-block-publiclms-lesson-content__attachments" aria-labelledby="<?php echo esc_attr( 'publiclms-attachments-' . $publiclms_item ); ?>">
			<h2 id="<?php echo esc_attr( 'publiclms-attachments-' . $publiclms_item ); ?>" class="wp-block-publiclms-lesson-content__attachments-title has-lg-font-size"><?php esc_html_e( 'Lesson files', 'public-lms' ); ?></h2>
			<ul class="wp-block-publiclms-lesson-content__files">
				<?php foreach ( $publiclms_files as $publiclms_file ) : ?>
					<li class="wp-block-publiclms-lesson-content__file">
						<a href="<?php echo esc_url( $publiclms_file['url'] ); ?>" download><?php echo esc_html( '' !== $publiclms_file['title'] ? $publiclms_file['title'] : $publiclms_file['filename'] ); ?></a>
						<?php if ( '' !== $publiclms_file['size'] ) : ?>
							<span class="wp-block-publiclms-lesson-content__file-meta"><?php echo esc_html( strtoupper( (string) pathinfo( $publiclms_file['filename'], PATHINFO_EXTENSION ) ) . ' · ' . $publiclms_file['size'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
</div>
