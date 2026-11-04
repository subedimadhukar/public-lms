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
 * Registers the plugin's block templates and the two places they need help
 * from the template hierarchy.
 *
 * Implements the template contract in docs/CONTRACTS.md: the plugin registers
 * `publiclms//{slug}` with register_block_template() (WordPress 6.7+), and a
 * theme overrides one by shipping templates/{slug}.html, because theme files
 * take precedence over plugin-registered templates with the same slug.
 *
 * Slugs follow the post type keys (ADR-023), so WordPress resolves the single
 * and archive templates on its own and no other plugin's post types can match
 * them. The registrar only adds two things to the hierarchy: course term
 * archives fall back to the course archive template, and the dashboard
 * templates apply to the pages chosen in settings.
 *
 * register_templates() registers each slug whose file exists under
 * templates/: single-plms_course, archive-plms_course, single-plms_lesson,
 * plms-dashboard (phase 1), single-plms_quiz and single-plms_assignment
 * (phase 2 part A), built from the patterns in patterns/; plms-instructor
 * arrives with the instructor dashboard. Classic themes get the same
 * blocks through ClassicTemplates.
 */
final class TemplateRegistrar {

	/**
	 * Template namespace, the part before `//` in a template ID.
	 */
	const PLUGIN_NAMESPACE = 'publiclms';

	/**
	 * Slug of the course archive template, also used for course term archives.
	 */
	const COURSE_ARCHIVE = 'archive-plms_course';

	/**
	 * Dashboard template slugs keyed by the option holding their page ID.
	 */
	const PAGE_TEMPLATES = array(
		'publiclms_dashboard_page_id'  => 'plms-dashboard',
		'publiclms_instructor_page_id' => 'plms-instructor',
	);

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
	 * Hooks registration and the hierarchy additions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_templates' ), 20 );
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
			'single-' . PostTypes::COURSE     => array(
				'title'       => __( 'Single Course', 'public-lms' ),
				'description' => __( 'Displays a course landing page with its curriculum and enrol button.', 'public-lms' ),
				'post_types'  => array(),
			),
			self::COURSE_ARCHIVE              => array(
				'title'       => __( 'Course Archive', 'public-lms' ),
				'description' => __( 'Displays the course catalogue and course category, tag and level archives.', 'public-lms' ),
				'post_types'  => array(),
			),
			'single-' . PostTypes::LESSON     => array(
				'title'       => __( 'Single Lesson', 'public-lms' ),
				'description' => __( 'Displays a lesson in a distraction-free layout with lesson navigation.', 'public-lms' ),
				'post_types'  => array(),
			),
			'single-' . PostTypes::QUIZ       => array(
				'title'       => __( 'Single Quiz', 'public-lms' ),
				'description' => __( 'Displays a quiz and its runner.', 'public-lms' ),
				'post_types'  => array(),
			),
			'single-' . PostTypes::ASSIGNMENT => array(
				'title'       => __( 'Single Assignment', 'public-lms' ),
				'description' => __( 'Displays an assignment brief and the hand-in form.', 'public-lms' ),
				'post_types'  => array(),
			),
			'plms-dashboard'                  => array(
				'title'       => __( 'Student Dashboard', 'public-lms' ),
				'description' => __( 'The learner dashboard: enrolled courses, progress, quiz results and certificates.', 'public-lms' ),
				'post_types'  => array( 'page' ),
			),
			'plms-instructor'                 => array(
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
	 * Lets course category, tag and level archives fall back to the course archive template.
	 *
	 * Inserted after `taxonomy-{taxonomy}`, so a theme or user template for the
	 * taxonomy itself still wins.
	 *
	 * @param array<int, string> $templates Candidate template files.
	 * @return array<int, string>
	 */
	public function taxonomy_hierarchy( $templates ): array {
		$templates = (array) $templates;
		$term      = get_queried_object();
		if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, Taxonomies::KEYS, true ) ) {
			return $templates;
		}

		$candidate = self::COURSE_ARCHIVE . '.php';
		if ( in_array( $candidate, $templates, true ) ) {
			return $templates;
		}
		$position = array_search( 'taxonomy-' . $term->taxonomy . '.php', $templates, true );
		$position = false === $position ? 0 : $position + 1;
		array_splice( $templates, $position, 0, array( $candidate ) );
		return $templates;
	}

	/**
	 * Applies plms-dashboard or plms-instructor to the page chosen in settings.
	 *
	 * The slugs have no `page-` prefix, so WordPress never matches them to a
	 * page by its slug; they apply only to the configured page (options
	 * publiclms_dashboard_page_id and publiclms_instructor_page_id) or where an
	 * editor picks them in the template picker. A template the editor picked
	 * for the configured page still wins, because WordPress puts it first.
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

		foreach ( self::PAGE_TEMPLATES as $option => $slug ) {
			if ( (int) get_option( $option, 0 ) !== $page_id ) {
				continue;
			}
			$assigned  = (string) get_page_template_slug( $page_id );
			$candidate = $slug . '.php';
			if ( $assigned === $slug || in_array( $candidate, $templates, true ) ) {
				break;
			}
			array_splice( $templates, '' === $assigned ? 0 : 1, 0, array( $candidate ) );
			break;
		}
		return $templates;
	}
}
