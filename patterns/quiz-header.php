<?php
/**
 * Title: Quiz header
 * Slug: publiclms/quiz-header
 * Categories: publiclms
 * Description: Link back to the course, the section name, the quiz title, and the time limit, pass mark and attempts left.
 * Keywords: quiz, header, attempts
 * Post Types: plms_quiz
 * Inserter: no
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"header","className":"publiclms-quiz-header","layout":{"type":"constrained","justifyContent":"left"},"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
<header class="wp-block-group publiclms-quiz-header">
	<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
	<div class="wp-block-group">
		<!-- wp:buttons -->
		<div class="wp-block-buttons">
			<!-- wp:button {"className":"is-style-outline","fontSize":"sm","metadata":{"bindings":{"text":{"source":"publiclms/course-field","args":{"key":"course_title"}},"url":{"source":"publiclms/course-field","args":{"key":"course_url"}}}}} -->
			<div class="wp-block-button has-custom-font-size is-style-outline has-sm-font-size"><a class="wp-block-button__link wp-element-button"><?php esc_html_e( 'Course', 'public-lms' ); ?></a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->
		<!-- wp:paragraph {"fontSize":"sm","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"section_title"}}}}} -->
		<p class="has-sm-font-size"></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
	<!-- wp:post-title {"level":1} /-->
	<!-- wp:group {"className":"publiclms-quiz-facts","layout":{"type":"flex","flexWrap":"wrap"},"style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
	<div class="wp-block-group publiclms-quiz-facts">
		<!-- wp:paragraph {"fontSize":"sm","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"quiz_question_count"}}}}} -->
		<p class="has-sm-font-size">—</p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph {"fontSize":"sm","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"quiz_time_limit"}}}}} -->
		<p class="has-sm-font-size">—</p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph {"fontSize":"sm","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"quiz_pass_mark"}}}}} -->
		<p class="has-sm-font-size">—</p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph {"fontSize":"sm","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"quiz_attempts_left"}}}}} -->
		<p class="has-sm-font-size">—</p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
	<!-- wp:publiclms/course-progress /-->
</header>
<!-- /wp:group -->
