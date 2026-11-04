<?php
/**
 * Certificate routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Capabilities\Capabilities;
use PublicLMS\Certificates\CertificateRenderer;
use PublicLMS\Certificates\CertificateService;
use PublicLMS\PostTypes\PostTypes;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes (ADR-044):
 *
 * - GET /certificates/me           The current learner's certificates (issuing any owed).
 * - GET /certificates/{course_id}  The certificate PDF. AccessPolicy::can_view_certificate: the
 *                                  learner who completed the course; `user_id` for report viewers.
 * - GET /certificates/preview      The template with sample values. publiclms_manage_courses.
 */
final class CertificatesController extends AbstractController {

	/**
	 * Certificates.
	 *
	 * @var CertificateService
	 */
	private $certificates;

	/**
	 * Renderer.
	 *
	 * @var CertificateRenderer
	 */
	private $renderer;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private $policy;

	/**
	 * Constructor.
	 *
	 * @param CertificateService  $certificates Certificates.
	 * @param CertificateRenderer $renderer     Renderer.
	 * @param AccessPolicy        $policy       Access policy.
	 */
	public function __construct( CertificateService $certificates, CertificateRenderer $renderer, AccessPolicy $policy ) {
		parent::__construct();
		$this->rest_base    = 'certificates';
		$this->certificates = $certificates;
		$this->renderer     = $renderer;
		$this->policy       = $policy;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'logged_in' ),
					'args'                => array(),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/preview',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'preview' ),
					'permission_callback' => $this->require_capability( Capabilities::MANAGE_COURSES ),
					'args'                => array(),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<course_id>[\d]+)',
			array(
				'args' => array(
					'course_id' => $this->id_arg( __( 'Course ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'user_id' => $this->id_arg( __( 'Learner whose certificate to download; defaults to the current user.', 'public-lms' ), false ),
					),
				),
			)
		);
	}

	/**
	 * Logged in.
	 *
	 * @return true|WP_Error
	 */
	public function logged_in() {
		return is_user_logged_in() ? true : new WP_Error( 'publiclms_rest_logged_out', __( 'Please log in first.', 'public-lms' ), array( 'status' => 401 ) );
	}

	/**
	 * Lists the current learner's certificates.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$items = array();
		foreach ( $this->certificates->for_user( get_current_user_id() ) as $row ) {
			$items[] = array(
				'id'             => (int) $row['id'],
				'certificate_id' => (string) $row['certificate_key'],
				'course_id'      => (int) $row['course_id'],
				'course_title'   => (string) $row['course_title'],
				'completed_at'   => str_replace( ' ', 'T', (string) $row['completed_at'] ) . 'Z',
				'issued_at'      => str_replace( ' ', 'T', (string) $row['issued_at'] ) . 'Z',
				'download_url'   => CertificateService::download_url( (int) $row['course_id'] ),
			);
		}
		return rest_ensure_response( $items );
	}

	/**
	 * AccessPolicy::can_view_certificate for the learner asked about.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$check = $this->logged_in();
		if ( true !== $check ) {
			return $check;
		}
		$course_id = (int) $request['course_id'];
		if ( PostTypes::COURSE !== get_post_type( $course_id ) ) {
			return $this->not_found();
		}
		$owner    = isset( $request['user_id'] ) ? (int) $request['user_id'] : get_current_user_id();
		$decision = $this->policy->can_view_certificate( $course_id, get_current_user_id(), $owner );
		return $decision->is_allowed() ? true : $this->not_found();
	}

	/**
	 * Streams a certificate PDF.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$course_id   = (int) $request['course_id'];
		$owner       = isset( $request['user_id'] ) ? (int) $request['user_id'] : get_current_user_id();
		$certificate = $this->certificates->issue( $owner, $course_id );
		if ( null === $certificate ) {
			return $this->not_found();
		}
		return $this->pdf_response( $certificate, sanitize_file_name( sanitize_title( (string) $certificate['course_title'] ) . '-certificate.pdf' ) );
	}

	/**
	 * Streams the template with sample values.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview( $request ) {
		return $this->pdf_response( CertificateRenderer::sample(), 'certificate-preview.pdf' );
	}

	/**
	 * Renders a certificate into a PDF response.
	 *
	 * @param array<string, mixed> $certificate Certificate row or sample.
	 * @param string               $name        File name.
	 * @return WP_REST_Response|WP_Error
	 */
	private function pdf_response( array $certificate, string $name ) {
		try {
			$pdf = $this->renderer->pdf( $certificate );
		} catch ( RuntimeException $exception ) {
			return new WP_Error( 'publiclms_pdf_unavailable', __( 'Certificates cannot be created on this site right now. Ask the site owner to reinstall Public LMS.', 'public-lms' ), array( 'status' => 503 ) );
		}
		return FileResponse::from_string( $pdf, $name, 'application/pdf', 'inline' );
	}
}
