<?php
/**
 * Stand-ins for WooCommerce objects.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Commerce;

/**
 * The parts of WC_Order the bridge uses.
 */
final class FakeWooOrder {

	/**
	 * Order ID.
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Customer user ID.
	 *
	 * @var int
	 */
	public $customer;

	/**
	 * Billing email.
	 *
	 * @var string
	 */
	public $email;

	/**
	 * Product IDs on the order.
	 *
	 * @var array<int, int>
	 */
	public $products;

	/**
	 * Meta.
	 *
	 * @var array<string, mixed>
	 */
	public $meta = array();

	/**
	 * Notes added.
	 *
	 * @var array<int, string>
	 */
	public $notes = array();

	/**
	 * Constructor.
	 *
	 * @param int             $id       Order ID.
	 * @param int             $customer Customer.
	 * @param string          $email    Billing email.
	 * @param array<int, int> $products Product IDs.
	 */
	public function __construct( int $id, int $customer, string $email, array $products ) {
		$this->id       = $id;
		$this->customer = $customer;
		$this->email    = $email;
		$this->products = $products;
	}

	/**
	 * Order ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Customer.
	 *
	 * @return int
	 */
	public function get_customer_id() {
		return $this->customer;
	}

	/**
	 * Billing email.
	 *
	 * @return string
	 */
	public function get_billing_email() {
		return $this->email;
	}

	/**
	 * First name.
	 *
	 * @return string
	 */
	public function get_billing_first_name() {
		return 'Lucía';
	}

	/**
	 * Last name.
	 *
	 * @return string
	 */
	public function get_billing_last_name() {
		return 'Fernández';
	}

	/**
	 * Line items.
	 *
	 * @return array<int, object>
	 */
	public function get_items() {
		return array_map(
			static function ( $product_id ) {
				return new FakeWooItem( (int) $product_id );
			},
			$this->products
		);
	}

	/**
	 * Meta value.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get_meta( $key ) {
		return $this->meta[ $key ] ?? '';
	}

	/**
	 * Sets meta.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	/**
	 * Saves.
	 *
	 * @return int
	 */
	public function save() {
		return $this->id;
	}

	/**
	 * Adds a note.
	 *
	 * @param string $note Note.
	 * @return int
	 */
	public function add_order_note( $note ) {
		$this->notes[] = $note;
		return count( $this->notes );
	}
}
