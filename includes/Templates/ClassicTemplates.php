<?php
/**
 * Classic theme fallback.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Templates;

use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Taxonomies\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the plugin's block templates inside a classic theme (ADR-009).
 *
 * On a classic theme WordPress never looks at block templates, so course,
 * lesson, archive and dashboard requests would fall to the theme's
 * single.php or page.php and lose the curriculum, player and enrol card.
 * When the theme has no PHP template for the request of its own (for example
 * single-plms_course.php), `template_include` points to templates/classic.php,
 * which prints the theme's header, the same blocks as the block template
 * minus its top-level header and footer template parts, and the theme's footer.
 */
final class ClassicTemplates {

	/**
	 * Directory holding the plugin's template files, with a trailing slash.
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Template slug chosen for the current request.
	 *
	 * @var string
	 */
	private $slug = '';

	/**
	 * Constructor.
	 *
	 * @param string $directory Directory holding {slug}.html and classic.php.
	 */
	public function __construct( string $directory ) {
		$this->directory = trailingslashit( $directory );
	}

	/**
	 * Hooks the fallback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'template_include', array( $this, 'template_include' ), 99 );
	}

	/**
	 * Swaps in the plugin's classic wrapper for plugin requests.
	 *
	 * @param string $template Template file WordPress chose.
	 * @return string
	 */
	public function template_include( $template ): string {
		$template = (string) $template;
		if ( wp_is_block_theme() ) {
			return $template;
		}
		$slug = $this->slug_for_request();
		if ( '' === $slug || ! is_readable( $this->directory . $slug . '.html' ) ) {
			return $template;
		}
		// A theme template made for this request wins (single-plms_course.php and the like).
		if ( '' !== locate_template( array( $slug . '.php' ) ) ) {
			return $template;
		}
		$this->slug = $slug;
		return $this->directory . 'classic.php';
	}

	/**
	 * Returns the block template slug for the current request, or ''.
	 *
	 * @return string
	 */
	public function slug_for_request(): string {
		if ( is_singular( PostTypes::COURSE ) ) {
			return 'single-' . PostTypes::COURSE;
		}
		if ( is_singular( PostTypes::LESSON ) ) {
			return 'single-' . PostTypes::LESSON;
		}
		if ( is_post_type_archive( PostTypes::COURSE ) || is_tax( Taxonomies::KEYS ) ) {
			return TemplateRegistrar::COURSE_ARCHIVE;
		}
		$dashboard = (int) get_option( 'publiclms_dashboard_page_id', 0 );
		if ( $dashboard > 0 && is_page( $dashboard ) ) {
			return 'plms-dashboard';
		}
		return '';
	}

	/**
	 * Renders the chosen block template's content without its template parts.
	 *
	 * Mirrors get_the_block_template_html(): on a single post the loop is set
	 * up first, so Post Title, Post Content and the plugin's blocks see the
	 * post in context.
	 *
	 * @return string
	 */
	public function render(): string {
		if ( '' === $this->slug ) {
			$this->slug = $this->slug_for_request();
		}
		$file = $this->directory . $this->slug . '.html';
		if ( '' === $this->slug || ! is_readable( $file ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local template file shipped with the plugin.
		$markup = (string) file_get_contents( $file );
		$blocks = $this->without_template_parts( parse_blocks( $markup ) );
		$markup = serialize_blocks( $blocks );

		global $wp_query;
		if ( is_singular() && 1 === (int) $wp_query->post_count && have_posts() ) {
			$html = '';
			while ( have_posts() ) {
				the_post();
				$html = do_blocks( $markup );
			}
			wp_reset_postdata();
			return $html;
		}
		return do_blocks( $markup );
	}

	/**
	 * Removes the top-level core/template-part blocks (the plugin's templates
	 * only place header and footer at the top level).
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function without_template_parts( array $blocks ): array {
		return array_values(
			array_filter(
				$blocks,
				static function ( $block ) {
					return 'core/template-part' !== ( $block['blockName'] ?? '' );
				}
			)
		);
	}
}
