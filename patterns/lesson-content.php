<?php
/**
 * Title: Lesson content
 * Slug: publiclms/lesson-content
 * Categories: publiclms
 * Description: The lesson, shown to learners who can open it, and the mark complete button.
 * Keywords: lesson, content, complete
 * Post Types: plms_lesson
 * Inserter: no
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"article","className":"publiclms-lesson-body","layout":{"type":"constrained"},"style":{"spacing":{"blockGap":"var:preset|spacing|50"}}} -->
<article class="wp-block-group publiclms-lesson-body">
	<!-- wp:publiclms/lesson-content {"layout":{"type":"constrained"}} /-->
	<!-- wp:publiclms/mark-complete /-->
</article>
<!-- /wp:group -->
