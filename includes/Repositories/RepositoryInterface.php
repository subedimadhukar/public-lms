<?php
/**
 * Base repository contract.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Row-level access to one custom table.
 *
 * Repositories are the only code that touches the plugin's tables (ADR-002).
 * Rows are plain associative arrays at this layer; domain models arrive with
 * the phase 1 implementations.
 */
interface RepositoryInterface {

	/**
	 * Returns the table name, with the site prefix.
	 *
	 * @return string
	 */
	public function table(): string;

	/**
	 * Finds one row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>|null The row, or null when it does not exist.
	 */
	public function find( int $id ): ?array;

	/**
	 * Inserts a row.
	 *
	 * @param array<string, mixed> $data Column values; unknown columns are ignored.
	 * @return int The new row ID, or 0 on failure.
	 */
	public function insert( array $data ): int;

	/**
	 * Updates a row by primary key.
	 *
	 * @param int                  $id   Row ID.
	 * @param array<string, mixed> $data Column values; unknown columns are ignored.
	 * @return bool Whether the query succeeded.
	 */
	public function update( int $id, array $data ): bool;

	/**
	 * Deletes a row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return bool Whether a row was deleted.
	 */
	public function delete( int $id ): bool;
}
