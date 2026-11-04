<?php
/**
 * Extra fields on the wp/v2 routes of the plugin's post types.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Admin\AdminApp;
use PublicLMS\Capabilities\Capabilities;
use PublicLMS\Courses\CourseFields;
use PublicLMS\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a read-only `publiclms` field to courses, lessons, quizzes and
 * assignments on wp/v2. The block editor reads it for the course panel and
 * for the `publiclms/course-field` bindings source, so the editor shows the
 * same values the front end renders.
 */
final class PostFields {

	/**
	 * Course fields.
	 *
	 * @var CourseFields
	 */
	private $fields;

	/**
	 * Constructor.
	 *
	 * @param CourseFields $fields Course fields.
	 */
	public function __construct( CourseFields $fields ) {
		$this->fields = $fields;
	}

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_fields' ) );
	}

	/**
	 * Registers the field. Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_fields(): void {
		register_rest_field(
			array( PostTypes::COURSE, PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT ),
			'publiclms',
			array(
				'get_callback' => array( $this, 'get_field' ),
				'schema'       => array(
					'description' => __( 'Public LMS course context: course ID and title, builder link and course field values.', 'public-lms' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'properties'  => array(
						'course_id'    => array( 'type' => 'integer' ),
						'course_title' => array( 'type' => 'string' ),
						'builder_url'  => array( 'type' => 'string' ),
						'fields'       => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Returns the field value.
	 *
	 * @param array<string, mixed> $post Prepared post data with an `id`.
	 * @return array<string, mixed>
	 */
	public function get_field( $post ): array {
		$post_id   = (int) ( $post['id'] ?? 0 );
		$course_id = $this->fields->resolve_course_id( $post_id );
		if ( $course_id <= 0 ) {
			return array(
				'course_id'    => 0,
				'course_title' => '',
				'builder_url'  => '',
				'fields'       => (object) array(),
			);
		}
		return array(
			'course_id'    => $course_id,
			'course_title' => wp_strip_all_tags( get_the_title( $course_id ) ),
			'builder_url'  => current_user_can( Capabilities::META_EDIT_COURSE, $course_id )
				? add_query_arg( 'course', $course_id, admin_url( 'admin.php?page=' . AdminApp::SLUG ) )
				: '',
			'fields'       => $this->fields->all( $course_id ),
		);
	}
}
