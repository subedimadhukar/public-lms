<?php
/**
 * Integration tests for enrolment, progress and student routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Rest;

use PublicLMS\PostTypes\Meta;
use PublicLMS\Repositories\EventRepositoryInterface;

/**
 * @covers \PublicLMS\Rest\EnrollmentsController
 * @covers \PublicLMS\Rest\ProgressController
 * @covers \PublicLMS\Rest\StudentsController
 * @covers \PublicLMS\Services\EnrollmentService
 * @covers \PublicLMS\Services\ProgressService
 */
class LearningControllersTest extends RestTestCase {

	public function test_free_self_enrolment() {
		$course = $this->fixture['course'];
		$this->assertSame( 401, $this->request( 'POST', '/enrollments/me', array( 'course_id' => $course ) )->get_status() );

		$fired = 0;
		$count = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'publiclms_enrolled', $count );

		$response = $this->request( 'POST', '/enrollments/me', array( 'course_id' => $course ), $this->subscriber );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'active', $response->get_data()['status'] );
		$this->assertSame( 'free', $response->get_data()['source'] );
		$this->assertSame( get_permalink( $this->fixture['lessons'][0] ), $response->get_data()['course']['resume_url'] );

		$again = $this->request( 'POST', '/enrollments/me', array( 'course_id' => $course ), $this->subscriber );
		$this->assertSame( 403, $again->get_status() );
		$this->assertSame( 'already_enrolled', $again->get_data()['data']['reason'] );
		$this->assertSame( 1, $fired );

		$mine = $this->request( 'GET', '/enrollments/me', array(), $this->subscriber )->get_data();
		$this->assertSame( array( $course ), wp_list_pluck( $mine, 'course_id' ) );
		$this->assertSame( 0, $mine[0]['progress']['percent'] );
		$this->assertSame( 4, $mine[0]['progress']['items_total'] );
	}

	public function test_closed_and_paid_courses_refuse_self_enrolment() {
		update_post_meta( $this->fixture['course'], Meta::ACCESS_MODE, 'closed' );
		$closed = $this->request( 'POST', '/enrollments/me', array( 'course_id' => $this->fixture['course'] ), $this->subscriber );
		$this->assertSame( 403, $closed->get_status() );
		$this->assertSame( 'enrollment_closed', $closed->get_data()['data']['reason'] );
		$this->assertSame( 404, $this->request( 'POST', '/enrollments/me', array( 'course_id' => $this->fixture['lessons'][0] ), $this->subscriber )->get_status() );
	}

	public function test_course_enrolment_lists_and_manual_enrolment_follow_the_capabilities() {
		$course = $this->fixture['course'];

		$this->assertSame( 403, $this->request( 'POST', '/courses/' . $course . '/enrollments', array( 'user_id' => $this->subscriber ), $this->other_instructor )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/courses/' . $course . '/enrollments', array( 'user_id' => $this->subscriber ), $this->subscriber )->get_status() );
		$this->assertSame( 201, $this->request( 'POST', '/courses/' . $course . '/enrollments', array( 'user_id' => $this->subscriber ), $this->instructor )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/courses/' . $course . '/enrollments', array( 'user_id' => 999999 ), $this->instructor )->get_status() );

		$others = self::factory()->user->create_many( 2 );
		foreach ( $others as $user ) {
			$this->request( 'POST', '/courses/' . $course . '/enrollments', array( 'user_id' => $user ), $this->manager );
		}

		$this->assertSame( 403, $this->request( 'GET', '/courses/' . $course . '/enrollments', array(), $this->other_instructor )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/courses/' . $course . '/enrollments', array(), $this->subscriber )->get_status() );

		$page1 = $this->request( 'GET', '/courses/' . $course . '/enrollments', array( 'per_page' => 2 ), $this->instructor );
		$this->assertSame( 200, $page1->get_status() );
		$this->assertSame( '3', $page1->get_headers()['X-WP-Total'] );
		$this->assertSame( 'Lucía Fernández', $page1->get_data()[0]['user']['name'] );
		$page2 = $this->request(
			'GET',
			'/courses/' . $course . '/enrollments',
			array(
				'per_page' => 2,
				'after'    => (int) $page1->get_headers()['X-PublicLMS-Next-Cursor'],
			),
			$this->instructor
		);
		$this->assertCount( 1, $page2->get_data() );

		$this->assertSame( 403, $this->request( 'DELETE', '/courses/' . $course . '/enrollments/' . $this->subscriber, array(), $this->other_instructor )->get_status() );
		$deleted = $this->request( 'DELETE', '/courses/' . $course . '/enrollments/' . $this->subscriber, array(), $this->instructor );
		$this->assertSame( 'cancelled', $deleted->get_data()['previous']['status'] );
		$this->assertSame( 404, $this->request( 'DELETE', '/courses/' . $course . '/enrollments/' . $this->manager, array(), $this->instructor )->get_status() );
	}

	public function test_progress_needs_enrolment_and_is_idempotent() {
		$course  = $this->fixture['course'];
		$lessons = $this->fixture['lessons'];

		$this->assertSame( 401, $this->request( 'POST', '/progress', array( 'item_id' => $lessons[0] ) )->get_status() );
		$locked = $this->request( 'POST', '/progress', array( 'item_id' => $lessons[0] ), $this->subscriber );
		$this->assertSame( 403, $locked->get_status() );
		$this->assertSame( 'not_enrolled', $locked->get_data()['data']['reason'] );

		$this->request( 'POST', '/enrollments/me', array( 'course_id' => $course ), $this->subscriber );

		$first = $this->request( 'POST', '/progress', array( 'item_id' => $lessons[0] ), $this->subscriber );
		$this->assertSame( 200, $first->get_status() );
		$data = $first->get_data();
		$this->assertSame( 25, $data['percent'] );
		$this->assertSame( array( $lessons[0] ), $data['completed_items'] );
		$this->assertSame( $lessons[1], $data['next_item']['id'] );
		$this->assertStringContainsString( '25%', $data['message'] );

		$again = $this->request( 'POST', '/progress', array( 'item_id' => $lessons[0] ), $this->subscriber )->get_data();
		$this->assertSame( 25, $again['percent'] );

		$this->request( 'POST', '/progress', array( 'item_id' => $lessons[2] ), $this->subscriber );
		$next = $this->request( 'POST', '/progress', array( 'item_id' => $lessons[1] ), $this->subscriber )->get_data();
		$this->assertSame( $lessons[3], $next['next_item']['id'], 'Next skips lessons already completed.' );

		$undo = $this->request(
			'POST',
			'/progress',
			array(
				'item_id' => $lessons[1],
				'status'  => 'incomplete',
			),
			$this->subscriber
		)->get_data();
		$this->assertSame( 50, $undo['percent'] );
		$this->assertFalse( $undo['item_completed'] );

		$summary = $this->request( 'GET', '/courses/' . $course . '/progress/me', array(), $this->subscriber )->get_data();
		$this->assertSame( 50, $summary['percent'] );
		$this->assertSame( $lessons[1], $summary['resume_item']['id'] );
		$this->assertSame( 403, $this->request( 'GET', '/courses/' . $course . '/progress/me', array(), $this->other_instructor )->get_status() );
		$this->assertSame( 200, $this->request( 'GET', '/courses/' . $course . '/progress/me', array(), $this->instructor )->get_status() );
	}

	public function test_completing_every_lesson_completes_the_course_once() {
		$course    = $this->fixture['course'];
		$completed = 0;
		$count     = static function () use ( &$completed ) {
			++$completed;
		};
		add_action( 'publiclms_course_completed', $count );
		$events = publiclms()->container()->get( EventRepositoryInterface::class );
		$before = $events->record( 'test.marker' );

		$this->request( 'POST', '/enrollments/me', array( 'course_id' => $course ), $this->subscriber );
		foreach ( $this->fixture['lessons'] as $lesson ) {
			$last = $this->request( 'POST', '/progress', array( 'item_id' => $lesson ), $this->subscriber )->get_data();
		}
		$this->request( 'POST', '/progress', array( 'item_id' => $this->fixture['lessons'][3] ), $this->subscriber );

		$this->assertSame( 100, $last['percent'] );
		$this->assertTrue( $last['completed'] );
		$this->assertNull( $last['next_item'] );
		$this->assertSame( 1, $completed );
		$mine = $this->request( 'GET', '/enrollments/me', array(), $this->subscriber )->get_data();
		$this->assertNotNull( $mine[0]['completed_at'] );
		$types = wp_list_pluck( $events->since( $before ), 'event_type' );
		$this->assertSame( array( 'enrollment.created', 'item.completed', 'item.completed', 'item.completed', 'item.completed', 'course.completed', 'certificate.issued' ), $types );
	}

	public function test_students_list_is_scoped_paginated_and_searchable() {
		$theirs = $this->create_course_with_lessons( array( 'post_author' => $this->other_instructor ) );
		$amir   = self::factory()->user->create( array( 'display_name' => 'Amir Khan' ) );
		$zoe    = self::factory()->user->create( array( 'display_name' => 'Zoe Adams' ) );
		$this->request( 'POST', '/courses/' . $this->fixture['course'] . '/enrollments', array( 'user_id' => $this->subscriber ), $this->manager );
		$this->request( 'POST', '/courses/' . $this->fixture['course'] . '/enrollments', array( 'user_id' => $amir ), $this->manager );
		$this->request( 'POST', '/courses/' . $theirs['course'] . '/enrollments', array( 'user_id' => $zoe ), $this->manager );

		$this->assertSame( 403, $this->request( 'GET', '/students', array(), $this->subscriber )->get_status() );

		$scoped = wp_list_pluck( $this->request( 'GET', '/students', array(), $this->instructor )->get_data(), 'id' );
		$this->assertEqualsCanonicalizing( array( $this->subscriber, $amir ), $scoped );
		$this->assertSame( 403, $this->request( 'GET', '/students', array( 'course' => $theirs['course'] ), $this->instructor )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/students/' . $zoe, array(), $this->instructor )->get_status() );

		$first = $this->request( 'GET', '/students', array( 'per_page' => 2 ), $this->manager );
		$this->assertSame( '3', $first->get_headers()['X-WP-Total'] );
		$this->assertSame( array( 'Amir Khan', 'Lucía Fernández' ), wp_list_pluck( $first->get_data(), 'name' ) );
		$second = $this->request(
			'GET',
			'/students',
			array(
				'per_page' => 2,
				'cursor'   => $first->get_headers()['X-PublicLMS-Next-Cursor'],
			),
			$this->manager
		);
		$this->assertSame( array( 'Zoe Adams' ), wp_list_pluck( $second->get_data(), 'name' ) );

		$this->assertSame( array( $zoe ), wp_list_pluck( $this->request( 'GET', '/students', array( 'search' => 'adams' ), $this->manager )->get_data(), 'id' ) );

		$detail = $this->request( 'GET', '/students/' . $amir, array(), $this->instructor )->get_data();
		$this->assertSame( 'Amir Khan', $detail['name'] );
		$this->assertSame( array( $this->fixture['course'] ), wp_list_pluck( $detail['courses'], 'course_id' ) );
	}
}
