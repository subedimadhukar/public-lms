<?php
/**
 * Post type registration.
 *
 * @package PublicLMS
 */

namespace PublicLMS\PostTypes;

use PublicLMS\Capabilities\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the authored-content post types.
 *
 * Everything authored, revised and templated is a post type (ADR-001). Keys
 * use the `plms_` prefix and stay within WordPress's 20-character limit.
 * Relationships (lesson to section to course) are stored as post meta and
 * land with the course builder in phase 1.
 */
final class PostTypes {

	const COURSE      = 'plms_course';
	const SECTION     = 'plms_section';
	const LESSON      = 'plms_lesson';
	const QUIZ        = 'plms_quiz';
	const QUESTION    = 'plms_question';
	const ASSIGNMENT  = 'plms_assignment';
	const CERTIFICATE = 'plms_certificate';
	const MEMBERSHIP  = 'plms_membership';
	const GROUP       = 'plms_group';

	/**
	 * Every post type key the plugin owns.
	 */
	const KEYS = array(
		self::COURSE,
		self::SECTION,
		self::LESSON,
		self::QUIZ,
		self::QUESTION,
		self::ASSIGNMENT,
		self::CERTIFICATE,
		self::MEMBERSHIP,
		self::GROUP,
	);

	/**
	 * Admin menu parent. Since phase 1 the "Public LMS" app menu replaces the
	 * post type screens in the menu (ADR-033); the screens stay reachable for
	 * the block editor and for Pro, which can show them again through
	 * `publiclms_post_type_args`.
	 */
	const MENU_PARENT = false;

	/**
	 * Registers the post types. Hooked to `init`.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->definitions() as $post_type => $args ) {
			/**
			 * Filters the arguments of a Public LMS post type before registration.
			 *
			 * @param array<string, mixed> $args      Arguments for register_post_type().
			 * @param string               $post_type Post type key.
			 */
			$args = (array) apply_filters( 'publiclms_post_type_args', $args, $post_type );
			register_post_type( $post_type, $args );
		}
	}

	/**
	 * Returns the registration arguments keyed by post type.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function definitions(): array {
		$public_content = array(
			'public'              => true,
			'publicly_queryable'  => true,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => self::MENU_PARENT,
			'show_in_rest'        => true,
			'show_in_nav_menus'   => false,
			'has_archive'         => false,
			'hierarchical'        => false,
			'delete_with_user'    => false,
			'map_meta_cap'        => true,
			'capabilities'        => self::capabilities( 'content' ),
		);

		$internal = array(
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => self::MENU_PARENT,
			'show_in_rest'        => true,
			'show_in_nav_menus'   => false,
			'has_archive'         => false,
			'hierarchical'        => false,
			'rewrite'             => false,
			'query_var'           => false,
			'delete_with_user'    => false,
			'map_meta_cap'        => true,
		);

		return array(
			self::COURSE      => array(
				'labels'              => array(
					'name'               => _x( 'Courses', 'post type general name', 'public-lms' ),
					'singular_name'      => _x( 'Course', 'post type singular name', 'public-lms' ),
					'menu_name'          => _x( 'Courses', 'admin menu', 'public-lms' ),
					'all_items'          => __( 'All Courses', 'public-lms' ),
					'add_new'            => __( 'Add Course', 'public-lms' ),
					'add_new_item'       => __( 'Add New Course', 'public-lms' ),
					'edit_item'          => __( 'Edit Course', 'public-lms' ),
					'new_item'           => __( 'New Course', 'public-lms' ),
					'view_item'          => __( 'View Course', 'public-lms' ),
					'view_items'         => __( 'View Courses', 'public-lms' ),
					'search_items'       => __( 'Search Courses', 'public-lms' ),
					'not_found'          => __( 'No courses found.', 'public-lms' ),
					'not_found_in_trash' => __( 'No courses found in Trash.', 'public-lms' ),
					'archives'           => __( 'Course Archives', 'public-lms' ),
					'item_published'     => __( 'Course published.', 'public-lms' ),
					'item_updated'       => __( 'Course updated.', 'public-lms' ),
				),
				'description'         => __( 'A course: the unit learners enrol in.', 'public-lms' ),
				'public'              => true,
				'publicly_queryable'  => true,
				'exclude_from_search' => false,
				'show_ui'             => true,
				'show_in_menu'        => self::MENU_PARENT,
				'show_in_rest'        => true,
				'show_in_nav_menus'   => true,
				'menu_position'       => 25,
				'menu_icon'           => 'dashicons-welcome-learn-more',
				'has_archive'         => 'courses',
				'rewrite'             => array(
					'slug'       => 'courses',
					'with_front' => false,
				),
				'hierarchical'        => false,
				'delete_with_user'    => false,
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ),
				'taxonomies'          => array(),
				'map_meta_cap'        => true,
				'capabilities'        => self::capabilities( 'content' ),
			),
			self::SECTION     => array_merge(
				$internal,
				array(
					'labels'       => array(
						'name'               => _x( 'Sections', 'post type general name', 'public-lms' ),
						'singular_name'      => _x( 'Section', 'post type singular name', 'public-lms' ),
						'all_items'          => __( 'Sections', 'public-lms' ),
						'add_new'            => __( 'Add Section', 'public-lms' ),
						'add_new_item'       => __( 'Add New Section', 'public-lms' ),
						'edit_item'          => __( 'Edit Section', 'public-lms' ),
						'new_item'           => __( 'New Section', 'public-lms' ),
						'search_items'       => __( 'Search Sections', 'public-lms' ),
						'not_found'          => __( 'No sections found.', 'public-lms' ),
						'not_found_in_trash' => __( 'No sections found in Trash.', 'public-lms' ),
					),
					'description'  => __( 'A group of lessons, quizzes and assignments inside a course.', 'public-lms' ),
					'supports'     => array( 'title', 'editor', 'author', 'page-attributes', 'custom-fields' ),
					'capabilities' => self::capabilities( 'content' ),
				)
			),
			self::LESSON      => array_merge(
				$public_content,
				array(
					'labels'      => array(
						'name'               => _x( 'Lessons', 'post type general name', 'public-lms' ),
						'singular_name'      => _x( 'Lesson', 'post type singular name', 'public-lms' ),
						'all_items'          => __( 'Lessons', 'public-lms' ),
						'add_new'            => __( 'Add Lesson', 'public-lms' ),
						'add_new_item'       => __( 'Add New Lesson', 'public-lms' ),
						'edit_item'          => __( 'Edit Lesson', 'public-lms' ),
						'new_item'           => __( 'New Lesson', 'public-lms' ),
						'view_item'          => __( 'View Lesson', 'public-lms' ),
						'view_items'         => __( 'View Lessons', 'public-lms' ),
						'search_items'       => __( 'Search Lessons', 'public-lms' ),
						'not_found'          => __( 'No lessons found.', 'public-lms' ),
						'not_found_in_trash' => __( 'No lessons found in Trash.', 'public-lms' ),
						'item_published'     => __( 'Lesson published.', 'public-lms' ),
						'item_updated'       => __( 'Lesson updated.', 'public-lms' ),
					),
					'description' => __( 'A lesson inside a course.', 'public-lms' ),
					'rewrite'     => array(
						'slug'       => 'lessons',
						'with_front' => false,
					),
					'supports'    => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'page-attributes', 'custom-fields' ),
				)
			),
			self::QUIZ        => array_merge(
				$public_content,
				array(
					'labels'      => array(
						'name'               => _x( 'Quizzes', 'post type general name', 'public-lms' ),
						'singular_name'      => _x( 'Quiz', 'post type singular name', 'public-lms' ),
						'all_items'          => __( 'Quizzes', 'public-lms' ),
						'add_new'            => __( 'Add Quiz', 'public-lms' ),
						'add_new_item'       => __( 'Add New Quiz', 'public-lms' ),
						'edit_item'          => __( 'Edit Quiz', 'public-lms' ),
						'new_item'           => __( 'New Quiz', 'public-lms' ),
						'view_item'          => __( 'View Quiz', 'public-lms' ),
						'view_items'         => __( 'View Quizzes', 'public-lms' ),
						'search_items'       => __( 'Search Quizzes', 'public-lms' ),
						'not_found'          => __( 'No quizzes found.', 'public-lms' ),
						'not_found_in_trash' => __( 'No quizzes found in Trash.', 'public-lms' ),
						'item_published'     => __( 'Quiz published.', 'public-lms' ),
						'item_updated'       => __( 'Quiz updated.', 'public-lms' ),
					),
					'description' => __( 'A graded quiz inside a course.', 'public-lms' ),
					'rewrite'     => array(
						'slug'       => 'quizzes',
						'with_front' => false,
					),
					'supports'    => array( 'title', 'editor', 'author', 'revisions', 'page-attributes', 'custom-fields' ),
				)
			),
			self::QUESTION    => array_merge(
				$internal,
				array(
					'labels'       => array(
						'name'               => _x( 'Questions', 'post type general name', 'public-lms' ),
						'singular_name'      => _x( 'Question', 'post type singular name', 'public-lms' ),
						'all_items'          => __( 'Question Bank', 'public-lms' ),
						'add_new'            => __( 'Add Question', 'public-lms' ),
						'add_new_item'       => __( 'Add New Question', 'public-lms' ),
						'edit_item'          => __( 'Edit Question', 'public-lms' ),
						'new_item'           => __( 'New Question', 'public-lms' ),
						'search_items'       => __( 'Search Questions', 'public-lms' ),
						'not_found'          => __( 'No questions found.', 'public-lms' ),
						'not_found_in_trash' => __( 'No questions found in Trash.', 'public-lms' ),
					),
					'description'  => __( 'A reusable question in the question bank.', 'public-lms' ),
					'supports'     => array( 'title', 'editor', 'author', 'revisions', 'custom-fields' ),
					'capabilities' => self::capabilities( 'content' ),
				)
			),
			self::ASSIGNMENT  => array_merge(
				$public_content,
				array(
					'labels'      => array(
						'name'               => _x( 'Assignments', 'post type general name', 'public-lms' ),
						'singular_name'      => _x( 'Assignment', 'post type singular name', 'public-lms' ),
						'all_items'          => __( 'Assignments', 'public-lms' ),
						'add_new'            => __( 'Add Assignment', 'public-lms' ),
						'add_new_item'       => __( 'Add New Assignment', 'public-lms' ),
						'edit_item'          => __( 'Edit Assignment', 'public-lms' ),
						'new_item'           => __( 'New Assignment', 'public-lms' ),
						'view_item'          => __( 'View Assignment', 'public-lms' ),
						'view_items'         => __( 'View Assignments', 'public-lms' ),
						'search_items'       => __( 'Search Assignments', 'public-lms' ),
						'not_found'          => __( 'No assignments found.', 'public-lms' ),
						'not_found_in_trash' => __( 'No assignments found in Trash.', 'public-lms' ),
						'item_published'     => __( 'Assignment published.', 'public-lms' ),
						'item_updated'       => __( 'Assignment updated.', 'public-lms' ),
					),
					'description' => __( 'An assignment learners submit files or text for.', 'public-lms' ),
					'rewrite'     => array(
						'slug'       => 'assignments',
						'with_front' => false,
					),
					'supports'    => array( 'title', 'editor', 'author', 'revisions', 'page-attributes', 'custom-fields' ),
				)
			),
			self::CERTIFICATE => array_merge(
				$internal,
				array(
					'labels'       => array(
						'name'               => _x( 'Certificates', 'post type general name', 'public-lms' ),
						'singular_name'      => _x( 'Certificate', 'post type singular name', 'public-lms' ),
						'all_items'          => __( 'Certificates', 'public-lms' ),
						'add_new'            => __( 'Add Certificate', 'public-lms' ),
						'add_new_item'       => __( 'Add New Certificate Template', 'public-lms' ),
						'edit_item'          => __( 'Edit Certificate Template', 'public-lms' ),
						'new_item'           => __( 'New Certificate Template', 'public-lms' ),
						'search_items'       => __( 'Search Certificates', 'public-lms' ),
						'not_found'          => __( 'No certificate templates found.', 'public-lms' ),
						'not_found_in_trash' => __( 'No certificate templates found in Trash.', 'public-lms' ),
					),
					'description'  => __( 'A block-built certificate template.', 'public-lms' ),
					'supports'     => array( 'title', 'editor', 'revisions', 'custom-fields' ),
					'capabilities' => self::capabilities( 'manage' ),
				)
			),
			self::MEMBERSHIP  => array_merge(
				$internal,
				array(
					'labels'       => array(
						'name'               => _x( 'Memberships', 'post type general name', 'public-lms' ),
						'singular_name'      => _x( 'Membership', 'post type singular name', 'public-lms' ),
						'all_items'          => __( 'Memberships', 'public-lms' ),
						'add_new'            => __( 'Add Membership', 'public-lms' ),
						'add_new_item'       => __( 'Add New Membership', 'public-lms' ),
						'edit_item'          => __( 'Edit Membership', 'public-lms' ),
						'new_item'           => __( 'New Membership', 'public-lms' ),
						'search_items'       => __( 'Search Memberships', 'public-lms' ),
						'not_found'          => __( 'No memberships found.', 'public-lms' ),
						'not_found_in_trash' => __( 'No memberships found in Trash.', 'public-lms' ),
					),
					'description'  => __( 'An access plan that grants a set of courses.', 'public-lms' ),
					'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ),
					'capabilities' => self::capabilities( 'orders' ),
				)
			),
			self::GROUP       => array_merge(
				$internal,
				array(
					'labels'       => array(
						'name'               => _x( 'Groups', 'post type general name', 'public-lms' ),
						'singular_name'      => _x( 'Group', 'post type singular name', 'public-lms' ),
						'all_items'          => __( 'Groups', 'public-lms' ),
						'add_new'            => __( 'Add Group', 'public-lms' ),
						'add_new_item'       => __( 'Add New Group', 'public-lms' ),
						'edit_item'          => __( 'Edit Group', 'public-lms' ),
						'new_item'           => __( 'New Group', 'public-lms' ),
						'search_items'       => __( 'Search Groups', 'public-lms' ),
						'not_found'          => __( 'No groups found.', 'public-lms' ),
						'not_found_in_trash' => __( 'No groups found in Trash.', 'public-lms' ),
					),
					'description'  => __( 'A group of learners enrolled together, with seats and a leader.', 'public-lms' ),
					'supports'     => array( 'title', 'author', 'custom-fields' ),
					'capabilities' => self::capabilities( 'manage' ),
				)
			),
		);
	}

	/**
	 * Maps a post type's primitive capabilities to the plugin's capabilities.
	 *
	 * - content: instructors create, publish and edit their own items with
	 *   `publiclms_edit_own_course`; editing others' items needs
	 *   `publiclms_manage_courses`.
	 * - manage:  every primitive needs `publiclms_manage_courses`.
	 * - orders:  every primitive needs `publiclms_manage_orders`.
	 *
	 * See docs/ARCHITECTURE.md, ADR-005 and ADR-025.
	 *
	 * @param string $profile One of 'content', 'manage' or 'orders'.
	 * @return array<string, string>
	 */
	public static function capabilities( string $profile ): array {
		if ( 'content' === $profile ) {
			$own    = Capabilities::EDIT_OWN_COURSE;
			$others = Capabilities::MANAGE_COURSES;
		} elseif ( 'orders' === $profile ) {
			$own    = Capabilities::MANAGE_ORDERS;
			$others = Capabilities::MANAGE_ORDERS;
		} else {
			$own    = Capabilities::MANAGE_COURSES;
			$others = Capabilities::MANAGE_COURSES;
		}

		return array(
			'create_posts'           => $own,
			'edit_posts'             => $own,
			'edit_published_posts'   => $own,
			'publish_posts'          => $own,
			'delete_posts'           => $own,
			'delete_published_posts' => $own,
			'edit_others_posts'      => $others,
			'delete_others_posts'    => $others,
			'edit_private_posts'     => $others,
			'delete_private_posts'   => $others,
			'read_private_posts'     => $others,
			'read'                   => 'read',
		);
	}
}
