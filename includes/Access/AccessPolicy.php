<?php
/**
 * The single access policy.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Answers every learner-facing "can this user open this" question.
 *
 * REST controllers, blocks and templates all ask this class; none of them
 * reads enrolments, drip rules or prerequisites directly (ADR-004). Authoring
 * permissions are capabilities, not part of this policy (see Capabilities).
 *
 * Phase 0 stub: every method returns a permissive default so the scaffold can
 * be exercised. Phase 1 implements enrolment and preview; phase 2 adds drip,
 * prerequisites, expiry and attempt limits. The rule order for items is:
 *
 * 1. The user can edit the course: allow.
 * 2. The item is not published: deny (not_published).
 * 3. The item is marked as a free preview: allow.
 * 4. The user is logged out: deny (logged_out).
 * 5. No active enrolment: deny (not_enrolled); expired enrolment: deny (expired).
 * 6. A prerequisite course or item is incomplete: deny (prerequisite).
 * 7. Drip schedule not reached: deny (drip, with available_at).
 * 8. Otherwise: allow.
 *
 * Every decision passes through the `publiclms_access_decision` filter so Pro
 * memberships and groups can grant access without changing this class.
 */
class AccessPolicy {

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
		return $this->filter( AccessDecision::allow(), 'view_course', $course_id, $this->resolve_user( $user_id ) )->is_allowed();
	}

	/**
	 * Whether a user may enrol in a course now.
	 *
	 * Phase 1: requires a logged-in user, a published course that is open for
	 * enrolment and has seats left, and no existing active enrolment. Paid
	 * courses are enrolled by the checkout, never through this method alone.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_enroll( int $course_id, int $user_id = 0 ): AccessDecision {
		return $this->filter( AccessDecision::allow(), 'enroll', $course_id, $this->resolve_user( $user_id ) );
	}

	/**
	 * Whether a user can open the content of a course (its lessons, quizzes and assignments).
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_access_course( int $course_id, int $user_id = 0 ): AccessDecision {
		return $this->filter( AccessDecision::allow(), 'access_course', $course_id, $this->resolve_user( $user_id ) );
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
		return $this->filter( AccessDecision::allow(), 'access_item', $item_id, $this->resolve_user( $user_id ) );
	}

	/**
	 * Whether a user can start or submit an attempt at a quiz.
	 *
	 * Adds the attempts limit and retake cool-down to can_access_item().
	 *
	 * @param int $quiz_id Quiz ID.
	 * @param int $user_id User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_attempt_quiz( int $quiz_id, int $user_id = 0 ): AccessDecision {
		return $this->filter( AccessDecision::allow(), 'attempt_quiz', $quiz_id, $this->resolve_user( $user_id ) );
	}

	/**
	 * Whether a user can submit work for an assignment.
	 *
	 * @param int $assignment_id Assignment ID.
	 * @param int $user_id       User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_submit_assignment( int $assignment_id, int $user_id = 0 ): AccessDecision {
		return $this->filter( AccessDecision::allow(), 'submit_assignment', $assignment_id, $this->resolve_user( $user_id ) );
	}

	/**
	 * Whether a user can download the certificate for a completed course.
	 *
	 * Phase 2: the learner who completed the course, or anyone who can view
	 * the course's reports.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID; 0 for the current user.
	 * @return AccessDecision
	 */
	public function can_view_certificate( int $course_id, int $user_id = 0 ): AccessDecision {
		return $this->filter( AccessDecision::allow(), 'view_certificate', $course_id, $this->resolve_user( $user_id ) );
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
