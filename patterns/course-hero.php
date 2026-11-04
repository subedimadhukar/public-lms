<?php
/**
 * Title: Course hero
 * Slug: publiclms/course-hero
 * Categories: publiclms
 * Description: Course title, short description and featured image, with level, instructor and the enrol button.
 * Keywords: course, hero, header
 * Post Types: plms_course
 * Inserter: yes
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","className":"publiclms-course-hero","align":"wide","layout":{"type":"constrained","justifyContent":"left"},"style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
<section class="wp-block-group alignwide publiclms-course-hero">
	<!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-center">
		<!-- wp:column {"verticalAlignment":"center","width":"55%"} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:55%">
			<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"},"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph {"className":"publiclms-badge","fontSize":"sm","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"level"}}}}} -->
				<p class="publiclms-badge has-sm-font-size"></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"fontSize":"sm","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"lesson_count"}}}}} -->
				<p class="has-sm-font-size"></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:post-title {"level":1} /-->
			<!-- wp:post-excerpt {"fontSize":"lg"} /-->
			<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph {"fontSize":"sm"} -->
				<p class="has-sm-font-size"><?php esc_html_e( 'Taught by', 'public-lms' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"className":"publiclms-value","fontSize":"sm","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"instructor"}}}}} -->
				<p class="publiclms-value has-sm-font-size"></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:publiclms/enroll-button {"showPrice":false} /-->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"verticalAlignment":"center","width":"45%"} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:45%">
			<!-- wp:post-featured-image {"aspectRatio":"16/9","style":{"border":{"radius":"var(--wp--custom--radius--lg, 16px)"}}} /-->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</section>
<!-- /wp:group -->
