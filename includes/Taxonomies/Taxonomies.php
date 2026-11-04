<?php
/**
 * Taxonomy registration.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Taxonomies;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the course taxonomies.
 */
final class Taxonomies {

	const COURSE_CATEGORY = 'plms_course_category';
	const COURSE_TAG      = 'plms_course_tag';
	const LEVEL           = 'plms_level';

	/**
	 * Every taxonomy key the plugin owns.
	 */
	const KEYS = array(
		self::COURSE_CATEGORY,
		self::COURSE_TAG,
		self::LEVEL,
	);

	/**
	 * Registers the taxonomies. Hooked to `init`.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->definitions() as $taxonomy => $args ) {
			/**
			 * Filters the arguments of a Public LMS taxonomy before registration.
			 *
			 * @param array<string, mixed> $args     Arguments for register_taxonomy().
			 * @param string               $taxonomy Taxonomy key.
			 */
			$args = (array) apply_filters( 'publiclms_taxonomy_args', $args, $taxonomy );
			register_taxonomy( $taxonomy, array( PostTypes::COURSE ), $args );
		}
	}

	/**
	 * Returns the registration arguments keyed by taxonomy.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function definitions(): array {
		$common = array(
			'public'            => true,
			'show_ui'           => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => true,
			'capabilities'      => array(
				'manage_terms' => Capabilities::MANAGE_COURSES,
				'edit_terms'   => Capabilities::MANAGE_COURSES,
				'delete_terms' => Capabilities::MANAGE_COURSES,
				'assign_terms' => Capabilities::EDIT_OWN_COURSE,
			),
		);

		return array(
			self::COURSE_CATEGORY => array_merge(
				$common,
				array(
					'labels'       => array(
						'name'          => _x( 'Course Categories', 'taxonomy general name', 'public-lms' ),
						'singular_name' => _x( 'Course Category', 'taxonomy singular name', 'public-lms' ),
						'menu_name'     => __( 'Categories', 'public-lms' ),
						'all_items'     => __( 'All Course Categories', 'public-lms' ),
						'edit_item'     => __( 'Edit Course Category', 'public-lms' ),
						'view_item'     => __( 'View Course Category', 'public-lms' ),
						'update_item'   => __( 'Update Course Category', 'public-lms' ),
						'add_new_item'  => __( 'Add New Course Category', 'public-lms' ),
						'new_item_name' => __( 'New Course Category Name', 'public-lms' ),
						'parent_item'   => __( 'Parent Course Category', 'public-lms' ),
						'search_items'  => __( 'Search Course Categories', 'public-lms' ),
						'not_found'     => __( 'No course categories found.', 'public-lms' ),
					),
					'hierarchical' => true,
					'rewrite'      => array(
						'slug'         => 'course-category',
						'with_front'   => false,
						'hierarchical' => true,
					),
				)
			),
			self::COURSE_TAG      => array_merge(
				$common,
				array(
					'labels'       => array(
						'name'                       => _x( 'Course Tags', 'taxonomy general name', 'public-lms' ),
						'singular_name'              => _x( 'Course Tag', 'taxonomy singular name', 'public-lms' ),
						'menu_name'                  => __( 'Tags', 'public-lms' ),
						'all_items'                  => __( 'All Course Tags', 'public-lms' ),
						'edit_item'                  => __( 'Edit Course Tag', 'public-lms' ),
						'view_item'                  => __( 'View Course Tag', 'public-lms' ),
						'update_item'                => __( 'Update Course Tag', 'public-lms' ),
						'add_new_item'               => __( 'Add New Course Tag', 'public-lms' ),
						'new_item_name'              => __( 'New Course Tag Name', 'public-lms' ),
						'search_items'               => __( 'Search Course Tags', 'public-lms' ),
						'separate_items_with_commas' => __( 'Separate course tags with commas', 'public-lms' ),
						'not_found'                  => __( 'No course tags found.', 'public-lms' ),
					),
					'hierarchical' => false,
					'rewrite'      => array(
						'slug'       => 'course-tag',
						'with_front' => false,
					),
				)
			),
			self::LEVEL           => array_merge(
				$common,
				array(
					'labels'       => array(
						'name'          => _x( 'Levels', 'taxonomy general name', 'public-lms' ),
						'singular_name' => _x( 'Level', 'taxonomy singular name', 'public-lms' ),
						'menu_name'     => __( 'Levels', 'public-lms' ),
						'all_items'     => __( 'All Levels', 'public-lms' ),
						'edit_item'     => __( 'Edit Level', 'public-lms' ),
						'view_item'     => __( 'View Level', 'public-lms' ),
						'update_item'   => __( 'Update Level', 'public-lms' ),
						'add_new_item'  => __( 'Add New Level', 'public-lms' ),
						'new_item_name' => __( 'New Level Name', 'public-lms' ),
						'search_items'  => __( 'Search Levels', 'public-lms' ),
						'not_found'     => __( 'No levels found.', 'public-lms' ),
					),
					// Hierarchical only for the checkbox UI; levels are a flat list.
					'hierarchical' => true,
					'rewrite'      => array(
						'slug'       => 'course-level',
						'with_front' => false,
					),
				)
			),
		);
	}
}
