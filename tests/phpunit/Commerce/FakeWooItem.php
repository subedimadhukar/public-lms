<?php
/**
 * Stand-in for a WooCommerce order line.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Commerce;

/**
 * The part of WC_Order_Item_Product the bridge uses.
 */
final class FakeWooItem {

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $product;

	/**
	 * Constructor.
	 *
	 * @param int $product Product ID.
	 */
	public function __construct( int $product ) {
		$this->product = $product;
	}

	/**
	 * Product ID.
	 *
	 * @return int
	 */
	public function get_product_id() {
		return $this->product;
	}
}
