<?php
/**
 * The admin app shell.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Admin;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Taxonomies\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the "Public LMS" menu and mounts the React app on its pages.
 *
 * One app (ADR-007, ADR-033) serves three screens: Courses (list and the
 * course builder), Students and Settings. Each submenu renders the same
 * empty root; the app reads which screen it is from the boot data. Lesson
 * content is edited in the block editor, which the builder links to.
 */
final class AdminApp {

	/**
	 * Menu slug of the top-level page, which is also the Courses screen.
	 */
	const SLUG = 'publiclms';

	/**
	 * Script and style handle.
	 */
	const HANDLE = 'publiclms-admin';

	/**
	 * Screens keyed by menu slug.
	 *
	 * @var array<string, string>
	 */
	private $screens = array();

	/**
	 * Hooks the menu and assets.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'parent_file', array( $this, 'parent_file' ) );
		add_filter( 'submenu_file', array( $this, 'submenu_file' ), 10, 2 );
	}

	/**
	 * Adds the menu and its submenus. Hooked to `admin_menu`.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$title = __( 'Public LMS', 'public-lms' );
		$hook  = add_menu_page( $title, $title, Capabilities::EDIT_OWN_COURSE, self::SLUG, array( $this, 'render' ), 'dashicons-welcome-learn-more', 25 );

		$this->screens[ (string) $hook ] = 'courses';

		$courses = add_submenu_page( self::SLUG, __( 'Courses', 'public-lms' ), __( 'Courses', 'public-lms' ), Capabilities::EDIT_OWN_COURSE, self::SLUG, array( $this, 'render' ) );
		if ( false !== $courses ) {
			$this->screens[ $courses ] = 'courses';
		}
		$questions = add_submenu_page( self::SLUG, __( 'Question Bank', 'public-lms' ), __( 'Questions', 'public-lms' ), Capabilities::EDIT_OWN_COURSE, self::SLUG . '-questions', array( $this, 'render' ) );
		if ( false !== $questions ) {
			$this->screens[ $questions ] = 'questions';
		}
		$students = add_submenu_page( self::SLUG, __( 'Students', 'public-lms' ), __( 'Students', 'public-lms' ), Capabilities::VIEW_REPORTS, self::SLUG . '-students', array( $this, 'render' ) );
		if ( false !== $students ) {
			$this->screens[ $students ] = 'students';
		}
		add_submenu_page( self::SLUG, __( 'Course Categories', 'public-lms' ), __( 'Categories', 'public-lms' ), Capabilities::MANAGE_COURSES, 'edit-tags.php?taxonomy=' . Taxonomies::COURSE_CATEGORY . '&post_type=' . PostTypes::COURSE );
		add_submenu_page( self::SLUG, __( 'Levels', 'public-lms' ), __( 'Levels', 'public-lms' ), Capabilities::MANAGE_COURSES, 'edit-tags.php?taxonomy=' . Taxonomies::LEVEL . '&post_type=' . PostTypes::COURSE );
		$settings = add_submenu_page( self::SLUG, __( 'Settings', 'public-lms' ), __( 'Settings', 'public-lms' ), Capabilities::MANAGE_COURSES, self::SLUG . '-settings', array( $this, 'render' ) );
		if ( false !== $settings ) {
			$this->screens[ $settings ] = 'settings';
		}
	}

	/**
	 * Prints the app root.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<div class="wrap publiclms-admin"><h1 class="screen-reader-text">' . esc_html( get_admin_page_title() ) . '</h1>';
		echo '<div id="publiclms-admin-root"><p class="publiclms-admin__loading">' . esc_html__( 'Loading…', 'public-lms' ) . '</p></div>';
		echo '<noscript><div class="notice notice-error"><p>' . esc_html__( 'Public LMS needs JavaScript to manage courses.', 'public-lms' ) . '</p></div></noscript>';
		echo '</div>';
	}

	/**
	 * Enqueues the app on its own screens only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		$screen = $this->screens[ (string) $hook_suffix ] ?? '';
		if ( '' === $screen ) {
			return;
		}
		$asset_file = PUBLICLMS_PATH . 'build/admin/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script( self::HANDLE, PUBLICLMS_URL . 'build/admin/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'public-lms', PUBLICLMS_PATH . 'languages' );
		wp_enqueue_style( self::HANDLE, PUBLICLMS_URL . 'build/admin/style-index.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		if ( 'courses' === $screen ) {
			wp_enqueue_media();
		}

		$course_type = get_post_type_object( PostTypes::COURSE );
		$boot        = array(
			'screen'      => $screen,
			'adminUrl'    => admin_url(),
			'appUrl'      => admin_url( 'admin.php?page=' . self::SLUG ),
			'userId'      => get_current_user_id(),
			'currency'    => (string) get_option( 'publiclms_currency', 'USD' ),
			'permissions' => array(
				'manageCourses'  => current_user_can( Capabilities::MANAGE_COURSES ),
				'createCourses'  => null !== $course_type && current_user_can( $course_type->cap->create_posts ),
				'publishCourses' => null !== $course_type && current_user_can( $course_type->cap->publish_posts ),
				'viewReports'    => current_user_can( Capabilities::VIEW_REPORTS ),
				'manageTerms'    => current_user_can( Capabilities::MANAGE_COURSES ),
				'deletePlugins'  => current_user_can( 'delete_plugins' ),
			),
		);
		wp_add_inline_script( self::HANDLE, 'window.publiclmsAdmin = ' . wp_json_encode( $boot ) . ';', 'before' );
	}

	/**
	 * Keeps the Public LMS menu open on the term screens and when editing plugin content.
	 *
	 * @param string $parent_file Parent menu file.
	 * @return string
	 */
	public function parent_file( $parent_file ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen ) {
			return (string) $parent_file;
		}
		if ( in_array( $screen->taxonomy, Taxonomies::KEYS, true ) || in_array( $screen->post_type, PostTypes::KEYS, true ) ) {
			return self::SLUG;
		}
		return (string) $parent_file;
	}

	/**
	 * Highlights the matching submenu on the term screens.
	 *
	 * @param string|null $submenu_file Submenu file.
	 * @param string      $parent_file  Parent menu file.
	 * @return string|null
	 */
	public function submenu_file( $submenu_file, $parent_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null !== $screen && in_array( $screen->taxonomy, Taxonomies::KEYS, true ) ) {
			return 'edit-tags.php?taxonomy=' . $screen->taxonomy . '&post_type=' . PostTypes::COURSE;
		}
		if ( null !== $screen && in_array( $screen->post_type, PostTypes::KEYS, true ) && self::SLUG === $parent_file ) {
			return self::SLUG;
		}
		return $submenu_file;
	}
}
