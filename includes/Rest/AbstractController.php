<?php
/**
 * Base REST controller.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use WP_Error;
use WP_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour for every publiclms/v1 controller.
 *
 * Rules every subclass follows (ADR-006):
 * - register_routes() declares a permission_callback on every route, mapped
 *   to a capability or to AccessPolicy, never `__return_true` for writes;
 * - every argument has a type plus sanitize and validate callbacks;
 * - state-changing routes rely on the REST nonce (cookie auth) or
 *   Application Passwords;
 * - collections use keyset pagination (`after` plus `per_page`).
 */
abstract class AbstractController extends WP_REST_Controller {

	/**
	 * REST namespace shared by every controller.
	 */
	const REST_NAMESPACE = 'publiclms/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = self::REST_NAMESPACE;
	}

	/**
	 * Returns a permission callback that requires a capability.
	 *
	 * The returned closure reads the object ID from the request's `id`
	 * parameter when present, so meta capabilities resolve per object.
	 *
	 * @param string $capability Primitive or meta capability.
	 * @return callable(\WP_REST_Request<array<string, mixed>>): (bool|WP_Error)
	 */
	protected function require_capability( string $capability ): callable {
		return function ( $request ) use ( $capability ) {
			$object_id = absint( $request->get_param( 'id' ) );
			$allowed   = $object_id > 0
				? current_user_can( $capability, $object_id )
				: current_user_can( $capability );

			return $allowed ? true : $this->forbidden();
		};
	}

	/**
	 * Returns the standard error for a failed permission check.
	 *
	 * @return WP_Error
	 */
	protected function forbidden(): WP_Error {
		return new WP_Error(
			'publiclms_rest_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'public-lms' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Returns the standard error for a missing object.
	 *
	 * @return WP_Error
	 */
	protected function not_found(): WP_Error {
		return new WP_Error(
			'publiclms_rest_not_found',
			__( 'The requested item could not be found.', 'public-lms' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Returns the shared keyset pagination arguments for collection routes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function keyset_collection_params(): array {
		return array(
			'after'    => array(
				'description'       => __( 'Return items with an ID greater than this.', 'public-lms' ),
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to return.', 'public-lms' ),
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}
}
