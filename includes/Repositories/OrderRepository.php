<?php
/**
 * Order repository.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Rows in plms_orders, plms_order_items and plms_transactions (ADR-046).
 *
 * Statuses: `pending` (sent to a gateway, not paid yet), `on-hold` (waiting
 * for an offline payment), `completed` (paid; the learner is enrolled),
 * `failed`, `cancelled` and `refunded`. Status changes go through
 * transition(), a compare-and-set on the current status, so a webhook and
 * the buyer's return can both report a payment and only one of them
 * completes the order.
 */
class OrderRepository extends AbstractRepository implements OrderRepositoryInterface {

	const STATUS_PENDING   = 'pending';
	const STATUS_ON_HOLD   = 'on-hold';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_REFUNDED  = 'refunded';

	const STATUSES = array( self::STATUS_PENDING, self::STATUS_ON_HOLD, self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_REFUNDED );

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function table_name(): string {
		return 'plms_orders';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function columns(): array {
		return array(
			'order_key'        => '%s',
			'user_id'          => '%d',
			'status'           => '%s',
			'currency'         => '%s',
			'subtotal'         => '%f',
			'discount_total'   => '%f',
			'tax_total'        => '%f',
			'total'            => '%f',
			'gateway'          => '%s',
			'gateway_order_id' => '%s',
			'coupon_code'      => '%s',
			'billing_email'    => '%s',
			'customer_ip'      => '%s',
			'meta'             => '%s',
			'created_at'       => '%s',
			'updated_at'       => '%s',
			'completed_at'     => '%s',
		);
	}

	/**
	 * Creates an order and its line items.
	 *
	 * @param array<string, mixed>             $order Order columns; `meta` may be an array.
	 * @param array<int, array<string, mixed>> $items Line items.
	 * @return int Order ID, or 0 on failure.
	 */
	public function create( array $order, array $items ): int {
		$now                 = $this->now();
		$order['order_key']  = $order['order_key'] ?? self::new_key();
		$order['status']     = $order['status'] ?? self::STATUS_PENDING;
		$order['created_at'] = $now;
		$order['updated_at'] = $now;
		if ( isset( $order['meta'] ) && is_array( $order['meta'] ) ) {
			$order['meta'] = (string) wp_json_encode( $order['meta'] );
		}
		$order_id = $this->insert( $order );
		if ( $order_id <= 0 ) {
			return 0;
		}
		foreach ( $items as $item ) {
			$row = array(
				'order_id'   => $order_id,
				'item_type'  => (string) ( $item['item_type'] ?? 'course' ),
				'object_id'  => (int) ( $item['object_id'] ?? 0 ),
				'name'       => (string) ( $item['name'] ?? '' ),
				'quantity'   => (int) ( $item['quantity'] ?? 1 ),
				'unit_price' => (float) ( $item['unit_price'] ?? 0 ),
				'subtotal'   => (float) ( $item['subtotal'] ?? 0 ),
				'discount'   => (float) ( $item['discount'] ?? 0 ),
				'tax'        => (float) ( $item['tax'] ?? 0 ),
				'total'      => (float) ( $item['total'] ?? 0 ),
				'meta'       => isset( $item['meta'] ) ? (string) wp_json_encode( $item['meta'] ) : null,
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
			$inserted = $this->db->insert( $this->items_table(), $row, array( '%d', '%s', '%d', '%s', '%d', '%f', '%f', '%f', '%f', '%f', '%s' ) );
			if ( false === $inserted ) {
				$this->delete_order( $order_id );
				return 0;
			}
		}
		return $order_id;
	}

	/**
	 * Returns a new random order key.
	 *
	 * @return string
	 */
	public static function new_key(): string {
		return 'plms_' . strtolower( wp_generate_password( 24, false, false ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Order ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = parent::find( $id );
		return null === $row ? null : $this->normalize( $row );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $order_key Order key.
	 * @return array<string, mixed>|null
	 */
	public function find_by_key( string $order_key ): ?array {
		if ( '' === $order_key ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; unique key lookup.
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE order_key = %s', $this->table(), $order_key ), ARRAY_A );
		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $gateway          Gateway ID.
	 * @param string $gateway_order_id The gateway's reference.
	 * @return array<string, mixed>|null
	 */
	public function find_by_gateway_reference( string $gateway, string $gateway_order_id ): ?array {
		if ( '' === $gateway_order_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; indexed lookup.
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE gateway = %s AND gateway_order_id = %s ORDER BY id DESC LIMIT 1', $this->table(), $gateway, $gateway_order_id ), ARRAY_A );
		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function items( int $order_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; small read.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id ASC', $this->items_table(), $order_id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'         => (int) $row['id'],
				'item_type'  => (string) $row['item_type'],
				'object_id'  => (int) $row['object_id'],
				'name'       => (string) $row['name'],
				'quantity'   => (int) $row['quantity'],
				'unit_price' => (float) $row['unit_price'],
				'subtotal'   => (float) $row['subtotal'],
				'discount'   => (float) $row['discount'],
				'tax'        => (float) $row['tax'],
				'total'      => (float) $row['total'],
			);
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $order_id    Order ID.
	 * @param array<string, mixed> $transaction gateway, type, status, amount, currency, gateway_transaction_id, payload.
	 * @return int
	 */
	public function add_transaction( int $order_id, array $transaction ): int {
		$payload = $transaction['payload'] ?? null;
		$row     = array(
			'order_id'               => $order_id,
			'gateway'                => (string) ( $transaction['gateway'] ?? '' ),
			'type'                   => (string) ( $transaction['type'] ?? 'payment' ),
			'status'                 => (string) ( $transaction['status'] ?? 'completed' ),
			'amount'                 => (float) ( $transaction['amount'] ?? 0 ),
			'currency'               => (string) ( $transaction['currency'] ?? '' ),
			'gateway_transaction_id' => isset( $transaction['gateway_transaction_id'] ) ? (string) $transaction['gateway_transaction_id'] : null,
			'payload'                => null === $payload ? null : ( is_string( $payload ) ? $payload : (string) wp_json_encode( $payload ) ),
			'created_at'             => $this->now(),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$result = $this->db->insert( $this->transactions_table(), $row, array( '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s' ) );
		return false === $result ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Returns an order's transactions, oldest first.
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function transactions( int $order_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; small read.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id ASC', $this->transactions_table(), $order_id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'                     => (int) $row['id'],
				'gateway'                => (string) $row['gateway'],
				'type'                   => (string) $row['type'],
				'status'                 => (string) $row['status'],
				'amount'                 => (float) $row['amount'],
				'currency'               => (string) $row['currency'],
				'gateway_transaction_id' => (string) $row['gateway_transaction_id'],
				'created_at'             => (string) $row['created_at'],
			);
		}
		return $out;
	}

	/**
	 * Whether a gateway transaction of this type was already recorded, so webhook retries add nothing.
	 *
	 * @param string $gateway                Gateway ID.
	 * @param string $gateway_transaction_id The gateway's ID.
	 * @param string $type                   payment, refund or chargeback.
	 * @return bool
	 */
	public function has_transaction( string $gateway, string $gateway_transaction_id, string $type ): bool {
		if ( '' === $gateway_transaction_id ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; indexed lookup.
		$found = $this->db->get_var( $this->db->prepare( 'SELECT id FROM %i WHERE gateway = %s AND gateway_transaction_id = %s AND type = %s LIMIT 1', $this->transactions_table(), $gateway, $gateway_transaction_id, $type ) );
		return null !== $found;
	}

	/**
	 * Moves an order to a new status when its current status is one of $from.
	 *
	 * @param int                  $order_id Order ID.
	 * @param array<int, string>   $from     Allowed current statuses.
	 * @param string               $to       New status.
	 * @param array<string, mixed> $extra    Other columns to set in the same update.
	 * @return bool Whether this call changed the status.
	 */
	public function transition( int $order_id, array $from, string $to, array $extra = array() ): bool {
		if ( empty( $from ) || ! in_array( $to, self::STATUSES, true ) ) {
			return false;
		}
		$set  = array_merge( $this->filter_columns( $extra ), array( 'status' => $to ) );
		$sets = array();
		$args = array( $this->table() );
		foreach ( $set as $column => $value ) {
			$sets[] = null === $value ? "`{$column}` = NULL" : "`{$column}` = " . $this->columns()[ $column ];
			if ( null !== $value ) {
				$args[] = $value;
			}
		}
		$sets[] = '`updated_at` = %s';
		$args[] = $this->now();
		$args[] = $order_id;
		$args   = array_merge( $args, array_values( $from ) );
		$in     = implode( ', ', array_fill( 0, count( $from ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column names come from columns(); values are placeholders.
		$changed = $this->db->query( $this->db->prepare( 'UPDATE %i SET ' . implode( ', ', $sets ) . " WHERE id = %d AND status IN ($in)", $args ) );
		return is_int( $changed ) && $changed > 0;
	}

	/**
	 * Merges values into an order's meta JSON.
	 *
	 * @param int                  $order_id Order ID.
	 * @param array<string, mixed> $values   Values.
	 * @return bool
	 */
	public function update_meta( int $order_id, array $values ): bool {
		$order = $this->find( $order_id );
		if ( null === $order ) {
			return false;
		}
		$meta = array_merge( (array) $order['meta'], $values );
		return $this->update(
			$order_id,
			array(
				'meta'       => (string) wp_json_encode( $meta ),
				'updated_at' => $this->now(),
			)
		);
	}

	/**
	 * Returns orders newest first, for the admin list.
	 *
	 * @param string $status Status filter, or ''.
	 * @param string $search Order ID, key, email or gateway reference, or ''.
	 * @param int    $before Return orders with an ID lower than this; 0 for the newest.
	 * @param int    $limit  Rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $status, string $search, int $before, int $limit ): array {
		list( $where, $args ) = $this->where( $status, $search );
		if ( $before > 0 ) {
			$where .= ' AND id < %d';
			$args[] = $before;
		}
		$args[] = $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Built from placeholders in where().
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC LIMIT %d", array_merge( array( $this->table() ), $args ) ), ARRAY_A );
		return array_map( array( $this, 'normalize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Counts orders matching the admin filters.
	 *
	 * @param string $status Status filter, or ''.
	 * @param string $search Search, or ''.
	 * @return int
	 */
	public function count( string $status, string $search ): int {
		list( $where, $args ) = $this->where( $status, $search );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Built from placeholders in where().
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i WHERE $where", array_merge( array( $this->table() ), $args ) ) );
	}

	/**
	 * Returns a learner's orders, newest first.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id, int $limit = 50 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; indexed on user_id.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d', $this->table(), $user_id, $limit ), ARRAY_A );
		return array_map( array( $this, 'normalize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Counts orders that used a coupon and are paid or waiting for payment.
	 *
	 * @param string $code    Coupon code (compared case-insensitively).
	 * @param int    $user_id Only this user's orders; 0 for everyone.
	 * @param string $email   Or this billing email, for guests.
	 * @return int
	 */
	public function coupon_uses( string $code, int $user_id = 0, string $email = '' ): int {
		$sql  = 'SELECT COUNT(*) FROM %i WHERE LOWER(coupon_code) = %s AND status IN (%s, %s, %s)';
		$args = array( $this->table(), strtolower( $code ), self::STATUS_COMPLETED, self::STATUS_ON_HOLD, self::STATUS_PENDING );
		if ( $user_id > 0 || '' !== $email ) {
			$sql   .= ' AND (user_id = %d OR billing_email = %s)';
			$args[] = $user_id;
			$args[] = $email;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Placeholders only.
		return (int) $this->db->get_var( $this->db->prepare( $sql, $args ) );
	}

	/**
	 * Returns the number of orders per status.
	 *
	 * @return array<string, int>
	 */
	public function status_counts(): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; admin-only aggregate.
		$rows   = $this->db->get_results( $this->db->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $this->table() ), ARRAY_A );
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Deletes an order with its items and transactions.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public function delete_order( int $order_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables.
		$this->db->delete( $this->items_table(), array( 'order_id' => $order_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables.
		$this->db->delete( $this->transactions_table(), array( 'order_id' => $order_id ), array( '%d' ) );
		return $this->delete( $order_id );
	}

	/**
	 * Casts an order row's columns.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function normalize( array $row ): array {
		$meta = isset( $row['meta'] ) && is_string( $row['meta'] ) ? json_decode( $row['meta'], true ) : array();
		return array(
			'id'               => (int) $row['id'],
			'order_key'        => (string) $row['order_key'],
			'user_id'          => (int) $row['user_id'],
			'status'           => (string) $row['status'],
			'currency'         => (string) $row['currency'],
			'subtotal'         => (float) $row['subtotal'],
			'discount_total'   => (float) $row['discount_total'],
			'tax_total'        => (float) $row['tax_total'],
			'total'            => (float) $row['total'],
			'gateway'          => (string) $row['gateway'],
			'gateway_order_id' => (string) $row['gateway_order_id'],
			'coupon_code'      => (string) $row['coupon_code'],
			'billing_email'    => (string) $row['billing_email'],
			'customer_ip'      => (string) $row['customer_ip'],
			'meta'             => is_array( $meta ) ? $meta : array(),
			'created_at'       => (string) $row['created_at'],
			'updated_at'       => (string) $row['updated_at'],
			'completed_at'     => null === $row['completed_at'] ? null : (string) $row['completed_at'],
		);
	}

	/**
	 * Builds the WHERE clause for the admin filters.
	 *
	 * @param string $status Status filter.
	 * @param string $search Search.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function where( string $status, string $search ): array {
		$where = '1=1';
		$args  = array();
		if ( '' !== $status ) {
			$where .= ' AND status = %s';
			$args[] = $status;
		}
		$search = trim( $search );
		if ( '' !== $search ) {
			$id     = (int) ltrim( $search, '#' );
			$like   = '%' . $this->db->esc_like( $search ) . '%';
			$where .= ' AND (id = %d OR order_key = %s OR gateway_order_id = %s OR billing_email LIKE %s)';
			array_push( $args, $id, $search, $search, $like );
		}
		return array( $where, $args );
	}

	/**
	 * Returns the line items table.
	 *
	 * @return string
	 */
	private function items_table(): string {
		return $this->db->prefix . 'plms_order_items';
	}

	/**
	 * Returns the transactions table.
	 *
	 * @return string
	 */
	private function transactions_table(): string {
		return $this->db->prefix . 'plms_transactions';
	}
}
