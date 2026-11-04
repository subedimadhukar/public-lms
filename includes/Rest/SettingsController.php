<?php
/**
 * Settings routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Capabilities\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes, for users with publiclms_manage_courses:
 *
 * - GET  /settings                  The plugin's options.
 * - POST /settings                  Update them. Removing data on uninstall also needs delete_plugins.
 * - POST /settings/dashboard-page   Create a "My learning" page with the student dashboard and select it.
 *
 * WordPress's own /wp/v2/settings needs manage_options, which course managers
 * do not have, so the plugin keeps its options behind its own capability.
 */
final class SettingsController extends AbstractController {

	/**
	 * Option names keyed by response field.
	 */
	const OPTIONS = array(
		'dashboard_page_id'        => 'publiclms_dashboard_page_id',
		'currency'                 => 'publiclms_currency',
		'remove_data_on_uninstall' => 'publiclms_remove_data_on_uninstall',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->rest_base = 'settings';
	}

	/**
	 * Registers the routes.
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
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_COURSES ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'dashboard_page_id'        => array(
							'description'       => __( 'Page that shows the student dashboard; 0 for none.', 'public-lms' ),
							'type'              => 'integer',
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'currency'                 => array(
							'description'       => __( 'ISO 4217 currency code for prices.', 'public-lms' ),
							'type'              => 'string',
							'pattern'           => '^[A-Za-z]{3}$',
							'sanitize_callback' => array( $this, 'sanitize_currency' ),
							'validate_callback' => 'rest_validate_request_arg',
						),
						'remove_data_on_uninstall' => array(
							'description'       => __( 'Delete every course, enrolment and order when the plugin is deleted.', 'public-lms' ),
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/dashboard-page',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_dashboard_page' ),
					'permission_callback' => array( $this, 'create_dashboard_page_permissions_check' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Returns the settings.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		return rest_ensure_response( $this->data() );
	}

	/**
	 * Needs publiclms_manage_courses, plus delete_plugins to change the data removal switch.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		if ( ! current_user_can( Capabilities::MANAGE_COURSES ) ) {
			return $this->forbidden();
		}
		if ( isset( $request['remove_data_on_uninstall'] ) && ! current_user_can( 'delete_plugins' ) ) {
			return $this->forbidden();
		}
		return true;
	}

	/**
	 * Updates the settings.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		if ( isset( $request['dashboard_page_id'] ) ) {
			$page_id = (int) $request['dashboard_page_id'];
			if ( $page_id > 0 && 'page' !== get_post_type( $page_id ) ) {
				return new WP_Error( 'rest_invalid_param', __( 'Choose a page for the dashboard.', 'public-lms' ), array( 'status' => 400 ) );
			}
			update_option( self::OPTIONS['dashboard_page_id'], $page_id );
		}
		if ( isset( $request['currency'] ) ) {
			update_option( self::OPTIONS['currency'], $this->sanitize_currency( $request['currency'] ) );
		}
		if ( isset( $request['remove_data_on_uninstall'] ) ) {
			update_option( self::OPTIONS['remove_data_on_uninstall'], $request['remove_data_on_uninstall'] ? 'yes' : 'no' );
		}
		return rest_ensure_response( $this->data() );
	}

	/**
	 * Needs publiclms_manage_courses and the right to publish pages.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function create_dashboard_page_permissions_check( $request ) {
		return current_user_can( Capabilities::MANAGE_COURSES ) && current_user_can( 'publish_pages' ) ? true : $this->forbidden();
	}

	/**
	 * Creates the dashboard page and selects it.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_dashboard_page( $request ) {
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'My learning', 'public-lms' ),
				'post_name'    => 'my-learning',
				'post_content' => '<!-- wp:publiclms/student-dashboard /-->',
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			$page_id->add_data( array( 'status' => 500 ) );
			return $page_id;
		}
		update_option( self::OPTIONS['dashboard_page_id'], (int) $page_id );
		return rest_ensure_response( $this->data() );
	}

	/**
	 * Sanitises a currency code to three upper-case letters, defaulting to USD.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_currency( $value ): string {
		$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', is_string( $value ) ? $value : '' ) );
		return 3 === strlen( $value ) ? $value : 'USD';
	}

	/**
	 * Returns the current settings.
	 *
	 * @return array<string, mixed>
	 */
	private function data(): array {
		$page_id = (int) get_option( self::OPTIONS['dashboard_page_id'], 0 );
		return array(
			'dashboard_page_id'        => $page_id,
			'dashboard_page_url'       => $page_id > 0 ? (string) get_permalink( $page_id ) : '',
			'currency'                 => (string) get_option( self::OPTIONS['currency'], 'USD' ),
			'remove_data_on_uninstall' => 'yes' === get_option( self::OPTIONS['remove_data_on_uninstall'], 'no' ),
		);
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
			'title'      => 'publiclms-settings',
			'type'       => 'object',
			'properties' => array(
				'dashboard_page_id'        => array( 'type' => 'integer' ),
				'dashboard_page_url'       => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'currency'                 => array( 'type' => 'string' ),
				'remove_data_on_uninstall' => array( 'type' => 'boolean' ),
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}
}
