<?php
/**
 * Commerce service bindings.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Commerce;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Commerce\Gateways\Gateways;
use PublicLMS\Commerce\Gateways\OfflineGateway;
use PublicLMS\Commerce\Gateways\PayPalGateway;
use PublicLMS\Commerce\Gateways\StripeGateway;
use PublicLMS\Core\Container;
use PublicLMS\Courses\CourseFields;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Repositories\EventRepositoryInterface;
use PublicLMS\Repositories\OrderRepository;
use PublicLMS\Repositories\OrderRepositoryInterface;
use PublicLMS\Services\EnrollmentService;

defined( 'ABSPATH' ) || exit;

/**
 * Binds the commerce services in the container (ADR-046, ADR-047), so Pro
 * or an add-on can replace any of them during `publiclms_register_services`.
 */
final class CommerceServices {

	/**
	 * Binds the services.
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	public static function register( Container $c ): void {
		$c->set(
			OrderRepository::class,
			static function () {
				global $wpdb;
				return new OrderRepository( $wpdb );
			}
		);
		$c->set(
			OrderRepositoryInterface::class,
			static function ( Container $container ) {
				return $container->get( OrderRepository::class );
			}
		);
		$c->set(
			Gateways::class,
			static function () {
				return new Gateways(
					array(
						new OfflineGateway(),
						new StripeGateway(),
						new PayPalGateway(),
					)
				);
			}
		);
		$c->set(
			Coupons::class,
			static function ( Container $container ) {
				return new Coupons( $container->get( OrderRepository::class ) );
			}
		);
		$c->set(
			Customers::class,
			static function () {
				return new Customers();
			}
		);
		$c->set(
			OrderEmails::class,
			static function () {
				return new OrderEmails();
			}
		);
		$c->set(
			OrderService::class,
			static function ( Container $container ) {
				return new OrderService(
					$container->get( OrderRepository::class ),
					$container->get( Gateways::class ),
					$container->get( EnrollmentService::class ),
					$container->get( EnrollmentRepository::class ),
					$container->get( AccessPolicy::class ),
					$container->get( CourseFields::class ),
					$container->get( Coupons::class ),
					$container->get( Customers::class ),
					$container->get( EventRepositoryInterface::class ),
					$container->get( OrderEmails::class )
				);
			}
		);
		$c->set(
			CheckoutHandler::class,
			static function ( Container $container ) {
				return new CheckoutHandler( $container->get( OrderService::class ) );
			}
		);
		$c->set(
			OrdersTab::class,
			static function ( Container $container ) {
				return new OrdersTab( $container->get( OrderService::class ) );
			}
		);
	}

	/**
	 * Hooks the front-end parts.
	 *
	 * @param Container $c Container.
	 * @return void
	 */
	public static function boot( Container $c ): void {
		$c->get( CheckoutHandler::class )->register();
		$c->get( OrdersTab::class )->register();
	}
}
