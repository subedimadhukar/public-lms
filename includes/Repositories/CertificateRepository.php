<?php
/**
 * Certificate repository.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Rows in plms_certificates: one per learner and course (ADR-044).
 *
 * A certificate stores what it says at the time it was issued (learner
 * name, course title, completion date) so a later rename does not change an
 * issued certificate. `certificate_key` is the public ID printed on it
 * ("PLMS-7K3F-Q9XM-2DWP"), unique, for Pro's verification page.
 * `template_id` and `revoked_at` are there for Pro's multiple templates and
 * revocation.
 */
class CertificateRepository extends AbstractRepository {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function table_name(): string {
		return 'plms_certificates';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function columns(): array {
		return array(
			'certificate_key' => '%s',
			'user_id'         => '%d',
			'course_id'       => '%d',
			'template_id'     => '%d',
			'learner_name'    => '%s',
			'course_title'    => '%s',
			'completed_at'    => '%s',
			'issued_at'       => '%s',
			'revoked_at'      => '%s',
			'data'            => '%s',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Certificate ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = parent::find( $id );
		return null === $row ? null : $this->normalize( $row );
	}

	/**
	 * Returns a learner's certificate for a course, or null.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 * @return array<string, mixed>|null
	 */
	public function for_course( int $user_id, int $course_id ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; single-row lookup by unique key.
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d AND course_id = %d', $this->table(), $user_id, $course_id ), ARRAY_A );
		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Returns a certificate by its public key, or null.
	 *
	 * @param string $key Certificate key.
	 * @return array<string, mixed>|null
	 */
	public function by_key( string $key ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; single-row lookup by unique key.
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE certificate_key = %s', $this->table(), strtoupper( $key ) ), ARRAY_A );
		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Returns a learner's certificates, newest first.
	 *
	 * @param int $user_id Learner.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; dashboard read.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY issued_at DESC, id DESC', $this->table(), $user_id ), ARRAY_A );
		return array_map( array( $this, 'normalize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Deletes every certificate of a learner, for data erasure.
	 *
	 * @param int $user_id Learner.
	 * @return int Rows deleted.
	 */
	public function delete_for_user( int $user_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$result = $this->db->delete( $this->table(), array( 'user_id' => $user_id ), array( '%d' ) );
		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Casts a row's columns.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function normalize( array $row ): array {
		foreach ( array( 'id', 'user_id', 'course_id', 'template_id' ) as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		$data        = json_decode( (string) $row['data'], true );
		$row['data'] = is_array( $data ) ? $data : array();
		return $row;
	}
}
