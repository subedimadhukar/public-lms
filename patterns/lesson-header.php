<?php
/**
 * Title: Lesson header
 * Slug: publiclms/lesson-header
 * Categories: publiclms
 * Description: Link back to the course, the section name, the lesson title and the learner's progress.
 * Keywords: lesson, header, progress
 * Post Types: plms_lesson
 * Inserter: no
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"header","className":"publiclms-lesson-header","layout":{"type":"constrained","justifyContent":"left"},"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
<header class="wp-block-group publiclms-lesson-header">
	<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
	<div class="wp-block-group">
		<!-- wp:buttons -->
		<div class="wp-block-buttons">
			<!-- wp:button {"className":"is-style-outline","fontSize":"small","metadata":{"bindings":{"text":{"source":"publiclms/course-field","args":{"key":"course_title"}},"url":{"source":"publiclms/course-field","args":{"key":"course_url"}}}}} -->
			<div class="wp-block-button has-custom-font-size is-style-outline has-small-font-size"><a class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Course', 'public-lms' ); ?></a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->
		<!-- wp:paragraph {"fontSize":"small","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"section_title"}}}}} -->
		<p class="has-small-font-size"></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
	<!-- wp:post-title {"level":1} /-->
	<!-- wp:publiclms/course-progress /-->
</header>
<!-- /wp:group -->
