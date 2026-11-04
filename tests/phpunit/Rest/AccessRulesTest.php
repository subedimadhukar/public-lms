<?php
/**
 * Integration tests for the access rule fields on courses, sections and items.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Rest;

use PublicLMS\PostTypes\Meta;
use PublicLMS\Repositories\EnrollmentRepository;

/**
 * @covers \PublicLMS\Rest\AccessRules
 * @covers \PublicLMS\Rest\AbstractItemController
 * @covers \PublicLMS\Rest\SectionsController
 * @covers \PublicLMS\Rest\CoursesController
 */
class AccessRulesTest extends RestTestCase {

	public function test_items_take_drip_and_prerequisites_from_the_builder() {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 5.75 );
		$lessons  = $this->fixture['lessons'];
		$response = $this->request(
			'PATCH',
			'/lessons/' . $lessons[2],
			array(
				'drip_days'     => 7,
				'drip_date'     => '2030-01-15T09:00',
				'prerequisites' => array( $lessons[0], $lessons[1] ),
			),
			$this->instructor
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 7, $data['drip_days'] );
		$this->assertSame( '2030-01-15T09:00:00', $data['drip_date'] );
		$this->assertSame( '2030-01-15T03:15:00', $data['drip_date_gmt'], 'Stored in UTC; the site is UTC+5:45.' );
		$this->assertSame( array( $lessons[0], $lessons[1] ), $data['prerequisites'] );
		$this->assertSame( '2030-01-15 03:15:00', get_post_meta( $lessons[2], Meta::DRIP_DATE, true ) );

		$other = $this->create_course_with_lessons();
		$this->assertSame( 400, $this->request( 'PATCH', '/lessons/' . $lessons[2], array( 'prerequisites' => array( $other['lessons'][0] ) ), $this->instructor )->get_status() );
		$this->assertSame( 400, $this->request( 'PATCH', '/lessons/' . $lessons[2], array( 'prerequisites' => array( $lessons[2] ) ), $this->instructor )->get_status() );
		$this->assertSame( 400, $this->request( 'PATCH', '/lessons/' . $lessons[2], array( 'drip_date' => 'next tuesday' ), $this->instructor )->get_status() );
		$this->assertSame( 403, $this->request( 'PATCH', '/lessons/' . $lessons[2], array( 'drip_days' => 1 ), $this->other_instructor )->get_status() );

		$cleared = $this->request( 'PATCH', '/lessons/' . $lessons[2], array( 'drip_date' => '' ), $this->instructor )->get_data();
		$this->assertSame( '', $cleared['drip_date'] );
	}

	public function test_sections_take_drip_and_the_curriculum_reports_locks() {
		$section = $this->fixture['sections'][1];
		$data    = $this->request( 'PATCH', '/sections/' . $section, array( 'drip_days' => 14 ), $this->instructor )->get_data();
		$this->assertSame( 14, $data['drip_days'] );

		publiclms()->container()->get( EnrollmentRepository::class )->enroll( $this->subscriber, $this->fixture['course'] );
		$curriculum = $this->request( 'GET', '/courses/' . $this->fixture['course'] . '/curriculum', array(), $this->subscriber )->get_data();
		$locked     = $curriculum['sections'][1]['items'][0];
		$this->assertTrue( $locked['locked'] );
		$this->assertSame( 'drip', $locked['lock_reason'] );
		$this->assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, $locked['available_at'], 5 );
		$this->assertFalse( $curriculum['sections'][0]['items'][0]['locked'] );
		$this->assertSame( 14, $curriculum['sections'][1]['drip_days'] );
	}

	public function test_courses_take_prerequisites_and_the_certificate_switch() {
		$course   = $this->fixture['course'];
		$required = $this->create_course( array( 'post_title' => 'Foundations' ) );
		$response = $this->request(
			'PATCH',
			'/courses/' . $course,
			array(
				'prerequisites' => array( $required ),
				'certificate'   => false,
			),
			$this->instructor
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $required ), $response->get_data()['prerequisites'] );
		$this->assertFalse( $response->get_data()['certificate'] );
		$this->assertFalse( Meta::issues_certificate( $course ) );
		$this->assertSame( 400, $this->request( 'PATCH', '/courses/' . $course, array( 'prerequisites' => array( $course ) ), $this->instructor )->get_status() );

		$enroll = $this->request( 'POST', '/enrollments/me', array( 'course_id' => $course ), $this->subscriber );
		$this->assertSame( 403, $enroll->get_status() );
		$this->assertSame( 'prerequisite', $enroll->get_data()['data']['reason'] );

		$single = $this->request( 'GET', '/courses/' . $course, array(), $this->subscriber )->get_data();
		$this->assertSame( 'prerequisite', $single['enrollment']['state'] );
		$this->assertSame( $required, $single['enrollment']['access']['requires'][0]['id'] );
	}
}
