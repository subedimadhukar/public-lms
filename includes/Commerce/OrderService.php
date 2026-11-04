<?php
/**
 * Orders: checkout, payment results, refunds.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Commerce\Gateways\Gateway;
use PublicLMS\Commerce\Gateways\Gateways;
use PublicLMS\Courses\CourseFields;
use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Repositories\EventRepositoryInterface;
use PublicLMS\Repositories\OrderRepository;
use PublicLMS\Services\EnrollmentService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every change to an order and its side effects (ADR-046).
 *
 * Checkout prices the courses on the server (never from the request),
 * applies a coupon, creates the order and hands it to a gateway. Gateways
 * report payment results; apply() records each as a transaction once (by
 * the provider's ID), checks amount and currency against the order, and
 * moves the order. Completing an order enrols the buyer in its courses
 * (source `order`); refunding it cancels exactly those enrolments.
 */
class OrderService {

	/**
	 * Orders.
	 *
	 * @var OrderRepository
	 */
	private $orders;

	/**
	 * Gateways.
	 *
	 * @var Gateways
	 */
	private $gateways;

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
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private $policy;

	/**
	 * Course fields (prices).
	 *
	 * @var CourseFields
	 */
	private $fields;

	/**
	 * Coupons.
	 *
	 * @var Coupons
	 */
	private $coupons;

	/**
	 * Guest accounts.
	 *
	 * @var Customers
	 */
	private $customers;

	/**
	 * Event log.
	 *
	 * @var EventRepositoryInterface
	 */
	private $events;

	/**
	 * Order emails.
	 *
	 * @var OrderEmails
	 */
	private $emails;

	/**
	 * Constructor.
	 *
	 * @param OrderRepository          $orders      Orders.
	 * @param Gateways                 $gateways    Gateways.
	 * @param EnrollmentService        $enrollment  Enrolment service.
	 * @param EnrollmentRepository     $enrollments Enrolments.
	 * @param AccessPolicy             $policy      Access policy.
	 * @param CourseFields             $fields      Course fields.
	 * @param Coupons                  $coupons     Coupons.
	 * @param Customers                $customers   Guest accounts.
	 * @param EventRepositoryInterface $events      Event log.
	 * @param OrderEmails              $emails      Emails.
	 */
	public function __construct( OrderRepository $orders, Gateways $gateways, EnrollmentService $enrollment, EnrollmentRepository $enrollments, AccessPolicy $policy, CourseFields $fields, Coupons $coupons, Customers $customers, EventRepositoryInterface $events, OrderEmails $emails ) {
		$this->orders      = $orders;
		$this->gateways    = $gateways;
		$this->enrollment  = $enrollment;
		$this->enrollments = $enrollments;
		$this->policy      = $policy;
		$this->fields      = $fields;
		$this->coupons     = $coupons;
		$this->customers   = $customers;
		$this->events      = $events;
		$this->emails      = $emails;
	}

	/**
	 * Returns the order repository.
	 *
	 * @return OrderRepository
	 */
	public function repository(): OrderRepository {
		return $this->orders;
	}

	/**
	 * Returns the gateway registry.
	 *
	 * @return Gateways
	 */
	public function gateways(): Gateways {
		return $this->gateways;
	}

	/**
	 * Prices courses for a buyer, with an optional coupon.
	 *
	 * @param array<int, int> $course_ids Courses.
	 * @param string          $coupon     Coupon code, or ''.
	 * @param int             $user_id    Buyer, or 0 for a guest.
	 * @param string          $email      Guest's email, or ''.
	 * @return array{currency: string, items: array<int, array<string, mixed>>, subtotal: float, discount: float, total: float, coupon: string}|WP_Error
	 */
	public function quote( array $course_ids, string $coupon, int $user_id, string $email ) {
		$currency = Money::currency();
		if ( '' === $currency ) {
			return new WP_Error( 'publiclms_no_currency', __( 'This site is not selling courses yet: no currency has been chosen.', 'public-lms' ), array( 'status' => 409 ) );
		}
		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		if ( empty( $course_ids ) || count( $course_ids ) > 20 ) {
			return new WP_Error( 'rest_invalid_param', __( 'Choose a course to buy.', 'public-lms' ), array( 'status' => 400 ) );
		}
		$owner = $user_id;
		if ( $owner <= 0 && '' !== $email ) {
			$existing = get_user_by( 'email', $email );
			$owner    = false === $existing ? 0 : (int) $existing->ID;
		}

		$items = array();
		foreach ( $course_ids as $course_id ) {
			$error = $this->check_purchasable( $course_id, $owner, $user_id > 0 );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			$price   = Money::round( (float) $this->fields->effective_price( $course_id ), $currency );
			$items[] = array(
				'item_type'  => 'course',
				'object_id'  => $course_id,
				'name'       => wp_strip_all_tags( get_the_title( $course_id ) ),
				'quantity'   => 1,
				'unit_price' => $price,
				'subtotal'   => $price,
				'discount'   => 0.0,
				'tax'        => 0.0,
				'total'      => $price,
			);
		}

		$code = '';
		if ( '' !== trim( $coupon ) ) {
			$applied = $this->coupons->apply( $coupon, $items, $currency, $user_id, $email );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			$code = $applied['code'];
			foreach ( $applied['discounts'] as $index => $discount ) {
				$items[ $index ]['discount'] = $discount;
				$items[ $index ]['total']    = Money::round( max( 0.0, $items[ $index ]['subtotal'] - $discount ), $currency );
			}
		}

		$subtotal = Money::round( (float) array_sum( wp_list_pluck( $items, 'subtotal' ) ), $currency );
		$discount = Money::round( (float) array_sum( wp_list_pluck( $items, 'discount' ) ), $currency );
		return array(
			'currency' => $currency,
			'items'    => $items,
			'subtotal' => $subtotal,
			'discount' => $discount,
			'total'    => Money::round( max( 0.0, $subtotal - $discount ), $currency ),
			'coupon'   => $code,
		);
	}

	/**
	 * Creates an order and starts its payment.
	 *
	 * @param array<string, mixed> $args courses (int[]), gateway, coupon, user_id, email, name, ip.
	 * @return array{order: array<string, mixed>, redirect: string}|WP_Error
	 */
	public function checkout( array $args ) {
		$user_id = (int) ( $args['user_id'] ?? 0 );
		$name    = trim( sanitize_text_field( (string) ( $args['name'] ?? '' ) ) );
		if ( $user_id > 0 ) {
			$user  = get_userdata( $user_id );
			$email = false === $user ? '' : (string) $user->user_email;
		} else {
			if ( ! CheckoutPage::guests_allowed() ) {
				return new WP_Error( 'publiclms_rest_logged_out', __( 'Please log in to buy a course.', 'public-lms' ), array( 'status' => 401 ) );
			}
			$email = sanitize_email( (string) ( $args['email'] ?? '' ) );
			if ( '' === $email || ! is_email( $email ) ) {
				return new WP_Error( 'rest_invalid_param', __( 'Enter a valid email address. Your course access is sent there.', 'public-lms' ), array( 'status' => 400 ) );
			}
			if ( '' === $name ) {
				return new WP_Error( 'rest_invalid_param', __( 'Enter your name as it should appear on certificates.', 'public-lms' ), array( 'status' => 400 ) );
			}
		}

		$quote = $this->quote( (array) ( $args['courses'] ?? array() ), (string) ( $args['coupon'] ?? '' ), $user_id, $user_id > 0 ? '' : $email );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		$gateway = null;
		if ( $quote['total'] > 0 ) {
			$gateway = $this->gateways->available( $quote['currency'] )[ (string) ( $args['gateway'] ?? '' ) ] ?? null;
			if ( null === $gateway ) {
				return new WP_Error( 'rest_invalid_param', __( 'Choose a payment method.', 'public-lms' ), array( 'status' => 400 ) );
			}
		}

		$order_id = $this->orders->create(
			array(
				'user_id'        => $user_id > 0 ? $user_id : null,
				'status'         => OrderRepository::STATUS_PENDING,
				'currency'       => $quote['currency'],
				'subtotal'       => $quote['subtotal'],
				'discount_total' => $quote['discount'],
				'tax_total'      => 0,
				'total'          => $quote['total'],
				'gateway'        => null === $gateway ? 'free' : $gateway->id(),
				'coupon_code'    => '' === $quote['coupon'] ? null : $quote['coupon'],
				'billing_email'  => $email,
				'customer_ip'    => (string) ( $args['ip'] ?? '' ),
				'meta'           => array(
					'name'    => $name,
					'locale'  => function_exists( 'determine_locale' ) ? determine_locale() : get_locale(),
					'history' => array(),
				),
			),
			$quote['items']
		);
		$order    = $order_id > 0 ? $this->orders->find( $order_id ) : null;
		if ( null === $order ) {
			return new WP_Error( 'publiclms_order_failed', __( 'The order could not be saved. Please try again.', 'public-lms' ), array( 'status' => 500 ) );
		}
		$this->events->record(
			'order.created',
			array(
				'user_id'     => $user_id,
				'object_type' => 'order',
				'object_id'   => $order_id,
				'payload'     => array(
					'total'    => $quote['total'],
					'currency' => $quote['currency'],
					'gateway'  => $order['gateway'],
				),
			)
		);

		$view = CheckoutPage::order_url( $order['order_key'] );
		if ( null === $gateway ) {
			$this->complete( $order_id, __( 'Nothing to pay after the coupon.', 'public-lms' ) );
			return array(
				'order'    => $this->prepare( (array) $this->orders->find( $order_id ) ),
				'redirect' => $view,
			);
		}

		$cancel  = add_query_arg( 'cancelled', rawurlencode( $order['order_key'] ), CheckoutPage::url( (int) $quote['items'][0]['object_id'] ) );
		$started = $gateway->start( $order, $this->orders->items( $order_id ), $view, $cancel );
		if ( is_wp_error( $started ) ) {
			$this->orders->transition( $order_id, array( OrderRepository::STATUS_PENDING ), OrderRepository::STATUS_FAILED );
			$this->note( $order_id, sprintf( /* translators: %s: error message. */ __( 'The payment could not start: %s', 'public-lms' ), $started->get_error_message() ) );
			return $started;
		}
		if ( '' !== $started['gateway_order_id'] ) {
			$this->orders->update( $order_id, array( 'gateway_order_id' => $started['gateway_order_id'] ) );
		}
		if ( OrderRepository::STATUS_ON_HOLD === $started['status'] && $this->orders->transition( $order_id, array( OrderRepository::STATUS_PENDING ), OrderRepository::STATUS_ON_HOLD ) ) {
			$this->note( $order_id, __( 'Waiting for the offline payment.', 'public-lms' ) );
			$held = (array) $this->orders->find( $order_id );
			$this->emails->on_hold( $held, $this->orders->items( $order_id ), $gateway );
		}
		return array(
			'order'    => $this->prepare( (array) $this->orders->find( $order_id ) ),
			'redirect' => $started['redirect'],
		);
	}

	/**
	 * Asks the order's gateway about a buyer who came back, and applies what it says.
	 *
	 * @param array<string, mixed>  $order  Order.
	 * @param array<string, string> $params Return URL arguments.
	 * @return array<string, mixed> The order afterwards.
	 */
	public function handle_return( array $order, array $params ): array {
		$open = array( OrderRepository::STATUS_PENDING, OrderRepository::STATUS_FAILED );
		$gate = $this->gateways->get( (string) $order['gateway'] );
		if ( null !== $gate && in_array( $order['status'], $open, true ) ) {
			$results = $gate->confirm( $order, $params );
			if ( is_wp_error( $results ) ) {
				$this->note( (int) $order['id'], sprintf( /* translators: %s: error message. */ __( 'Checking the payment on return failed: %s', 'public-lms' ), $results->get_error_message() ) );
			} else {
				$this->apply( $gate, $results, $order );
			}
		}
		return (array) $this->orders->find( (int) $order['id'] );
	}

	/**
	 * Cancels a pending order whose buyer gave up at the provider.
	 *
	 * @param string $order_key Order key from the cancel URL.
	 * @return void
	 */
	public function buyer_cancelled( string $order_key ): void {
		$order = $this->orders->find_by_key( $order_key );
		if ( null !== $order && $this->orders->transition( (int) $order['id'], array( OrderRepository::STATUS_PENDING ), OrderRepository::STATUS_CANCELLED ) ) {
			$this->note( (int) $order['id'], __( 'The buyer left the payment page without paying.', 'public-lms' ) );
		}
	}

	/**
	 * Applies payment results from a gateway.
	 *
	 * @param Gateway                          $gateway Gateway that reported them.
	 * @param array<int, array<string, mixed>> $results Payment results.
	 * @param array<string, mixed>|null        $known   The order, when the caller already has it.
	 * @return int Results applied to an order.
	 */
	public function apply( Gateway $gateway, array $results, ?array $known = null ): int {
		$applied = 0;
		foreach ( $results as $result ) {
			$order = $this->order_for_result( $gateway, $result, $known );
			if ( null === $order ) {
				continue;
			}
			++$applied;
			$order_id = (int) $order['id'];
			$txn_id   = (string) $result['transaction_id'];
			$amount   = (float) $result['amount'];
			$currency = strtoupper( (string) $result['currency'] );

			switch ( $result['event'] ) {
				case 'paid':
					if ( $this->orders->has_transaction( $gateway->id(), $txn_id, 'payment' ) ) {
						break;
					}
					$this->orders->add_transaction(
						$order_id,
						array(
							'gateway'                => $gateway->id(),
							'type'                   => 'payment',
							'status'                 => 'completed',
							'amount'                 => $amount,
							'currency'               => '' === $currency ? $order['currency'] : $currency,
							'gateway_transaction_id' => $txn_id,
						)
					);
					if ( ( '' !== $currency && $currency !== $order['currency'] ) || abs( $amount - (float) $order['total'] ) > 0.0001 ) {
						$this->orders->transition( $order_id, array( OrderRepository::STATUS_PENDING, OrderRepository::STATUS_FAILED ), OrderRepository::STATUS_ON_HOLD );
						$this->note( $order_id, sprintf( /* translators: 1: amount paid, 2: order total. */ __( 'Payment received for %1$s, but the order total is %2$s. Check it before marking the order paid.', 'public-lms' ), Money::format( $amount, '' === $currency ? $order['currency'] : $currency ), Money::format( (float) $order['total'], $order['currency'] ) ) );
						break;
					}
					$this->complete( $order_id, '' === $result['message'] ? sprintf( /* translators: %s: payment method. */ __( 'Paid with %s.', 'public-lms' ), $gateway->admin_title() ) : (string) $result['message'] );
					break;

				case 'pending':
					$this->note( $order_id, '' === $result['message'] ? __( 'The payment is processing.', 'public-lms' ) : (string) $result['message'] );
					break;

				case 'failed':
					if ( $this->orders->transition( $order_id, array( OrderRepository::STATUS_PENDING ), OrderRepository::STATUS_FAILED ) ) {
						$this->note( $order_id, '' === $result['message'] ? __( 'The payment failed.', 'public-lms' ) : (string) $result['message'] );
					}
					break;

				case 'cancelled':
					if ( $this->orders->transition( $order_id, array( OrderRepository::STATUS_PENDING ), OrderRepository::STATUS_CANCELLED ) ) {
						$this->note( $order_id, '' === $result['message'] ? __( 'The payment session ended without a payment.', 'public-lms' ) : (string) $result['message'] );
					}
					break;

				case 'refunded':
					if ( $this->orders->has_transaction( $gateway->id(), $txn_id, 'refund' ) ) {
						break;
					}
					$this->orders->add_transaction(
						$order_id,
						array(
							'gateway'                => $gateway->id(),
							'type'                   => 'refund',
							'status'                 => 'completed',
							'amount'                 => $amount,
							'currency'               => '' === $currency ? $order['currency'] : $currency,
							'gateway_transaction_id' => $txn_id,
						)
					);
					if ( ! empty( $result['full'] ) || $this->orders->refunded_total( $order_id ) >= (float) $order['total'] - 0.0001 ) {
						$this->mark_refunded( $order_id, '' === $result['message'] ? sprintf( /* translators: %s: payment method. */ __( 'Refunded through %s.', 'public-lms' ), $gateway->admin_title() ) : (string) $result['message'] );
					} else {
						$this->note( $order_id, sprintf( /* translators: %s: amount. */ __( 'Partly refunded: %s. Access stays open.', 'public-lms' ), Money::format( $amount, $order['currency'] ) ) );
					}
					break;
			}
		}
		return $applied;
	}

	/**
	 * Completes an order and enrols the buyer. Safe to call more than once.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $note     History note.
	 * @return bool Whether this call completed the order.
	 */
	public function complete( int $order_id, string $note = '' ): bool {
		$order = $this->orders->find( $order_id );
		if ( null === $order || in_array( $order['status'], array( OrderRepository::STATUS_COMPLETED, OrderRepository::STATUS_REFUNDED ), true ) ) {
			return false;
		}
		$user_id = (int) $order['user_id'];
		if ( $user_id <= 0 ) {
			$resolved = $this->customers->resolve( (string) $order['billing_email'], (string) ( $order['meta']['name'] ?? '' ) );
			if ( is_wp_error( $resolved ) ) {
				$this->note( $order_id, sprintf( /* translators: %s: error message. */ __( 'No account could be found or created for the buyer: %s', 'public-lms' ), $resolved->get_error_message() ) );
				return false;
			}
			$user_id = $resolved;
		}

		$open = array( OrderRepository::STATUS_PENDING, OrderRepository::STATUS_ON_HOLD, OrderRepository::STATUS_FAILED, OrderRepository::STATUS_CANCELLED );
		if ( ! $this->orders->transition(
			$order_id,
			$open,
			OrderRepository::STATUS_COMPLETED,
			array(
				'user_id'      => $user_id,
				'completed_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		) ) {
			return false;
		}

		$items = $this->orders->items( $order_id );
		foreach ( $items as $item ) {
			if ( 'course' === $item['item_type'] ) {
				$this->enrollment->enroll( $user_id, (int) $item['object_id'], 'order', $order_id );
			}
		}
		if ( '' !== $note ) {
			$this->note( $order_id, $note );
		}
		$this->events->record(
			'order.completed',
			array(
				'user_id'     => $user_id,
				'object_type' => 'order',
				'object_id'   => $order_id,
				'payload'     => array(
					'total'    => (float) $order['total'],
					'currency' => (string) $order['currency'],
				),
			)
		);
		$completed = (array) $this->orders->find( $order_id );
		$this->emails->completed( $completed, $items );

		/**
		 * Fires after an order is paid and its learner enrolled.
		 *
		 * @param array<string, mixed>             $order Order.
		 * @param array<int, array<string, mixed>> $items Line items.
		 */
		do_action( 'publiclms_order_completed', $completed, $items );
		return true;
	}

	/**
	 * Marks an order paid by hand (offline payments), with a manual payment record.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, mixed>|WP_Error The order.
	 */
	public function mark_paid( int $order_id ) {
		$order = $this->orders->find( $order_id );
		if ( null === $order ) {
			return new WP_Error( 'rest_not_found', __( 'Order not found.', 'public-lms' ), array( 'status' => 404 ) );
		}
		if ( in_array( $order['status'], array( OrderRepository::STATUS_COMPLETED, OrderRepository::STATUS_REFUNDED ), true ) ) {
			return new WP_Error( 'publiclms_order_state', __( 'This order is already paid or refunded.', 'public-lms' ), array( 'status' => 409 ) );
		}
		$this->orders->add_transaction(
			$order_id,
			array(
				'gateway'  => (string) $order['gateway'],
				'type'     => 'payment',
				'status'   => 'completed',
				'amount'   => (float) $order['total'],
				'currency' => (string) $order['currency'],
			)
		);
		$user = wp_get_current_user();
		/* translators: %s: name of the person who marked the order paid. */
		$this->complete( $order_id, sprintf( __( 'Marked paid by %s.', 'public-lms' ), $user->exists() ? $user->display_name : __( 'the system', 'public-lms' ) ) );
		return (array) $this->orders->find( $order_id );
	}

	/**
	 * Cancels an unpaid order.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, mixed>|WP_Error The order.
	 */
	public function cancel( int $order_id ) {
		$open = array( OrderRepository::STATUS_PENDING, OrderRepository::STATUS_ON_HOLD, OrderRepository::STATUS_FAILED );
		if ( ! $this->orders->transition( $order_id, $open, OrderRepository::STATUS_CANCELLED ) ) {
			return new WP_Error( 'publiclms_order_state', __( 'Only unpaid orders can be cancelled.', 'public-lms' ), array( 'status' => 409 ) );
		}
		$user = wp_get_current_user();
		/* translators: %s: name of the person who cancelled the order. */
		$this->note( $order_id, sprintf( __( 'Cancelled by %s.', 'public-lms' ), $user->exists() ? $user->display_name : __( 'the system', 'public-lms' ) ) );
		return (array) $this->orders->find( $order_id );
	}

	/**
	 * Refunds a paid order: through its gateway when it can, by hand otherwise.
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $manual   The money was returned outside the gateway; only record it.
	 * @return array<string, mixed>|WP_Error The order.
	 */
	public function refund( int $order_id, bool $manual = false ) {
		$order = $this->orders->find( $order_id );
		if ( null === $order ) {
			return new WP_Error( 'rest_not_found', __( 'Order not found.', 'public-lms' ), array( 'status' => 404 ) );
		}
		if ( OrderRepository::STATUS_COMPLETED !== $order['status'] ) {
			return new WP_Error( 'publiclms_order_state', __( 'Only paid orders can be refunded.', 'public-lms' ), array( 'status' => 409 ) );
		}
		$gateway = $this->gateways->get( (string) $order['gateway'] );
		$payment = $this->payment_transaction( $order_id );
		if ( ! $manual && null !== $gateway && $gateway->supports( 'refunds' ) && null !== $payment && '' !== $payment['gateway_transaction_id'] ) {
			$results = $gateway->refund( $order, $payment );
			if ( is_wp_error( $results ) ) {
				return $results;
			}
			$this->apply( $gateway, $results, $order );
			return (array) $this->orders->find( $order_id );
		}
		if ( ! $manual && (float) $order['total'] > 0 && null !== $gateway && $gateway->supports( 'refunds' ) ) {
			return new WP_Error( 'publiclms_refund_no_payment', __( 'No payment from the provider is recorded for this order. Refund it at the provider, then record the refund by hand.', 'public-lms' ), array( 'status' => 409 ) );
		}
		$this->orders->add_transaction(
			$order_id,
			array(
				'gateway'  => (string) $order['gateway'],
				'type'     => 'refund',
				'status'   => 'completed',
				'amount'   => (float) $order['total'],
				'currency' => (string) $order['currency'],
			)
		);
		$user = wp_get_current_user();
		/* translators: %s: name of the person who recorded the refund. */
		$this->mark_refunded( $order_id, sprintf( __( 'Refund recorded by %s.', 'public-lms' ), $user->exists() ? $user->display_name : __( 'the system', 'public-lms' ) ) );
		return (array) $this->orders->find( $order_id );
	}

	/**
	 * Moves a paid order to refunded and cancels the enrolments it gave.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $note     History note.
	 * @return bool
	 */
	private function mark_refunded( int $order_id, string $note ): bool {
		if ( ! $this->orders->transition( $order_id, array( OrderRepository::STATUS_COMPLETED ), OrderRepository::STATUS_REFUNDED ) ) {
			return false;
		}
		$order = (array) $this->orders->find( $order_id );
		$items = $this->orders->items( $order_id );
		$this->note( $order_id, $note );

		/**
		 * Filters whether refunding an order cancels the enrolments it gave.
		 *
		 * @param bool                 $revoke Whether to cancel them. Default true.
		 * @param array<string, mixed> $order  Order.
		 */
		if ( apply_filters( 'publiclms_revoke_access_on_refund', true, $order ) ) {
			$user_id = (int) $order['user_id'];
			foreach ( $items as $item ) {
				$row = 'course' === $item['item_type'] ? $this->enrollments->find_by_user_and_course( $user_id, (int) $item['object_id'] ) : null;
				if ( null !== $row && 'order' === $row['source'] && $order_id === (int) $row['source_id'] ) {
					$this->enrollment->unenroll( $user_id, (int) $item['object_id'] );
				}
			}
		}
		$this->events->record(
			'order.refunded',
			array(
				'user_id'     => (int) $order['user_id'],
				'object_type' => 'order',
				'object_id'   => $order_id,
				'payload'     => array(
					'total'    => (float) $order['total'],
					'currency' => (string) $order['currency'],
				),
			)
		);
		$this->emails->refunded( $order, $items );

		/**
		 * Fires after an order is refunded.
		 *
		 * @param array<string, mixed>             $order Order.
		 * @param array<int, array<string, mixed>> $items Line items.
		 */
		do_action( 'publiclms_order_refunded', $order, $items );
		return true;
	}

	/**
	 * Adds a line to an order's history.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $text     Text.
	 * @return void
	 */
	public function note( int $order_id, string $text ): void {
		$order = $this->orders->find( $order_id );
		if ( null === $order ) {
			return;
		}
		$history   = (array) ( $order['meta']['history'] ?? array() );
		$history[] = array(
			'at'   => gmdate( 'Y-m-d H:i:s' ),
			'text' => $text,
		);
		$this->orders->update_meta( $order_id, array( 'history' => array_slice( $history, -100 ) ) );
	}

	/**
	 * Returns the order's completed payment transaction, or null.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, mixed>|null
	 */
	public function payment_transaction( int $order_id ): ?array {
		foreach ( array_reverse( $this->orders->transactions( $order_id ) ) as $transaction ) {
			if ( 'payment' === $transaction['type'] && 'completed' === $transaction['status'] ) {
				return $transaction;
			}
		}
		return null;
	}

	/**
	 * Returns the translated name of a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			OrderRepository::STATUS_PENDING   => __( 'Waiting for payment', 'public-lms' ),
			OrderRepository::STATUS_ON_HOLD   => __( 'On hold', 'public-lms' ),
			OrderRepository::STATUS_COMPLETED => __( 'Paid', 'public-lms' ),
			OrderRepository::STATUS_FAILED    => __( 'Failed', 'public-lms' ),
			OrderRepository::STATUS_CANCELLED => __( 'Cancelled', 'public-lms' ),
			OrderRepository::STATUS_REFUNDED  => __( 'Refunded', 'public-lms' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Shapes an order for REST and blocks.
	 *
	 * @param array<string, mixed> $order Order.
	 * @param bool                 $admin Include buyer details, history and transactions.
	 * @return array<string, mixed>
	 */
	public function prepare( array $order, bool $admin = false ): array {
		$currency = (string) $order['currency'];
		$gateway  = $this->gateways->get( (string) $order['gateway'] );
		$items    = array();
		foreach ( $this->orders->items( (int) $order['id'] ) as $item ) {
			$items[] = array(
				'type'               => $item['item_type'],
				'id'                 => $item['object_id'],
				'name'               => $item['name'],
				'url'                => 'course' === $item['item_type'] ? (string) get_permalink( $item['object_id'] ) : '',
				'subtotal'           => $item['subtotal'],
				'discount'           => $item['discount'],
				'total'              => $item['total'],
				'subtotal_formatted' => Money::format( $item['subtotal'], $currency ),
				'total_formatted'    => Money::format( $item['total'], $currency ),
			);
		}
		$status = (string) $order['status'];
		$data   = array(
			'id'                 => (int) $order['id'],
			'status'             => $status,
			'status_label'       => self::status_label( $status ),
			'currency'           => $currency,
			'subtotal'           => (float) $order['subtotal'],
			'discount_total'     => (float) $order['discount_total'],
			'total'              => (float) $order['total'],
			'subtotal_formatted' => Money::format( (float) $order['subtotal'], $currency ),
			'discount_formatted' => Money::format( (float) $order['discount_total'], $currency ),
			'total_formatted'    => Money::format( (float) $order['total'], $currency ),
			'gateway'            => (string) $order['gateway'],
			'gateway_title'      => null === $gateway ? ( 'free' === $order['gateway'] ? __( 'No payment needed', 'public-lms' ) : (string) $order['gateway'] ) : $gateway->admin_title(),
			'coupon_code'        => (string) $order['coupon_code'],
			'created_at'         => mysql_to_rfc3339( (string) $order['created_at'] ),
			'completed_at'       => null === $order['completed_at'] ? null : mysql_to_rfc3339( (string) $order['completed_at'] ),
			'items'              => $items,
			'view_url'           => CheckoutPage::order_url( (string) $order['order_key'] ),
		);
		if ( $admin ) {
			$user                     = (int) $order['user_id'] > 0 ? get_userdata( (int) $order['user_id'] ) : false;
			$data['billing_email']    = (string) $order['billing_email'];
			$data['customer']         = array(
				'id'   => false === $user ? 0 : (int) $user->ID,
				'name' => false === $user ? (string) ( $order['meta']['name'] ?? '' ) : (string) $user->display_name,
			);
			$data['gateway_order_id'] = (string) $order['gateway_order_id'];
			$data['history']          = array_map(
				static function ( $entry ) {
					return array(
						'at'   => mysql_to_rfc3339( (string) ( $entry['at'] ?? '' ) ),
						'text' => (string) ( $entry['text'] ?? '' ),
					);
				},
				(array) ( $order['meta']['history'] ?? array() )
			);
			$data['transactions']     = array_map(
				static function ( $transaction ) use ( $currency ) {
					$transaction['amount_formatted'] = Money::format( (float) $transaction['amount'], '' === $transaction['currency'] ? $currency : $transaction['currency'] );
					$transaction['created_at']       = mysql_to_rfc3339( (string) $transaction['created_at'] );
					return $transaction;
				},
				$this->orders->transactions( (int) $order['id'] )
			);
			$data['actions']          = array(
				'mark_paid'         => in_array( $status, array( OrderRepository::STATUS_PENDING, OrderRepository::STATUS_ON_HOLD, OrderRepository::STATUS_FAILED, OrderRepository::STATUS_CANCELLED ), true ),
				'cancel'            => in_array( $status, array( OrderRepository::STATUS_PENDING, OrderRepository::STATUS_ON_HOLD, OrderRepository::STATUS_FAILED ), true ),
				'refund'            => OrderRepository::STATUS_COMPLETED === $status,
				'refund_at_gateway' => OrderRepository::STATUS_COMPLETED === $status && null !== $gateway && $gateway->supports( 'refunds' ) && null !== $this->payment_transaction( (int) $order['id'] ),
			);
		}
		return $data;
	}

	/**
	 * Checks that a course can be bought by this buyer.
	 *
	 * @param int  $course_id Course.
	 * @param int  $owner     The account the order would enrol (0 for a new guest).
	 * @param bool $logged_in Whether the buyer is logged in.
	 * @return true|WP_Error
	 */
	private function check_purchasable( int $course_id, int $owner, bool $logged_in ) {
		if ( PostTypes::COURSE !== get_post_type( $course_id ) || 'publish' !== get_post_status( $course_id ) || 'paid' !== Meta::access_mode( $course_id ) || null === $this->fields->effective_price( $course_id ) ) {
			return new WP_Error( 'publiclms_not_for_sale', __( 'This course is not for sale.', 'public-lms' ), array( 'status' => 400 ) );
		}
		$title = wp_strip_all_tags( get_the_title( $course_id ) );
		if ( $owner > 0 ) {
			$decision = $this->policy->can_enroll( $course_id, $owner );
			if ( 'already_enrolled' === $decision->reason() ) {
				return new WP_Error(
					'publiclms_already_enrolled',
					$logged_in
						/* translators: %s: course title. */
						? sprintf( __( 'You are already enrolled in %s.', 'public-lms' ), $title )
						/* translators: %s: course title. */
						: sprintf( __( 'This email is already enrolled in %s. Log in to continue learning.', 'public-lms' ), $title ),
					array( 'status' => 409 )
				);
			}
			if ( 'prerequisite' === $decision->reason() ) {
				return new WP_Error( 'publiclms_prerequisite', $decision->message(), array( 'status' => 403 ) );
			}
			if ( ! in_array( $decision->reason(), array( 'payment_required', '' ), true ) ) {
				return new WP_Error( 'publiclms_not_for_sale', __( 'This course is not for sale.', 'public-lms' ), array( 'status' => 400 ) );
			}
		} elseif ( ! empty( get_post_meta( $course_id, Meta::PREREQUISITES, true ) ) ) {
			/* translators: %s: course title. */
			return new WP_Error( 'publiclms_prerequisite', sprintf( __( '%s needs another course first. Log in to buy it.', 'public-lms' ), $title ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Finds the order a payment result is about.
	 *
	 * @param Gateway                   $gateway Gateway.
	 * @param array<string, mixed>      $result  Result.
	 * @param array<string, mixed>|null $known   The caller's order.
	 * @return array<string, mixed>|null
	 */
	private function order_for_result( Gateway $gateway, array $result, ?array $known ): ?array {
		$order = null;
		if ( ! empty( $result['order_id'] ) ) {
			$order = $this->orders->find( (int) $result['order_id'] );
		} elseif ( '' !== (string) $result['gateway_order_id'] ) {
			$order = $this->orders->find_by_gateway_reference( $gateway->id(), (string) $result['gateway_order_id'] );
		} elseif ( '' !== (string) ( $result['payment_id'] ?? '' ) ) {
			$order = $this->orders->find_by_payment( $gateway->id(), (string) $result['payment_id'] );
		} elseif ( null !== $known ) {
			$order = $this->orders->find( (int) $known['id'] );
		}
		if ( null === $order || $order['gateway'] !== $gateway->id() ) {
			return null;
		}
		return $order;
	}
}
