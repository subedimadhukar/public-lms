<?php
/**
 * An HTTP client that never connects.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Certificates\Pdf;

use InvalidArgumentException;
use PublicLMS\Vendor\Mpdf\Http\ClientInterface;
use PublicLMS\Vendor\Psr\Http\Message\RequestInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The HTTP client mPDF gets for certificates: it refuses every request, so a
 * template or stylesheet can never make the server fetch a URL (PRD §5,
 * ADR-052). mPDF treats the refusal as a missing image and carries on.
 *
 * Load only after the prefixed mPDF (`CertificateRenderer::available()`).
 */
final class NoNetwork implements ClientInterface {

	/**
	 * Refuses the request.
	 *
	 * @param RequestInterface $request Request.
	 * @return never
	 * @throws InvalidArgumentException Always.
	 */
	public function sendRequest( RequestInterface $request ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mPDF's interface.
		throw new InvalidArgumentException( 'Certificates do not load remote content.' );
	}
}
