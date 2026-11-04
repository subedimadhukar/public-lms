<?php
/**
 * Minimal WP-CLI stubs for PHPStan.
 *
 * php-stubs/wp-cli-stubs does not yet allow php-stubs/wordpress-stubs 7.x,
 * so only the methods the plugin calls are declared here.
 *
 * @package PublicLMS
 */

// phpcs:disable

if ( ! class_exists( 'WP_CLI' ) ) {
class WP_CLI {
	/**
	 * @param string          $name     Command name.
	 * @param callable|object $callable Handler.
	 * @param array<string, mixed> $args Options.
	 * @return bool
	 */
	public static function add_command( $name, $callable, $args = array() ) {}

	/**
	 * @param string|object $message Message or WP_Error.
	 * @param bool|int      $exit    Whether to exit.
	 * @return never
	 */
	public static function error( $message, $exit = true ) {}

	/**
	 * @param string $message Message.
	 * @return void
	 */
	public static function success( $message ) {}

	/**
	 * @param string $message Message.
	 * @return void
	 */
	public static function log( $message ) {}

	/**
	 * @param string               $question   Question.
	 * @param array<string, mixed> $assoc_args Named arguments; --yes skips the prompt.
	 * @return void
	 */
	public static function confirm( $question, $assoc_args = array() ) {}
}
}
