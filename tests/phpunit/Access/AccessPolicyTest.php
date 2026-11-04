<?php
/**
 * Tests for the access policy.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Access;

use PublicLMS\Access\AccessDecision;
use PublicLMS\Access\AccessPolicy;
use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Services\ProgressService;
use PublicLMS\Tests\TestCase;

/**
 * @coversDefaultClass \PublicLMS\Access\AccessPolicy
 */
class AccessPolicyTest extends TestCase {

	/**
	 * Policy under test.
	 *
	 * @var AccessPolicy
	 */
	private $policy;

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	private $enrollments;

	/**
	 * Instructor who owns the fixture course.
	 *
	 * @var int
	 */
	private $instructor;

	/**
	 * Fixture.
	 *
	 * @var array{course: int, sections: array<int, int>, lessons: array<int, int>}
	 */
	private $fixture;

	public function set_up() {
		parent::set_up();
		$this->policy      = publiclms()->container()->get( AccessPolicy::class );
		$this->enrollments = publiclms()->container()->get( EnrollmentRepository::class );
		$this->instructor  = self::factory()->user->create( array( 'role' => 'plms_instructor' ) );
		$this->fixture     = $this->create_course_with_lessons( array( 'post_author' => $this->instructor ) );
	}

	/**
	 * @covers ::can_view_course
	 */
	public function test_published_courses_are_public_and_drafts_need_edit_rights() {
		$draft   = $this->create_course(
			array(
				'post_status' => 'draft',
				'post_author' => $this->instructor,
			)
		);
		$student = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertTrue( $this->policy->can_view_course( $this->fixture['course'] ) );
		$this->assertFalse( $this->policy->can_view_course( $draft ) );
		$this->assertFalse( $this->policy->can_view_course( $draft, $student ) );
		$this->assertTrue( $this->policy->can_view_course( $draft, $this->instructor ) );
		$this->assertFalse( $this->policy->can_view_course( $this->fixture['lessons'][0] ) );
		$this->assertFalse( $this->policy->can_view_course( 0 ) );
	}

	/**
	 * @covers ::can_enroll
	 */
	public function test_can_enroll_rules() {
		$student = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$course  = $this->fixture['course'];

		$this->assertSame( AccessDecision::REASON_LOGGED_OUT, $this->policy->can_enroll( $course )->reason() );
		$this->assertTrue( $this->policy->can_enroll( $course, $student )->is_allowed() );

		$this->enrollments->enroll( $student, $course, 'free' );
		$this->assertSame( AccessDecision::REASON_ALREADY_ENROLLED, $this->policy->can_enroll( $course, $student )->reason() );

		$other = self::factory()->user->create();
		update_post_meta( $course, Meta::ACCESS_MODE, 'closed' );
		$this->assertSame( AccessDecision::REASON_ENROLLMENT_CLOSED, $this->policy->can_enroll( $course, $other )->reason() );
		update_post_meta( $course, Meta::ACCESS_MODE, 'paid' );
		$this->assertSame( AccessDecision::REASON_PAYMENT_REQUIRED, $this->policy->can_enroll( $course, $other )->reason() );

		$draft = $this->create_course( array( 'post_status' => 'draft' ) );
		$this->assertSame( AccessDecision::REASON_NOT_PUBLISHED, $this->policy->can_enroll( $draft, $other )->reason() );
		$this->assertSame( AccessDecision::REASON_NOT_PUBLISHED, $this->policy->can_enroll( $this->fixture['lessons'][0], $other )->reason() );
	}

	/**
	 * @covers ::can_access_item
	 * @covers ::can_access_course
	 */
	public function test_items_need_login_and_an_active_enrolment() {
		$lesson  = $this->fixture['lessons'][1];
		$student = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( AccessDecision::REASON_LOGGED_OUT, $this->policy->can_access_item( $lesson )->reason() );
		$this->assertSame( AccessDecision::REASON_NOT_ENROLLED, $this->policy->can_access_item( $lesson, $student )->reason() );
		$this->assertSame( AccessDecision::REASON_NOT_ENROLLED, $this->policy->can_access_course( $this->fixture['course'], $student )->reason() );

		$this->enrollments->enroll( $student, $this->fixture['course'], 'free' );
		$this->assertTrue( $this->policy->can_access_item( $lesson, $student )->is_allowed() );
		$this->assertTrue( $this->policy->can_access_course( $this->fixture['course'], $student )->is_allowed() );

		$this->enrollments->unenroll( $student, $this->fixture['course'] );
		$this->assertSame( AccessDecision::REASON_NOT_ENROLLED, $this->policy->can_access_item( $lesson, $student )->reason() );
	}

	/**
	 * @covers ::can_access_item
	 */
	public function test_expired_enrolments_are_denied_with_their_own_reason() {
		$student = self::factory()->user->create();
		$this->enrollments->enroll( $student, $this->fixture['course'], 'manual', 0, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$decision = $this->policy->can_access_item( $this->fixture['lessons'][0], $student );
		$this->assertSame( AccessDecision::REASON_EXPIRED, $decision->reason() );
		$this->assertNotSame( '', $decision->message() );
		$this->enrollments->set_status( $student, $this->fixture['course'], 'expired' );
		$this->assertSame( AccessDecision::REASON_EXPIRED, $this->policy->can_access_course( $this->fixture['course'], $student )->reason() );
	}

	/**
	 * @covers ::can_access_item
	 */
	public function test_previews_are_open_to_anyone_but_unpublished_items_are_not() {
		$lesson = $this->fixture['lessons'][0];
		update_post_meta( $lesson, Meta::PREVIEW, true );
		$this->assertTrue( $this->policy->can_access_item( $lesson )->is_allowed() );

		$student = self::factory()->user->create();
		$this->enrollments->enroll( $student, $this->fixture['course'] );
		wp_update_post(
			array(
				'ID'          => $this->fixture['lessons'][2],
				'post_status' => 'draft',
			)
		);
		$this->assertSame( AccessDecision::REASON_NOT_PUBLISHED, $this->policy->can_access_item( $this->fixture['lessons'][2], $student )->reason() );

		wp_update_post(
			array(
				'ID'          => $this->fixture['course'],
				'post_status' => 'draft',
			)
		);
		$this->assertSame( AccessDecision::REASON_NOT_PUBLISHED, $this->policy->can_access_item( $lesson )->reason(), 'A preview of a draft course stays closed.' );
		$this->assertSame( AccessDecision::REASON_NOT_PUBLISHED, $this->policy->can_access_course( $this->fixture['course'], $student )->reason() );
	}

	/**
	 * @covers ::can_access_item
	 */
	public function test_teachers_and_managers_open_everything_in_their_courses() {
		$manager = self::factory()->user->create( array( 'role' => 'plms_manager' ) );
		$other   = self::factory()->user->create( array( 'role' => 'plms_instructor' ) );
		wp_update_post(
			array(
				'ID'          => $this->fixture['lessons'][3],
				'post_status' => 'draft',
			)
		);

		$this->assertTrue( $this->policy->can_access_item( $this->fixture['lessons'][3], $this->instructor )->is_allowed() );
		$this->assertTrue( $this->policy->can_access_item( $this->fixture['lessons'][3], $manager )->is_allowed() );
		$this->assertFalse( $this->policy->can_access_item( $this->fixture['lessons'][0], $other )->is_allowed() );
	}

	/**
	 * @covers ::can_access_item
	 */
	public function test_items_outside_a_course_are_for_editors_only() {
		$orphan = (int) self::factory()->post->create(
			array(
				'post_type'   => PostTypes::LESSON,
				'post_author' => $this->instructor,
			)
		);
		$this->assertSame( AccessDecision::REASON_NOT_PUBLISHED, $this->policy->can_access_item( $orphan, self::factory()->user->create() )->reason() );
		$this->assertTrue( $this->policy->can_access_item( $orphan, $this->instructor )->is_allowed() );
		$this->assertSame( AccessDecision::REASON_NOT_PUBLISHED, $this->policy->can_access_item( $this->fixture['course'] )->reason() );
	}

	/**
	 * Without rules every published item of an active enrolment is open.
	 *
	 * @covers ::can_access_item
	 * @covers ::can_submit_assignment
	 */
	public function test_items_without_access_rules_stay_open() {
		$student = self::factory()->user->create();
		$this->enrollments->enroll( $student, $this->fixture['course'] );
		foreach ( $this->fixture['lessons'] as $lesson ) {
			$this->assertTrue( $this->policy->can_access_item( $lesson, $student )->is_allowed() );
		}
		$this->assertTrue( $this->policy->can_submit_assignment( $this->fixture['lessons'][0], $student )->is_allowed() );
	}

	/**
	 * @covers ::check_drip
	 * @covers ::unlock_time
	 */
	public function test_drip_days_count_from_the_enrolment_date() {
		$student = self::factory()->user->create();
		$lesson  = $this->fixture['lessons'][1];
		update_post_meta( $lesson, Meta::DRIP_DAYS, 3 );
		$enrolment = $this->enrollments->enroll( $student, $this->fixture['course'] );

		$decision = $this->policy->can_access_item( $lesson, $student );
		$this->assertSame( AccessDecision::REASON_DRIP, $decision->reason() );
		$this->assertEqualsWithDelta( time() + 3 * DAY_IN_SECONDS, (int) $decision->available_at(), 5 );
		$this->assertStringContainsString( wp_date( (string) get_option( 'date_format' ), (int) $decision->available_at() ), $decision->message() );
		$this->assertTrue( $this->policy->can_access_item( $this->fixture['lessons'][0], $student )->is_allowed(), 'Items without a rule stay open.' );
		$this->assertTrue( $this->policy->can_access_item( $lesson, $this->instructor )->is_allowed(), 'Teachers are never dripped.' );

		$this->enrollments->update( $enrolment, array( 'enrolled_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ) ) );
		$this->assertTrue( $this->policy->can_access_item( $lesson, $student )->is_allowed() );
	}

	/**
	 * @covers ::check_drip
	 * @covers ::unlock_time
	 */
	public function test_section_and_item_drip_dates_apply_together_and_the_later_wins() {
		$student = self::factory()->user->create();
		$this->enrollments->enroll( $student, $this->fixture['course'] );
		$tomorrow = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$in_week  = gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS );
		update_post_meta( $this->fixture['sections'][1], Meta::DRIP_DATE, $tomorrow );
		update_post_meta( $this->fixture['lessons'][3], Meta::DRIP_DATE, $in_week );

		$this->assertTrue( $this->policy->can_access_item( $this->fixture['lessons'][1], $student )->is_allowed() );
		$this->assertSame( strtotime( $tomorrow . ' UTC' ), $this->policy->can_access_item( $this->fixture['lessons'][2], $student )->available_at() );
		$this->assertSame( strtotime( $in_week . ' UTC' ), $this->policy->can_access_item( $this->fixture['lessons'][3], $student )->available_at() );

		update_post_meta( $this->fixture['sections'][1], Meta::DRIP_DATE, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->assertTrue( $this->policy->can_access_item( $this->fixture['lessons'][2], $student )->is_allowed() );
		$this->assertSame( AccessDecision::REASON_DRIP, $this->policy->can_access_item( $this->fixture['lessons'][3], $student )->reason() );
	}

	/**
	 * @covers ::check_prerequisites
	 * @covers ::missing_item_prerequisites
	 */
	public function test_item_prerequisites_open_once_the_required_steps_are_complete() {
		$student = self::factory()->user->create();
		$this->enrollments->enroll( $student, $this->fixture['course'] );
		$lessons = $this->fixture['lessons'];
		$other   = $this->create_course_with_lessons();
		update_post_meta( $lessons[2], Meta::PREREQUISITES, array( $lessons[0], $other['lessons'][0], $lessons[2] ) );

		$decision = $this->policy->can_access_item( $lessons[2], $student );
		$this->assertSame( AccessDecision::REASON_PREREQUISITE, $decision->reason() );
		$this->assertSame( array( $lessons[0] ), $decision->requires(), 'Items of other courses and the item itself are ignored.' );
		$this->assertStringContainsString( get_the_title( $lessons[0] ), $decision->message() );
		$this->assertSame( $lessons[0], $decision->to_array()['requires'][0]['id'] );

		publiclms()->container()->get( ProgressService::class )->complete_item( $student, $lessons[0] );
		$this->assertTrue( $this->policy->can_access_item( $lessons[2], $student )->is_allowed() );
	}

	/**
	 * @covers ::can_enroll
	 * @covers ::missing_course_prerequisites
	 */
	public function test_course_prerequisites_block_enrolment_and_items_until_completed() {
		$student  = self::factory()->user->create();
		$required = $this->create_course_with_lessons( array( 'post_title' => 'Foundations' ) );
		$draft    = $this->create_course( array( 'post_status' => 'draft' ) );
		update_post_meta( $this->fixture['course'], Meta::PREREQUISITES, array( $required['course'], $draft, $this->fixture['course'] ) );

		$decision = $this->policy->can_enroll( $this->fixture['course'], $student );
		$this->assertSame( AccessDecision::REASON_PREREQUISITE, $decision->reason() );
		$this->assertSame( array( $required['course'] ), $decision->requires(), 'Unpublished courses and the course itself do not count.' );
		$this->assertStringContainsString( 'Foundations', $decision->message() );

		$this->enrollments->enroll( $student, $this->fixture['course'], 'manual' );
		$this->assertSame( AccessDecision::REASON_PREREQUISITE, $this->policy->can_access_item( $this->fixture['lessons'][0], $student )->reason() );

		$this->enrollments->enroll( $student, $required['course'] );
		$this->enrollments->set_completed( $student, $required['course'], gmdate( 'Y-m-d H:i:s' ) );
		$this->assertTrue( $this->policy->can_access_item( $this->fixture['lessons'][0], $student )->is_allowed() );
		$other = self::factory()->user->create();
		$this->assertSame( AccessDecision::REASON_PREREQUISITE, $this->policy->can_enroll( $this->fixture['course'], $other )->reason() );
	}

	/**
	 * @covers ::can_view_certificate
	 */
	public function test_certificates_are_for_report_viewers_until_phase_2() {
		$this->assertFalse( $this->policy->can_view_certificate( $this->fixture['course'], self::factory()->user->create() )->is_allowed() );
		$this->assertTrue( $this->policy->can_view_certificate( $this->fixture['course'], $this->instructor )->is_allowed() );
	}

	/**
	 * @covers ::filter
	 */
	public function test_decisions_pass_through_the_filter() {
		$student = self::factory()->user->create();
		$grant   = static function ( $decision, $action ) {
			return 'access_item' === $action ? AccessDecision::allow() : $decision;
		};
		add_filter( 'publiclms_access_decision', $grant, 10, 2 );
		$this->assertTrue( $this->policy->can_access_item( $this->fixture['lessons'][0], $student )->is_allowed() );
		remove_filter( 'publiclms_access_decision', $grant, 10 );
		$this->assertFalse( $this->policy->can_access_item( $this->fixture['lessons'][0], $student )->is_allowed() );
	}
}
