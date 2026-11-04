<?php
/**
 * Base repository over $wpdb.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Generic create, read, update and delete for one table.
 *
 * Subclasses declare the table and its column formats. Every query goes
 * through $wpdb->prepare() or the $wpdb helpers with explicit formats, and
 * only whitelisted columns are written. Domain queries (by user and course,
 * keyset pagination, upserts) belong in the subclasses.
 */
abstract class AbstractRepository implements RepositoryInterface {

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	protected $db;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db Database connection.
	 */
	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Returns the table name without the site prefix, for example 'plms_enrollments'.
	 *
	 * @return string
	 */
	abstract protected function table_name(): string;

	/**
	 * Returns the writable columns and their $wpdb formats ('%d', '%f' or '%s').
	 *
	 * The primary key is not included.
	 *
	 * @return array<string, string>
	 */
	abstract protected function columns(): array;

	/**
	 * Returns the table name, with the site prefix.
	 *
	 * @return string
	 */
	public function table(): string {
		return $this->db->prefix . $this->table_name();
	}

	/**
	 * Finds one row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; caching is added per repository where reads are hot.
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Inserts a row; unknown columns are ignored.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int The new row ID, or 0 on failure.
	 */
	public function insert( array $data ): int {
		$data = $this->filter_columns( $data );
		if ( empty( $data ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$result = $this->db->insert( $this->table(), $data, $this->formats( $data ) );
		return false === $result ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Updates a row by primary key; unknown columns are ignored.
	 *
	 * @param int                  $id   Row ID.
	 * @param array<string, mixed> $data Column values.
	 * @return bool Whether the query succeeded.
	 */
	public function update( int $id, array $data ): bool {
		$data = $this->filter_columns( $data );
		if ( empty( $data ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$result = $this->db->update( $this->table(), $data, array( 'id' => $id ), $this->formats( $data ), array( '%d' ) );
		return false !== $result;
	}

	/**
	 * Deletes a row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return bool Whether a row was deleted.
	 */
	public function delete( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$result = $this->db->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		return is_int( $result ) && $result > 0;
	}

	/**
	 * Returns the current time in UTC as a MySQL DATETIME string.
	 *
	 * @return string
	 */
	protected function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Drops keys that are not writable columns.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return array<string, mixed>
	 */
	protected function filter_columns( array $data ): array {
		return array_intersect_key( $data, $this->columns() );
	}

	/**
	 * Returns the $wpdb formats for the given columns, in the same order.
	 *
	 * @param array<string, mixed> $data Column values, already filtered.
	 * @return array<int, string>
	 */
	protected function formats( array $data ): array {
		$columns = $this->columns();
		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = $columns[ $column ];
		}
		return $formats;
	}
}
