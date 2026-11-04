<?php
/**
 * Capabilities and meta-capability mapping.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Capabilities;

use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the plugin's capabilities and maps its meta capabilities.
 *
 * Code checks capabilities, never roles (ADR-005), so agencies can compose
 * roles freely. Primitive capabilities are granted to roles; meta
 * capabilities take an object ID and resolve to primitives in map_meta_cap().
 *
 * Usage: current_user_can( Capabilities::META_EDIT_COURSE, $course_id ).
 */
final class Capabilities {

	/** Create and edit any course and its content; manage taxonomies and certificates. */
	const MANAGE_COURSES = 'publiclms_manage_courses';

	/** Create, publish and edit courses and content the user authored. */
	const EDIT_OWN_COURSE = 'publiclms_edit_own_course';

	/** View progress, quiz and enrolment reports (own courses unless combined with MANAGE_COURSES). */
	const VIEW_REPORTS = 'publiclms_view_reports';

	/** View and manage orders, transactions, coupons and memberships. */
	const MANAGE_ORDERS = 'publiclms_manage_orders';

	/** Enrol and unenrol learners manually (own courses unless combined with MANAGE_COURSES). */
	const ENROLL_STUDENTS = 'publiclms_enroll_students';

	/**
	 * Every primitive capability the plugin defines.
	 */
	const ALL = array(
		self::MANAGE_COURSES,
		self::EDIT_OWN_COURSE,
		self::VIEW_REPORTS,
		self::MANAGE_ORDERS,
		self::ENROLL_STUDENTS,
	);

	/** Meta capability: edit one course. Argument: course ID. */
	const META_EDIT_COURSE = 'publiclms_edit_course';

	/** Meta capability: view reports. Argument: course ID, or none for site-wide reports. */
	const META_VIEW_COURSE_REPORTS = 'publiclms_view_course_reports';

	/** Meta capability: enrol a learner in one course. Argument: course ID. */
	const META_ENROLL_STUDENT = 'publiclms_enroll_student';

	/** Meta capability: manage one order. Argument: order ID. */
	const META_MANAGE_ORDER = 'publiclms_manage_order';

	/**
	 * Course structure, used to find the course a section or item belongs to.
	 *
	 * @var CourseStructureRepositoryInterface|null
	 */
	private $structure;

	/**
	 * Constructor.
	 *
	 * @param CourseStructureRepositoryInterface|null $structure Course structure; without it sections and
	 *                                                           items follow WordPress's author mapping.
	 */
	public function __construct( ?CourseStructureRepositoryInterface $structure = null ) {
		$this->structure = $structure;
	}

	/**
	 * Hooks the meta-capability map.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Resolves the plugin's meta capabilities to primitive capabilities.
	 *
	 * @param array<int, string> $caps    Primitive capabilities required so far.
	 * @param string             $cap     Capability being checked.
	 * @param int                $user_id User ID.
	 * @param array<int, mixed>  $args    Extra arguments; the first is the object ID.
	 * @return array<int, string>
	 */
	public function map_meta_cap( $caps, $cap, $user_id, $args ): array {
		$caps    = (array) $caps;
		$handled = array( self::META_EDIT_COURSE, self::META_VIEW_COURSE_REPORTS, self::META_ENROLL_STUDENT, self::META_MANAGE_ORDER, 'edit_post', 'delete_post' );
		if ( ! in_array( $cap, $handled, true ) ) {
			return $caps;
		}
		$user_id = (int) $user_id;
		// Core passes objects for some capabilities (edit_block_binding gets a WP_Block_Editor_Context).
		$object_id = isset( $args[0] ) && is_scalar( $args[0] ) ? absint( $args[0] ) : 0;

		switch ( $cap ) {
			case self::META_EDIT_COURSE:
				if ( ! $this->is_course( $object_id ) ) {
					return array( 'do_not_allow' );
				}
				if ( $this->user_teaches_course( $user_id, $object_id ) ) {
					return array( self::EDIT_OWN_COURSE );
				}
				return map_meta_cap( 'edit_post', $user_id, $object_id );

			case self::META_VIEW_COURSE_REPORTS:
				if ( 0 === $object_id ) {
					return array( self::VIEW_REPORTS, self::MANAGE_COURSES );
				}
				if ( ! $this->is_course( $object_id ) ) {
					return array( 'do_not_allow' );
				}
				if ( $this->user_teaches_course( $user_id, $object_id ) ) {
					return array( self::VIEW_REPORTS );
				}
				return array( self::VIEW_REPORTS, self::MANAGE_COURSES );

			case self::META_ENROLL_STUDENT:
				if ( ! $this->is_course( $object_id ) ) {
					return array( 'do_not_allow' );
				}
				if ( $this->user_teaches_course( $user_id, $object_id ) ) {
					return array( self::ENROLL_STUDENTS );
				}
				return array( self::ENROLL_STUDENTS, self::MANAGE_COURSES );

			case self::META_MANAGE_ORDER:
				return array( self::MANAGE_ORDERS );

			case 'edit_post':
			case 'delete_post':
				$course_id = $this->course_of_structure_post( $object_id );
				if ( $course_id > 0 ) {
					// ADR-025: a section or item belongs to whoever teaches its course.
					return $this->user_teaches_course( $user_id, $course_id )
						? array( self::EDIT_OWN_COURSE )
						: array( self::MANAGE_COURSES );
				}
				break;
		}

		return $caps;
	}

	/**
	 * Returns the course of a section, lesson, quiz or assignment, or 0 for anything else.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private function course_of_structure_post( int $post_id ): int {
		if ( null === $this->structure || $post_id <= 0 ) {
			return 0;
		}
		$type = get_post_type( $post_id );
		if ( ! in_array( $type, array( PostTypes::SECTION, PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT ), true ) ) {
			return 0;
		}
		return $this->structure->course_id_for( $post_id );
	}

	/**
	 * Whether an ID belongs to a course.
	 *
	 * @param int $course_id Post ID.
	 * @return bool
	 */
	private function is_course( int $course_id ): bool {
		return $course_id > 0 && PostTypes::COURSE === get_post_type( $course_id );
	}

	/**
	 * Whether a user authored or co-teaches a course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return bool
	 */
	private function user_teaches_course( int $user_id, int $course_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$course    = get_post( $course_id );
		$is_author = $course instanceof \WP_Post && (int) $course->post_author === $user_id;

		/**
		 * Filters whether a user teaches a course. Pro adds co-instructors here.
		 *
		 * @param bool $teaches   Whether the user is the course author.
		 * @param int  $user_id   User ID.
		 * @param int  $course_id Course ID.
		 */
		return (bool) apply_filters( 'publiclms_user_teaches_course', $is_author, $user_id, $course_id );
	}
}
