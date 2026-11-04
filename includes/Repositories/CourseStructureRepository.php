<?php
/**
 * Course structure repository.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

use PublicLMS\Core\Cache;
use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Curriculum stored in post_parent and menu_order (ADR-028).
 *
 * A section's post_parent is its course and an item's post_parent is its
 * section. Both are ordered by menu_order, then ID. The structure is read
 * with two queries and cached per course under `structure:{course_id}`;
 * any save, trash or delete of a course, section or item clears it, also
 * when the change comes from outside this class (block editor, wp-admin).
 * Queries pass `'lang' => ''`: a course's structure is loaded by ID in
 * every language, because Polylang adds its language condition on
 * parse_query, which suppress_filters does not skip (finding 5).
 */
class CourseStructureRepository implements CourseStructureRepositoryInterface {

	/**
	 * Item types and their post types.
	 */
	const ITEM_TYPES = array(
		'lesson'     => PostTypes::LESSON,
		'quiz'       => PostTypes::QUIZ,
		'assignment' => PostTypes::ASSIGNMENT,
	);

	/**
	 * Statuses that appear in the authoring structure.
	 */
	const AUTHORING_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Hooks cache invalidation for changes made outside the repository.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'clean_post_cache', array( $this, 'on_clean_post_cache' ), 10, 2 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
	}

	/**
	 * Clears the structure cache of the course a changed post belongs to.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 * @return void
	 */
	public function on_clean_post_cache( $post_id, $post = null ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$this->invalidate_for_post( $post );
	}

	/**
	 * Clears the caches of the courses an item or section was moved between.
	 *
	 * The clean_post_cache action receives the object cached before the update, so the
	 * course it was moved into is cleared here.
	 *
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post_after  Post after the update.
	 * @param WP_Post $post_before Post before the update.
	 * @return void
	 */
	public function on_post_updated( $post_id, $post_after, $post_before ): void {
		if ( $post_before instanceof WP_Post && $post_after instanceof WP_Post && (int) $post_before->post_parent !== (int) $post_after->post_parent ) {
			$this->invalidate_for_post( $post_before );
			$this->invalidate_for_post( $post_after );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $course_id Course ID.
	 * @return array{course_id: int, sections: array<int, array<string, mixed>>}
	 */
	public function get_structure( int $course_id ): array {
		$empty = array(
			'course_id' => $course_id,
			'sections'  => array(),
		);
		if ( $course_id <= 0 || PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return $empty;
		}

		$cached = Cache::get( 'structure:' . $course_id, $found );
		if ( $found && is_array( $cached ) ) {
			/**
			 * Cached structure.
			 *
			 * @var array{course_id: int, sections: array<int, array<string, mixed>>} $cached
			 */
			return $cached;
		}

		$sections = get_posts(
			array(
				'post_type'        => PostTypes::SECTION,
				'post_parent'      => $course_id,
				'post_status'      => self::AUTHORING_STATUSES,
				'posts_per_page'   => -1,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'lang'             => '', // All languages (Polylang).
			)
		);

		$structure = $empty;
		if ( ! empty( $sections ) ) {
			$section_ids = wp_list_pluck( $sections, 'ID' );
			$items       = get_posts(
				array(
					'post_type'        => array_values( self::ITEM_TYPES ),
					'post_parent__in'  => array_map( 'intval', $section_ids ),
					'post_status'      => self::AUTHORING_STATUSES,
					'posts_per_page'   => -1,
					'orderby'          => array(
						'menu_order' => 'ASC',
						'ID'         => 'ASC',
					),
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'lang'             => '', // All languages (Polylang).
				)
			);

			$by_section = array();
			foreach ( $items as $item ) {
				$by_section[ (int) $item->post_parent ][] = $this->item_to_array( $item );
			}

			foreach ( $sections as $section ) {
				$structure['sections'][] = array(
					'id'         => (int) $section->ID,
					'title'      => (string) $section->post_title,
					'status'     => (string) $section->post_status,
					'menu_order' => (int) $section->menu_order,
					'items'      => $by_section[ (int) $section->ID ] ?? array(),
				);
			}
		}

		Cache::set( 'structure:' . $course_id, $structure );
		return $structure;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $course_id Course ID.
	 * @return array{course_id: int, sections: array<int, array<string, mixed>>}
	 */
	public function get_public_structure( int $course_id ): array {
		$structure = $this->get_structure( $course_id );
		$sections  = array();
		foreach ( $structure['sections'] as $section ) {
			if ( 'publish' !== $section['status'] ) {
				continue;
			}
			$section['items'] = array_values(
				array_filter(
					(array) $section['items'],
					static function ( $item ) {
						return 'publish' === $item['status'];
					}
				)
			);
			$sections[]       = $section;
		}
		$structure['sections'] = $sections;
		return $structure;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $course_id Course ID.
	 * @return array<int, int>
	 */
	public function item_ids( int $course_id ): array {
		$ids = array();
		foreach ( $this->get_public_structure( $course_id )['sections'] as $section ) {
			foreach ( (array) $section['items'] as $item ) {
				$ids[] = (int) $item['id'];
			}
		}
		return $ids;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Section, lesson, quiz or assignment ID.
	 * @return int
	 */
	public function course_id_for( int $post_id ): int {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return 0;
		}
		if ( PostTypes::SECTION === $post->post_type ) {
			$parent = (int) $post->post_parent;
			return $parent > 0 && PostTypes::COURSE === get_post_type( $parent ) ? $parent : 0;
		}
		if ( $this->is_item_type( $post->post_type ) ) {
			$section_id = $this->section_id_for( $post_id );
			return $section_id > 0 ? $this->course_id_for( $section_id ) : 0;
		}
		return 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $item_id Lesson, quiz or assignment ID.
	 * @return int
	 */
	public function section_id_for( int $item_id ): int {
		$post = get_post( $item_id );
		if ( ! $post instanceof WP_Post || ! $this->is_item_type( $post->post_type ) ) {
			return 0;
		}
		$parent = (int) $post->post_parent;
		return $parent > 0 && PostTypes::SECTION === get_post_type( $parent ) ? $parent : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $item_id Lesson, quiz or assignment ID.
	 * @return array{previous: int, next: int}
	 */
	public function adjacent_items( int $item_id ): array {
		$ids      = $this->item_ids( $this->course_id_for( $item_id ) );
		$position = array_search( $item_id, $ids, true );
		if ( false === $position ) {
			return array(
				'previous' => 0,
				'next'     => 0,
			);
		}
		return array(
			'previous' => $ids[ $position - 1 ] ?? 0,
			'next'     => $ids[ $position + 1 ] ?? 0,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $course_id Course ID.
	 * @param string $title     Section title.
	 * @param int    $author_id Author; 0 for the current user.
	 * @return int
	 */
	public function add_section( int $course_id, string $title, int $author_id = 0 ): int {
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return 0;
		}
		$structure = $this->get_structure( $course_id );
		$id        = wp_insert_post(
			array(
				'post_type'   => PostTypes::SECTION,
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_parent' => $course_id,
				'menu_order'  => count( $structure['sections'] ),
				'post_author' => $author_id > 0 ? $author_id : get_current_user_id(),
			),
			true
		);
		$this->invalidate( $course_id );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $section_id Section ID.
	 * @param string $type       'lesson', 'quiz' or 'assignment'.
	 * @param string $title      Item title.
	 * @param int    $author_id  Author; 0 for the current user.
	 * @param string $status     Post status for the new item.
	 * @return int
	 */
	public function add_item( int $section_id, string $type, string $title, int $author_id = 0, string $status = 'publish' ): int {
		if ( ! isset( self::ITEM_TYPES[ $type ] ) || PostTypes::SECTION !== get_post_type( $section_id ) ) {
			return 0;
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::ITEM_TYPES[ $type ],
				'post_title'  => $title,
				'post_status' => in_array( $status, self::AUTHORING_STATUSES, true ) ? $status : 'draft',
				'post_parent' => $section_id,
				'menu_order'  => $this->count_children( $section_id ),
				'post_author' => $author_id > 0 ? $author_id : get_current_user_id(),
			),
			true
		);
		$this->invalidate( $this->course_id_for( $section_id ) );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $post_id Section or item ID.
	 * @param string $title   New title.
	 * @return bool
	 */
	public function rename( int $post_id, string $title ): bool {
		$type = get_post_type( $post_id );
		if ( PostTypes::SECTION !== $type && ! $this->is_item_type( (string) $type ) ) {
			return false;
		}
		$result = wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $title,
			),
			true
		);
		$this->invalidate( $this->course_id_for( $post_id ) );
		return ! is_wp_error( $result ) && $result > 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $item_id    Item ID.
	 * @param int $section_id Target section ID.
	 * @param int $position   Zero-based position in the target section.
	 * @return bool
	 */
	public function move_item( int $item_id, int $section_id, int $position ): bool {
		if ( ! $this->is_item_type( (string) get_post_type( $item_id ) ) || PostTypes::SECTION !== get_post_type( $section_id ) ) {
			return false;
		}
		$old_course = $this->course_id_for( $item_id );

		$siblings = get_posts(
			array(
				'post_type'        => array_values( self::ITEM_TYPES ),
				'post_parent'      => $section_id,
				'post_status'      => self::AUTHORING_STATUSES,
				'posts_per_page'   => -1,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'lang'             => '', // All languages (Polylang).
			)
		);
		$siblings = array_values( array_diff( array_map( 'intval', $siblings ), array( $item_id ) ) );
		$position = max( 0, min( $position, count( $siblings ) ) );
		array_splice( $siblings, $position, 0, array( $item_id ) );

		foreach ( $siblings as $order => $sibling_id ) {
			$this->set_parent_and_order( $sibling_id, $section_id, $order );
		}

		$this->invalidate( $old_course );
		$this->invalidate( $this->course_id_for( $section_id ) );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                                                $course_id Course ID.
	 * @param array<int, array{id: int, items: array<int, int>}> $tree      Ordered sections with ordered item IDs.
	 * @return bool
	 */
	public function save_order( int $course_id, array $tree ): bool {
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return false;
		}
		$affected = array( $course_id => true );

		foreach ( array_values( $tree ) as $section_order => $section ) {
			$section_id = (int) $section['id'];
			if ( PostTypes::SECTION !== get_post_type( $section_id ) ) {
				continue;
			}
			$previous_course = $this->course_id_for( $section_id );
			if ( $previous_course > 0 ) {
				$affected[ $previous_course ] = true;
			}
			$this->set_parent_and_order( $section_id, $course_id, $section_order );

			foreach ( array_values( (array) $section['items'] ) as $item_order => $item_id ) {
				$item_id = (int) $item_id;
				if ( ! $this->is_item_type( (string) get_post_type( $item_id ) ) ) {
					continue;
				}
				$previous_course = $this->course_id_for( $item_id );
				if ( $previous_course > 0 ) {
					$affected[ $previous_course ] = true;
				}
				$this->set_parent_and_order( $item_id, $section_id, $item_order );
			}
		}

		foreach ( array_keys( $affected ) as $affected_course ) {
			$this->invalidate( (int) $affected_course );
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $section_id Section ID.
	 * @return bool
	 */
	public function delete_section( int $section_id ): bool {
		if ( PostTypes::SECTION !== get_post_type( $section_id ) ) {
			return false;
		}
		$course_id = $this->course_id_for( $section_id );
		$children  = get_posts(
			array(
				'post_type'        => array_values( self::ITEM_TYPES ),
				'post_parent'      => $section_id,
				'post_status'      => self::AUTHORING_STATUSES,
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'lang'             => '', // All languages (Polylang).
			)
		);
		foreach ( $children as $child_id ) {
			wp_trash_post( (int) $child_id );
		}
		$result = wp_trash_post( $section_id );
		$this->invalidate( $course_id );
		return $result instanceof WP_Post;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $item_id Item ID.
	 * @return bool
	 */
	public function delete_item( int $item_id ): bool {
		if ( ! $this->is_item_type( (string) get_post_type( $item_id ) ) ) {
			return false;
		}
		$course_id = $this->course_id_for( $item_id );
		$result    = wp_trash_post( $item_id );
		$this->invalidate( $course_id );
		return $result instanceof WP_Post;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $course_id Course ID.
	 * @return void
	 */
	public function invalidate( int $course_id ): void {
		if ( $course_id > 0 ) {
			Cache::delete( 'structure:' . $course_id );
		}
	}

	/**
	 * Whether a post type is a curriculum item type.
	 *
	 * @param string $post_type Post type key.
	 * @return bool
	 */
	public function is_item_type( string $post_type ): bool {
		return in_array( $post_type, self::ITEM_TYPES, true );
	}

	/**
	 * Returns the item type name ('lesson', 'quiz', 'assignment') for a post type, or ''.
	 *
	 * @param string $post_type Post type key.
	 * @return string
	 */
	public function item_type_of( string $post_type ): string {
		$type = array_search( $post_type, self::ITEM_TYPES, true );
		return false === $type ? '' : (string) $type;
	}

	/**
	 * Clears the cache of the course a post belongs to, from its stored parent.
	 *
	 * @param WP_Post $post Course, section or item.
	 * @return void
	 */
	private function invalidate_for_post( WP_Post $post ): void {
		if ( PostTypes::COURSE === $post->post_type ) {
			$this->invalidate( (int) $post->ID );
			return;
		}
		if ( PostTypes::SECTION === $post->post_type ) {
			$this->invalidate( (int) $post->post_parent );
			return;
		}
		if ( $this->is_item_type( $post->post_type ) && $post->post_parent > 0 ) {
			$section = get_post( (int) $post->post_parent );
			if ( $section instanceof WP_Post && PostTypes::SECTION === $section->post_type ) {
				$this->invalidate( (int) $section->post_parent );
			}
		}
	}

	/**
	 * Converts an item post to its structure entry.
	 *
	 * @param WP_Post $item Lesson, quiz or assignment.
	 * @return array<string, mixed>
	 */
	private function item_to_array( WP_Post $item ): array {
		return array(
			'id'         => (int) $item->ID,
			'type'       => $this->item_type_of( $item->post_type ),
			'title'      => (string) $item->post_title,
			'status'     => (string) $item->post_status,
			'menu_order' => (int) $item->menu_order,
			'preview'    => (bool) get_post_meta( $item->ID, Meta::PREVIEW, true ),
			'duration'   => (int) get_post_meta( $item->ID, Meta::DURATION, true ),
		);
	}

	/**
	 * Counts the items of a section, any authoring status.
	 *
	 * @param int $section_id Section ID.
	 * @return int
	 */
	private function count_children( int $section_id ): int {
		$ids = get_posts(
			array(
				'post_type'        => array_values( self::ITEM_TYPES ),
				'post_parent'      => $section_id,
				'post_status'      => self::AUTHORING_STATUSES,
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'lang'             => '', // All languages (Polylang).
			)
		);
		return count( $ids );
	}

	/**
	 * Writes post_parent and menu_order when either changed.
	 *
	 * @param int $post_id   Post ID.
	 * @param int $parent_id New parent.
	 * @param int $order     New menu order.
	 * @return void
	 */
	private function set_parent_and_order( int $post_id, int $parent_id, int $order ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( (int) $post->post_parent === $parent_id && (int) $post->menu_order === $order ) {
			return;
		}
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_parent' => $parent_id,
				'menu_order'  => $order,
			)
		);
	}
}
