<?php
/**
 * Integration tests for course, curriculum, section and lesson routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Rest;

use PublicLMS\PostTypes\Meta;
use PublicLMS\Taxonomies\Taxonomies;

/**
 * @covers \PublicLMS\Rest\CoursesController
 * @covers \PublicLMS\Rest\SectionsController
 * @covers \PublicLMS\Rest\LessonsController
 * @covers \PublicLMS\Rest\AbstractController
 */
class CoursesControllerTest extends RestTestCase {

	public function test_a_subscriber_cannot_create_a_course() {
		$response = $this->request( 'POST', '/courses', array( 'title' => 'Hacking 101' ), $this->subscriber );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 401, $this->request( 'POST', '/courses', array( 'title' => 'Hacking 101' ) )->get_status() );
	}

	public function test_an_instructor_creates_and_publishes_a_course_with_settings() {
		$level    = self::factory()->term->create(
			array(
				'taxonomy' => Taxonomies::LEVEL,
				'name'     => 'Beginner',
			)
		);
		$category = self::factory()->term->create( array( 'taxonomy' => Taxonomies::COURSE_CATEGORY ) );
		$image    = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$response = $this->request(
			'POST',
			'/courses',
			array(
				'title'          => 'Photography for Beginners',
				'excerpt'        => 'Learn exposure, light and composition.',
				'status'         => 'publish',
				'levels'         => array( $level ),
				'categories'     => array( $category ),
				'featured_media' => $image,
				'access_mode'    => 'free',
				'duration'       => 95,
			),
			$this->instructor
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Photography for Beginners', $data['title'] );
		$this->assertSame( 'publish', $data['status'] );
		$this->assertSame( array( $level ), $data['levels'] );
		$this->assertSame( array( $category ), $data['categories'] );
		$this->assertSame( $image, $data['featured_media'] );
		$this->assertSame( '1h 35m', $data['duration_label'] );
		$this->assertSame( $this->instructor, $data['author']['id'] );
	}

	public function test_an_instructor_can_edit_only_their_own_course() {
		$course = $this->fixture['course'];

		$own = $this->request( 'PATCH', '/courses/' . $course, array( 'title' => 'Statistics, Renamed' ), $this->instructor );
		$this->assertSame( 200, $own->get_status() );
		$this->assertSame( 'Statistics, Renamed', get_the_title( $course ) );

		$other = $this->request( 'PATCH', '/courses/' . $course, array( 'title' => 'Taken over' ), $this->other_instructor );
		$this->assertSame( 403, $other->get_status() );
		$this->assertSame( 'Statistics, Renamed', get_the_title( $course ) );

		$this->assertSame( 403, $this->request( 'DELETE', '/courses/' . $course, array(), $this->other_instructor )->get_status() );
		$this->assertSame( 403, $this->request( 'PATCH', '/courses/' . $course, array( 'access_mode' => 'closed' ), $this->subscriber )->get_status() );
		$this->assertSame( 200, $this->request( 'PATCH', '/courses/' . $course, array( 'access_mode' => 'closed' ), $this->manager )->get_status() );
		$this->assertSame( 'closed', Meta::access_mode( $course ) );
	}

	public function test_invalid_settings_are_rejected() {
		$course = $this->fixture['course'];
		$this->assertSame( 400, $this->request( 'PATCH', '/courses/' . $course, array( 'access_mode' => 'bogus' ), $this->instructor )->get_status() );
		$this->assertSame( 400, $this->request( 'PATCH', '/courses/' . $course, array( 'price' => '12abc' ), $this->instructor )->get_status() );
		$this->assertSame( 400, $this->request( 'PATCH', '/courses/' . $course, array( 'levels' => array( 999999 ) ), $this->instructor )->get_status() );
		$this->assertSame( 400, $this->request( 'PATCH', '/courses/' . $course, array( 'featured_media' => $this->fixture['lessons'][0] ), $this->instructor )->get_status() );
		$this->assertSame( 404, $this->request( 'PATCH', '/courses/' . $this->fixture['lessons'][0], array( 'title' => 'x' ), $this->manager )->get_status() );
	}

	public function test_list_shows_published_courses_publicly_and_own_courses_in_edit_context() {
		$draft_mine   = $this->create_course(
			array(
				'post_status' => 'draft',
				'post_author' => $this->instructor,
			)
		);
		$draft_theirs = $this->create_course(
			array(
				'post_status' => 'draft',
				'post_author' => $this->other_instructor,
			)
		);

		$public = wp_list_pluck( $this->request( 'GET', '/courses' )->get_data(), 'id' );
		$this->assertContains( $this->fixture['course'], $public );
		$this->assertNotContains( $draft_mine, $public );

		$this->assertSame( 401, $this->request( 'GET', '/courses', array( 'status' => array( 'any' ) ) )->get_status() );

		$mine = wp_list_pluck(
			$this->request(
				'GET',
				'/courses',
				array(
					'context' => 'edit',
					'status'  => array( 'any' ),
				),
				$this->instructor
			)->get_data(),
			'id'
		);
		$this->assertEqualsCanonicalizing( array( $this->fixture['course'], $draft_mine ), $mine );

		$all = wp_list_pluck(
			$this->request(
				'GET',
				'/courses',
				array(
					'context' => 'edit',
					'status'  => array( 'any' ),
				),
				$this->manager
			)->get_data(),
			'id'
		);
		$this->assertContains( $draft_theirs, $all );
	}

	public function test_list_paginates_with_a_cursor() {
		$ids = array( $this->fixture['course'] );
		for ( $i = 0; $i < 4; $i++ ) {
			$ids[] = $this->create_course( array( 'post_title' => 'Course ' . $i ) );
		}

		$first = $this->request(
			'GET',
			'/courses',
			array(
				'per_page' => 2,
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);
		$this->assertSame( '5', $first->get_headers()['X-WP-Total'] );
		$cursor = $first->get_headers()['X-PublicLMS-Next-Cursor'];
		$this->assertNotEmpty( $cursor );

		$second = $this->request(
			'GET',
			'/courses',
			array(
				'per_page' => 2,
				'orderby'  => 'id',
				'order'    => 'asc',
				'cursor'   => $cursor,
			)
		);
		$third  = $this->request(
			'GET',
			'/courses',
			array(
				'per_page' => 2,
				'orderby'  => 'id',
				'order'    => 'asc',
				'cursor'   => $second->get_headers()['X-PublicLMS-Next-Cursor'],
			)
		);

		$seen = array_merge(
			wp_list_pluck( $first->get_data(), 'id' ),
			wp_list_pluck( $second->get_data(), 'id' ),
			wp_list_pluck( $third->get_data(), 'id' )
		);
		$this->assertSame( $ids, $seen );
		$this->assertArrayNotHasKey( 'X-PublicLMS-Next-Cursor', $third->get_headers() );
		$this->assertSame( 400, $this->request( 'GET', '/courses', array( 'cursor' => 'not-a-cursor!' ) )->get_status() );

		$by_title = $this->request(
			'GET',
			'/courses',
			array(
				'per_page' => 3,
				'orderby'  => 'title',
				'order'    => 'asc',
			)
		);
		$rest     = $this->request(
			'GET',
			'/courses',
			array(
				'per_page' => 3,
				'orderby'  => 'title',
				'order'    => 'asc',
				'cursor'   => $by_title->get_headers()['X-PublicLMS-Next-Cursor'],
			)
		);
		$titles   = array_merge( wp_list_pluck( $by_title->get_data(), 'title' ), wp_list_pluck( $rest->get_data(), 'title' ) );
		$this->assertSame( array( 'Course 0', 'Course 1', 'Course 2', 'Course 3', 'Introduction to Statistics' ), $titles );
	}

	public function test_single_course_includes_curriculum_and_hides_drafts_from_the_public() {
		$draft = $this->create_course(
			array(
				'post_status' => 'draft',
				'post_author' => $this->instructor,
			)
		);
		$this->assertSame( 404, $this->request( 'GET', '/courses/' . $draft )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/courses/' . $draft, array(), $this->subscriber )->get_status() );
		$this->assertSame( 200, $this->request( 'GET', '/courses/' . $draft, array(), $this->instructor )->get_status() );

		$data = $this->request( 'GET', '/courses/' . $this->fixture['course'] )->get_data();
		$this->assertCount( 2, $data['curriculum']['sections'] );
		$this->assertFalse( $data['curriculum']['editable'] );
		$this->assertTrue( $data['curriculum']['sections'][0]['items'][0]['locked'] );
		$this->assertSame( '', $data['curriculum']['sections'][0]['items'][0]['edit_url'] );
		$this->assertSame( 'Free', $data['fields']['price'] );
		$this->assertSame( '4 lessons', $data['fields']['lesson_count'] );
		$this->assertSame( 'logged_out', $data['enrollment']['state'] );
	}

	public function test_curriculum_reorder_needs_edit_rights_on_every_course_it_touches() {
		$f     = $this->fixture;
		$order = array(
			array(
				'id'    => $f['sections'][1],
				'items' => array( $f['lessons'][3], $f['lessons'][2] ),
			),
			array(
				'id'    => $f['sections'][0],
				'items' => array( $f['lessons'][0], $f['lessons'][1] ),
			),
		);

		$this->assertSame( 403, $this->request( 'PUT', '/courses/' . $f['course'] . '/curriculum', array( 'sections' => $order ), $this->other_instructor )->get_status() );

		$ok = $this->request( 'PUT', '/courses/' . $f['course'] . '/curriculum', array( 'sections' => $order ), $this->instructor );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( array( $f['sections'][1], $f['sections'][0] ), wp_list_pluck( $ok->get_data()['sections'], 'id' ) );
		$this->assertSame( array( $f['lessons'][3], $f['lessons'][2] ), wp_list_pluck( $ok->get_data()['sections'][0]['items'], 'id' ) );

		$incomplete = $this->request( 'PUT', '/courses/' . $f['course'] . '/curriculum', array( 'sections' => array( $order[0] ) ), $this->instructor );
		$this->assertSame( 409, $incomplete->get_status() );

		$theirs  = $this->create_course_with_lessons( array( 'post_author' => $this->other_instructor ) );
		$order[] = array(
			'id'    => $f['sections'][0],
			'items' => array(),
		);
		$this->assertSame( 400, $this->request( 'PUT', '/courses/' . $f['course'] . '/curriculum', array( 'sections' => $order ), $this->instructor )->get_status(), 'Duplicates are rejected.' );

		array_pop( $order );
		$order[0]['items'][] = $theirs['lessons'][0];
		$this->assertSame( 403, $this->request( 'PUT', '/courses/' . $f['course'] . '/curriculum', array( 'sections' => $order ), $this->instructor )->get_status(), 'Taking a lesson from someone else\'s course is refused.' );
		$this->assertSame( 200, $this->request( 'PUT', '/courses/' . $f['course'] . '/curriculum', array( 'sections' => $order ), $this->manager )->get_status() );
	}

	public function test_sections_add_rename_move_and_delete() {
		$course = $this->fixture['course'];

		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/sections',
				array(
					'course_id' => $course,
					'title'     => 'Nope',
				),
				$this->other_instructor
			)->get_status()
		);
		$created = $this->request(
			'POST',
			'/sections',
			array(
				'course_id' => $course,
				'title'     => 'Probability',
			),
			$this->instructor
		);
		$this->assertSame( 201, $created->get_status() );
		$section = $created->get_data()['id'];
		$this->assertSame( $this->instructor, (int) get_post_field( 'post_author', $section ) );

		$this->assertSame( 403, $this->request( 'PATCH', '/sections/' . $section, array( 'title' => 'x' ), $this->other_instructor )->get_status() );
		$moved = $this->request(
			'PATCH',
			'/sections/' . $section,
			array(
				'title'    => 'Probability basics',
				'position' => 0,
			),
			$this->instructor
		);
		$this->assertSame( 'Probability basics', $moved->get_data()['title'] );
		$curriculum = $this->request( 'GET', '/courses/' . $course . '/curriculum', array(), $this->instructor )->get_data();
		$this->assertSame( $section, $curriculum['sections'][0]['id'] );
		$this->assertTrue( $curriculum['editable'] );

		$this->assertSame( 200, $this->request( 'DELETE', '/sections/' . $this->fixture['sections'][0], array(), $this->instructor )->get_status() );
		$this->assertSame( 'trash', get_post_status( $this->fixture['lessons'][0] ) );
		$this->assertSame( 404, $this->request( 'DELETE', '/sections/' . $this->fixture['sections'][0], array(), $this->instructor )->get_status() );
	}

	public function test_lessons_add_update_move_and_delete() {
		$f = $this->fixture;

		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/lessons',
				array(
					'section_id' => $f['sections'][0],
					'title'      => 'x',
				),
				$this->subscriber
			)->get_status()
		);
		$created = $this->request(
			'POST',
			'/lessons',
			array(
				'section_id' => $f['sections'][0],
				'title'      => 'Standard deviation',
			),
			$this->manager
		);
		$this->assertSame( 201, $created->get_status() );
		$lesson = $created->get_data()['id'];
		$this->assertSame( $this->instructor, (int) get_post_field( 'post_author', $lesson ), 'New lessons belong to the course instructor.' );
		$this->assertStringContainsString( 'post.php', $created->get_data()['edit_url'] );

		$this->assertSame( 403, $this->request( 'PATCH', '/lessons/' . $lesson, array( 'title' => 'x' ), $this->other_instructor )->get_status() );
		$updated = $this->request(
			'PATCH',
			'/lessons/' . $lesson,
			array(
				'title'      => 'Spread and standard deviation',
				'preview'    => true,
				'duration'   => 14,
				'section_id' => $f['sections'][1],
				'position'   => 0,
			),
			$this->instructor
		);
		$this->assertSame( 200, $updated->get_status() );
		$data = $updated->get_data();
		$this->assertSame( 'Spread and standard deviation', $data['title'] );
		$this->assertTrue( $data['preview'] );
		$this->assertSame( 14, $data['duration'] );
		$this->assertSame( $f['sections'][1], $data['section_id'] );
		$this->assertSame( 0, $data['menu_order'] );

		$theirs = $this->create_course_with_lessons( array( 'post_author' => $this->other_instructor ) );
		$this->assertSame( 403, $this->request( 'PATCH', '/lessons/' . $lesson, array( 'section_id' => $theirs['sections'][0] ), $this->instructor )->get_status() );

		$this->assertSame( 200, $this->request( 'DELETE', '/lessons/' . $lesson, array(), $this->instructor )->get_status() );
		$this->assertSame( 'trash', get_post_status( $lesson ) );
	}

	public function test_lesson_read_hides_content_until_access_is_granted() {
		$lesson = $this->fixture['lessons'][1];
		$public = $this->request( 'GET', '/lessons/' . $lesson )->get_data();
		$this->assertSame( '', $public['content'] );
		$this->assertFalse( $public['access']['allowed'] );
		$this->assertSame( 'logged_out', $public['access']['reason'] );
		$this->assertSame( $this->fixture['lessons'][2], $public['next']['id'] );

		$teacher = $this->request( 'GET', '/lessons/' . $lesson, array(), $this->instructor )->get_data();
		$this->assertStringContainsString( 'Lesson body.', $teacher['content'] );
	}
}
