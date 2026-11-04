<?php
/**
 * Mocked HTTP for gateway tests.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Commerce;

/**
 * Answers wp_remote_request() through pre_http_request from a queue of
 * responses and records every request. Any request it has no answer for
 * fails the test, so PHPUnit never reaches Stripe or PayPal.
 */
final class HttpMock {

	/**
	 * Requests seen: url, method, headers, body.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $requests = array();

	/**
	 * Responses by URL fragment, each a list used in order.
	 *
	 * @var array<string, array<int, array{0: int, 1: array<string, mixed>}>>
	 */
	private $responses = array();

	/**
	 * Hooks the mock.
	 */
	public function __construct() {
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * Unhooks the mock.
	 *
	 * @return void
	 */
	public function stop(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
	}

	/**
	 * Queues a JSON response for requests whose "METHOD url" contains $needle.
	 *
	 * @param string               $needle  For example "POST https://api.stripe.com/v1/checkout/sessions".
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $body   JSON body.
	 * @return self
	 */
	public function on( string $needle, int $status, array $body ): self {
		$this->responses[ $needle ][] = array( $status, $body );
		return $this;
	}

	/**
	 * Answers a request.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $args Request arguments.
	 * @param string               $url  URL.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When no answer is queued for the request.
	 */
	public function answer( $pre, $args, $url ): array {
		$method           = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$this->requests[] = array(
			'url'     => $url,
			'method'  => $method,
			'headers' => (array) ( $args['headers'] ?? array() ),
			'body'    => $args['body'] ?? null,
		);
		$key              = $method . ' ' . $url;
		$needles          = array_keys( $this->responses );
		// The most specific match wins: ".../orders/{id}/capture" before ".../orders".
		usort(
			$needles,
			static function ( $a, $b ) {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);
		foreach ( $needles as $needle ) {
			$queue = $this->responses[ $needle ];
			if ( false !== strpos( $key, $needle ) && ! empty( $queue ) ) {
				$response = count( $queue ) > 1 ? array_shift( $this->responses[ $needle ] ) : $queue[0];
				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( $response[1] ),
					'response' => array(
						'code'    => $response[0],
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		throw new \RuntimeException( 'Unexpected HTTP request in a test: ' . $key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test failure message.
	}

	/**
	 * Returns the last request whose "METHOD url" contains $needle.
	 *
	 * @param string $needle Fragment.
	 * @return array<string, mixed>|null
	 */
	public function last( string $needle ): ?array {
		foreach ( array_reverse( $this->requests ) as $request ) {
			if ( false !== strpos( $request['method'] . ' ' . $request['url'], $needle ) ) {
				return $request;
			}
		}
		return null;
	}
}
