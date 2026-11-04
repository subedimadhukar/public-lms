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

		$labels  = self::labels()[ $headers['slug'] ] ?? array();
		$pattern = array(
			'slug'        => $headers['slug'],
			'title'       => $labels['title'] ?? $headers['title'],
			'description' => $labels['description'] ?? $headers['description'],
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
	/**
	 * Returns the translated title and description of each pattern.
	 *
	 * WP-CLI's make-pot extracts pattern headers only from themes, so the
	 * strings live here as well, where it finds them. The file headers stay
	 * the source for everything else.
	 *
	 * @return array<string, array{title: string, description: string}>
	 */
	public static function labels(): array {
		return array(
			'publiclms/course-archive-header' => array(
				'title'       => _x( 'Course archive header', 'Pattern title', 'public-lms' ),
				'description' => _x( 'The catalogue or category title and its description.', 'Pattern description', 'public-lms' ),
			),
			'publiclms/course-curriculum'     => array(
				'title'       => _x( 'Course curriculum', 'Pattern title', 'public-lms' ),
				'description' => _x( 'The course\'s sections and lessons, with completion and lock states.', 'Pattern description', 'public-lms' ),
			),
			'publiclms/course-details'        => array(
				'title'       => _x( 'Course details', 'Pattern title', 'public-lms' ),
				'description' => _x( 'Duration, lessons, level and last update of the course, from the course-field bindings source.', 'Pattern description', 'public-lms' ),
			),
			'publiclms/course-enroll-card'    => array(
				'title'       => _x( 'Course enrol card', 'Pattern title', 'public-lms' ),
				'description' => _x( 'Price, enrol button and what the course includes, sticky on wide screens.', 'Pattern description', 'public-lms' ),
			),
			'publiclms/course-grid'           => array(
				'title'       => _x( 'Course grid', 'Pattern title', 'public-lms' ),
				'description' => _x( 'A grid of course cards: image, level, title, short description and price, with pagination.', 'Pattern description', 'public-lms' ),
			),
			'publiclms/course-hero'           => array(
				'title'       => _x( 'Course hero', 'Pattern title', 'public-lms' ),
				'description' => _x( 'Course title, short description and featured image, with level, instructor and the enrol button.', 'Pattern description', 'public-lms' ),
			),
			'publiclms/course-instructor'     => array(
				'title'       => _x( 'Course instructor', 'Pattern title', 'public-lms' ),
				'description' => _x( 'The instructor\'s avatar, name and biography.', 'Pattern description', 'public-lms' ),
			),
			'publiclms/lesson-content'        => array(
				'title'       => _x( 'Lesson content', 'Pattern title', 'public-lms' ),
				'description' => _x( 'The lesson, shown to learners who can open it, and the mark complete button.', 'Pattern description', 'public-lms' ),
			),
			'publiclms/lesson-header'         => array(
				'title'       => _x( 'Lesson header', 'Pattern title', 'public-lms' ),
				'description' => _x( 'Link back to the course, the section name, the lesson title and the learner\'s progress.', 'Pattern description', 'public-lms' ),
			),
			'publiclms/lesson-navigation'     => array(
				'title'       => _x( 'Lesson navigation', 'Pattern title', 'public-lms' ),
				'description' => _x( 'Links to the previous and next lessons in the course.', 'Pattern description', 'public-lms' ),
			),
			'publiclms/lesson-sidebar'        => array(
				'title'       => _x( 'Lesson sidebar', 'Pattern title', 'public-lms' ),
				'description' => _x( 'The course curriculum beside a lesson, with the current lesson highlighted.', 'Pattern description', 'public-lms' ),
			),
			'publiclms/student-dashboard'     => array(
				'title'       => _x( 'Student dashboard', 'Pattern title', 'public-lms' ),
				'description' => _x( 'The learner\'s courses with progress, in tabs.', 'Pattern description', 'public-lms' ),
			),
		);
	}
}
