<?php
/**
 * Personal data reads and erasure for the privacy tools.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

use PublicLMS\Core\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Pages through and removes a person's rows in the learner tables that have
 * no repository of their own (events, notifications, orders, transactions),
 * and pages enrolments and item progress for export (ADR-044).
 */
class PersonalDataRepository {

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Tables a page can be read from, by key.
	 */
	const TABLES = array(
		'enrollments'   => 'plms_enrollments',
		'progress'      => 'plms_progress',
		'events'        => 'plms_events',
		'notifications' => 'plms_notifications',
	);

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database connection.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Returns a page of a user's rows in one table, oldest first.
	 *
	 * @param string $key     One of the TABLES keys.
	 * @param int    $user_id User ID.
	 * @param int    $page    1-based page.
	 * @param int    $per     Rows per page.
	 * @return array<int, array<string, mixed>>
	 */
	public function page( string $key, int $user_id, int $page, int $per = 100 ): array {
		if ( ! isset( self::TABLES[ $key ] ) || $user_id <= 0 ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; privacy export.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', $this->db->prefix . self::TABLES[ $key ], $user_id, $per, max( 0, ( $page - 1 ) * $per ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes a user's notifications.
	 *
	 * @param int $user_id User ID.
	 * @return int Rows deleted.
	 */
	public function delete_notifications( int $user_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; privacy erasure.
		$result = $this->db->delete( $this->db->prefix . 'plms_notifications', array( 'user_id' => $user_id ), array( '%d' ) );
		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Returns a page of a person's orders, by user or billing email.
	 *
	 * @param int    $user_id User ID, or 0.
	 * @param string $email   Billing email.
	 * @param int    $page    1-based page.
	 * @param int    $per     Rows per page.
	 * @return array<int, array<string, mixed>>
	 */
	public function orders( int $user_id, string $email, int $page, int $per = 100 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; privacy export.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d OR billing_email = %s ORDER BY id ASC LIMIT %d OFFSET %d', $this->db->prefix . 'plms_orders', $user_id, $email, $per, max( 0, ( $page - 1 ) * $per ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Removes personal details from a person's orders and their transactions; amounts stay for the books.
	 *
	 * @param int    $user_id User ID, or 0.
	 * @param string $email   Billing email.
	 * @return int Orders changed.
	 */
	public function anonymize_orders( int $user_id, string $email ): int {
		$orders = $this->db->prefix . 'plms_orders';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; privacy erasure.
		$ids = $this->db->get_col( $this->db->prepare( 'SELECT id FROM %i WHERE user_id = %d OR billing_email = %s', $orders, $user_id, $email ) );
		$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );
		if ( empty( $ids ) ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; placeholders only.
		$this->db->query( $this->db->prepare( "UPDATE %i SET user_id = NULL, billing_email = NULL, customer_ip = NULL, meta = NULL WHERE id IN ($in)", array_merge( array( $orders ), $ids ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; placeholders only.
		$this->db->query( $this->db->prepare( "UPDATE %i SET payload = NULL WHERE order_id IN ($in)", array_merge( array( $this->db->prefix . 'plms_transactions' ), $ids ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; privacy erasure.
		$this->db->delete( $this->db->prefix . 'plms_subscriptions', array( 'user_id' => $user_id ), array( '%d' ) );
		Cache::delete( 'enrollments:user:' . $user_id );
		return count( $ids );
	}
}
