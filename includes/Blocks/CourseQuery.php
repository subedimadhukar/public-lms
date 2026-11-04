<?php
/**
 * Server side of the publiclms/course-query Query Loop variation.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Blocks;

use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\EnrollmentRepository;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * The variation itself is registered in JS (src/editor/course-query.js) as a
 * core/query variation with `namespace: "publiclms/course-query"` and
 * `postType: "plms_course"` (CONTRACTS.md A5.5). Course cards are core Post
 * Template, Post Title, Post Featured Image and Post Excerpt blocks with
 * values from the bindings source, so the variation needs no wrapper block.
 *
 * This class adds the one control core lacks: `query.publiclmsHideEnrolled`
 * hides courses the current learner is already enrolled in. Level and
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
	 * Constructor.
	 *
	 * @param EnrollmentRepository $enrollments Enrolments.
	 */
	public function __construct( EnrollmentRepository $enrollments ) {
		$this->enrollments = $enrollments;
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
		if ( self::NAMESPACE_NAME !== ( $settings['namespace'] ?? '' ) ) {
			return $query;
		}
		$query['post_type'] = PostTypes::COURSE;
		if ( ! empty( $settings['publiclmsHideEnrolled'] ) ) {
			$query = $this->exclude_enrolled( $query );
		}
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
