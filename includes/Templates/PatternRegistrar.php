<?php
/**
 * Pattern registration.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's patterns from patterns/*.php (CONTRACTS.md A5).
 *
 * Plugins do not get WordPress's automatic pattern folder, so this reads the
 * same file headers a theme's patterns use (Title, Slug, Categories,
 * Description, Keywords, Block Types, Post Types, Inserter, Viewport Width)
 * and registers each file. On WordPress 6.8 and later the file is passed as
 * `filePath`, so it is only loaded when the pattern is used; on 6.7 its
 * output is captured once at registration.
 */
final class PatternRegistrar {

	/**
	 * Pattern category slug.
	 */
	const CATEGORY = 'publiclms';

	/**
	 * Directory holding the pattern files, with a trailing slash.
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Constructor.
	 *
	 * @param string $directory Directory holding pattern files.
	 */
	public function __construct( string $directory ) {
		$this->directory = trailingslashit( $directory );
	}

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_patterns' ), 21 );
	}

	/**
	 * Registers the category and every pattern file. Hooked to `init`.
	 *
	 * @return void
	 */
	public function register_patterns(): void {
		register_block_pattern_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Public LMS', 'public-lms' ),
				'description' => __( 'Course, lesson and dashboard sections from Public LMS.', 'public-lms' ),
			)
		);

		$files = glob( $this->directory . '*.php' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$pattern = $this->read( $file );
			if ( null === $pattern ) {
				continue;
			}
			$slug = $pattern['slug'];
			unset( $pattern['slug'] );
			if ( \WP_Block_Patterns_Registry::get_instance()->is_registered( $slug ) ) {
				continue;
			}
			register_block_pattern( $slug, $pattern );
		}
	}

	/**
	 * Reads a pattern file's headers into register_block_pattern() arguments.
	 *
	 * @param string $file Pattern file path.
	 * @return array<string, mixed>|null
	 */
	public function read( string $file ): ?array {
		$headers = get_file_data(
			$file,
			array(
				'title'         => 'Title',
				'slug'          => 'Slug',
				'description'   => 'Description',
				'categories'    => 'Categories',
				'keywords'      => 'Keywords',
				'blockTypes'    => 'Block Types',
				'postTypes'     => 'Post Types',
				'inserter'      => 'Inserter',
				'viewportWidth' => 'Viewport Width',
			)
		);
		if ( '' === $headers['slug'] || '' === $headers['title'] || 0 !== strpos( $headers['slug'], 'publiclms/' ) ) {
			return null;
		}

		$pattern = array(
			'slug'        => $headers['slug'],
			// Header text is translated the way core translates theme pattern headers; make-pot extracts it.
			'title'       => translate_with_gettext_context( $headers['title'], 'Pattern title', 'public-lms' ), // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText -- Variable header text, as core does for patterns.
			'description' => translate_with_gettext_context( $headers['description'], 'Pattern description', 'public-lms' ), // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText -- Variable header text, as core does for patterns.
			'inserter'    => ! in_array( strtolower( $headers['inserter'] ), array( 'no', 'false' ), true ),
		);
		foreach ( array( 'categories', 'keywords', 'blockTypes', 'postTypes' ) as $list ) {
			if ( '' !== $headers[ $list ] ) {
				$pattern[ $list ] = array_filter( array_map( 'trim', explode( ',', $headers[ $list ] ) ) );
			}
		}
		if ( '' !== $headers['viewportWidth'] ) {
			$pattern['viewportWidth'] = (int) $headers['viewportWidth'];
		}

		if ( version_compare( (string) get_bloginfo( 'version' ), '6.8', '>=' ) ) {
			$pattern['filePath'] = $file;
		} else {
			ob_start();
			include $file;
			$pattern['content'] = (string) ob_get_clean();
		}
		return $pattern;
	}
}
