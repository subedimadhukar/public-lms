<?php
/**
 * Tests for the progress repository.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Repositories;

use PublicLMS\Core\Cache;
use PublicLMS\Repositories\ProgressRepository;
use PublicLMS\Tests\TestCase;

/**
 * @coversDefaultClass \PublicLMS\Repositories\ProgressRepository
 */
class ProgressRepositoryTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var ProgressRepository
	 */
	private $repo;

	public function set_up() {
		parent::set_up();
		$this->repo = publiclms()->container()->get( ProgressRepository::class );
	}

	/**
	 * @covers ::upsert_item
	 * @covers ::find_item
	 */
	public function test_upsert_item_is_idempotent_and_keeps_the_first_completion() {
		global $wpdb;
		$user = self::factory()->user->create();

		$this->assertTrue( $this->repo->upsert_item( $user, 10, 11, 'lesson', 'in_progress' ) );
		$row = $this->repo->find_item( $user, 11 );
		$this->assertSame( 'in_progress', $row['status'] );
		$this->assertNull( $row['completed_at'] );
		$this->assertNotNull( $row['started_at'] );

		$this->assertTrue( $this->repo->upsert_item( $user, 10, 11, 'lesson', 'completed' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test backdates a row.
		$wpdb->update( $this->repo->items_table(), array( 'completed_at' => '2026-01-01 00:00:00' ), array( 'item_id' => 11 ) );

		$this->assertTrue( $this->repo->upsert_item( $user, 10, 11, 'lesson', 'completed' ) );
		$this->assertTrue( $this->repo->upsert_item( $user, 10, 11, 'lesson', 'in_progress' ) );

		$row = $this->repo->find_item( $user, 11 );
		$this->assertSame( 'completed', $row['status'] );
		$this->assertSame( '2026-01-01 00:00:00', $row['completed_at'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test counts rows.
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $this->repo->items_table(), $user ) ) );
	}

	/**
	 * @covers ::upsert_item
	 */
	public function test_upsert_item_rejects_invalid_input() {
		$user = self::factory()->user->create();
		$this->assertFalse( $this->repo->upsert_item( 0, 10, 11, 'lesson', 'completed' ) );
		$this->assertFalse( $this->repo->upsert_item( $user, 0, 11, 'lesson', 'completed' ) );
		$this->assertFalse( $this->repo->upsert_item( $user, 10, 11, 'video', 'completed' ) );
		$this->assertFalse( $this->repo->upsert_item( $user, 10, 11, 'lesson', 'passed' ) );
		$this->assertNull( $this->repo->find_item( $user, 11 ) );
	}

	/**
	 * @covers ::reset_item
	 */
	public function test_reset_item_removes_the_row() {
		$user = self::factory()->user->create();
		$this->repo->upsert_item( $user, 10, 11, 'lesson', 'completed' );
		$this->repo->items_for_course( $user, 10 );

		$this->assertTrue( $this->repo->reset_item( $user, 11 ) );
		$this->assertNull( $this->repo->find_item( $user, 11 ) );
		$this->assertSame( array(), $this->repo->items_for_course( $user, 10 ) );
		$this->assertFalse( $this->repo->reset_item( $user, 11 ) );
	}

	/**
	 * @covers ::items_for_course
	 * @covers ::completed_item_ids
	 */
	public function test_items_for_course_are_cached_and_invalidated() {
		$user = self::factory()->user->create();
		$this->repo->upsert_item( $user, 10, 11, 'lesson', 'completed' );
		$this->repo->upsert_item( $user, 10, 12, 'lesson', 'in_progress' );
		$this->repo->upsert_item( $user, 20, 21, 'lesson', 'completed' );

		$items = $this->repo->items_for_course( $user, 10 );
		$this->assertSame( array( 11, 12 ), array_keys( $items ) );
		$this->assertIsArray( Cache::get( 'progress_items:' . $user . ':10' ) );
		$this->assertSame( array( 11 ), $this->repo->completed_item_ids( $user, 10 ) );

		$this->repo->upsert_item( $user, 10, 12, 'lesson', 'completed' );
		$this->assertFalse( Cache::get( 'progress_items:' . $user . ':10' ) );
		$this->assertSame( array( 11, 12 ), $this->repo->completed_item_ids( $user, 10 ) );
		$this->assertSame( array(), $this->repo->items_for_course( 0, 10 ) );
	}

	/**
	 * @covers ::refresh_course_progress
	 * @covers ::course_progress
	 */
	public function test_refresh_course_progress_counts_only_current_items() {
		$user = self::factory()->user->create();
		$this->repo->upsert_item( $user, 10, 11, 'lesson', 'completed' );
		$this->repo->upsert_item( $user, 10, 99, 'lesson', 'completed' ); // Removed from the course since.

		$row = $this->repo->refresh_course_progress( $user, 10, array( 11, 12, 13, 14 ), 11, 7 );

		$this->assertSame( 1, $row['items_completed'] );
		$this->assertSame( 4, $row['items_total'] );
		$this->assertSame( 25, $row['percent'] );
		$this->assertSame( 11, $row['last_item_id'] );
		$this->assertSame( 7, $row['enrollment_id'] );
		$this->assertNull( $row['completed_at'] );
		$this->assertSame( $row, $this->repo->course_progress( $user, 10 ) );
	}

	/**
	 * @covers ::refresh_course_progress
	 */
	public function test_refresh_course_progress_sets_and_clears_completion() {
		$user = self::factory()->user->create();
		$this->repo->upsert_item( $user, 10, 11, 'lesson', 'completed' );
		$this->repo->upsert_item( $user, 10, 12, 'lesson', 'completed' );

		$done = $this->repo->refresh_course_progress( $user, 10, array( 11, 12 ), 12 );
		$this->assertSame( 100, $done['percent'] );
		$this->assertNotNull( $done['completed_at'] );

		$again = $this->repo->refresh_course_progress( $user, 10, array( 11, 12 ) );
		$this->assertSame( $done['completed_at'], $again['completed_at'] );
		$this->assertSame( 12, $again['last_item_id'], 'Passing 0 keeps the stored last item.' );
		$this->assertNull( $again['enrollment_id'] );

		$grown = $this->repo->refresh_course_progress( $user, 10, array( 11, 12, 13 ) );
		$this->assertSame( 67, $grown['percent'] );
		$this->assertNull( $grown['completed_at'] );

		$empty = $this->repo->refresh_course_progress( $user, 10, array() );
		$this->assertSame( 0, $empty['percent'] );
		$this->assertSame( 0, $empty['items_total'] );
	}

	/**
	 * @covers ::course_progress_for_user
	 */
	public function test_course_progress_for_user_is_cached_per_user() {
		$user = self::factory()->user->create();
		$this->repo->upsert_item( $user, 10, 11, 'lesson', 'completed' );
		$this->repo->refresh_course_progress( $user, 10, array( 11, 12 ) );
		$this->repo->refresh_course_progress( $user, 20, array( 21 ) );

		$rows = $this->repo->course_progress_for_user( $user );
		$this->assertEqualsCanonicalizing( array( 10, 20 ), array_keys( $rows ) );
		$this->assertIsArray( Cache::get( 'course_progress:user:' . $user ) );
		$this->assertNull( $this->repo->course_progress( $user, 30 ) );
		$this->assertSame( array(), $this->repo->course_progress_for_user( 0 ) );
	}

	/**
	 * @covers ::delete_for_course
	 */
	public function test_delete_for_course_removes_items_and_aggregate() {
		$user = self::factory()->user->create();
		$this->repo->upsert_item( $user, 10, 11, 'lesson', 'completed' );
		$this->repo->upsert_item( $user, 20, 21, 'lesson', 'completed' );
		$this->repo->refresh_course_progress( $user, 10, array( 11 ) );

		$this->repo->delete_for_course( $user, 10 );

		$this->assertSame( array(), $this->repo->items_for_course( $user, 10 ) );
		$this->assertNull( $this->repo->course_progress( $user, 10 ) );
		$this->assertCount( 1, $this->repo->items_for_course( $user, 20 ) );
	}

	/**
	 * @covers ::delete_for_user
	 */
	public function test_delete_for_user_removes_everything_for_that_user() {
		$user  = self::factory()->user->create();
		$other = self::factory()->user->create();
		$this->repo->upsert_item( $user, 10, 11, 'lesson', 'completed' );
		$this->repo->upsert_item( $other, 10, 11, 'lesson', 'completed' );
		$this->repo->refresh_course_progress( $user, 10, array( 11 ) );
		$this->repo->items_for_course( $user, 10 );

		$this->repo->delete_for_user( $user );

		$this->assertSame( array(), $this->repo->items_for_course( $user, 10 ) );
		$this->assertSame( array(), $this->repo->course_progress_for_user( $user ) );
		$this->assertCount( 1, $this->repo->items_for_course( $other, 10 ) );
	}

	/**
	 * @covers ::percent
	 */
	public function test_percent_rounds_to_nearest_but_keeps_100_for_completion() {
		$this->assertSame( 17, ProgressRepository::percent( 1, 6 ) );
		$this->assertSame( 67, ProgressRepository::percent( 2, 3 ) );
		$this->assertSame( 83, ProgressRepository::percent( 5, 6 ) );
		$this->assertSame( 33, ProgressRepository::percent( 1, 3 ) );
		$this->assertSame( 99, ProgressRepository::percent( 199, 200 ), 'Never 100 before the last item.' );
		$this->assertSame( 1, ProgressRepository::percent( 1, 300 ), 'Never 0 once an item is done.' );
		$this->assertSame( 100, ProgressRepository::percent( 6, 6 ) );
		$this->assertSame( 0, ProgressRepository::percent( 0, 6 ) );
		$this->assertSame( 0, ProgressRepository::percent( 0, 0 ) );
	}

	/**
	 * @covers ::refresh_course_progress
	 */
	public function test_one_of_six_reads_17_percent_in_the_stored_aggregate() {
		$user = self::factory()->user->create();
		$this->repo->upsert_item( $user, 10, 11, 'lesson', 'completed' );
		$row = $this->repo->refresh_course_progress( $user, 10, array( 11, 12, 13, 14, 15, 16 ) );
		$this->assertSame( 17, $row['percent'] );
	}
}
