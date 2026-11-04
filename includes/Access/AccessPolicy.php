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
 * 6. A prerequisite course or item is incomplete: deny (prerequisite). Phase 2; no-op today.
 * 7. Drip schedule not reached: deny (drip, with available_at). Phase 2; no-op today.
 * 8. Otherwise: allow.
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
	 * Constructor.
	 *
	 * @param CourseStructureRepositoryInterface $structure   Course structure.
	 * @param EnrollmentRepository               $enrollments Enrolments.
	 */
	public function __construct( CourseStructureRepositoryInterface $structure, EnrollmentRepository $enrollments ) {
		$this->structure   = $structure;
		$this->enrollments = $enrollments;
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
	 * Whether a user can start or submit an attempt at a quiz.
	 *
	 * Phase 1 applies can_access_item(); phase 2 adds the attempts limit and
	 * retake cool-down.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @param int $user_id User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_attempt_quiz( int $quiz_id, int $user_id = 0 ): AccessDecision {
		$user_id = $this->resolve_user( $user_id );
		return $this->filter( $this->item_decision( $quiz_id, $user_id ), 'attempt_quiz', $quiz_id, $user_id );
	}

	/**
	 * Whether a user can submit work for an assignment.
	 *
	 * @param int $assignment_id Assignment ID.
	 * @param int $user_id       User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_submit_assignment( int $assignment_id, int $user_id = 0 ): AccessDecision {
		$user_id = $this->resolve_user( $user_id );
		return $this->filter( $this->item_decision( $assignment_id, $user_id ), 'submit_assignment', $assignment_id, $user_id );
	}

	/**
	 * Whether a user can download the certificate for a completed course.
	 *
	 * Phase 2: the learner who completed the course, or anyone who can view
	 * the course's reports. Until certificates exist this denies everyone but
	 * report viewers.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_view_certificate( int $course_id, int $user_id = 0 ): AccessDecision {
		$user_id  = $this->resolve_user( $user_id );
		$decision = $user_id > 0 && user_can( $user_id, Capabilities::META_VIEW_COURSE_REPORTS, $course_id )
			? AccessDecision::allow()
			: AccessDecision::deny( AccessDecision::REASON_NOT_PUBLISHED );
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

		// 6. Prerequisites (phase 2).
		$prerequisite = $this->check_prerequisites( $item_id, $course_id, $user_id );
		if ( null !== $prerequisite ) {
			return $prerequisite;
		}

		// 7. Drip (phase 2).
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
	 * Rule 6: prerequisites. Documented no-op until phase 2, which reads the
	 * course's prerequisite courses and the item's required earlier items.
	 *
	 * @param int $item_id   Item ID.
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID.
	 * @return AccessDecision|null A denial, or null to continue.
	 */
	protected function check_prerequisites( int $item_id, int $course_id, int $user_id ): ?AccessDecision { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Phase 2 hook point; the signature is fixed for subclasses.
		return null;
	}

	/**
	 * Rule 7: drip. Documented no-op until phase 2, which opens items a number
	 * of days after enrolment or on a date and returns the unlock time.
	 *
	 * @param int $item_id   Item ID.
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID.
	 * @return AccessDecision|null A denial, or null to continue.
	 */
	protected function check_drip( int $item_id, int $course_id, int $user_id ): ?AccessDecision { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Phase 2 hook point; the signature is fixed for subclasses.
		return null;
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
