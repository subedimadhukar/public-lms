<?php
/**
 * WooCommerce bridge.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce\WooCommerce;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\Commerce\Customers;
use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Services\EnrollmentService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sells courses through WooCommerce products (ADR-011, ADR-051).
 *
 * Free, and off until the owner switches it on in Payments while
 * WooCommerce is active. A product lists the courses it gives
 * (`_publiclms_courses`); a paid WooCommerce order (processing or
 * completed) enrols its customer in them with source `woocommerce` and the
 * order's ID, and a refunded or cancelled order closes exactly those
 * enrolments. Linked courses' "Buy" buttons add the product to the cart,
 * their price is WooCommerce's, and the native checkout does not sell them.
 * Orders are read only through WooCommerce's order objects, so the bridge
 * works with both order storages (HPOS and posts).
 */
final class Bridge {

	/**
	 * Option: 'yes' when the bridge is on.
	 */
	const OPTION = 'publiclms_woocommerce_bridge';

	/**
	 * Product meta: course IDs the product gives.
	 */
	const PRODUCT_META = '_publiclms_courses';

	/**
	 * Course meta: the product that sells it.
	 */
	const COURSE_META = '_plms_wc_product';

	/**
	 * Order meta: set once the order's enrolments were made.
	 */
	const ORDER_META = '_publiclms_enrolled';

	/**
	 * Enrolment service.
	 *
	 * @var EnrollmentService
	 */
	private $enrollment;

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	private $enrollments;

	/**
	 * Guest accounts.
	 *
	 * @var Customers
	 */
	private $customers;

	/**
	 * Constructor.
	 *
	 * @param EnrollmentService    $enrollment  Enrolment service.
	 * @param EnrollmentRepository $enrollments Enrolments.
	 * @param Customers            $customers   Guest accounts.
	 */
	public function __construct( EnrollmentService $enrollment, EnrollmentRepository $enrollments, Customers $customers ) {
		$this->enrollment  = $enrollment;
		$this->enrollments = $enrollments;
		$this->customers   = $customers;
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Whether the owner switched the bridge on.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION, 'no' );
	}

	/**
	 * Hooks the bridge.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		// WooCommerce sends users without edit_posts from wp-admin to My account and hides their
		// toolbar, bridge on or off. Instructors and course managers work in wp-admin (ADR-051).
		add_filter( 'woocommerce_prevent_admin_access', array( $this, 'keep_staff_in_admin' ) );
		add_filter( 'woocommerce_disable_admin_bar', array( $this, 'keep_staff_toolbar' ) );
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_paid' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_paid' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'on_closed' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_closed' ), 10, 2 );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ) );
		add_filter( 'publiclms_purchase_url', array( $this, 'purchase_url' ), 10, 2 );
		add_filter( 'publiclms_course_price_label', array( $this, 'price_label' ), 10, 2 );
		add_filter( 'publiclms_course_purchasable', array( $this, 'purchasable' ), 10, 2 );
	}

	/**
	 * Whether the current user is Public LMS staff: holds any of its capabilities (instructors,
	 * course managers, administrators). Students hold none.
	 *
	 * @return bool
	 */
	public static function current_user_is_staff(): bool {
		foreach ( Capabilities::ALL as $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lets Public LMS staff into wp-admin, which WooCommerce otherwise closes to users without
	 * edit_posts. Everyone else keeps WooCommerce's answer.
	 *
	 * @param bool $prevent Whether WooCommerce would redirect the user to My account.
	 * @return bool
	 */
	public function keep_staff_in_admin( $prevent ): bool {
		return self::current_user_is_staff() ? false : (bool) $prevent;
	}

	/**
	 * Keeps the toolbar for Public LMS staff, which WooCommerce otherwise hides from users without
	 * edit_posts. Everyone else keeps WooCommerce's answer.
	 *
	 * @param bool $disable Whether WooCommerce hides the toolbar.
	 * @return bool
	 */
	public function keep_staff_toolbar( $disable ): bool {
		return self::current_user_is_staff() ? false : (bool) $disable;
	}

	/**
	 * Declares that the plugin works with WooCommerce's order tables (it never reads order tables).
	 *
	 * @return void
	 */
	public function declare_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PUBLICLMS_FILE, true );
		}
	}

	/**
	 * Enrols the customer of a paid order. Hooked to the processing and completed statuses.
	 *
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    Order object, when WooCommerce passes it.
	 * @return void
	 */
	public function on_paid( $order_id, $order = null ): void {
		$order = is_object( $order ) ? $order : wc_get_order( (int) $order_id );
		if ( is_object( $order ) ) {
			$this->enroll_order( $order );
		}
	}

	/**
	 * Closes the enrolments of a refunded or cancelled order.
	 *
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    Order object, when WooCommerce passes it.
	 * @return void
	 */
	public function on_closed( $order_id, $order = null ): void {
		$order = is_object( $order ) ? $order : wc_get_order( (int) $order_id );
		if ( is_object( $order ) ) {
			$this->revoke_order( $order );
		}
	}

	/**
	 * Enrols an order's customer in the courses of its products, once.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, int> Courses enrolled now.
	 */
	public function enroll_order( $order ): array {
		$courses = $this->order_courses( $order );
		if ( empty( $courses ) || 'yes' === $order->get_meta( self::ORDER_META ) ) {
			return array();
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			$name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$resolved = $this->customers->resolve( (string) $order->get_billing_email(), $name );
			if ( is_wp_error( $resolved ) ) {
				/* translators: %s: error message. */
				$order->add_order_note( sprintf( __( 'Public LMS could not find or create an account for the customer: %s', 'public-lms' ), $resolved->get_error_message() ) );
				return array();
			}
			$user_id = $resolved;
		}
		$enrolled = array();
		foreach ( $courses as $course_id ) {
			// Access the customer already has from elsewhere stays theirs, so a refund here never closes it.
			if ( $this->enrollments->is_enrolled( $user_id, $course_id ) ) {
				continue;
			}
			if ( $this->enrollment->enroll( $user_id, $course_id, 'woocommerce', (int) $order->get_id() ) > 0 ) {
				$enrolled[] = $course_id;
			}
		}
		$order->update_meta_data( self::ORDER_META, 'yes' );
		$order->save();
		if ( ! empty( $enrolled ) ) {
			/* translators: %s: comma-separated course titles. */
			$order->add_order_note( sprintf( __( 'Public LMS enrolled the customer in: %s', 'public-lms' ), implode( ', ', array_map( array( $this, 'title' ), $enrolled ) ) ) );
		}
		return $enrolled;
	}

	/**
	 * Closes the enrolments an order gave.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, int> Courses closed.
	 */
	public function revoke_order( $order ): array {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			$user    = get_user_by( 'email', (string) $order->get_billing_email() );
			$user_id = false === $user ? 0 : (int) $user->ID;
		}
		if ( $user_id <= 0 ) {
			return array();
		}
		$closed = array();
		foreach ( $this->order_courses( $order ) as $course_id ) {
			$row = $this->enrollments->find_by_user_and_course( $user_id, $course_id );
			if ( null !== $row && 'woocommerce' === $row['source'] && (int) $order->get_id() === (int) $row['source_id'] && $this->enrollment->unenroll( $user_id, $course_id ) ) {
				$closed[] = $course_id;
			}
		}
		$order->update_meta_data( self::ORDER_META, 'no' );
		$order->save();
		if ( ! empty( $closed ) ) {
			/* translators: %s: comma-separated course titles. */
			$order->add_order_note( sprintf( __( 'Public LMS closed access to: %s', 'public-lms' ), implode( ', ', array_map( array( $this, 'title' ), $closed ) ) ) );
		}
		return $closed;
	}

	/**
	 * Returns the course IDs a product gives.
	 *
	 * @param int $product_id Product (or variation's parent) ID.
	 * @return array<int, int>
	 */
	public function product_courses( int $product_id ): array {
		$courses = get_post_meta( $product_id, self::PRODUCT_META, true );
		return array_values(
			array_filter(
				array_map( 'intval', is_array( $courses ) ? $courses : array() ),
				static function ( $course_id ) {
					return $course_id > 0 && PostTypes::COURSE === get_post_type( $course_id );
				}
			)
		);
	}

	/**
	 * Links a product to courses and records the link on each course.
	 *
	 * @param int             $product_id Product ID.
	 * @param array<int, int> $course_ids Courses.
	 * @return void
	 */
	public function link( int $product_id, array $course_ids ): void {
		$course_ids = array_values( array_unique( array_filter( array_map( 'absint', $course_ids ) ) ) );
		foreach ( $this->product_courses( $product_id ) as $old ) {
			$linked = (int) get_post_meta( $old, self::COURSE_META, true );
			if ( ! in_array( $old, $course_ids, true ) && $linked === $product_id ) {
				delete_post_meta( $old, self::COURSE_META );
			}
		}
		update_post_meta( $product_id, self::PRODUCT_META, $course_ids );
		foreach ( $course_ids as $course_id ) {
			update_post_meta( $course_id, self::COURSE_META, $product_id );
		}
	}

	/**
	 * Prints the "Courses" field on the product's General tab.
	 *
	 * @return void
	 */
	public function product_field(): void {
		global $post;
		$product_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$selected   = $this->product_courses( $product_id );
		$courses    = get_posts(
			array(
				'post_type'      => PostTypes::COURSE,
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- One admin field; sites sell far fewer courses.
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		?>
		<div class="options_group">
			<p class="form-field">
				<label for="publiclms_courses"><?php esc_html_e( 'Public LMS courses', 'public-lms' ); ?></label>
				<select id="publiclms_courses" name="publiclms_courses[]" multiple="multiple" style="width: 50%; min-height: 6em;">
					<?php foreach ( $courses as $course ) : ?>
						<option value="<?php echo esc_attr( (string) $course->ID ); ?>" <?php selected( in_array( (int) $course->ID, $selected, true ) ); ?>><?php echo esc_html( wp_strip_all_tags( get_the_title( $course ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php wp_nonce_field( 'publiclms_product_courses', 'publiclms_product_courses_nonce' ); ?>
				<span class="description"><?php esc_html_e( 'Buying this product enrols the customer in these courses once the order is paid. Set the courses to "Paid" so they cannot be joined for free.', 'public-lms' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Saves the "Courses" field, and makes a product that gives courses "sold individually".
	 *
	 * @param \WC_Product $product The product being saved.
	 * @return void
	 */
	public function save_product( $product ): void {
		$nonce = isset( $_POST['publiclms_product_courses_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['publiclms_product_courses_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'publiclms_product_courses' ) || ! is_object( $product ) ) {
			return;
		}
		$ids = isset( $_POST['publiclms_courses'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['publiclms_courses'] ) ) : array();
		$this->link( (int) $product->get_id(), $ids );
		if ( ! empty( $this->product_courses( (int) $product->get_id() ) ) ) {
			// A course is bought once: no quantity field, and the cart refuses a second one.
			$product->set_sold_individually( true );
		}
	}

	/**
	 * Points a linked course's "Buy" button at its product in the cart.
	 *
	 * @param string $url       Checkout URL.
	 * @param int    $course_id Course.
	 * @return string
	 */
	public function purchase_url( $url, $course_id ): string {
		$product = $this->course_product( (int) $course_id );
		return null === $product ? (string) $url : add_query_arg( 'add-to-cart', (int) $product->get_id(), wc_get_cart_url() );
	}

	/**
	 * Shows a linked course's price as WooCommerce formats it.
	 *
	 * @param string $label     Label.
	 * @param int    $course_id Course.
	 * @return string
	 */
	public function price_label( $label, $course_id ): string {
		$product = $this->course_product( (int) $course_id );
		if ( null === $product ) {
			return (string) $label;
		}
		return html_entity_decode( wp_strip_all_tags( (string) wc_price( (float) wc_get_price_to_display( $product ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Keeps the native checkout from selling a course WooCommerce sells.
	 *
	 * @param true|WP_Error $purchasable Whether it can be bought natively.
	 * @param int           $course_id   Course.
	 * @return true|WP_Error
	 */
	public function purchasable( $purchasable, $course_id ) {
		if ( null === $this->course_product( (int) $course_id ) ) {
			return $purchasable;
		}
		return new WP_Error( 'publiclms_sold_in_shop', __( 'This course is sold in the shop. Use its “Buy” button.', 'public-lms' ), array( 'status' => 409 ) );
	}

	/**
	 * Returns the purchasable product that sells a course, or null.
	 *
	 * @param int $course_id Course.
	 * @return \WC_Product|null
	 */
	private function course_product( int $course_id ) {
		if ( ! self::woocommerce_active() || 'paid' !== Meta::access_mode( $course_id ) ) {
			return null;
		}
		$product_id = (int) get_post_meta( $course_id, self::COURSE_META, true );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() || ! $product->is_purchasable() || ! in_array( $course_id, $this->product_courses( $product_id ), true ) ) {
			return null;
		}
		return $product;
	}

	/**
	 * Returns the courses an order's line items give.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, int>
	 */
	private function order_courses( $order ): array {
		$courses = array();
		foreach ( (array) $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$courses = array_merge( $courses, $this->product_courses( (int) $item->get_product_id() ) );
		}
		return array_values( array_unique( $courses ) );
	}

	/**
	 * Returns a course title as plain text.
	 *
	 * @param int $course_id Course.
	 * @return string
	 */
	private function title( int $course_id ): string {
		return wp_strip_all_tags( get_the_title( $course_id ) );
	}
}
