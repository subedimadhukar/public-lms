<?php
/**
 * Course structure under a translation plugin (finding 5).
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Repositories;

use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Tests\TestCase;
use WP_Query;

/**
 * A stand-in for Polylang: on parse_query it limits every query to "the
 * current language" (here: nothing) unless the query asks for `lang` = ''.
 */
class StructureLanguageTest extends TestCase {

	/**
	 * Adds the stand-in language condition.
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public function language_filter( $query ): void {
		if ( '' !== ( $query->query_vars['lang'] ?? null ) ) {
			$query->set( 'author', PHP_INT_MAX );
		}
	}

	public function tear_down() {
		remove_action( 'parse_query', array( $this, 'language_filter' ) );
		parent::tear_down();
	}

	public function test_structure_loads_in_every_language() {
		$fixture = $this->create_course_with_lessons();
		add_action( 'parse_query', array( $this, 'language_filter' ) );
		wp_cache_flush();

		$this->assertCount( 0, get_posts( array( 'post_type' => 'plms_lesson', 'suppress_filters' => true ) ), 'The stand-in filter hides posts from ordinary queries.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$structure = publiclms()->container()->get( CourseStructureRepositoryInterface::class );
		$this->assertSame( $fixture['lessons'], $structure->item_ids( $fixture['course'] ) );
		$this->assertSame( $fixture['course'], $structure->course_id_for( $fixture['lessons'][3] ) );
		$this->assertCount( 2, $structure->get_public_structure( $fixture['course'] )['sections'] );
	}
}
