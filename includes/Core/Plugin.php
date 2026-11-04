<?php
/**
 * Plugin bootstrap.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Core;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Admin\AdminApp;
use PublicLMS\Admin\EditorAssets;
use PublicLMS\Access\ContentGate;
use PublicLMS\Courses\CourseFields;
use PublicLMS\Frontend\EnrollmentHandler;
use PublicLMS\Capabilities\Capabilities;
use PublicLMS\Licensing\LicenseProvider;
use PublicLMS\Licensing\NullLicenseProvider;
use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CourseStructureRepository;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Repositories\EventRepository;
use PublicLMS\Repositories\EventRepositoryInterface;
use PublicLMS\Repositories\ProgressRepository;
use PublicLMS\Rest\Api;
use PublicLMS\Rest\PostFields;
use PublicLMS\Services\EnrollmentService;
use PublicLMS\Services\ProgressService;
use PublicLMS\Taxonomies\Taxonomies;
use PublicLMS\Templates\TemplateRegistrar;

defined( 'ABSPATH' ) || exit;

/**
 * Wires services into WordPress.
 *
 * The plugin keeps no global state outside this object. Every service is
 * resolved from the container so Pro and add-ons can replace one by binding
 * a different factory during `publiclms_register_services`.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Builds the plugin and its container. Use instance() instead.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->register_services();
	}

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Entry point on `plugins_loaded`.
	 *
	 * @return void
	 */
	public static function boot(): void {
		self::instance()->run();
	}

	/**
	 * Registers hooks once.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Installer::maybe_upgrade();

		add_action( 'init', array( $this->container->get( PostTypes::class ), 'register' ), 5 );
		add_action( 'init', array( $this->container->get( Taxonomies::class ), 'register' ), 5 );
		add_action( 'init', array( Installer::class, 'maybe_flush_rewrite_rules' ), 99 );

		$this->container->get( Meta::class )->register();
		$this->container->get( CourseStructureRepository::class )->register();
		$this->container->get( ContentGate::class )->register();
		$this->container->get( EnrollmentHandler::class )->register();
		$this->container->get( PostFields::class )->register();
		$this->container->get( EditorAssets::class )->register();
		if ( is_admin() ) {
			$this->container->get( AdminApp::class )->register();
		}

		$this->container->get( Capabilities::class )->register();
		$this->container->get( TemplateRegistrar::class )->register();
		$this->container->get( Api::class )->register();

		add_action( 'wp_initialize_site', array( Installer::class, 'install_on_new_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( Installer::class, 'drop_tables_on_site_delete' ) );

		/**
		 * Fires once Public LMS has registered its hooks.
		 *
		 * Pro and add-ons hook in here. The plugin instance is passed so they
		 * can reach the container without calling publiclms().
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'publiclms_loaded', $this );
	}

	/**
	 * Binds the core services.
	 *
	 * @return void
	 */
	private function register_services(): void {
		$c = $this->container;

		$c->set(
			PostTypes::class,
			static function () {
				return new PostTypes();
			}
		);
		$c->set(
			Taxonomies::class,
			static function () {
				return new Taxonomies();
			}
		);
		$c->set(
			Meta::class,
			static function () {
				return new Meta();
			}
		);
		$c->set(
			CourseStructureRepository::class,
			static function () {
				return new CourseStructureRepository();
			}
		);
		$c->set(
			CourseStructureRepositoryInterface::class,
			static function ( Container $container ) {
				return $container->get( CourseStructureRepository::class );
			}
		);
		$c->set(
			EnrollmentRepository::class,
			static function () {
				global $wpdb;
				return new EnrollmentRepository( $wpdb );
			}
		);
		$c->set(
			ProgressRepository::class,
			static function () {
				global $wpdb;
				return new ProgressRepository( $wpdb );
			}
		);
		$c->set(
			EventRepositoryInterface::class,
			static function () {
				global $wpdb;
				return new EventRepository( $wpdb );
			}
		);
		$c->set(
			Capabilities::class,
			static function ( Container $container ) {
				return new Capabilities( $container->get( CourseStructureRepositoryInterface::class ) );
			}
		);
		$c->set(
			AccessPolicy::class,
			static function ( Container $container ) {
				return new AccessPolicy(
					$container->get( CourseStructureRepositoryInterface::class ),
					$container->get( EnrollmentRepository::class )
				);
			}
		);
		$c->set(
			EnrollmentService::class,
			static function ( Container $container ) {
				return new EnrollmentService(
					$container->get( EnrollmentRepository::class ),
					$container->get( ProgressRepository::class ),
					$container->get( CourseStructureRepositoryInterface::class ),
					$container->get( EventRepositoryInterface::class ),
					$container->get( AccessPolicy::class )
				);
			}
		);
		$c->set(
			ProgressService::class,
			static function ( Container $container ) {
				return new ProgressService(
					$container->get( ProgressRepository::class ),
					$container->get( EnrollmentRepository::class ),
					$container->get( CourseStructureRepositoryInterface::class ),
					$container->get( EventRepositoryInterface::class ),
					$container->get( AccessPolicy::class )
				);
			}
		);
		$c->set(
			CourseFields::class,
			static function ( Container $container ) {
				return new CourseFields(
					$container->get( CourseStructureRepositoryInterface::class ),
					$container->get( EnrollmentRepository::class ),
					$container->get( ProgressService::class ),
					$container->get( AccessPolicy::class )
				);
			}
		);
		$c->set(
			ContentGate::class,
			static function ( Container $container ) {
				return new ContentGate( $container->get( AccessPolicy::class ) );
			}
		);
		$c->set(
			EnrollmentHandler::class,
			static function ( Container $container ) {
				return new EnrollmentHandler( $container->get( EnrollmentService::class ), $container->get( CourseFields::class ) );
			}
		);
		$c->set(
			PostFields::class,
			static function ( Container $container ) {
				return new PostFields( $container->get( CourseFields::class ) );
			}
		);
		$c->set(
			EditorAssets::class,
			static function () {
				return new EditorAssets();
			}
		);
		$c->set(
			AdminApp::class,
			static function () {
				return new AdminApp();
			}
		);
		$c->set(
			TemplateRegistrar::class,
			static function () {
				return new TemplateRegistrar( PUBLICLMS_PATH . 'templates/' );
			}
		);
		$c->set(
			Api::class,
			static function ( Container $container ) {
				return new Api( $container );
			}
		);
		$c->set(
			LicenseProvider::class,
			static function () {
				return new NullLicenseProvider();
			}
		);

		/**
		 * Fires after the core services are bound and before any is built.
		 *
		 * Bind a replacement with `$container->set( Interface::class, $factory )`.
		 *
		 * @param Container $container The service container.
		 */
		do_action( 'publiclms_register_services', $c );
	}

	/**
	 * Returns the service container.
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Returns the plugin version.
	 *
	 * @return string
	 */
	public function version(): string {
		return PUBLICLMS_VERSION;
	}
}
