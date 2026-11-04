<?php
/**
 * Tests for the installer.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests;

use PublicLMS\Core\Installer;
use WP_UnitTestCase;

/**
 * Covers the installer's table list and schema.
 *
 * @coversDefaultClass \PublicLMS\Core\Installer
 */
class InstallerTest extends WP_UnitTestCase {

	/**
	 * Drops the temporary copies install() created.
	 *
	 * The test suite turns CREATE TABLE into CREATE TEMPORARY TABLE, and a
	 * temporary table shadows the real one for the rest of the run. Its rows
	 * do not roll back, so later tests would see each other's data.
	 */
	public function tear_down() {
		global $wpdb;
		foreach ( Installer::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test cleanup of temporary tables.
			$wpdb->query( $wpdb->prepare( 'DROP TEMPORARY TABLE IF EXISTS %i', $table ) );
		}
		parent::tear_down();
	}

	/**
	 * The table list matches the data model in docs/ARCHITECTURE.md, and
	 * install() creates every table in it.
	 *
	 * The WordPress test suite turns CREATE TABLE into CREATE TEMPORARY TABLE,
	 * which SHOW TABLES does not list, so existence is checked with DESCRIBE.
	 *
	 * @covers ::install
	 * @covers ::tables
	 * @covers ::schema
	 */
	public function test_install_creates_every_table_in_the_table_list() {
		global $wpdb;

		$expected = array(
			'plms_enrollments',
			'plms_progress',
			'plms_course_progress',
			'plms_quiz_attempts',
			'plms_quiz_answers',
			'plms_orders',
			'plms_order_items',
			'plms_transactions',
			'plms_subscriptions',
			'plms_events',
			'plms_notifications',
			'plms_report_daily',
			'plms_assignment_submissions',
		);

		$this->assertSame( $expected, Installer::TABLES );
		$this->assertSame(
			array_map(
				static function ( $name ) use ( $wpdb ) {
					return $wpdb->prefix . $name;
				},
				$expected
			),
			Installer::tables()
		);

		Installer::install();

		foreach ( Installer::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test inspects the schema.
			$columns = $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', $table ) );
			$this->assertContains( 'id', $columns, "Table {$table} was not created." );
		}

		$this->assertSame( Installer::DB_VERSION, get_option( Installer::DB_VERSION_OPTION ) );
	}
}
