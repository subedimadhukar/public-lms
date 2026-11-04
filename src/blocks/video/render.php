<?php
/**
 * Renders publiclms/video.
 *
 * YouTube, Vimeo and Bunny Stream play in their own iframes (privacy-friendly
 * embed URLs, lazy-loaded); self-hosted files play in a native <video> with
 * an optional captions track. No provider script is loaded: on a lesson, the
 * shared store reads the players' postMessage events (or the <video>
 * element's), counts the seconds actually watched, and marks the lesson
 * complete once the learner has watched `requiredPercent` of it (ADR-041).
 * Skipping ahead does not count as watching.
 *
 * @package PublicLMS
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner content.
 * @var WP_Block             $block      Block instance.
 */

use PublicLMS\Blocks\Blocks;
use PublicLMS\Blocks\VideoSource;

defined( 'ABSPATH' ) || exit;

$publiclms_source = VideoSource::parse( (string) ( $attributes['provider'] ?? '' ), (string) ( $attributes['src'] ?? '' ) );
if ( null === $publiclms_source && ! empty( $attributes['mediaId'] ) ) {
	$publiclms_source = VideoSource::parse( 'self', (string) wp_get_attachment_url( (int) $attributes['mediaId'] ) );
}
if ( null === $publiclms_source ) {
	return;
}

$publiclms_blocks   = Blocks::instance();
$publiclms_item     = $publiclms_blocks->item_id( $block );
$publiclms_course   = $publiclms_item > 0 ? $publiclms_blocks->structure->course_id_for( $publiclms_item ) : 0;
$publiclms_user     = get_current_user_id();
$publiclms_track    = ! empty( $attributes['trackCompletion'] )
	&& $publiclms_course > 0
	&& $publiclms_user > 0
	&& $publiclms_blocks->enrollments->is_enrolled( $publiclms_user, $publiclms_course );
$publiclms_title    = trim( wp_strip_all_tags( (string) ( $attributes['title'] ?? '' ) ) );
$publiclms_title    = '' === $publiclms_title ? __( 'Lesson video', 'public-lms' ) : $publiclms_title;
$publiclms_caption  = trim( (string) ( $attributes['caption'] ?? '' ) );
$publiclms_required = max( 10, min( 100, (int) ( $attributes['requiredPercent'] ?? 90 ) ) );
$publiclms_done     = false;

if ( $publiclms_track ) {
	$publiclms_progress = $publiclms_blocks->seed_state( $publiclms_course, $publiclms_item );
	$publiclms_done     = isset( ( (array) $publiclms_progress['items'] )[ $publiclms_item ] );
}

$publiclms_context = array(
	'courseId' => $publiclms_course,
	'itemId'   => $publiclms_item,
	'provider' => $publiclms_source['provider'],
	'origin'   => $publiclms_source['origin'],
	'track'    => $publiclms_track && ! $publiclms_done,
	'required' => $publiclms_required,
	'watched'  => 0,
	'message'  => '',
);

$publiclms_wrapper = get_block_wrapper_attributes( array( 'class' => 'is-provider-' . $publiclms_source['provider'] ) );
?>
<figure <?php echo $publiclms_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>
	data-wp-interactive="publiclms/learning"
	<?php echo wp_interactivity_data_wp_context( $publiclms_context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_interactivity_data_wp_context(). ?>
	data-wp-init="callbacks.initVideo"
>
	<div class="wp-block-publiclms-video__frame">
		<?php if ( 'self' === $publiclms_source['provider'] ) : ?>
			<video controls playsinline preload="metadata" src="<?php echo esc_url( $publiclms_source['url'] ); ?>" aria-label="<?php echo esc_attr( $publiclms_title ); ?>">
				<?php if ( '' !== (string) ( $attributes['captions'] ?? '' ) ) : ?>
					<track kind="captions" src="<?php echo esc_url( (string) $attributes['captions'] ); ?>" srclang="<?php echo esc_attr( substr( get_locale(), 0, 2 ) ); ?>" label="<?php esc_attr_e( 'Captions', 'public-lms' ); ?>" default />
				<?php endif; ?>
				<a href="<?php echo esc_url( $publiclms_source['url'] ); ?>"><?php esc_html_e( 'Download the video', 'public-lms' ); ?></a>
			</video>
		<?php else : ?>
			<iframe
				src="<?php echo esc_url( $publiclms_source['url'] ); ?>"
				title="<?php echo esc_attr( $publiclms_title ); ?>"
				loading="lazy"
				allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen"
				allowfullscreen
				referrerpolicy="strict-origin-when-cross-origin"
			></iframe>
		<?php endif; ?>
	</div>
	<?php if ( '' !== $publiclms_caption ) : ?>
		<figcaption class="wp-block-publiclms-video__caption"><?php echo wp_kses_post( $publiclms_caption ); ?></figcaption>
	<?php endif; ?>
	<?php if ( $publiclms_track ) : ?>
		<p class="wp-block-publiclms-video__status" role="status" aria-live="polite" data-wp-text="context.message"></p>
	<?php endif; ?>
</figure>
