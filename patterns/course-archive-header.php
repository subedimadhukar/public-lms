<?php
/**
 * Title: Course archive header
 * Slug: publiclms/course-archive-header
 * Categories: publiclms
 * Description: The catalogue or category title and its description.
 * Keywords: archive, courses, header
 * Inserter: no
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"tagName":"header","className":"publiclms-course-archive-header","layout":{"type":"constrained"}} -->
<header class="wp-block-group publiclms-course-archive-header">
	<!-- wp:query-title {"type":"archive","showPrefix":false} /-->
	<!-- wp:term-description /-->
</header>
<!-- /wp:group -->
