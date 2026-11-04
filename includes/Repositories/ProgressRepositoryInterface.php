<?php
/**
 * Progress repository contract.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes plms_progress (per item) and plms_course_progress (per course).
 *
 * Writes are idempotent upserts on the unique keys user_item and user_course,
 * so a repeated "mark complete" never creates duplicate rows (ADR-002).
 */
interface ProgressRepositoryInterface {

	/**
	 * Records progress on one lesson, quiz or assignment.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $course_id Course ID.
	 * @param int    $item_id   Lesson, quiz or assignment ID.
	 * @param string $item_type 'lesson', 'quiz' or 'assignment'.
	 * @param string $status    'in_progress' or 'completed'.
	 * @return bool Whether the row was written.
	 */
	public function upsert_item( int $user_id, int $course_id, int $item_id, string $item_type, string $status ): bool;

	/**
	 * Returns the per-item rows for a user in a course, keyed by item ID.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function items_for_course( int $user_id, int $course_id ): array;

	/**
	 * Returns the denormalised course progress row.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array<string, mixed>|null
	 */
	public function course_progress( int $user_id, int $course_id ): ?array;

	/**
	 * Recalculates and stores the course progress aggregate.
	 *
	 * Only completed rows whose item is still in the course count, so a
	 * deleted lesson never pushes the percentage past 100.
	 *
	 * @param int             $user_id       User ID.
	 * @param int             $course_id     Course ID.
	 * @param array<int, int> $item_ids      The course's current completable items.
	 * @param int             $last_item_id  The item just worked on, or 0 to keep the stored one.
	 * @param int             $enrollment_id The enrolment the aggregate belongs to, or 0.
	 * @return array<string, mixed> The stored aggregate.
	 */
	public function refresh_course_progress( int $user_id, int $course_id, array $item_ids, int $last_item_id = 0, int $enrollment_id = 0 ): array;
}
