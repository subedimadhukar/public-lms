<?php
/**
 * Course structure repository contract.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the curriculum of a course: sections and their items.
 *
 * Structure is stored on the posts themselves (ADR-028): a section's
 * post_parent is its course, an item's post_parent is its section, and
 * menu_order orders both. Implementations cache the structure per course in
 * the object cache group 'publiclms' and invalidate it on every write.
 *
 * The structure array has this shape:
 *
 *     array(
 *         'course_id' => 12,
 *         'sections'  => array(
 *             array(
 *                 'id' => 30, 'title' => 'Getting started', 'status' => 'publish', 'menu_order' => 0,
 *                 'items' => array(
 *                     array( 'id' => 31, 'type' => 'lesson', 'title' => 'Welcome', 'status' => 'publish',
 *                            'menu_order' => 0, 'preview' => true, 'duration' => 5 ),
 *                 ),
 *             ),
 *         ),
 *     )
 */
interface CourseStructureRepositoryInterface {

	/**
	 * Returns the full structure of a course, every status except trash and auto-draft.
	 *
	 * @param int $course_id Course ID.
	 * @return array{course_id: int, sections: array<int, array<string, mixed>>}
	 */
	public function get_structure( int $course_id ): array;

	/**
	 * Returns the structure learners see: published sections with their published items.
	 *
	 * @param int $course_id Course ID.
	 * @return array{course_id: int, sections: array<int, array<string, mixed>>}
	 */
	public function get_public_structure( int $course_id ): array;

	/**
	 * Returns the published item IDs of a course in curriculum order.
	 *
	 * @param int $course_id Course ID.
	 * @return array<int, int>
	 */
	public function item_ids( int $course_id ): array;

	/**
	 * Returns the course a section or item belongs to, or 0.
	 *
	 * @param int $post_id Section, lesson, quiz or assignment ID.
	 * @return int
	 */
	public function course_id_for( int $post_id ): int;

	/**
	 * Returns the section an item belongs to, or 0.
	 *
	 * @param int $item_id Lesson, quiz or assignment ID.
	 * @return int
	 */
	public function section_id_for( int $item_id ): int;

	/**
	 * Returns the published items before and after an item, or 0 at either end.
	 *
	 * @param int $item_id Lesson, quiz or assignment ID.
	 * @return array{previous: int, next: int}
	 */
	public function adjacent_items( int $item_id ): array;

	/**
	 * Adds a section at the end of a course.
	 *
	 * @param int    $course_id Course ID.
	 * @param string $title     Section title.
	 * @param int    $author_id Author; 0 for the current user.
	 * @return int The section ID, or 0 on failure.
	 */
	public function add_section( int $course_id, string $title, int $author_id = 0 ): int;

	/**
	 * Adds an item at the end of a section.
	 *
	 * @param int    $section_id Section ID.
	 * @param string $type       'lesson', 'quiz' or 'assignment'.
	 * @param string $title      Item title.
	 * @param int    $author_id  Author; 0 for the current user.
	 * @param string $status     Post status for the new item.
	 * @return int The item ID, or 0 on failure.
	 */
	public function add_item( int $section_id, string $type, string $title, int $author_id = 0, string $status = 'publish' ): int;

	/**
	 * Renames a section or item.
	 *
	 * @param int    $post_id Section or item ID.
	 * @param string $title   New title.
	 * @return bool
	 */
	public function rename( int $post_id, string $title ): bool;

	/**
	 * Moves an item into a section at a zero-based position, reordering its new siblings.
	 *
	 * @param int $item_id    Item ID.
	 * @param int $section_id Target section ID.
	 * @param int $position   Zero-based position in the target section.
	 * @return bool
	 */
	public function move_item( int $item_id, int $section_id, int $position ): bool;

	/**
	 * Applies a whole curriculum order: sections in the given order, each with its items in order.
	 *
	 * Callers validate ownership of every ID first; this method only writes.
	 *
	 * @param int                                                $course_id Course ID.
	 * @param array<int, array{id: int, items: array<int, int>}> $tree      Ordered sections with ordered item IDs.
	 * @return bool
	 */
	public function save_order( int $course_id, array $tree ): bool;

	/**
	 * Moves a section and its items to the trash.
	 *
	 * @param int $section_id Section ID.
	 * @return bool
	 */
	public function delete_section( int $section_id ): bool;

	/**
	 * Moves an item to the trash.
	 *
	 * @param int $item_id Item ID.
	 * @return bool
	 */
	public function delete_item( int $item_id ): bool;

	/**
	 * Drops the cached structure of a course.
	 *
	 * @param int $course_id Course ID.
	 * @return void
	 */
	public function invalidate( int $course_id ): void;
}
