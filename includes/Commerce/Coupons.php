<?php
/**
 * Coupons.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce;

use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\OrderRepository;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Discount codes (ADR-050): `plms_coupon` posts, published when active and
 * draft when paused. A coupon takes a percentage or a fixed amount off, in
 * the store currency, optionally only for some courses, between two dates,
 * and a limited number of times in all or per buyer. Codes match without
 * regard to case. Uses count paid, on-hold and pending orders, so an order
 * waiting for payment holds its use.
 */
class Coupons {

	const META_CODE        = '_plms_coupon_code';
	const META_TYPE        = '_plms_coupon_type';
	const META_AMOUNT      = '_plms_coupon_amount';
	const META_COURSES     = '_plms_coupon_courses';
	const META_STARTS      = '_plms_coupon_starts';
	const META_EXPIRES     = '_plms_coupon_expires';
	const META_USAGE_LIMIT = '_plms_coupon_usage_limit';
	const META_USER_LIMIT  = '_plms_coupon_user_limit';

	const TYPES = array( 'percent', 'fixed' );

	/**
	 * Orders, for counting uses.
	 *
	 * @var OrderRepository
	 */
	private $orders;

	/**
	 * Constructor.
	 *
	 * @param OrderRepository $orders Orders.
	 */
	public function __construct( OrderRepository $orders ) {
		$this->orders = $orders;
	}

	/**
	 * Normalises a code for lookup: trimmed, lower case (multibyte-safe).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function normalize_code( string $code ): string {
		$code = trim( wp_strip_all_tags( $code ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $code, 'UTF-8' ) : strtolower( $code );
	}

	/**
	 * Finds a coupon by code, whatever its status.
	 *
	 * @param string $code Code.
	 * @return WP_Post|null
	 */
	public function find( string $code ): ?WP_Post {
		$code = self::normalize_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'        => PostTypes::COUPON,
				'post_status'      => array( 'publish', 'draft' ),
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::META_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One indexed meta_key lookup per checkout.
				'meta_value'       => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);
		return empty( $posts ) ? null : $posts[0];
	}

	/**
	 * Returns a coupon's settings.
	 *
	 * @param WP_Post $coupon Coupon post.
	 * @return array<string, mixed>
	 */
	public function get( WP_Post $coupon ): array {
		$id      = (int) $coupon->ID;
		$type    = (string) get_post_meta( $id, self::META_TYPE, true );
		$courses = get_post_meta( $id, self::META_COURSES, true );
		return array(
			'id'          => $id,
			'code'        => (string) $coupon->post_title,
			'active'      => 'publish' === $coupon->post_status,
			'type'        => in_array( $type, self::TYPES, true ) ? $type : 'percent',
			'amount'      => (float) get_post_meta( $id, self::META_AMOUNT, true ),
			'courses'     => array_values( array_filter( array_map( 'intval', is_array( $courses ) ? $courses : array() ) ) ),
			'starts'      => (string) get_post_meta( $id, self::META_STARTS, true ),
			'expires'     => (string) get_post_meta( $id, self::META_EXPIRES, true ),
			'usage_limit' => (int) get_post_meta( $id, self::META_USAGE_LIMIT, true ),
			'user_limit'  => (int) get_post_meta( $id, self::META_USER_LIMIT, true ),
			'uses'        => $this->orders->coupon_uses( (string) $coupon->post_title ),
		);
	}

	/**
	 * Saves a coupon from admin input.
	 *
	 * @param array<string, mixed> $data code, active, type, amount, courses, starts, expires, usage_limit, user_limit.
	 * @param int                  $id   Coupon to update, or 0 for a new one.
	 * @return int|WP_Error Coupon ID.
	 */
	public function save( array $data, int $id = 0 ) {
		$code = trim( wp_strip_all_tags( (string) ( $data['code'] ?? '' ) ) );
		if ( $id > 0 && ! isset( $data['code'] ) ) {
			$code = (string) get_the_title( $id );
		}
		if ( '' === $code || preg_match( '/\s/u', $code ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Enter a code without spaces.', 'public-lms' ), array( 'status' => 400 ) );
		}
		$existing = $this->find( $code );
		if ( null !== $existing && (int) $existing->ID !== $id ) {
			return new WP_Error( 'publiclms_coupon_exists', __( 'Another coupon already uses this code.', 'public-lms' ), array( 'status' => 409 ) );
		}
		$type   = in_array( $data['type'] ?? '', self::TYPES, true ) ? (string) $data['type'] : ( $id > 0 ? (string) get_post_meta( $id, self::META_TYPE, true ) : 'percent' );
		$amount = isset( $data['amount'] ) ? (float) $data['amount'] : (float) get_post_meta( $id, self::META_AMOUNT, true );
		if ( $amount <= 0 || ( 'percent' === $type && $amount > 100 ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'A percentage must be more than 0 and at most 100; a fixed amount must be more than 0.', 'public-lms' ), array( 'status' => 400 ) );
		}
		foreach ( array( 'starts', 'expires' ) as $date ) {
			if ( isset( $data[ $date ] ) && '' !== $data[ $date ] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $data[ $date ] ) ) {
				return new WP_Error( 'rest_invalid_param', __( 'Dates must look like 2026-12-31.', 'public-lms' ), array( 'status' => 400 ) );
			}
		}

		$post = array(
			'post_type'   => PostTypes::COUPON,
			'post_title'  => $code,
			'post_status' => array_key_exists( 'active', $data ) ? ( $data['active'] ? 'publish' : 'draft' ) : ( $id > 0 ? (string) get_post_status( $id ) : 'publish' ),
		);
		if ( $id > 0 ) {
			$post['ID'] = $id;
			$result     = wp_update_post( $post, true );
		} else {
			$result = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = (int) $result;
		update_post_meta( $id, self::META_CODE, self::normalize_code( $code ) );
		update_post_meta( $id, self::META_TYPE, $type );
		update_post_meta( $id, self::META_AMOUNT, (string) $amount );
		if ( isset( $data['courses'] ) ) {
			update_post_meta( $id, self::META_COURSES, array_values( array_filter( array_map( 'absint', (array) $data['courses'] ) ) ) );
		}
		foreach ( array(
			'starts'      => self::META_STARTS,
			'expires'     => self::META_EXPIRES,
			'usage_limit' => self::META_USAGE_LIMIT,
			'user_limit'  => self::META_USER_LIMIT,
		) as $key => $meta ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $id, $meta, in_array( $key, array( 'starts', 'expires' ), true ) ? (string) $data[ $key ] : (string) absint( $data[ $key ] ) );
			}
		}
		return $id;
	}

	/**
	 * Works out a coupon's discount for line items.
	 *
	 * @param string                           $code     Code the buyer typed.
	 * @param array<int, array<string, mixed>> $items    Items with object_id and subtotal.
	 * @param string                           $currency Store currency.
	 * @param int                              $user_id  Buyer, or 0.
	 * @param string                           $email    Buyer's email, or ''.
	 * @return array{code: string, discounts: array<int, float>}|WP_Error Discount per item index.
	 */
	public function apply( string $code, array $items, string $currency, int $user_id, string $email ) {
		$post = $this->find( $code );
		if ( null === $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'publiclms_coupon_invalid', __( 'This coupon code is not valid.', 'public-lms' ), array( 'status' => 400 ) );
		}
		$coupon = $this->get( $post );
		$today  = current_datetime()->format( 'Y-m-d' );
		if ( '' !== $coupon['starts'] && $today < $coupon['starts'] ) {
			return new WP_Error( 'publiclms_coupon_not_started', __( 'This coupon cannot be used yet.', 'public-lms' ), array( 'status' => 400 ) );
		}
		if ( '' !== $coupon['expires'] && $today > $coupon['expires'] ) {
			return new WP_Error( 'publiclms_coupon_expired', __( 'This coupon has expired.', 'public-lms' ), array( 'status' => 400 ) );
		}
		if ( $coupon['usage_limit'] > 0 && $coupon['uses'] >= $coupon['usage_limit'] ) {
			return new WP_Error( 'publiclms_coupon_used_up', __( 'This coupon has been used up.', 'public-lms' ), array( 'status' => 400 ) );
		}
		if ( $coupon['user_limit'] > 0 && ( $user_id > 0 || '' !== $email ) && $this->orders->coupon_uses( $coupon['code'], $user_id, $email ) >= $coupon['user_limit'] ) {
			return new WP_Error( 'publiclms_coupon_user_limit', __( 'You have already used this coupon.', 'public-lms' ), array( 'status' => 400 ) );
		}

		$eligible = array();
		foreach ( $items as $index => $item ) {
			if ( empty( $coupon['courses'] ) || in_array( (int) $item['object_id'], $coupon['courses'], true ) ) {
				$eligible[ $index ] = (float) $item['subtotal'];
			}
		}
		if ( empty( $eligible ) ) {
			return new WP_Error( 'publiclms_coupon_not_applicable', __( 'This coupon does not apply to these courses.', 'public-lms' ), array( 'status' => 400 ) );
		}

		$discounts = array();
		if ( 'percent' === $coupon['type'] ) {
			foreach ( $eligible as $index => $subtotal ) {
				$discounts[ $index ] = Money::round( $subtotal * $coupon['amount'] / 100, $currency );
			}
		} else {
			$base  = array_sum( $eligible );
			$total = Money::round( min( $coupon['amount'], $base ), $currency );
			$left  = $total;
			$last  = (int) array_key_last( $eligible );
			foreach ( $eligible as $index => $subtotal ) {
				$share               = $index === $last ? $left : Money::round( $total * $subtotal / $base, $currency );
				$discounts[ $index ] = min( $share, $subtotal );
				$left                = Money::round( $left - $discounts[ $index ], $currency );
			}
		}
		return array(
			'code'      => $coupon['code'],
			'discounts' => $discounts,
		);
	}
}
