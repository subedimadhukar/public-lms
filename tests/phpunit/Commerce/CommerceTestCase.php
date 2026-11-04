<?php
/**
 * Shared commerce test helpers.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Commerce;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Commerce\CheckoutPage;
use PublicLMS\Commerce\Coupons;
use PublicLMS\Commerce\Customers;
use PublicLMS\Commerce\Gateways\Gateways;
use PublicLMS\Commerce\Gateways\OfflineGateway;
use PublicLMS\Commerce\Money;
use PublicLMS\Commerce\OrderEmails;
use PublicLMS\Commerce\OrderService;
use PublicLMS\Courses\CourseFields;
use PublicLMS\PostTypes\Meta;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Repositories\EventRepositoryInterface;
use PublicLMS\Repositories\OrderRepository;
use PublicLMS\Services\EnrollmentService;
use PublicLMS\Tests\TestCase;

/**
 * Base for commerce tests: a store in EUR with Offline and a fake gateway.
 */
abstract class CommerceTestCase extends TestCase {

	/**
	 * The fake gateway.
	 *
	 * @var FakeGateway
	 */
	protected $fake;

	/**
	 * Offline gateway.
	 *
	 * @var OfflineGateway
	 */
	protected $offline;

	/**
	 * Order service built with the two gateways.
	 *
	 * @var OrderService
	 */
	protected $service;

	/**
	 * Orders.
	 *
	 * @var OrderRepository
	 */
	protected $orders;

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	protected $enrollments;

	public function set_up() {
		parent::set_up();
		update_option( Money::OPTION, 'EUR' );
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Checkout',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:publiclms/checkout /-->',
			)
		);
		update_option( CheckoutPage::OPTION, $page );
		update_option( CheckoutPage::GUEST_OPTION, 'yes' );

		$this->offline = new OfflineGateway();
		$this->offline->update_settings(
			array(
				'enabled'      => true,
				'instructions' => 'Transfer to account 123 with your order number.',
			)
		);
		$this->fake = new FakeGateway();
		$this->fake->update_settings( array( 'enabled' => true ) );
		$this->service = $this->build_service( new Gateways( array( $this->offline, $this->fake ) ) );

		$c                 = publiclms()->container();
		$this->orders      = $c->get( OrderRepository::class );
		$this->enrollments = $c->get( EnrollmentRepository::class );
		// The tests site runs on "localhost", which makes WordPress's default sender address invalid.
		add_filter(
			'wp_mail_from',
			static function () {
				return 'store@example.org';
			}
		);
		reset_phpmailer_instance();
	}

	public function tear_down() {
		delete_option( Money::OPTION );
		delete_option( 'publiclms_gateway_offline' );
		delete_option( 'publiclms_gateway_fake' );
		parent::tear_down();
	}

	/**
	 * Builds an order service around the given gateways.
	 *
	 * @param Gateways $gateways Gateways.
	 * @return OrderService
	 */
	protected function build_service( Gateways $gateways ): OrderService {
		$c = publiclms()->container();
		return new OrderService(
			$c->get( OrderRepository::class ),
			$gateways,
			$c->get( EnrollmentService::class ),
			$c->get( EnrollmentRepository::class ),
			$c->get( AccessPolicy::class ),
			$c->get( CourseFields::class ),
			$c->get( Coupons::class ),
			new Customers(),
			$c->get( EventRepositoryInterface::class ),
			new OrderEmails()
		);
	}

	/**
	 * Creates a published paid course.
	 *
	 * @param string $price Price.
	 * @param string $sale  Sale price, or ''.
	 * @return int
	 */
	protected function paid_course( string $price = '49', string $sale = '' ): int {
		$course = $this->create_course( array( 'post_title' => 'Data Analysis with Spreadsheets' ) );
		update_post_meta( $course, Meta::ACCESS_MODE, 'paid' );
		update_post_meta( $course, Meta::PRICE, $price );
		if ( '' !== $sale ) {
			update_post_meta( $course, Meta::SALE_PRICE, $sale );
		}
		return $course;
	}

	/**
	 * Creates a coupon.
	 *
	 * @param array<string, mixed> $data Coupon data.
	 * @return int
	 */
	protected function coupon( array $data ): int {
		$id = publiclms()->container()->get( Coupons::class )->save( $data );
		$this->assertIsInt( $id );
		return $id;
	}
}
