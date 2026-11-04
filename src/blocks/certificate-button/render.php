<?php
/**
 * Renders publiclms/certificate-button: a download link for learners who
 * completed the course and earned a certificate; nothing for anyone else.
 *
 * @package PublicLMS
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner content.
 * @var WP_Block             $block      Block instance.
 */

use PublicLMS\Blocks\Blocks;
use PublicLMS\Certificates\CertificateService;

defined( 'ABSPATH' ) || exit;

$publiclms_blocks = Blocks::instance();
$publiclms_course = $publiclms_blocks->course_id( $attributes, $block );
if ( $publiclms_course <= 0 || ! is_user_logged_in() || ! $publiclms_blocks->policy->can_view_certificate( $publiclms_course )->is_allowed() ) {
	return;
}
$publiclms_label = trim( wp_strip_all_tags( (string) ( $attributes['label'] ?? '' ) ) );
$publiclms_label = '' === $publiclms_label ? __( 'Download your certificate', 'public-lms' ) : $publiclms_label;
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>
	<a class="wp-element-button" href="<?php echo esc_url( CertificateService::download_url( $publiclms_course ) ); ?>"><?php echo esc_html( $publiclms_label ); ?></a>
</div>
