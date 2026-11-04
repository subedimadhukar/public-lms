<?php
/**
 * Title: Course details
 * Slug: publiclms/course-details
 * Categories: publiclms
 * Description: Duration, lessons, level and last update of the course, from the course-field bindings source.
 * Keywords: course, details, duration
 * Post Types: plms_course
 * Inserter: yes
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"publiclms-course-details","layout":{"type":"grid","minimumColumnWidth":"8rem"},"style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
<div class="wp-block-group publiclms-course-details">
	<!-- wp:group {"layout":{"type":"constrained"},"style":{"spacing":{"blockGap":"0"}}} -->
	<div class="wp-block-group">
		<!-- wp:paragraph {"fontSize":"small"} -->
		<p class="has-small-font-size"><?php esc_html_e( 'Duration', 'public-lms' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph {"className":"publiclms-value","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"duration"}}}}} -->
		<p class="publiclms-value">—</p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
	<!-- wp:group {"layout":{"type":"constrained"},"style":{"spacing":{"blockGap":"0"}}} -->
	<div class="wp-block-group">
		<!-- wp:paragraph {"fontSize":"small"} -->
		<p class="has-small-font-size"><?php esc_html_e( 'Lessons', 'public-lms' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph {"className":"publiclms-value","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"lesson_count"}}}}} -->
		<p class="publiclms-value">—</p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
	<!-- wp:group {"layout":{"type":"constrained"},"style":{"spacing":{"blockGap":"0"}}} -->
	<div class="wp-block-group">
		<!-- wp:paragraph {"fontSize":"small"} -->
		<p class="has-small-font-size"><?php esc_html_e( 'Level', 'public-lms' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph {"className":"publiclms-value","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"level"}}}}} -->
		<p class="publiclms-value">—</p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
	<!-- wp:group {"layout":{"type":"constrained"},"style":{"spacing":{"blockGap":"0"}}} -->
	<div class="wp-block-group">
		<!-- wp:paragraph {"fontSize":"small"} -->
		<p class="has-small-font-size"><?php esc_html_e( 'Last updated', 'public-lms' ); ?></p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph {"className":"publiclms-value","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"updated"}}}}} -->
		<p class="publiclms-value">—</p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
