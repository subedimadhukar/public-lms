<?php
/**
 * Order repository contract.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes plms_orders, plms_order_items and plms_transactions.
 */
interface OrderRepositoryInterface extends RepositoryInterface {

	/**
	 * Finds an order by its public key.
	 *
	 * @param string $order_key Order key.
	 * @return array<string, mixed>|null
	 */
	public function find_by_key( string $order_key ): ?array;

	/**
	 * Finds an order by the gateway's own reference, for webhook handling.
	 *
	 * @param string $gateway          Gateway ID, for example 'stripe'.
	 * @param string $gateway_order_id The gateway's order or session ID.
	 * @return array<string, mixed>|null
	 */
	public function find_by_gateway_reference( string $gateway, string $gateway_order_id ): ?array;

	/**
	 * Returns the line items of an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function items( int $order_id ): array;

	/**
	 * Appends a gateway transaction to an order.
	 *
	 * @param int                  $order_id    Order ID.
	 * @param array<string, mixed> $transaction Transaction columns.
	 * @return int The transaction ID, or 0 on failure.
	 */
	public function add_transaction( int $order_id, array $transaction ): int;
}
