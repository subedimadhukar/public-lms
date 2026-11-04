<?php
/**
 * Title: Lesson sidebar
 * Slug: publiclms/lesson-sidebar
 * Categories: publiclms
 * Description: The course curriculum beside a lesson, with the current lesson highlighted.
 * Keywords: lesson, sidebar, curriculum
 * Post Types: plms_lesson
 * Inserter: no
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"aside","className":"publiclms-lesson-sidebar","layout":{"type":"constrained"},"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
<aside class="wp-block-group publiclms-lesson-sidebar">
	<!-- wp:heading {"level":2,"fontSize":"medium"} -->
	<h2 class="wp-block-heading has-medium-font-size"><?php esc_html_e( 'Course content', 'public-lms' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:publiclms/curriculum {"headingLevel":3,"fontSize":"small"} /-->
</aside>
<!-- /wp:group -->
