<?php
/**
 * Block template registration.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Templates;

use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Taxonomies\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's block templates and maps them into the template hierarchy.
 *
 * Implements the template contract in docs/CONTRACTS.md: the plugin registers
 * `publiclms//{slug}` with register_block_template() (WordPress 6.7+), and a
 * theme overrides one by shipping templates/{slug}.html, because theme files
 * take precedence over plugin-registered templates with the same slug.
 *
 * Phase 0 stub: the slug list and hierarchy mapping are final; the template
 * files under templates/ arrive in phase 1, and register_templates() only
 * registers slugs whose file exists, so nothing is registered yet.
 */
final class TemplateRegistrar {

	/**
	 * Template namespace, the part before `//` in a template ID.
	 */
	const PLUGIN_NAMESPACE = 'publiclms';

	/**
	 * Directory holding the plugin's template files, with a trailing slash.
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Constructor.
	 *
	 * @param string $directory Directory holding {slug}.html files.
	 */
	public function __construct( string $directory ) {
		$this->directory = trailingslashit( $directory );
	}

	/**
	 * Hooks registration and the hierarchy mapping.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_templates' ), 20 );
		add_filter( 'single_template_hierarchy', array( $this, 'single_hierarchy' ) );
		add_filter( 'archive_template_hierarchy', array( $this, 'archive_hierarchy' ) );
		add_filter( 'taxonomy_template_hierarchy', array( $this, 'taxonomy_hierarchy' ) );
		add_filter( 'page_template_hierarchy', array( $this, 'page_hierarchy' ) );
	}

	/**
	 * Returns the contract's template slugs with their metadata.
	 *
	 * @return array<string, array{title: string, description: string, post_types: array<int, string>}>
	 */
	public function templates(): array {
		return array(
			'single-course'   => array(
				'title'       => __( 'Single Course', 'public-lms' ),
				'description' => __( 'Displays a course landing page with its curriculum and enrol button.', 'public-lms' ),
				'post_types'  => array(),
			),
			'archive-course'  => array(
				'title'       => __( 'Course Archive', 'public-lms' ),
				'description' => __( 'Displays the course catalogue and course category, tag and level archives.', 'public-lms' ),
				'post_types'  => array(),
			),
			'single-lesson'   => array(
				'title'       => __( 'Single Lesson', 'public-lms' ),
				'description' => __( 'Displays a lesson in a distraction-free layout with lesson navigation.', 'public-lms' ),
				'post_types'  => array(),
			),
			'single-quiz'     => array(
				'title'       => __( 'Single Quiz', 'public-lms' ),
				'description' => __( 'Displays a quiz and its runner.', 'public-lms' ),
				'post_types'  => array(),
			),
			'page-dashboard'  => array(
				'title'       => __( 'Student Dashboard', 'public-lms' ),
				'description' => __( 'The learner dashboard: enrolled courses, progress, quiz results and certificates.', 'public-lms' ),
				'post_types'  => array( 'page' ),
			),
			'page-instructor' => array(
				'title'       => __( 'Instructor Dashboard', 'public-lms' ),
				'description' => __( 'The instructor dashboard: own courses, learners, submissions and reports.', 'public-lms' ),
				'post_types'  => array( 'page' ),
			),
		);
	}

	/**
	 * Registers each template whose file exists. Hooked to `init`.
	 *
	 * @return void
	 */
	public function register_templates(): void {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}

		foreach ( $this->templates() as $slug => $template ) {
			$file = $this->directory . $slug . '.html';
			if ( ! is_readable( $file ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local template file shipped with the plugin.
			$content = (string) file_get_contents( $file );

			register_block_template(
				self::PLUGIN_NAMESPACE . '//' . $slug,
				array(
					'title'       => $template['title'],
					'description' => $template['description'],
					'content'     => $content,
					'post_types'  => $template['post_types'],
				)
			);
		}
	}

	/**
	 * Adds single-course, single-lesson and single-quiz after the post-type candidate.
	 *
	 * @param array<int, string> $templates Candidate template files.
	 * @return array<int, string>
	 */
	public function single_hierarchy( $templates ): array {
		$aliases = array(
			PostTypes::COURSE => 'single-course',
			PostTypes::LESSON => 'single-lesson',
			PostTypes::QUIZ   => 'single-quiz',
		);
		$post    = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! isset( $aliases[ $post->post_type ] ) ) {
			return (array) $templates;
		}
		return $this->insert_after( (array) $templates, 'single-' . $post->post_type . '.php', $aliases[ $post->post_type ] . '.php' );
	}

	/**
	 * Adds archive-course after archive-plms_course.
	 *
	 * @param array<int, string> $templates Candidate template files.
	 * @return array<int, string>
	 */
	public function archive_hierarchy( $templates ): array {
		if ( ! is_post_type_archive( PostTypes::COURSE ) ) {
			return (array) $templates;
		}
		return $this->insert_after( (array) $templates, 'archive-' . PostTypes::COURSE . '.php', 'archive-course.php' );
	}

	/**
	 * Lets course taxonomy archives fall back to archive-course (ADR-023).
	 *
	 * @param array<int, string> $templates Candidate template files.
	 * @return array<int, string>
	 */
	public function taxonomy_hierarchy( $templates ): array {
		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, Taxonomies::KEYS, true ) ) {
			return (array) $templates;
		}
		return $this->insert_after( (array) $templates, 'taxonomy-' . $term->taxonomy . '.php', 'archive-course.php' );
	}

	/**
	 * Applies page-dashboard and page-instructor only to the pages they belong to.
	 *
	 * - The pages chosen in settings (options publiclms_dashboard_page_id and
	 *   publiclms_instructor_page_id) get the template without the editor
	 *   picking it.
	 * - A page whose editor picked the template keeps it.
	 * - Any other page that merely has the slug "dashboard" or "instructor"
	 *   does not get it, although WordPress adds page-{slug} to its hierarchy
	 *   (ADR-023).
	 *
	 * @param array<int, string> $templates Candidate template files.
	 * @return array<int, string>
	 */
	public function page_hierarchy( $templates ): array {
		$templates = (array) $templates;
		$page_id   = (int) get_queried_object_id();
		if ( $page_id <= 0 ) {
			return $templates;
		}

		$assigned = (string) get_page_template_slug( $page_id );
		$map      = array(
			'page-dashboard'  => 'publiclms_dashboard_page_id',
			'page-instructor' => 'publiclms_instructor_page_id',
		);

		foreach ( $map as $slug => $option ) {
			if ( $assigned === $slug ) {
				continue;
			}
			$candidate = $slug . '.php';
			$templates = array_values( array_diff( $templates, array( $candidate ) ) );

			if ( (int) get_option( $option, 0 ) === $page_id ) {
				array_splice( $templates, '' === $assigned ? 0 : 1, 0, array( $candidate ) );
			}
		}
		return $templates;
	}

	/**
	 * Inserts a candidate after another one, or before the generic fallbacks.
	 *
	 * @param array<int, string> $templates Candidate template files.
	 * @param string             $after     Candidate to insert after.
	 * @param string             $candidate Candidate to insert.
	 * @return array<int, string>
	 */
	private function insert_after( array $templates, string $after, string $candidate ): array {
		if ( in_array( $candidate, $templates, true ) ) {
			return $templates;
		}
		$position = array_search( $after, $templates, true );
		$position = false === $position ? 0 : $position + 1;
		array_splice( $templates, $position, 0, array( $candidate ) );
		return $templates;
	}
}
