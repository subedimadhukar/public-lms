<?php
/**
 * Minimal WooCommerce stubs for PHPStan (the bridge's surface only).
 *
 * @package PublicLMS
 */

// phpcs:disable

class WooCommerce {}

class WC_Data {
	/** @return int */
	public function get_id() {}
	/** @param string $key @return mixed */
	public function get_meta( $key ) {}
	/** @param string $key @param mixed $value @return void */
	public function update_meta_data( $key, $value ) {}
	/** @return int */
	public function save() {}
}

class WC_Order_Item_Product {
	/** @return int */
	public function get_product_id() {}
}

class WC_Order extends WC_Data {
	/** @return int */
	public function get_customer_id() {}
	/** @return string */
	public function get_billing_email() {}
	/** @return string */
	public function get_billing_first_name() {}
	/** @return string */
	public function get_billing_last_name() {}
	/** @return array<int, WC_Order_Item_Product> */
	public function get_items() {}
	/** @param string $note @return int */
	public function add_order_note( $note ) {}
}

class WC_Product extends WC_Data {
	/** @return string */
	public function get_status() {}
	/** @return bool */
	public function is_purchasable() {}
	/** @param bool $sold_individually @return void */
	public function set_sold_individually( $sold_individually ) {}
}

/** @param int $id @return WC_Order|false */
function wc_get_order( $id ) {}
/** @param int $id @return WC_Product|false|null */
function wc_get_product( $id ) {}
/** @return string */
function wc_get_cart_url() {}
/** @param float $price @return string */
function wc_price( $price ) {}
/** @param WC_Product $product @return float|string */
function wc_get_price_to_display( $product ) {}
