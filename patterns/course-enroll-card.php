<?php
/**
 * Title: Course enrol card
 * Slug: publiclms/course-enroll-card
 * Categories: publiclms
 * Description: Price, enrol button and what the course includes, sticky on wide screens.
 * Keywords: enrol, enroll, price, card
 * Post Types: plms_course
 * Inserter: yes
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"publiclms-course-enroll-card","style":{"position":{"type":"sticky","top":"0px"}},"layout":{"type":"default"}} -->
<div class="wp-block-group publiclms-course-enroll-card">
	<!-- wp:publiclms/enroll-button {"showPrice":true} /-->
	<!-- wp:publiclms/course-progress /-->
	<!-- wp:heading {"level":3,"fontSize":"lg"} -->
	<h3 class="wp-block-heading has-lg-font-size"><?php esc_html_e( 'This course includes', 'public-lms' ); ?></h3>
	<!-- /wp:heading -->
	<!-- wp:publiclms/course-meta {"fields":["lessons","duration","level","learners"]} /-->
</div>
<!-- /wp:group -->
