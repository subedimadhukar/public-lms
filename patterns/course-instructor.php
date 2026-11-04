<?php
/**
 * Title: Course instructor
 * Slug: publiclms/course-instructor
 * Categories: publiclms
 * Description: The instructor's avatar, name and biography.
 * Keywords: instructor, teacher, author
 * Post Types: plms_course
 * Inserter: yes
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"section","className":"publiclms-course-instructor","layout":{"type":"default"},"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
<section class="wp-block-group publiclms-course-instructor">
	<!-- wp:heading -->
	<h2 class="wp-block-heading"><?php esc_html_e( 'Your instructor', 'public-lms' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:post-author {"avatarSize":72,"showBio":true,"isLink":true} /-->
</section>
<!-- /wp:group -->
