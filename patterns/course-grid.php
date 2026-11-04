<?php
/**
 * Title: Course grid
 * Slug: publiclms/course-grid
 * Categories: publiclms
 * Description: A grid of course cards: image, level, title, short description and price, with pagination.
 * Keywords: courses, grid, catalogue, query
 * Inserter: yes
 *
 * @package PublicLMS
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:query {"queryId":0,"query":{"perPage":9,"pages":0,"offset":0,"postType":"plms_course","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":true,"publiclmsHideEnrolled":false},"namespace":"publiclms/course-query","align":"wide","layout":{"type":"constrained"}} -->
<div class="wp-block-query alignwide">
	<!-- wp:post-template {"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"16rem"}} -->
		<!-- wp:group {"className":"publiclms-course-card","style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"constrained"}} -->
		<div class="wp-block-group publiclms-course-card">
			<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"16/9","sizeSlug":"medium_large","style":{"border":{"radius":"var(--wp--custom--radius--md, 8px)"}}} /-->
			<!-- wp:paragraph {"fontSize":"small","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"level"}}}}} -->
			<p class="has-small-font-size"></p>
			<!-- /wp:paragraph -->
			<!-- wp:post-title {"isLink":true,"level":3,"fontSize":"large"} /-->
			<!-- wp:post-excerpt {"excerptLength":22} /-->
			<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph {"className":"publiclms-price","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"price"}}}}} -->
				<p class="publiclms-price"></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"fontSize":"small","metadata":{"bindings":{"content":{"source":"publiclms/course-field","args":{"key":"lesson_count"}}}}} -->
				<p class="has-small-font-size"></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
	<!-- /wp:post-template -->
	<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"center"}} -->
		<!-- wp:query-pagination-previous /-->
		<!-- wp:query-pagination-numbers /-->
		<!-- wp:query-pagination-next /-->
	<!-- /wp:query-pagination -->
	<!-- wp:query-no-results -->
		<!-- wp:paragraph -->
		<p><?php esc_html_e( 'No courses here yet. Check back soon.', 'public-lms' ); ?></p>
		<!-- /wp:paragraph -->
	<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
