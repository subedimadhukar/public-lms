<?php
/**
 * Enrolment repository contract.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in plms_enrollments.
 *
 * One row per user and course (unique key user_course). This is the hot path
 * for every access check, so implementations cache by user in the object
 * cache group 'publiclms' and invalidate on write.
 */
interface EnrollmentRepositoryInterface extends RepositoryInterface {

	/**
	 * Finds a user's enrolment in a course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_user_and_course( int $user_id, int $course_id ): ?array;

	/**
	 * Lists a user's enrolments.
	 *
	 * @param int         $user_id User ID.
	 * @param string|null $status  Only rows with this status, or all when null.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_user( int $user_id, ?string $status = null ): array;

	/**
	 * Lists a course's enrolments with keyset pagination.
	 *
	 * @param int         $course_id Course ID.
	 * @param string|null $status    Only rows with this status, or all when null.
	 * @param int         $after_id  Return rows with an ID greater than this.
	 * @param int         $limit     Maximum rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_course( int $course_id, ?string $status = null, int $after_id = 0, int $limit = 50 ): array;

	/**
	 * Counts a course's enrolments.
	 *
	 * @param int         $course_id Course ID.
	 * @param string|null $status    Only rows with this status, or all when null.
	 * @return int
	 */
	public function count_by_course( int $course_id, ?string $status = null ): int;
}
