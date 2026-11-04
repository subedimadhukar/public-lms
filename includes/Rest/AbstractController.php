<?php
/**
 * Base REST controller.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

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
 * - collections use keyset pagination (`after` plus `per_page`) or, where
 *   they sort by something other than the ID, an opaque `cursor` that holds
 *   the sort value and ID of the last row returned. Responses carry
 *   `X-WP-Total` and, when more rows exist, `X-PublicLMS-Next-Cursor` and a
 *   `Link: <…>; rel="next"` header.
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
	/**
	 * Returns the shared cursor pagination arguments for collection routes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function cursor_collection_params(): array {
		return array(
			'cursor'   => array(
				'description'       => __( 'Opaque cursor from the X-PublicLMS-Next-Cursor header of the previous page.', 'public-lms' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_cursor' ),
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

	/**
	 * Encodes a cursor from the sort value and ID of the last row.
	 *
	 * @param string|int $value Sort value.
	 * @param int        $id    Row or object ID.
	 * @return string
	 */
	protected function encode_cursor( $value, int $id ): string {
		return rtrim( strtr( base64_encode( (string) wp_json_encode( array( $value, $id ) ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque pagination cursor, not obfuscation.
	}

	/**
	 * Decodes a cursor into its sort value and ID, or null when empty or malformed.
	 *
	 * @param string $cursor Cursor.
	 * @return array{0: string|int, 1: int}|null
	 */
	protected function decode_cursor( string $cursor ): ?array {
		if ( '' === $cursor ) {
			return null;
		}
		$json = base64_decode( strtr( $cursor, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Opaque pagination cursor, not obfuscation.
		if ( false === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || 2 !== count( $data ) || ! isset( $data[0], $data[1] ) || ! is_scalar( $data[0] ) || ! is_numeric( $data[1] ) ) {
			return null;
		}
		return array( is_int( $data[0] ) ? $data[0] : (string) $data[0], (int) $data[1] );
	}

	/**
	 * Validates a cursor argument.
	 *
	 * @param mixed $value Raw value.
	 * @return true|WP_Error
	 */
	public function validate_cursor( $value ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The cursor must be a string.', 'public-lms' ), array( 'status' => 400 ) );
		}
		if ( '' !== $value && null === $this->decode_cursor( $value ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The cursor is not valid.', 'public-lms' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Wraps a page of items with the pagination headers.
	 *
	 * @param array<int, mixed>                     $items       Prepared items.
	 * @param int                                   $total       Total matching rows.
	 * @param string                                $next_cursor Cursor for the next page, or ''.
	 * @param WP_REST_Request<array<string, mixed>> $request     Request.
	 * @return WP_REST_Response
	 */
	protected function paginated_response( array $items, int $total, string $next_cursor, $request ): WP_REST_Response {
		$response = new WP_REST_Response( array_values( $items ) );
		$response->header( 'X-WP-Total', (string) $total );
		if ( '' !== $next_cursor ) {
			$response->header( 'X-PublicLMS-Next-Cursor', $next_cursor );
			$params           = $request->get_query_params();
			$params['cursor'] = $next_cursor;
			$next_url         = add_query_arg( urlencode_deep( $params ), rest_url( ltrim( $request->get_route(), '/' ) ) );
			$response->link_header( 'next', $next_url );
		}
		return $response;
	}

	/**
	 * Returns an integer route or body argument definition.
	 *
	 * @param string $description Description.
	 * @param bool   $required    Whether the argument is required.
	 * @return array<string, mixed>
	 */
	protected function id_arg( string $description, bool $required = true ): array {
		return array(
			'description'       => $description,
			'type'              => 'integer',
			'minimum'           => 1,
			'required'          => $required,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Returns a WP_Error for a failed access policy decision.
	 *
	 * @param \PublicLMS\Access\AccessDecision $decision The denial.
	 * @return WP_Error
	 */
	protected function denied( $decision ): WP_Error {
		return new WP_Error(
			'publiclms_rest_' . ( '' === $decision->reason() ? 'forbidden' : $decision->reason() ),
			$decision->message(),
			array(
				'status' => is_user_logged_in() ? 403 : 401,
				'reason' => $decision->reason(),
			)
		);
	}
}
