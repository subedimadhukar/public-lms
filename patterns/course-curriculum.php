<?php
/**
 * Title: Course curriculum
 * Slug: publiclms/course-curriculum
 * Categories: publiclms
 * Description: The course's sections and lessons, with completion and lock states.
 * Keywords: curriculum, syllabus, lessons
 * Post Types: plms_course
 * Inserter: yes
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","className":"publiclms-course-curriculum","layout":{"type":"constrained"},"style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
<section class="wp-block-group publiclms-course-curriculum">
	<!-- wp:heading -->
	<h2 class="wp-block-heading"><?php esc_html_e( 'Curriculum', 'public-lms' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:publiclms/curriculum {"headingLevel":3} /-->
</section>
<!-- /wp:group -->
