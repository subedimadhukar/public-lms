<?php
/**
 * Progress service.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Services;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Repositories\CourseStructureRepository;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Repositories\EventRepositoryInterface;
use PublicLMS\Repositories\ProgressRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Marks items complete and answers "where is this learner in this course".
 *
 * Summaries are computed from the cached structure and the cached item rows,
 * so they reflect lessons added or removed since the stored aggregate was
 * written; the aggregate table stays the source for reports and sorting.
 */
class ProgressService {

	/**
	 * Progress.
	 *
	 * @var ProgressRepository
	 */
	private $progress;

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	private $enrollments;

	/**
	 * Course structure.
	 *
	 * @var CourseStructureRepositoryInterface
	 */
	private $structure;

	/**
	 * Event log.
	 *
	 * @var EventRepositoryInterface
	 */
	private $events;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private $policy;

	/**
	 * Constructor.
	 *
	 * @param ProgressRepository                 $progress    Progress.
	 * @param EnrollmentRepository               $enrollments Enrolments.
	 * @param CourseStructureRepositoryInterface $structure   Course structure.
	 * @param EventRepositoryInterface           $events      Event log.
	 * @param AccessPolicy                       $policy      Access policy.
	 */
	public function __construct( ProgressRepository $progress, EnrollmentRepository $enrollments, CourseStructureRepositoryInterface $structure, EventRepositoryInterface $events, AccessPolicy $policy ) {
		$this->progress    = $progress;
		$this->enrollments = $enrollments;
		$this->structure   = $structure;
		$this->events      = $events;
		$this->policy      = $policy;
	}

	/**
	 * Marks an item complete for an enrolled learner who can open it.
	 *
	 * Idempotent: completing twice changes nothing and fires nothing twice.
	 *
	 * @param int $user_id User ID.
	 * @param int $item_id Lesson, quiz or assignment ID.
	 * @return array<string, mixed>|WP_Error The course summary plus item_id and next_item, or an error.
	 */
	public function complete_item( int $user_id, int $item_id ) {
		$course_id = $this->check_item( $user_id, $item_id );
		if ( is_wp_error( $course_id ) ) {
			return $course_id;
		}

		$already      = in_array( $item_id, $this->progress->completed_item_ids( $user_id, $course_id ), true );
		$before       = $this->progress->course_progress( $user_id, $course_id );
		$was_complete = null !== $before && ! empty( $before['completed_at'] );
		$type         = $this->item_type( $item_id );

		$this->progress->upsert_item( $user_id, $course_id, $item_id, $type, ProgressRepository::STATUS_COMPLETED );
		$enrolment = $this->enrollments->find_by_user_and_course( $user_id, $course_id );
		$row       = $this->progress->refresh_course_progress( $user_id, $course_id, $this->structure->item_ids( $course_id ), $item_id, null === $enrolment ? 0 : (int) $enrolment['id'] );

		if ( ! $already ) {
			$this->events->record(
				'item.completed',
				array(
					'user_id'     => $user_id,
					'course_id'   => $course_id,
					'object_type' => $type,
					'object_id'   => $item_id,
				)
			);

			/**
			 * Fires the first time a learner completes a lesson, quiz or assignment.
			 *
			 * @param int    $user_id   User ID.
			 * @param int    $item_id   Item ID.
			 * @param int    $course_id Course ID.
			 * @param string $type      lesson, quiz or assignment.
			 */
			do_action( 'publiclms_item_completed', $user_id, $item_id, $course_id, $type );
		}

		if ( ! $was_complete && ! empty( $row['completed_at'] ) ) {
			$this->enrollments->set_completed( $user_id, $course_id, (string) $row['completed_at'] );
			$this->events->record(
				'course.completed',
				array(
					'user_id'     => $user_id,
					'course_id'   => $course_id,
					'object_type' => 'course',
					'object_id'   => $course_id,
				)
			);

			/**
			 * Fires when a learner completes every item of a course.
			 *
			 * @param int $user_id   User ID.
			 * @param int $course_id Course ID.
			 */
			do_action( 'publiclms_course_completed', $user_id, $course_id );
		}

		return $this->result( $user_id, $course_id, $item_id, true );
	}

	/**
	 * Removes a learner's completion of an item ("mark incomplete").
	 *
	 * @param int $user_id User ID.
	 * @param int $item_id Item ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function uncomplete_item( int $user_id, int $item_id ) {
		$course_id = $this->check_item( $user_id, $item_id );
		if ( is_wp_error( $course_id ) ) {
			return $course_id;
		}
		$this->progress->reset_item( $user_id, $item_id );
		$row = $this->progress->refresh_course_progress( $user_id, $course_id, $this->structure->item_ids( $course_id ) );
		if ( empty( $row['completed_at'] ) ) {
			$this->enrollments->set_completed( $user_id, $course_id, null );
		}
		return $this->result( $user_id, $course_id, $item_id, false );
	}

	/**
	 * Returns where a learner is in a course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array{course_id: int, percent: int, items_completed: int, items_total: int, completed: bool, completed_items: array<int, int>, resume_item: int}
	 */
	public function summary( int $user_id, int $course_id ): array {
		$item_ids  = $this->structure->item_ids( $course_id );
		$completed = array_values( array_intersect( $item_ids, $this->progress->completed_item_ids( $user_id, $course_id ) ) );
		$total     = count( $item_ids );
		$done      = count( $completed );

		return array(
			'course_id'       => $course_id,
			'percent'         => ProgressRepository::percent( $done, $total ),
			'items_completed' => $done,
			'items_total'     => $total,
			'completed'       => $total > 0 && $done === $total,
			'completed_items' => array_map( 'intval', $completed ),
			'resume_item'     => $this->first_incomplete( $item_ids, $completed, 0 ),
		);
	}

	/**
	 * Returns the next item to study after an item: the first incomplete one after it,
	 * then the first incomplete one from the start, or 0 when everything is complete.
	 *
	 * @param int $user_id User ID.
	 * @param int $item_id Current item ID.
	 * @return int
	 */
	public function next_item_after( int $user_id, int $item_id ): int {
		$course_id = $this->structure->course_id_for( $item_id );
		$item_ids  = $this->structure->item_ids( $course_id );
		$completed = $this->progress->completed_item_ids( $user_id, $course_id );
		return $this->first_incomplete( $item_ids, $completed, $item_id );
	}

	/**
	 * Returns the ID, title and URL of an item for responses and Interactivity state.
	 *
	 * @param int $item_id Item ID, or 0.
	 * @return array{id: int, title: string, url: string}|null
	 */
	public function item_link( int $item_id ): ?array {
		if ( $item_id <= 0 ) {
			return null;
		}
		return array(
			'id'    => $item_id,
			'title' => wp_strip_all_tags( get_the_title( $item_id ) ),
			'url'   => (string) get_permalink( $item_id ),
		);
	}

	/**
	 * Checks that an item is in a course, the learner can open it, and is enrolled.
	 *
	 * @param int $user_id User ID.
	 * @param int $item_id Item ID.
	 * @return int|WP_Error The course ID, or an error.
	 */
	private function check_item( int $user_id, int $item_id ) {
		$course_id = $this->structure->course_id_for( $item_id );
		if ( $course_id <= 0 ) {
			return new WP_Error( 'publiclms_item_not_found', __( 'This lesson is not part of a course.', 'public-lms' ), array( 'status' => 404 ) );
		}
		$decision = $this->policy->can_access_item( $item_id, $user_id );
		if ( ! $decision->is_allowed() ) {
			return new WP_Error(
				'publiclms_item_locked',
				$decision->message(),
				array(
					'status' => 403,
					'reason' => $decision->reason(),
				)
			);
		}
		if ( ! $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			return new WP_Error(
				'publiclms_not_enrolled',
				__( 'Enrol in this course to save your progress.', 'public-lms' ),
				array(
					'status' => 403,
					'reason' => 'not_enrolled',
				)
			);
		}
		return $course_id;
	}

	/**
	 * Builds the response after a completion change.
	 *
	 * @param int  $user_id   User ID.
	 * @param int  $course_id Course ID.
	 * @param int  $item_id   Item ID.
	 * @param bool $completed Whether the item is now complete.
	 * @return array<string, mixed>
	 */
	private function result( int $user_id, int $course_id, int $item_id, bool $completed ): array {
		$summary = $this->summary( $user_id, $course_id );
		$next    = $completed ? $this->next_item_after( $user_id, $item_id ) : $this->structure->adjacent_items( $item_id )['next'];

		return array_merge(
			$summary,
			array(
				'item_id'        => $item_id,
				'item_completed' => $completed,
				'next_item'      => $this->item_link( $next ),
				'course_url'     => (string) get_permalink( $course_id ),
			)
		);
	}

	/**
	 * Returns the first incomplete item after a position, wrapping to the start.
	 *
	 * @param array<int, int> $item_ids  Items in order.
	 * @param array<int, int> $completed Completed item IDs.
	 * @param int             $after_id  Start after this item; 0 to start at the beginning.
	 * @return int
	 */
	private function first_incomplete( array $item_ids, array $completed, int $after_id ): int {
		$start = 0;
		if ( $after_id > 0 ) {
			$position = array_search( $after_id, $item_ids, true );
			$start    = false === $position ? 0 : $position + 1;
		}
		$ordered = array_merge( array_slice( $item_ids, $start ), array_slice( $item_ids, 0, $start ) );
		foreach ( $ordered as $id ) {
			if ( ! in_array( $id, $completed, true ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Returns 'lesson', 'quiz' or 'assignment' for an item.
	 *
	 * @param int $item_id Item ID.
	 * @return string
	 */
	private function item_type( int $item_id ): string {
		$post_type = (string) get_post_type( $item_id );
		$type      = array_search( $post_type, CourseStructureRepository::ITEM_TYPES, true );
		return false === $type ? 'lesson' : (string) $type;
	}
}
