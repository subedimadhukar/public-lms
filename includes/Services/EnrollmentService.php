<?php
/**
 * Enrolment service.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Services;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Repositories\EventRepositoryInterface;
use PublicLMS\Repositories\ProgressRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Enrols and unenrols learners, with the side effects in one place:
 * the progress aggregate row, the event log and the public actions.
 */
class EnrollmentService {

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	private $enrollments;

	/**
	 * Progress.
	 *
	 * @var ProgressRepository
	 */
	private $progress;

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
	 * @param EnrollmentRepository               $enrollments Enrolments.
	 * @param ProgressRepository                 $progress    Progress.
	 * @param CourseStructureRepositoryInterface $structure   Course structure.
	 * @param EventRepositoryInterface           $events      Event log.
	 * @param AccessPolicy                       $policy      Access policy.
	 */
	public function __construct( EnrollmentRepository $enrollments, ProgressRepository $progress, CourseStructureRepositoryInterface $structure, EventRepositoryInterface $events, AccessPolicy $policy ) {
		$this->enrollments = $enrollments;
		$this->progress    = $progress;
		$this->structure   = $structure;
		$this->events      = $events;
		$this->policy      = $policy;
	}

	/**
	 * Enrols a user in a course on their own request, after the access policy agrees.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return int|WP_Error The enrolment ID, or an error carrying the policy's reason.
	 */
	public function self_enroll( int $user_id, int $course_id ) {
		$decision = $this->policy->can_enroll( $course_id, $user_id );
		if ( ! $decision->is_allowed() ) {
			return new WP_Error(
				'publiclms_cannot_enroll',
				$decision->message(),
				array(
					'status' => 'logged_out' === $decision->reason() ? 401 : 403,
					'reason' => $decision->reason(),
				)
			);
		}
		$id = $this->enroll( $user_id, $course_id, 'free' );
		if ( $id <= 0 ) {
			return new WP_Error( 'publiclms_enroll_failed', __( 'The enrolment could not be saved. Please try again.', 'public-lms' ), array( 'status' => 500 ) );
		}
		return $id;
	}

	/**
	 * Enrols a user without a policy check. Callers check capabilities first.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $course_id Course ID.
	 * @param string $source    free, manual, order, group or import.
	 * @param int    $source_id Order, group or import ID.
	 * @return int The enrolment ID, or 0 on failure.
	 */
	public function enroll( int $user_id, int $course_id, string $source = 'manual', int $source_id = 0 ): int {
		$was_enrolled = $this->enrollments->is_enrolled( $user_id, $course_id );
		$id           = $this->enrollments->enroll( $user_id, $course_id, $source, $source_id );
		if ( $id <= 0 ) {
			return 0;
		}

		$this->progress->refresh_course_progress( $user_id, $course_id, $this->structure->item_ids( $course_id ), 0, $id );

		if ( ! $was_enrolled ) {
			$this->events->record(
				'enrollment.created',
				array(
					'user_id'     => $user_id,
					'course_id'   => $course_id,
					'object_type' => 'enrollment',
					'object_id'   => $id,
					'payload'     => array( 'source' => $source ),
				)
			);

			/**
			 * Fires after a user is enrolled in a course.
			 *
			 * @param int    $user_id       User ID.
			 * @param int    $course_id     Course ID.
			 * @param int    $enrollment_id Enrolment row ID.
			 * @param string $source        free, manual, order, group or import.
			 */
			do_action( 'publiclms_enrolled', $user_id, $course_id, $id, $source );
		}
		return $id;
	}

	/**
	 * Cancels a user's enrolment, keeping their progress.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return bool Whether an enrolment changed.
	 */
	public function unenroll( int $user_id, int $course_id ): bool {
		$changed = $this->enrollments->unenroll( $user_id, $course_id );
		if ( $changed ) {
			$this->events->record(
				'enrollment.cancelled',
				array(
					'user_id'   => $user_id,
					'course_id' => $course_id,
				)
			);

			/**
			 * Fires after a user's enrolment in a course is cancelled.
			 *
			 * @param int $user_id   User ID.
			 * @param int $course_id Course ID.
			 */
			do_action( 'publiclms_unenrolled', $user_id, $course_id );
		}
		return $changed;
	}
}
