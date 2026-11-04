<?php
/**
 * REST namespace registration.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Core\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the publiclms/v1 namespace and its controllers.
 */
final class Api {

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Hooks route registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers every controller's routes. Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		foreach ( $this->controllers() as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Returns the controllers to register.
	 *
	 * @return array<int, \WP_REST_Controller>
	 */
	public function controllers(): array {
		$controllers = array(
			new HealthController(),
		);

		/**
		 * Filters the REST controllers registered under publiclms/v1.
		 *
		 * Pro and add-ons append their controllers here.
		 *
		 * @param array<int, \WP_REST_Controller> $controllers Controller instances.
		 * @param Container                       $container   Service container.
		 */
		$controllers = (array) apply_filters( 'publiclms_rest_controllers', $controllers, $this->container );

		return array_values(
			array_filter(
				$controllers,
				static function ( $controller ) {
					return $controller instanceof \WP_REST_Controller;
				}
			)
		);
	}
}
