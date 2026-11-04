<?php
/**
 * Content gate for lessons, quizzes and assignments.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Access;

use PublicLMS\PostTypes\PostTypes;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps gated item content out of every output path, whatever the template.
 *
 * The `publiclms/lesson-content` block checks access itself and renders a
 * friendlier locked state, but a theme template, a Query Loop with Post
 * Content, the REST API (`/wp/v2/plms_lesson`), feeds and oEmbed all reach
 * the content through `the_content`, so the gate sits there too. It runs
 * first (priority 0) so blocks inside locked content are never rendered.
 *
 * Learner pages also set DONOTCACHEPAGE so page caches never store one
 * learner's view for another (docs/ARCHITECTURE.md §6).
 */
final class ContentGate {

	/**
	 * Gated post types.
	 */
	const GATED = array( PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT );

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private $policy;

	/**
	 * Constructor.
	 *
	 * @param AccessPolicy $policy Access policy.
	 */
	public function __construct( AccessPolicy $policy ) {
		$this->policy = $policy;
	}

	/**
	 * Hooks the gate.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'filter_content' ), 0 );
		add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 11, 2 );
		add_action( 'template_redirect', array( $this, 'no_page_cache' ) );
	}

	/**
	 * Replaces the content of a locked item with a notice.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ): string {
		$post = get_post();
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::GATED, true ) ) {
			return (string) $content;
		}
		$decision = $this->policy->can_access_item( (int) $post->ID );
		if ( $decision->is_allowed() ) {
			return (string) $content;
		}
		return self::locked_notice( $decision );
	}

	/**
	 * Removes generated excerpts of locked items; a hand-written excerpt is a public summary.
	 *
	 * Runs after wp_trim_excerpt() (priority 10) has generated one.
	 *
	 * @param string       $excerpt Excerpt.
	 * @param WP_Post|null $post    Post.
	 * @return string
	 */
	public function filter_excerpt( $excerpt, $post = null ): string {
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::GATED, true ) || '' !== trim( (string) $post->post_excerpt ) ) {
			return (string) $excerpt;
		}
		return $this->policy->can_access_item( (int) $post->ID )->is_allowed() ? (string) $excerpt : '';
	}

	/**
	 * Marks learner pages as uncacheable for page-cache plugins.
	 *
	 * @return void
	 */
	public function no_page_cache(): void {
		$dashboard = (int) get_option( 'publiclms_dashboard_page_id', 0 );
		if ( is_singular( self::GATED ) || ( $dashboard > 0 && is_page( $dashboard ) ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard constant read by page-cache plugins.
			}
		}
	}

	/**
	 * Returns the notice shown in place of locked content.
	 *
	 * @param AccessDecision $decision The denial.
	 * @return string
	 */
	public static function locked_notice( AccessDecision $decision ): string {
		return '<div class="publiclms-locked-notice"><p>' . esc_html( $decision->message() ) . '</p></div>';
	}
}
