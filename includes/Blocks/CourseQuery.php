<?php
/**
 * Server side of the publiclms/course-query Query Loop variation.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Blocks;

use PublicLMS\Courses\CourseFields;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Taxonomies\Taxonomies;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * The variation itself is registered in JS (src/editor/course-query.js) as a
 * core/query variation with `namespace: "publiclms/course-query"` and
 * `postType: "plms_course"` (CONTRACTS.md A5.5). Course cards are core Post
 * Template, Post Title, Post Featured Image and Post Excerpt blocks with
 * values from the bindings source, so the variation needs no wrapper block.
 *
 * This class adds two settings core lacks. `query.publiclmsHideEnrolled`
 * hides courses the current learner is already enrolled in.
 * `query.publiclmsRelated` (contract 1.2.0) shows courses related to the
 * course on screen (or the course of the lesson on screen): it leaves that
 * course out, lists courses sharing its categories first, newest first, and
 * fills the rest of the page with the newest other courses. Level and
 * category filters use core's taxonomy controls.
 */
final class CourseQuery {

	/**
	 * Variation namespace.
	 */
	const NAMESPACE_NAME = 'publiclms/course-query';

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	private $enrollments;

	/**
	 * Course fields, to find the course a page shows.
	 *
	 * @var CourseFields
	 */
	private $fields;

	/**
	 * Constructor.
	 *
	 * @param EnrollmentRepository $enrollments Enrolments.
	 * @param CourseFields         $fields      Course fields.
	 */
	public function __construct( EnrollmentRepository $enrollments, CourseFields $fields ) {
		$this->enrollments = $enrollments;
		$this->fields      = $fields;
	}

	/**
	 * Hooks the query filters.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'query_loop_block_query_vars', array( $this, 'query_vars' ), 10, 2 );
		add_filter( 'rest_' . PostTypes::COURSE . '_query', array( $this, 'rest_query' ), 10, 2 );
	}

	/**
	 * Applies the variation's extra settings on the front end.
	 *
	 * @param array<string, mixed> $query Query vars.
	 * @param WP_Block             $block Post Template block, with the query in context.
	 * @return array<string, mixed>
	 */
	public function query_vars( $query, $block ): array {
		$query = (array) $query;
		if ( ! $block instanceof WP_Block ) {
			return $query;
		}
		$settings = isset( $block->context['query'] ) && is_array( $block->context['query'] ) ? $block->context['query'] : array();
		// Core passes only the Query block's `query` attribute as context; `namespace` is a separate
		// attribute (A5.5), so the variation is recognised by its own keys (finding 11).
		$ours = self::NAMESPACE_NAME === ( $settings['namespace'] ?? '' ) || ! empty( $settings['publiclmsRelated'] ) || ! empty( $settings['publiclmsHideEnrolled'] );
		if ( ! $ours ) {
			return $query;
		}
		$query['post_type'] = PostTypes::COURSE;
		if ( ! empty( $settings['publiclmsHideEnrolled'] ) ) {
			$query = $this->exclude_enrolled( $query );
		}
		if ( ! empty( $settings['publiclmsRelated'] ) ) {
			$query = $this->related( $query, $this->fields->resolve_course_id( (int) get_queried_object_id() ) );
		}
		return $query;
	}

	/**
	 * Narrows a course query to courses related to one course.
	 *
	 * Courses sharing a category come first, then the newest others, so a
	 * "related courses" row is always full when the catalogue allows it. The
	 * result is fixed with post__in and orderby post__in, which keeps
	 * pagination and the hide-enrolled setting working.
	 *
	 * @param array<string, mixed> $query     Query vars.
	 * @param int                  $course_id The course to relate to; 0 when the page shows none.
	 * @return array<string, mixed>
	 */
	public function related( array $query, int $course_id ): array {
		if ( $course_id <= 0 ) {
			return $query;
		}
		$per_page = (int) ( $query['posts_per_page'] ?? get_option( 'posts_per_page' ) );
		$per_page = $per_page > 0 ? $per_page : 3;
		$exclude  = array_merge( array( $course_id ), array_map( 'intval', (array) ( $query['post__not_in'] ?? array() ) ) );
		$base     = array(
			'post_type'        => PostTypes::COURSE,
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'orderby'          => 'date',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		);

		$ids   = array();
		$terms = wp_get_post_terms( $course_id, Taxonomies::COURSE_CATEGORY, array( 'fields' => 'ids' ) );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$ids = get_posts(
				array_merge(
					$base,
					array(
						'posts_per_page' => $per_page,
						'post__not_in'   => $exclude, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- The current course and a learner's enrolments; small.
						'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Category match for a short row of courses.
							array(
								'taxonomy' => Taxonomies::COURSE_CATEGORY,
								'field'    => 'term_id',
								'terms'    => array_map( 'intval', $terms ),
							),
						),
					)
				)
			);
		}
		if ( count( $ids ) < $per_page ) {
			$ids = array_merge(
				$ids,
				get_posts(
					array_merge(
						$base,
						array(
							'posts_per_page' => $per_page - count( $ids ),
							'post__not_in'   => array_merge( $exclude, $ids ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- As above.
						)
					)
				)
			);
		}

		$query['post__in']       = empty( $ids ) ? array( 0 ) : array_map( 'intval', $ids );
		$query['orderby']        = 'post__in';
		$query['posts_per_page'] = $per_page;
		unset( $query['post__not_in'], $query['offset'] );
		return $query;
	}

	/**
	 * Applies the same setting to the editor preview, which queries wp/v2.
	 *
	 * @param array<string, mixed>                   $args    Query args.
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	public function rest_query( $args, $request ): array {
		$args = (array) $args;
		if ( wp_validate_boolean( $request->get_param( 'publiclmsHideEnrolled' ) ) ) {
			$args = $this->exclude_enrolled( $args );
		}
		return $args;
	}

	/**
	 * Adds the current user's active enrolments to post__not_in.
	 *
	 * @param array<string, mixed> $query Query vars.
	 * @return array<string, mixed>
	 */
	private function exclude_enrolled( array $query ): array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return $query;
		}
		$ids = array_map(
			'intval',
			wp_list_pluck( $this->enrollments->find_by_user( $user_id, EnrollmentRepository::STATUS_ACTIVE ), 'course_id' )
		);
		if ( ! empty( $ids ) ) {
			$query['post__not_in'] = array_merge( (array) ( $query['post__not_in'] ?? array() ), $ids ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- A learner's own enrolments; small and cached.
		}
		return $query;
	}
}
