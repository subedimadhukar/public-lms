<?php
/**
 * Tests for the event repository.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Repositories;

use PublicLMS\Repositories\EventRepositoryInterface;
use PublicLMS\Tests\TestCase;

/**
 * @coversDefaultClass \PublicLMS\Repositories\EventRepository
 */
class EventRepositoryTest extends TestCase {

	/**
	 * @covers ::record
	 * @covers ::since
	 */
	public function test_record_appends_and_since_reads_in_order() {
		$repo = publiclms()->container()->get( EventRepositoryInterface::class );

		$first  = $repo->record(
			'Enrollment.Created',
			array(
				'user_id'     => 3,
				'course_id'   => 10,
				'object_type' => 'course',
				'object_id'   => 10,
				'payload'     => array( 'source' => 'free' ),
			)
		);
		$second = $repo->record( 'item.completed', array( 'user_id' => 3 ) );

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 0, $repo->record( '!!!' ) );

		$events = $repo->since( $first - 1 );
		$this->assertSame( array( $first, $second ), wp_list_pluck( $events, 'id' ) );
		$this->assertSame( 'enrollment.created', $events[0]['event_type'] );
		$this->assertSame( array( 'source' => 'free' ), $events[0]['payload'] );
		$this->assertNull( $events[1]['payload'] );
		$this->assertNull( $events[1]['course_id'] );
		$this->assertSame( array( $second ), wp_list_pluck( $repo->since( $first ), 'id' ) );
	}
}
