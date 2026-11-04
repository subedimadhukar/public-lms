<?php
/**
 * The single access policy.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Access;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Quizzes\QuizSettings;
use PublicLMS\Repositories\ProgressRepository;
use PublicLMS\Repositories\QuizAttemptRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Answers every learner-facing "can this user open this" question.
 *
 * REST controllers, blocks and templates all ask this class; none of them
 * reads enrolments, drip rules or prerequisites directly (ADR-004). Authoring
 * permissions are capabilities, not part of this policy (see Capabilities).
 *
 * The rule order for items is:
 *
 * 1. The user can edit the course: allow.
 * 2. The item, its section or its course is not published: deny (not_published).
 * 3. The item is marked as a free preview: allow.
 * 4. The user is logged out: deny (logged_out).
 * 5. No active enrolment: deny (not_enrolled); expired enrolment: deny (expired).
 * 6. A prerequisite course of the course, or a prerequisite item of the item, is
 *    incomplete: deny (prerequisite, naming what to complete).
 * 7. The drip time of the item or its section has not come: deny (drip, with
 *    available_at). Drip counts days from the enrolment date, or waits for a
 *    fixed date; when both apply the later one wins (ADR-040).
 * 8. Otherwise: allow.
 *
 * Enrolment also checks course prerequisites: a learner who has not completed
 * the required courses cannot enrol themselves.
 *
 * Every decision passes through the `publiclms_access_decision` filter so Pro
 * memberships and groups can grant access without changing this class.
 */
class AccessPolicy {

	/**
	 * Course structure.
	 *
	 * @var CourseStructureRepositoryInterface
	 */
	private $structure;

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	private $enrollments;

	/**
	 * Progress, for item prerequisites.
	 *
	 * @var ProgressRepository|null
	 */
	private $progress;

	/**
	 * Quiz attempts, for the attempts limit.
	 *
	 * @var QuizAttemptRepository|null
	 */
	private $attempts;

	/**
	 * Constructor.
	 *
	 * @param CourseStructureRepositoryInterface $structure   Course structure.
	 * @param EnrollmentRepository               $enrollments Enrolments.
	 * @param ProgressRepository|null            $progress    Progress; without it item prerequisites never block.
	 * @param QuizAttemptRepository|null         $attempts    Quiz attempts; without it the attempts limit never blocks.
	 */
	public function __construct( CourseStructureRepositoryInterface $structure, EnrollmentRepository $enrollments, ?ProgressRepository $progress = null, ?QuizAttemptRepository $attempts = null ) {
		$this->structure   = $structure;
		$this->enrollments = $enrollments;
		$this->progress    = $progress;
		$this->attempts    = $attempts;
	}

	/**
	 * Whether a user can see a course's landing page and curriculum outline.
	 *
	 * Published courses are public; drafts and private courses need edit rights.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID; 0 for the current user.
	 * @return bool
	 */
	public function can_view_course( int $course_id, int $user_id = 0 ): bool {
		$user_id = $this->resolve_user( $user_id );
		$course  = $this->course( $course_id );

		if ( null === $course ) {
			$decision = AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
		} elseif ( 'publish' === $course->post_status || $this->can_edit_course( $course_id, $user_id ) ) {
			$decision = AccessDecision::allow();
		} else {
			$decision = AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
		}

		return $this->filter( $decision, 'view_course', $course_id, $user_id )->is_allowed();
	}

	/**
	 * Whether a user may enrol themselves in a course now.
	 *
	 * Requires a logged-in user, a published course open for self-enrolment
	 * (access mode "free"), and no active enrolment. Paid courses are enrolled
	 * by the checkout in phase 2, never through this method.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_enroll( int $course_id, int $user_id = 0 ): AccessDecision {
		$user_id = $this->resolve_user( $user_id );
		$course  = $this->course( $course_id );

		if ( null === $course || 'publish' !== $course->post_status ) {
			$decision = AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
		} elseif ( $user_id <= 0 ) {
			$decision = AccessDecision::deny( AccessDecision::REASON_LOGGED_OUT );
		} elseif ( $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
			$decision = AccessDecision::deny( AccessDecision::REASON_ALREADY_ENROLLED );
		} elseif ( ! empty( $this->missing_course_prerequisites( $course_id, $user_id ) ) ) {
			$decision = AccessDecision::deny( AccessDecision::REASON_PREREQUISITE, null, $this->missing_course_prerequisites( $course_id, $user_id ) );
		} else {
			$mode = Meta::access_mode( $course_id );
			if ( 'closed' === $mode ) {
				$decision = AccessDecision::deny( AccessDecision::REASON_ENROLLMENT_CLOSED );
			} elseif ( 'paid' === $mode ) {
				$decision = AccessDecision::deny( AccessDecision::REASON_PAYMENT_REQUIRED );
			} else {
				$decision = AccessDecision::allow();
			}
		}

		return $this->filter( $decision, 'enroll', $course_id, $user_id );
	}

	/**
	 * Whether a user can open the content of a course (its lessons, quizzes and assignments).
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_access_course( int $course_id, int $user_id = 0 ): AccessDecision {
		$user_id = $this->resolve_user( $user_id );
		return $this->filter( $this->course_decision( $course_id, $user_id ), 'access_course', $course_id, $user_id );
	}

	/**
	 * Whether a user can open one lesson, quiz or assignment.
	 *
	 * Applies the full rule order in the class description.
	 *
	 * @param int $item_id Lesson, quiz or assignment ID.
	 * @param int $user_id User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_access_item( int $item_id, int $user_id = 0 ): AccessDecision {
		$user_id = $this->resolve_user( $user_id );
		return $this->filter( $this->item_decision( $item_id, $user_id ), 'access_item', $item_id, $user_id );
	}

	/**
	 * Whether a user can start a new attempt at a quiz.
	 *
	 * Applies can_access_item(), then the quiz's attempts limit: once the
	 * learner has submitted `max_attempts` attempts, a new one is refused
	 * (attempts_limit). An attempt already in progress can always be finished.
	 * Teachers are not limited.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @param int $user_id User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_attempt_quiz( int $quiz_id, int $user_id = 0 ): AccessDecision {
		$user_id  = $this->resolve_user( $user_id );
		$decision = PostTypes::QUIZ === get_post_type( $quiz_id )
			? $this->item_decision( $quiz_id, $user_id )
			: AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
		if ( $decision->is_allowed() && $user_id <= 0 ) {
			$decision = AccessDecision::deny( AccessDecision::REASON_LOGGED_OUT );
		}
		if ( $decision->is_allowed() && null !== $this->attempts ) {
			$course_id = $this->structure->course_id_for( $quiz_id );
			$max       = QuizSettings::get( $quiz_id )['max_attempts'];
			if ( $max > 0
				&& ! $this->can_edit_course( $course_id, $user_id )
				&& null === $this->attempts->in_progress( $user_id, $quiz_id )
				&& $this->attempts->count_finished( $user_id, $quiz_id ) >= $max ) {
				$decision = AccessDecision::deny( AccessDecision::REASON_ATTEMPTS_LIMIT );
			}
		}
		return $this->filter( $decision, 'attempt_quiz', $quiz_id, $user_id );
	}

	/**
	 * Whether a user can submit work for an assignment.
	 *
	 * @param int $assignment_id Assignment ID.
	 * @param int $user_id       User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_submit_assignment( int $assignment_id, int $user_id = 0 ): AccessDecision {
		$user_id  = $this->resolve_user( $user_id );
		$decision = PostTypes::ASSIGNMENT === get_post_type( $assignment_id )
			? $this->item_decision( $assignment_id, $user_id )
			: AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
		if ( $decision->is_allowed() && $user_id <= 0 ) {
			$decision = AccessDecision::deny( AccessDecision::REASON_LOGGED_OUT );
		}
		return $this->filter( $decision, 'submit_assignment', $assignment_id, $user_id );
	}

	/**
	 * Whether a user can download a learner's certificate for a course.
	 *
	 * The learner who completed the course (when the course gives
	 * certificates), or anyone who can view the course's reports.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User asking; 0 for the current user.
	 * @param int $owner_id  Learner whose certificate it is; 0 for the user asking.
	 * @return AccessDecision
	 */
	public function can_view_certificate( int $course_id, int $user_id = 0, int $owner_id = 0 ): AccessDecision {
		$user_id  = $this->resolve_user( $user_id );
		$owner_id = $owner_id > 0 ? $owner_id : $user_id;
		if ( $user_id <= 0 ) {
			$decision = AccessDecision::deny( AccessDecision::REASON_LOGGED_OUT );
		} elseif ( null === $this->course( $course_id ) ) {
			$decision = AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
		} elseif ( $owner_id !== $user_id ) {
			$decision = user_can( $user_id, Capabilities::META_VIEW_COURSE_REPORTS, $course_id )
				? AccessDecision::allow()
				: AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
		} else {
			$row      = $this->enrollments->find_by_user_and_course( $owner_id, $course_id );
			$decision = null !== $row && ! empty( $row['completed_at'] ) && Meta::issues_certificate( $course_id )
				? AccessDecision::allow()
				: AccessDecision::deny( AccessDecision::REASON_NOT_ENROLLED );
		}
		return $this->filter( $decision, 'view_certificate', $course_id, $user_id );
	}

	/**
	 * Applies rules 1 to 8 to an item, unfiltered.
	 *
	 * @param int $item_id Item ID.
	 * @param int $user_id User ID, already resolved.
	 * @return AccessDecision
	 */
	protected function item_decision( int $item_id, int $user_id ): AccessDecision {
		$item = get_post( $item_id );
		if ( ! $item instanceof WP_Post || ! in_array( $item->post_type, array( PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT ), true ) ) {
			return AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
		}

		$course_id = $this->structure->course_id_for( $item_id );
		if ( $course_id <= 0 ) {
			// Outside any course: only people who can edit the item see it.
			return $user_id > 0 && user_can( $user_id, 'edit_post', $item_id )
				? AccessDecision::allow()
				: AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
		}

		// 1. Teachers and managers.
		if ( $this->can_edit_course( $course_id, $user_id ) ) {
			return AccessDecision::allow();
		}

		// 2. Everything on the path must be published.
		$section_id = $this->structure->section_id_for( $item_id );
		if ( 'publish' !== $item->post_status
			|| 'publish' !== get_post_status( $section_id )
			|| 'publish' !== get_post_status( $course_id ) ) {
			return AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
		}

		// 3. Free preview.
		if ( (bool) get_post_meta( $item_id, Meta::PREVIEW, true ) ) {
			return AccessDecision::allow();
		}

		// 4 and 5. Logged in and enrolled.
		$enrolment = $this->enrolment_decision( $course_id, $user_id );
		if ( ! $enrolment->is_allowed() ) {
			return $enrolment;
		}

		// 6. Prerequisites.
		$prerequisite = $this->check_prerequisites( $item_id, $course_id, $user_id );
		if ( null !== $prerequisite ) {
			return $prerequisite;
		}

		// 7. Drip.
		$drip = $this->check_drip( $item_id, $course_id, $user_id );
		if ( null !== $drip ) {
			return $drip;
		}

		// 8.
		return AccessDecision::allow();
	}

	/**
	 * Applies the course-level rules, unfiltered.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID, already resolved.
	 * @return AccessDecision
	 */
	protected function course_decision( int $course_id, int $user_id ): AccessDecision {
		$course = $this->course( $course_id );
		if ( null === $course ) {
			return AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
		}
		if ( $this->can_edit_course( $course_id, $user_id ) ) {
			return AccessDecision::allow();
		}
		if ( 'publish' !== $course->post_status ) {
			return AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
		}
		return $this->enrolment_decision( $course_id, $user_id );
	}

	/**
	 * Rules 4 and 5: logged in, with an active enrolment that has not expired.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID, already resolved.
	 * @return AccessDecision
	 */
	protected function enrolment_decision( int $course_id, int $user_id ): AccessDecision {
		if ( $user_id <= 0 ) {
			return AccessDecision::deny( AccessDecision::REASON_LOGGED_OUT );
		}
		$row = $this->enrollments->find_by_user_and_course( $user_id, $course_id );
		if ( null === $row ) {
			return AccessDecision::deny( AccessDecision::REASON_NOT_ENROLLED );
		}
		if ( 'expired' === $row['status'] || ( 'active' === $row['status'] && $this->enrollments->is_expired( $row ) ) ) {
			return AccessDecision::deny( AccessDecision::REASON_EXPIRED );
		}
		if ( 'active' !== $row['status'] ) {
			return AccessDecision::deny( AccessDecision::REASON_NOT_ENROLLED );
		}
		return AccessDecision::allow();
	}

	/**
	 * Rule 6: prerequisites. The course's prerequisite courses must be completed,
	 * then the item's prerequisite items (published items of the same course;
	 * others are ignored so a deleted lesson never locks a course for good).
	 *
	 * @param int $item_id   Item ID.
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID.
	 * @return AccessDecision|null A denial, or null to continue.
	 */
	protected function check_prerequisites( int $item_id, int $course_id, int $user_id ): ?AccessDecision {
		$courses = $this->missing_course_prerequisites( $course_id, $user_id );
		if ( ! empty( $courses ) ) {
			return AccessDecision::deny( AccessDecision::REASON_PREREQUISITE, null, $courses );
		}
		$items = $this->missing_item_prerequisites( $item_id, $course_id, $user_id );
		if ( ! empty( $items ) ) {
			return AccessDecision::deny( AccessDecision::REASON_PREREQUISITE, null, $items );
		}
		return null;
	}

	/**
	 * Rule 7: drip. Denies until the item's unlock time.
	 *
	 * @param int $item_id   Item ID.
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID.
	 * @return AccessDecision|null A denial, or null to continue.
	 */
	protected function check_drip( int $item_id, int $course_id, int $user_id ): ?AccessDecision {
		$unlock = $this->unlock_time( $item_id, $course_id, $user_id );
		if ( null !== $unlock && $unlock > $this->now() ) {
			return AccessDecision::deny( AccessDecision::REASON_DRIP, $unlock );
		}
		return null;
	}

	/**
	 * Returns the prerequisite courses of a course the user has not completed.
	 *
	 * Only published courses count; a course listed as its own prerequisite is ignored.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID.
	 * @return array<int, int>
	 */
	public function missing_course_prerequisites( int $course_id, int $user_id ): array {
		$missing = array();
		foreach ( Meta::id_list( $course_id, Meta::PREREQUISITES ) as $required ) {
			if ( $required === $course_id || PostTypes::COURSE !== get_post_type( $required ) || 'publish' !== get_post_status( $required ) ) {
				continue;
			}
			$row = $user_id > 0 ? $this->enrollments->find_by_user_and_course( $user_id, $required ) : null;
			if ( null === $row || empty( $row['completed_at'] ) ) {
				$missing[] = $required;
			}
		}
		return $missing;
	}

	/**
	 * Returns the prerequisite items of an item the user has not completed.
	 *
	 * @param int $item_id   Item ID.
	 * @param int $course_id The item's course.
	 * @param int $user_id   User ID.
	 * @return array<int, int>
	 */
	public function missing_item_prerequisites( int $item_id, int $course_id, int $user_id ): array {
		$required = Meta::id_list( $item_id, Meta::PREREQUISITES );
		if ( empty( $required ) ) {
			return array();
		}
		$required = array_values( array_intersect( $required, $this->structure->item_ids( $course_id ) ) );
		$required = array_values( array_diff( $required, array( $item_id ) ) );
		$done     = null === $this->progress || $user_id <= 0 ? array() : $this->progress->completed_item_ids( $user_id, $course_id );
		return array_values( array_diff( $required, $done ) );
	}

	/**
	 * Returns when an item opens for a learner under its drip rules, or null when it has none.
	 *
	 * The item's and its section's rules all apply, so the latest time wins.
	 * Day counts run from the enrolment date; without an enrolment they run
	 * from now, so the item reads as locked for that many days.
	 *
	 * @param int $item_id   Item ID.
	 * @param int $course_id The item's course.
	 * @param int $user_id   User ID.
	 * @return int|null Unix timestamp, or null.
	 */
	public function unlock_time( int $item_id, int $course_id, int $user_id ): ?int {
		$enrolment = $user_id > 0 ? $this->enrollments->find_by_user_and_course( $user_id, $course_id ) : null;
		$start     = null !== $enrolment && ! empty( $enrolment['enrolled_at'] ) ? strtotime( $enrolment['enrolled_at'] . ' UTC' ) : false;
		$start     = false === $start ? $this->now() : $start;

		$times = array();
		foreach ( array( $this->structure->section_id_for( $item_id ), $item_id ) as $post_id ) {
			if ( $post_id <= 0 ) {
				continue;
			}
			$drip = Meta::drip( $post_id );
			if ( $drip['days'] > 0 ) {
				$times[] = $start + $drip['days'] * DAY_IN_SECONDS;
			}
			if ( '' !== $drip['date'] ) {
				$date = strtotime( $drip['date'] . ' UTC' );
				if ( false !== $date ) {
					$times[] = $date;
				}
			}
		}
		return empty( $times ) ? null : (int) max( $times );
	}

	/**
	 * Returns the current Unix time. A seam for tests and for Pro's time-travel previews.
	 *
	 * @return int
	 */
	protected function now(): int {
		return time();
	}

	/**
	 * Whether a user can edit a course (teaches it or manages courses).
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID.
	 * @return bool
	 */
	protected function can_edit_course( int $course_id, int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, Capabilities::META_EDIT_COURSE, $course_id );
	}

	/**
	 * Returns the course post, or null.
	 *
	 * @param int $course_id Course ID.
	 * @return WP_Post|null
	 */
	protected function course( int $course_id ): ?WP_Post {
		$course = $course_id > 0 ? get_post( $course_id ) : null;
		return $course instanceof WP_Post && PostTypes::COURSE === $course->post_type && 'trash' !== $course->post_status ? $course : null;
	}

	/**
	 * Resolves 0 to the current user ID.
	 *
	 * @param int $user_id User ID or 0.
	 * @return int
	 */
	protected function resolve_user( int $user_id ): int {
		return $user_id > 0 ? $user_id : get_current_user_id();
	}

	/**
	 * Runs a decision through the `publiclms_access_decision` filter.
	 *
	 * @param AccessDecision $decision  Decision reached by the policy.
	 * @param string         $action    What is being checked, for example 'access_item'.
	 * @param int            $object_id Course or item ID.
	 * @param int            $user_id   User ID.
	 * @return AccessDecision
	 */
	protected function filter( AccessDecision $decision, string $action, int $object_id, int $user_id ): AccessDecision {
		/**
		 * Filters an access decision.
		 *
		 * @param AccessDecision $decision  Decision reached by the policy.
		 * @param string         $action    What is being checked: view_course, enroll, access_course,
		 *                                  access_item, attempt_quiz, submit_assignment or view_certificate.
		 * @param int            $object_id Course or item ID.
		 * @param int            $user_id   User ID.
		 */
		$filtered = apply_filters( 'publiclms_access_decision', $decision, $action, $object_id, $user_id );

		return $filtered instanceof AccessDecision ? $filtered : $decision;
	}
}
