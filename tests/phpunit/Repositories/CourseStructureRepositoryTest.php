<?php
/**
 * Tests for the course structure repository.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Repositories;

use PublicLMS\Core\Cache;
use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepository;
use PublicLMS\Tests\TestCase;

/**
 * @coversDefaultClass \PublicLMS\Repositories\CourseStructureRepository
 */
class CourseStructureRepositoryTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var CourseStructureRepository
	 */
	private $repo;

	public function set_up() {
		parent::set_up();
		$this->repo = publiclms()->container()->get( CourseStructureRepository::class );
	}

	/**
	 * @covers ::get_structure
	 */
	public function test_get_structure_returns_sections_and_items_in_menu_order() {
		$course  = $this->create_course();
		$second  = $this->create_section( $course, 1, array( 'post_title' => 'Describing data' ) );
		$first   = $this->create_section( $course, 0, array( 'post_title' => 'Why statistics' ) );
		$lesson2 = $this->create_lesson( $first, 1, array( 'post_title' => 'Samples and populations' ) );
		$lesson1 = $this->create_lesson( $first, 0, array( 'post_title' => 'What statistics answers' ) );
		$lesson3 = $this->create_lesson( $second, 0, array( 'post_title' => 'Mean, median and mode' ) );
		update_post_meta( $lesson1, Meta::PREVIEW, true );
		update_post_meta( $lesson1, Meta::DURATION, 12 );

		$structure = $this->repo->get_structure( $course );

		$this->assertSame( $course, $structure['course_id'] );
		$this->assertSame( array( $first, $second ), wp_list_pluck( $structure['sections'], 'id' ) );
		$this->assertSame( 'Why statistics', $structure['sections'][0]['title'] );
		$this->assertSame( array( $lesson1, $lesson2 ), wp_list_pluck( $structure['sections'][0]['items'], 'id' ) );
		$this->assertSame( array( $lesson3 ), wp_list_pluck( $structure['sections'][1]['items'], 'id' ) );
		$this->assertSame( 'lesson', $structure['sections'][0]['items'][0]['type'] );
		$this->assertTrue( $structure['sections'][0]['items'][0]['preview'] );
		$this->assertSame( 12, $structure['sections'][0]['items'][0]['duration'] );
		$this->assertFalse( $structure['sections'][0]['items'][1]['preview'] );
	}

	/**
	 * @covers ::get_structure
	 */
	public function test_get_structure_of_a_non_course_is_empty() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( array(), $this->repo->get_structure( $page )['sections'] );
		$this->assertSame( array(), $this->repo->get_structure( 0 )['sections'] );
	}

	/**
	 * @covers ::get_structure
	 * @covers ::on_clean_post_cache
	 */
	public function test_structure_is_cached_and_cleared_when_an_item_changes_elsewhere() {
		$fixture = $this->create_course_with_lessons();
		$this->repo->get_structure( $fixture['course'] );
		$this->assertIsArray( Cache::get( 'structure:' . $fixture['course'] ) );

		wp_update_post(
			array(
				'ID'         => $fixture['lessons'][0],
				'post_title' => 'Renamed in the block editor',
			)
		);

		$this->assertFalse( Cache::get( 'structure:' . $fixture['course'] ) );
		$this->assertSame( 'Renamed in the block editor', $this->repo->get_structure( $fixture['course'] )['sections'][0]['items'][0]['title'] );
	}

	/**
	 * @covers ::on_post_updated
	 */
	public function test_moving_a_lesson_elsewhere_clears_both_courses() {
		$a = $this->create_course_with_lessons();
		$b = $this->create_course_with_lessons();
		$this->repo->get_structure( $a['course'] );
		$this->repo->get_structure( $b['course'] );

		wp_update_post(
			array(
				'ID'          => $a['lessons'][0],
				'post_parent' => $b['sections'][0],
			)
		);

		$this->assertFalse( Cache::get( 'structure:' . $a['course'] ) );
		$this->assertFalse( Cache::get( 'structure:' . $b['course'] ) );
		$this->assertNotContains( $a['lessons'][0], $this->repo->item_ids( $a['course'] ) );
		$this->assertContains( $a['lessons'][0], $this->repo->item_ids( $b['course'] ) );
	}

	/**
	 * @covers ::get_public_structure
	 */
	public function test_public_structure_hides_drafts_and_unpublished_sections() {
		$course        = $this->create_course();
		$published     = $this->create_section( $course, 0 );
		$draft_section = $this->create_section( $course, 1, array( 'post_status' => 'draft' ) );
		$live          = $this->create_lesson( $published, 0 );
		$this->create_lesson( $published, 1, array( 'post_status' => 'draft' ) );
		$this->create_lesson( $draft_section, 0 );

		$public = $this->repo->get_public_structure( $course );

		$this->assertCount( 1, $public['sections'] );
		$this->assertSame( array( $live ), wp_list_pluck( $public['sections'][0]['items'], 'id' ) );
		$this->assertCount( 2, $this->repo->get_structure( $course )['sections'] );
	}

	/**
	 * @covers ::item_ids
	 * @covers ::adjacent_items
	 */
	public function test_item_ids_and_adjacent_items_follow_curriculum_order() {
		$fixture = $this->create_course_with_lessons();
		$lessons = $fixture['lessons'];

		$this->assertSame( $lessons, $this->repo->item_ids( $fixture['course'] ) );
		$this->assertSame(
			array(
				'previous' => 0,
				'next'     => $lessons[1],
			),
			$this->repo->adjacent_items( $lessons[0] )
		);
		$this->assertSame(
			array(
				'previous' => $lessons[1],
				'next'     => $lessons[3],
			),
			$this->repo->adjacent_items( $lessons[2] )
		);
		$this->assertSame(
			array(
				'previous' => $lessons[2],
				'next'     => 0,
			),
			$this->repo->adjacent_items( $lessons[3] )
		);
		$orphan = (int) self::factory()->post->create( array( 'post_type' => PostTypes::LESSON ) );
		$this->assertSame(
			array(
				'previous' => 0,
				'next'     => 0,
			),
			$this->repo->adjacent_items( $orphan )
		);
	}

	/**
	 * @covers ::course_id_for
	 * @covers ::section_id_for
	 */
	public function test_course_and_section_lookups() {
		$fixture = $this->create_course_with_lessons();
		$orphan  = (int) self::factory()->post->create( array( 'post_type' => PostTypes::LESSON ) );
		$post    = (int) self::factory()->post->create();

		$this->assertSame( $fixture['course'], $this->repo->course_id_for( $fixture['lessons'][3] ) );
		$this->assertSame( $fixture['course'], $this->repo->course_id_for( $fixture['sections'][1] ) );
		$this->assertSame( $fixture['sections'][1], $this->repo->section_id_for( $fixture['lessons'][3] ) );
		$this->assertSame( 0, $this->repo->course_id_for( $orphan ) );
		$this->assertSame( 0, $this->repo->section_id_for( $orphan ) );
		$this->assertSame( 0, $this->repo->course_id_for( $post ) );
		$this->assertSame( 0, $this->repo->section_id_for( $fixture['sections'][0] ) );
		$this->assertSame( 0, $this->repo->course_id_for( 999999 ) );
	}

	/**
	 * @covers ::add_section
	 */
	public function test_add_section_appends_to_the_course() {
		$fixture = $this->create_course_with_lessons();
		$this->repo->get_structure( $fixture['course'] );

		$id = $this->repo->add_section( $fixture['course'], 'Inference', 1 );

		$this->assertGreaterThan( 0, $id );
		$sections = $this->repo->get_structure( $fixture['course'] )['sections'];
		$this->assertSame( $id, $sections[2]['id'] );
		$this->assertSame( 'Inference', $sections[2]['title'] );
		$this->assertSame( 2, $sections[2]['menu_order'] );
		$this->assertSame( 0, $this->repo->add_section( $fixture['lessons'][0], 'Not a course' ) );
	}

	/**
	 * @covers ::add_item
	 */
	public function test_add_item_appends_to_the_section_and_rejects_unknown_types() {
		$fixture = $this->create_course_with_lessons();

		$id = $this->repo->add_item( $fixture['sections'][0], 'lesson', 'Variance', 1 );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( PostTypes::LESSON, get_post_type( $id ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$items = $this->repo->get_structure( $fixture['course'] )['sections'][0]['items'];
		$this->assertSame( $id, end( $items )['id'] );
		$this->assertSame( 2, end( $items )['menu_order'] );

		$draft = $this->repo->add_item( $fixture['sections'][0], 'lesson', 'Draft lesson', 1, 'draft' );
		$this->assertSame( 'draft', get_post_status( $draft ) );

		$this->assertSame( 0, $this->repo->add_item( $fixture['sections'][0], 'video', 'Nope' ) );
		$this->assertSame( 0, $this->repo->add_item( $fixture['course'], 'lesson', 'Not a section' ) );
	}

	/**
	 * @covers ::rename
	 */
	public function test_rename_updates_sections_and_items_only() {
		$fixture = $this->create_course_with_lessons();

		$this->assertTrue( $this->repo->rename( $fixture['sections'][0], 'Foundations' ) );
		$this->assertTrue( $this->repo->rename( $fixture['lessons'][0], 'Why we measure' ) );
		$this->assertFalse( $this->repo->rename( $fixture['course'], 'Courses are renamed elsewhere' ) );

		$structure = $this->repo->get_structure( $fixture['course'] );
		$this->assertSame( 'Foundations', $structure['sections'][0]['title'] );
		$this->assertSame( 'Why we measure', $structure['sections'][0]['items'][0]['title'] );
	}

	/**
	 * @covers ::move_item
	 */
	public function test_move_item_reparents_and_reorders_siblings() {
		$fixture = $this->create_course_with_lessons();
		$lessons = $fixture['lessons'];

		$this->assertTrue( $this->repo->move_item( $lessons[0], $fixture['sections'][1], 1 ) );

		$structure = $this->repo->get_structure( $fixture['course'] );
		$this->assertSame( array( $lessons[1] ), wp_list_pluck( $structure['sections'][0]['items'], 'id' ) );
		$this->assertSame( array( $lessons[2], $lessons[0], $lessons[3] ), wp_list_pluck( $structure['sections'][1]['items'], 'id' ) );
		$this->assertSame( array( 0, 1, 2 ), wp_list_pluck( $structure['sections'][1]['items'], 'menu_order' ) );

		$this->assertTrue( $this->repo->move_item( $lessons[3], $fixture['sections'][1], 99 ) );
		$this->assertFalse( $this->repo->move_item( $fixture['sections'][0], $fixture['sections'][1], 0 ) );
		$this->assertFalse( $this->repo->move_item( $lessons[1], $fixture['course'], 0 ) );
	}

	/**
	 * @covers ::save_order
	 */
	public function test_save_order_applies_a_whole_tree() {
		$fixture = $this->create_course_with_lessons();
		$s       = $fixture['sections'];
		$l       = $fixture['lessons'];

		$this->assertTrue(
			$this->repo->save_order(
				$fixture['course'],
				array(
					array(
						'id'    => $s[1],
						'items' => array( $l[3], $l[0] ),
					),
					array(
						'id'    => $s[0],
						'items' => array( $l[2], $l[1] ),
					),
				)
			)
		);

		$structure = $this->repo->get_structure( $fixture['course'] );
		$this->assertSame( array( $s[1], $s[0] ), wp_list_pluck( $structure['sections'], 'id' ) );
		$this->assertSame( array( $l[3], $l[0], $l[2], $l[1] ), $this->repo->item_ids( $fixture['course'] ) );
		$this->assertFalse( $this->repo->save_order( $s[0], array() ) );
	}

	/**
	 * @covers ::save_order
	 */
	public function test_save_order_can_take_a_section_from_another_course() {
		$a = $this->create_course_with_lessons();
		$b = $this->create_course_with_lessons();
		$this->repo->get_structure( $b['course'] );

		$this->repo->save_order(
			$a['course'],
			array(
				array(
					'id'    => $a['sections'][0],
					'items' => array( $a['lessons'][0], $a['lessons'][1] ),
				),
				array(
					'id'    => $b['sections'][0],
					'items' => array( $b['lessons'][0] ),
				),
			)
		);

		$this->assertSame( $a['course'], $this->repo->course_id_for( $b['sections'][0] ) );
		$this->assertCount( 1, $this->repo->get_structure( $b['course'] )['sections'] );
	}

	/**
	 * @covers ::delete_section
	 */
	public function test_delete_section_trashes_the_section_and_its_items() {
		$fixture = $this->create_course_with_lessons();

		$this->assertTrue( $this->repo->delete_section( $fixture['sections'][0] ) );

		$this->assertSame( 'trash', get_post_status( $fixture['sections'][0] ) );
		$this->assertSame( 'trash', get_post_status( $fixture['lessons'][0] ) );
		$this->assertSame( 'trash', get_post_status( $fixture['lessons'][1] ) );
		$this->assertSame( array( $fixture['lessons'][2], $fixture['lessons'][3] ), $this->repo->item_ids( $fixture['course'] ) );
		$this->assertFalse( $this->repo->delete_section( $fixture['lessons'][2] ) );
	}

	/**
	 * @covers ::delete_item
	 */
	public function test_delete_item_trashes_only_items() {
		$fixture = $this->create_course_with_lessons();

		$this->assertTrue( $this->repo->delete_item( $fixture['lessons'][1] ) );
		$this->assertSame( 'trash', get_post_status( $fixture['lessons'][1] ) );
		$this->assertNotContains( $fixture['lessons'][1], $this->repo->item_ids( $fixture['course'] ) );
		$this->assertFalse( $this->repo->delete_item( $fixture['sections'][0] ) );
	}

	/**
	 * @covers ::invalidate
	 */
	public function test_invalidate_drops_the_cached_structure() {
		$fixture = $this->create_course_with_lessons();
		$this->repo->get_structure( $fixture['course'] );

		$this->repo->invalidate( $fixture['course'] );

		$this->assertFalse( Cache::get( 'structure:' . $fixture['course'] ) );
	}

	/**
	 * @covers ::is_item_type
	 * @covers ::item_type_of
	 */
	public function test_item_type_helpers() {
		$this->assertTrue( $this->repo->is_item_type( PostTypes::QUIZ ) );
		$this->assertFalse( $this->repo->is_item_type( PostTypes::SECTION ) );
		$this->assertSame( 'assignment', $this->repo->item_type_of( PostTypes::ASSIGNMENT ) );
		$this->assertSame( '', $this->repo->item_type_of( 'post' ) );
	}
}
