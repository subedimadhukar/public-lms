<?php
/**
 * Event log repository.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only rows in plms_events.
 *
 * Phase 1 records enrollment.created, item.completed and course.completed;
 * reports, notifications and webhooks read them from phase 2.
 */
class EventRepository implements EventRepositoryInterface {

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database connection.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Returns the table name with the site prefix.
	 *
	 * @return string
	 */
	public function table(): string {
		return $this->db->prefix . 'plms_events';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $event_type Dotted event name, for example 'lesson.completed'.
	 * @param array<string, mixed> $context    Optional user_id, course_id, object_type, object_id and payload.
	 * @return int
	 */
	public function record( string $event_type, array $context = array() ): int {
		$event_type = substr( preg_replace( '/[^a-z0-9_.]/', '', strtolower( $event_type ) ), 0, 64 );
		if ( '' === $event_type ) {
			return 0;
		}

		$data    = array(
			'event_type' => $event_type,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$formats = array( '%s', '%s' );

		foreach ( array( 'user_id', 'course_id', 'object_id' ) as $column ) {
			if ( ! empty( $context[ $column ] ) ) {
				$data[ $column ] = absint( $context[ $column ] );
				$formats[]       = '%d';
			}
		}
		if ( ! empty( $context['object_type'] ) ) {
			$data['object_type'] = substr( sanitize_key( (string) $context['object_type'] ), 0, 20 );
			$formats[]           = '%s';
		}
		if ( isset( $context['payload'] ) ) {
			$data['payload'] = (string) wp_json_encode( $context['payload'] );
			$formats[]       = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom append-only table.
		$result = $this->db->insert( $this->table(), $data, $formats );
		return false === $result ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $after_id Return events with an ID greater than this.
	 * @param int $limit    Maximum events to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function since( int $after_id, int $limit = 500 ): array {
		$limit = max( 1, min( 5000, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; incremental reads are not cached.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $this->table(), $after_id, $limit ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map(
			static function ( $row ) {
				$row['id']      = (int) $row['id'];
				$row['payload'] = null === $row['payload'] ? null : json_decode( (string) $row['payload'], true );
				return $row;
			},
			$rows
		);
	}
}
