<?php
/**
 * A small service container.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Core;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Lazily builds and shares services by identifier.
 *
 * Identifiers are class or interface names. Each factory runs once, on first
 * use, and its result is shared for the rest of the request. Pro and add-ons
 * replace a binding with set() during `publiclms_register_services`.
 */
final class Container {

	/**
	 * Factories keyed by service identifier.
	 *
	 * @var array<string, callable(Container): object>
	 */
	private $factories = array();

	/**
	 * Built services keyed by service identifier.
	 *
	 * @var array<string, object>
	 */
	private $instances = array();

	/**
	 * Binds a factory to an identifier, replacing any earlier binding.
	 *
	 * @param string                      $id      Service identifier, usually a class or interface name.
	 * @param callable(Container): object $factory Builds the service; receives this container.
	 * @return void
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Whether an identifier has a binding.
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] );
	}

	/**
	 * Returns the shared service for an identifier, building it on first use.
	 *
	 * @template T of object
	 * @param string $id Service identifier.
	 * @phpstan-param class-string<T> $id
	 * @return object
	 * @phpstan-return T
	 * @throws InvalidArgumentException When nothing is bound to the identifier.
	 */
	public function get( string $id ) {
		if ( isset( $this->instances[ $id ] ) ) {
			/**
			 * The shared instance, typed for static analysis.
			 *
			 * @var T $instance
			 */
			$instance = $this->instances[ $id ];
			return $instance;
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'No service is bound to "%s".', esc_html( $id ) )
			);
		}

		/**
		 * The newly built instance, typed for static analysis.
		 *
		 * @var T $instance
		 */
		$instance               = ( $this->factories[ $id ] )( $this );
		$this->instances[ $id ] = $instance;

		return $instance;
	}
}
