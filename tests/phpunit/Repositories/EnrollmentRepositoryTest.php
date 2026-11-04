<?php
/**
 * Tests for the enrolment repository.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Repositories;

use PublicLMS\Core\Cache;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Tests\TestCase;

/**
 * @coversDefaultClass \PublicLMS\Repositories\EnrollmentRepository
 */
class EnrollmentRepositoryTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var EnrollmentRepository
	 */
	private $repo;

	public function set_up() {
		parent::set_up();
		$this->repo = publiclms()->container()->get( EnrollmentRepository::class );
	}

	/**
	 * @covers ::enroll
	 * @covers ::find_by_user_and_course
	 */
	public function test_enroll_creates_one_active_row_and_is_idempotent() {
		$user   = self::factory()->user->create();
		$course = $this->create_course();

		$first  = $this->repo->enroll( $user, $course, 'free' );
		$second = $this->repo->enroll( $user, $course, 'free' );

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( $first, $second );
		$row = $this->repo->find_by_user_and_course( $user, $course );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( 'free', $row['source'] );
		$this->assertNull( $row['source_id'] );
		$this->assertNull( $row['expires_at'] );
		$this->assertSame( 1, $this->repo->count_by_course( $course ) );
		$this->assertSame( 0, $this->repo->enroll( 0, $course ) );
		$this->assertSame( 'manual', $this->repo->find_by_user_and_course( $this->enrol_new_user( $course, 'bogus' ), $course )['source'] );
	}

	/**
	 * @covers ::enroll
	 */
	public function test_reenrolling_a_cancelled_row_reactivates_it_and_keeps_the_id() {
		global $wpdb;
		$user   = self::factory()->user->create();
		$course = $this->create_course();
		$id     = $this->repo->enroll( $user, $course, 'manual', 0, '2030-01-01 00:00:00' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test backdates a row.
		$wpdb->update( $this->repo->table(), array( 'enrolled_at' => '2020-01-01 00:00:00' ), array( 'id' => $id ) );
		$this->repo->invalidate( $user, $course );

		$this->repo->enroll( $user, $course, 'manual' );
		$this->assertSame( '2020-01-01 00:00:00', $this->repo->find_by_user_and_course( $user, $course )['enrolled_at'], 'An active re-enrolment keeps the original date.' );
		$this->assertNull( $this->repo->find_by_user_and_course( $user, $course )['expires_at'] );

		$this->repo->unenroll( $user, $course );
		$again = $this->repo->enroll( $user, $course, 'order', 42 );

		$row = $this->repo->find_by_user_and_course( $user, $course );
		$this->assertSame( $id, $again );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( 'order', $row['source'] );
		$this->assertSame( 42, $row['source_id'] );
		$this->assertNotSame( '2020-01-01 00:00:00', $row['enrolled_at'] );
	}

	/**
	 * @covers ::set_status
	 * @covers ::unenroll
	 */
	public function test_set_status_and_unenroll() {
		$user   = self::factory()->user->create();
		$course = $this->create_course();
		$this->repo->enroll( $user, $course );

		$this->assertFalse( $this->repo->set_status( $user, $course, 'graduated' ) );
		$this->assertTrue( $this->repo->set_status( $user, $course, 'pending' ) );
		$this->assertSame( 'pending', $this->repo->find_by_user_and_course( $user, $course )['status'] );
		$this->assertTrue( $this->repo->unenroll( $user, $course ) );
		$this->assertSame( 'cancelled', $this->repo->find_by_user_and_course( $user, $course )['status'] );
		$this->assertFalse( $this->repo->unenroll( $user, $this->create_course() ) );
	}

	/**
	 * @covers ::set_completed
	 */
	public function test_set_completed_records_and_clears_the_completion_time() {
		$user   = self::factory()->user->create();
		$course = $this->create_course();
		$this->repo->enroll( $user, $course );

		$this->assertTrue( $this->repo->set_completed( $user, $course, '2026-11-04 10:00:00' ) );
		$this->assertSame( '2026-11-04 10:00:00', $this->repo->find_by_user_and_course( $user, $course )['completed_at'] );
		$this->assertTrue( $this->repo->set_completed( $user, $course, null ) );
		$this->assertNull( $this->repo->find_by_user_and_course( $user, $course )['completed_at'] );
	}

	/**
	 * @covers ::is_enrolled
	 * @covers ::is_expired
	 */
	public function test_is_enrolled_needs_an_active_unexpired_row() {
		$user    = self::factory()->user->create();
		$active  = $this->create_course();
		$expired = $this->create_course();
		$gone    = $this->create_course();
		$this->repo->enroll( $user, $active, 'free', 0, gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		$this->repo->enroll( $user, $expired, 'free', 0, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		$this->repo->enroll( $user, $gone );
		$this->repo->unenroll( $user, $gone );

		$this->assertTrue( $this->repo->is_enrolled( $user, $active ) );
		$this->assertFalse( $this->repo->is_enrolled( $user, $expired ) );
		$this->assertFalse( $this->repo->is_enrolled( $user, $gone ) );
		$this->assertFalse( $this->repo->is_enrolled( $user, $this->create_course() ) );
		$this->assertFalse( $this->repo->is_enrolled( 0, $active ) );
		$this->assertFalse( $this->repo->is_expired( array( 'expires_at' => null ) ) );
	}

	/**
	 * @covers ::find_by_user
	 */
	public function test_find_by_user_filters_by_status() {
		$user = self::factory()->user->create();
		$a    = $this->create_course();
		$b    = $this->create_course();
		$this->repo->enroll( $user, $a );
		$this->repo->enroll( $user, $b );
		$this->repo->unenroll( $user, $b );

		$this->assertSame( array( $a, $b ), wp_list_pluck( $this->repo->find_by_user( $user ), 'course_id' ) );
		$this->assertSame( array( $a ), wp_list_pluck( $this->repo->find_by_user( $user, 'active' ), 'course_id' ) );
		$this->assertSame( array(), $this->repo->find_by_user( 0 ) );
	}

	/**
	 * @covers ::find_by_user_and_course
	 */
	public function test_user_rows_are_cached_and_invalidated_on_write() {
		$user   = self::factory()->user->create();
		$course = $this->create_course();
		$this->assertNull( $this->repo->find_by_user_and_course( $user, $course ) );
		$this->assertSame( array(), Cache::get( 'enrollments:user:' . $user ) );

		$this->repo->enroll( $user, $course );

		$this->assertFalse( Cache::get( 'enrollments:user:' . $user ) );
		$this->assertNotNull( $this->repo->find_by_user_and_course( $user, $course ) );
	}

	/**
	 * @covers ::find_by_course
	 */
	public function test_find_by_course_uses_keyset_pagination() {
		$course = $this->create_course();
		$users  = self::factory()->user->create_many( 5 );
		$ids    = array();
		foreach ( $users as $user ) {
			$ids[] = $this->repo->enroll( $user, $course );
		}
		$this->repo->unenroll( $users[4], $course );

		$page1 = $this->repo->find_by_course( $course, null, 0, 2 );
		$page2 = $this->repo->find_by_course( $course, null, $page1[1]['id'], 2 );
		$page3 = $this->repo->find_by_course( $course, null, $page2[1]['id'], 2 );

		$this->assertSame( array_slice( $ids, 0, 2 ), wp_list_pluck( $page1, 'id' ) );
		$this->assertSame( array_slice( $ids, 2, 2 ), wp_list_pluck( $page2, 'id' ) );
		$this->assertSame( array( $ids[4] ), wp_list_pluck( $page3, 'id' ) );
		$this->assertCount( 4, $this->repo->find_by_course( $course, 'active' ) );
	}

	/**
	 * @covers ::count_by_course
	 */
	public function test_count_by_course_caches_the_active_count() {
		$course = $this->create_course();
		$user   = self::factory()->user->create();
		$this->assertSame( 0, $this->repo->count_by_course( $course, 'active' ) );

		$this->repo->enroll( $user, $course );
		$this->assertSame( 1, $this->repo->count_by_course( $course, 'active' ) );
		$this->assertSame( 1, Cache::get( 'enrolled_count:' . $course ) );

		$this->repo->unenroll( $user, $course );
		$this->assertSame( 0, $this->repo->count_by_course( $course, 'active' ) );
		$this->assertSame( 1, $this->repo->count_by_course( $course, 'cancelled' ) );
		$this->assertSame( 1, $this->repo->count_by_course( $course ) );
	}

	/**
	 * @covers ::students
	 */
	public function test_students_lists_one_row_per_learner_with_search_scope_and_cursor() {
		$a      = $this->create_course();
		$b      = $this->create_course();
		$c      = $this->create_course();
		$amina  = self::factory()->user->create(
			array(
				'display_name' => 'Amina Rai',
				'user_email'   => 'amina@example.test',
			)
		);
		$bikash = self::factory()->user->create(
			array(
				'display_name' => 'Bikash Thapa',
				'user_email'   => 'bikash@example.test',
			)
		);
		$chen   = self::factory()->user->create(
			array(
				'display_name' => 'Chen Wei',
				'user_email'   => 'chen@example.test',
			)
		);
		$this->repo->enroll( $amina, $a );
		$this->repo->enroll( $amina, $b );
		$this->repo->enroll( $bikash, $a );
		$this->repo->enroll( $chen, $c );

		$all = $this->repo->students( null, '', 'name' );
		$this->assertSame( array( $amina, $bikash, $chen ), wp_list_pluck( $all, 'user_id' ) );
		$this->assertSame( 2, $all[0]['courses'] );

		$scoped = $this->repo->students( array( $a ), '', 'id' );
		$this->assertSame( array( $amina, $bikash ), wp_list_pluck( $scoped, 'user_id' ) );
		$this->assertSame( 1, $scoped[0]['courses'] );

		$this->assertSame( array( $chen ), wp_list_pluck( $this->repo->students( null, 'chen@' ), 'user_id' ) );
		$this->assertSame( array(), $this->repo->students( array() ) );

		$first  = $this->repo->students( null, '', 'name', 'desc', null, 1 );
		$second = $this->repo->students( null, '', 'name', 'desc', array( $first[0]['display_name'], $first[0]['user_id'] ), 2 );
		$this->assertSame( array( $chen ), wp_list_pluck( $first, 'user_id' ) );
		$this->assertSame( array( $bikash, $amina ), wp_list_pluck( $second, 'user_id' ) );

		$by_id = $this->repo->students( null, '', 'id', 'asc', array( '', $amina ), 10 );
		$this->assertSame( array( $bikash, $chen ), wp_list_pluck( $by_id, 'user_id' ) );

		$recent = $this->repo->students( null, '', 'enrolled', 'desc', null, 10 );
		$this->assertCount( 3, $recent );
		$after = $this->repo->students( null, '', 'enrolled', 'desc', array( $recent[0]['last_enrolled_at'], $recent[0]['user_id'] ), 10 );
		$this->assertSame( array_slice( wp_list_pluck( $recent, 'user_id' ), 1 ), wp_list_pluck( $after, 'user_id' ) );
	}

	/**
	 * @covers ::delete_for_user
	 */
	public function test_delete_for_user_removes_every_row() {
		$user = self::factory()->user->create();
		$a    = $this->create_course();
		$this->repo->enroll( $user, $a );
		$this->repo->enroll( $user, $this->create_course() );
		$this->repo->count_by_course( $a, 'active' );

		$this->assertSame( 2, $this->repo->delete_for_user( $user ) );
		$this->assertSame( array(), $this->repo->find_by_user( $user ) );
		$this->assertSame( 0, $this->repo->count_by_course( $a, 'active' ) );
	}

	/**
	 * @covers ::insert
	 * @covers ::update
	 * @covers ::delete
	 * @covers ::find
	 * @covers ::normalize
	 */
	public function test_generic_writes_invalidate_the_cache() {
		$user   = self::factory()->user->create();
		$course = $this->create_course();
		$this->repo->find_by_user_and_course( $user, $course );

		$id = $this->repo->insert(
			array(
				'user_id'      => $user,
				'course_id'    => $course,
				'status'       => 'pending',
				'source'       => 'import',
				'enrolled_at'  => '2026-01-01 00:00:00',
				'updated_at'   => '2026-01-01 00:00:00',
				'not_a_column' => 'ignored',
			)
		);
		$this->assertSame( 'pending', $this->repo->find_by_user_and_course( $user, $course )['status'] );

		$this->assertTrue( $this->repo->update( $id, array( 'status' => 'active' ) ) );
		$this->assertSame( 'active', $this->repo->find_by_user_and_course( $user, $course )['status'] );
		$this->assertSame( $id, (int) $this->repo->find( $id )['id'] );

		$this->assertTrue( $this->repo->delete( $id ) );
		$this->assertNull( $this->repo->find_by_user_and_course( $user, $course ) );
		$this->assertNull( $this->repo->find( $id ) );
	}

	/**
	 * Enrols a new user and returns the ID.
	 *
	 * @param int    $course Course ID.
	 * @param string $source Source.
	 * @return int
	 */
	private function enrol_new_user( int $course, string $source ): int {
		$user = self::factory()->user->create();
		$this->repo->enroll( $user, $course, $source );
		return $user;
	}
}
