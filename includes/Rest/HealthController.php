<?php
/**
 * Health check route.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\Core\Installer;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /publiclms/v1/health.
 *
 * Anyone gets `{ "status": "ok" }`, so uptime checks and the Playground
 * integration build can ping it. Users who can manage courses also get the
 * plugin and schema versions and a per-table check, which stay hidden from
 * everyone else to avoid version disclosure.
 */
final class HealthController extends AbstractController {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->rest_base = 'health';
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => array(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Returns the health payload.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$data = array( 'status' => 'ok' );

		if ( current_user_can( Capabilities::MANAGE_COURSES ) ) {
			$tables = $this->table_status();

			$data['status']     = in_array( false, $tables, true ) ? 'degraded' : 'ok';
			$data['version']    = PUBLICLMS_VERSION;
			$data['db_version'] = (string) get_option( Installer::DB_VERSION_OPTION, '' );
			$data['tables']     = $tables;
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Returns whether each plugin table exists, keyed by unprefixed name.
	 *
	 * @return array<string, bool>
	 */
	private function table_status(): array {
		global $wpdb;

		$status = array();
		foreach ( Installer::TABLES as $name ) {
			$table = Installer::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostic check for the plugin's own tables.
			$found           = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			$status[ $name ] = ( $found === $table );
		}
		return $status;
	}

	/**
	 * Returns the item schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'publiclms-health',
			'type'       => 'object',
			'properties' => array(
				'status'     => array(
					'description' => __( 'Overall status: ok or degraded.', 'public-lms' ),
					'type'        => 'string',
					'enum'        => array( 'ok', 'degraded' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'version'    => array(
					'description' => __( 'Plugin version. Only for users who can manage courses.', 'public-lms' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'db_version' => array(
					'description' => __( 'Installed database schema version. Only for users who can manage courses.', 'public-lms' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'tables'     => array(
					'description'          => __( 'Whether each plugin table exists. Only for users who can manage courses.', 'public-lms' ),
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'boolean' ),
					'context'              => array( 'view' ),
					'readonly'             => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
