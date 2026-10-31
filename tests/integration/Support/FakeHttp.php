<?php
/**
 * Fake HTTP boundary for integration tests.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Support;

use WP_Error;

/**
 * Intercepts every outgoing WordPress HTTP request through `pre_http_request`, so tests never
 * reach Mat Bao or Lark. Responses are routed by "METHOD path" (path without query string);
 * unrouted requests fail the call with a WP_Error instead of hitting the network.
 */
final class FakeHttp {

	/**
	 * Recorded requests in call order.
	 *
	 * @var list<array{method: string, url: string, headers: array<string, string>, body: mixed}>
	 */
	public array $requests = array();

	/**
	 * Route handlers keyed by "METHOD /path".
	 *
	 * @var array<string, callable(array<string, mixed>, string): (array<string, mixed>|WP_Error)>
	 */
	private array $routes = array();

	/**
	 * Registered filter callback, kept for removal.
	 *
	 * @var callable
	 */
	private $filter;

	/**
	 * Starts intercepting HTTP requests.
	 */
	public static function install(): self {
		$fake         = new self();
		$fake->filter = array( $fake, 'intercept' );
		add_filter( 'pre_http_request', $fake->filter, 10, 3 );

		return $fake;
	}

	/**
	 * Stops intercepting HTTP requests.
	 */
	public function uninstall(): void {
		remove_filter( 'pre_http_request', $this->filter, 10 );
	}

	/**
	 * Responds to "METHOD /path" with a JSON body.
	 *
	 * @param string $route  For example "POST /api/auth/login".
	 * @param int    $status HTTP status code.
	 * @param mixed  $json   Value encoded as the JSON response body.
	 */
	public function respond_json( string $route, int $status, mixed $json ): void {
		$this->routes[ $route ] = static fn(): array => self::json_response( $status, $json );
	}

	/**
	 * Responds to "METHOD /path" with a custom handler.
	 *
	 * @param string                                                                $route   For example "POST /api/auth/login".
	 * @param callable(array<string, mixed>, string): (array<string, mixed>|WP_Error) $handler Receives request args and URL.
	 */
	public function respond_with( string $route, callable $handler ): void {
		$this->routes[ $route ] = $handler;
	}

	/**
	 * Requests whose "METHOD /path" equals the given route.
	 *
	 * @param string $route For example "POST /api/auth/login".
	 * @return list<array{method: string, url: string, headers: array<string, string>, body: mixed}>
	 */
	public function requests_to( string $route ): array {
		return array_values( array_filter( $this->requests, static fn( array $request ): bool => self::route_of( $request['method'], $request['url'] ) === $route ) );
	}

	/**
	 * Builds a WordPress HTTP API response array with a JSON body.
	 *
	 * @param int   $status HTTP status code.
	 * @param mixed $json   Value encoded as the JSON body.
	 * @return array<string, mixed>
	 */
	public static function json_response( int $status, mixed $json ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $json ),
			'response' => array(
				'code'    => $status,
				'message' => get_status_header_desc( $status ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * The `pre_http_request` callback.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt Earlier short-circuit value.
	 * @param array<string, mixed>                $args    Request arguments.
	 * @param string                              $url     Request URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public function intercept( $preempt, array $args, string $url ) {
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$body   = $args['body'] ?? null;

		$this->requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => (array) ( $args['headers'] ?? array() ),
			'body'    => is_string( $body ) ? json_decode( $body, true ) : $body,
		);

		$route = self::route_of( $method, $url );
		if ( ! isset( $this->routes[ $route ] ) ) {
			return new WP_Error( 'http_request_failed', 'FakeHttp: no route for ' . $route );
		}

		return ( $this->routes[ $route ] )( $args, $url );
	}

	/**
	 * Route key for a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Full URL.
	 */
	private static function route_of( string $method, string $url ): string {
		return $method . ' ' . (string) wp_parse_url( $url, PHP_URL_PATH );
	}
}
