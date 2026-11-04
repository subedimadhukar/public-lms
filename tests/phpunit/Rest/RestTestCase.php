<?php
/**
 * Shared REST test helpers.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Rest;

use PublicLMS\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Base for publiclms/v1 integration tests.
 */
abstract class RestTestCase extends TestCase {

	/**
	 * Instructor who owns the fixture course.
	 *
	 * @var int
	 */
	protected $instructor;

	/**
	 * A second instructor.
	 *
	 * @var int
	 */
	protected $other_instructor;

	/**
	 * Course manager.
	 *
	 * @var int
	 */
	protected $manager;

	/**
	 * Subscriber.
	 *
	 * @var int
	 */
	protected $subscriber;

	/**
	 * Fixture owned by $instructor.
	 *
	 * @var array{course: int, sections: array<int, int>, lessons: array<int, int>}
	 */
	protected $fixture;

	public function set_up() {
		parent::set_up();
		rest_get_server();
		$this->instructor       = self::factory()->user->create( array( 'role' => 'plms_instructor' ) );
		$this->other_instructor = self::factory()->user->create( array( 'role' => 'plms_instructor' ) );
		$this->manager          = self::factory()->user->create( array( 'role' => 'plms_manager' ) );
		$this->subscriber       = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Lucía Fernández',
			)
		);
		$this->fixture          = $this->create_course_with_lessons( array( 'post_author' => $this->instructor ) );
	}

	/**
	 * Dispatches a request as a user.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $route   Route under /publiclms/v1.
	 * @param array<string, mixed> $params  Body or query parameters.
	 * @param int                  $user_id User to act as; 0 for logged out.
	 * @return WP_REST_Response
	 */
	protected function request( string $method, string $route, array $params = array(), int $user_id = 0 ): WP_REST_Response {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, '/publiclms/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		return rest_do_request( $request );
	}
}
