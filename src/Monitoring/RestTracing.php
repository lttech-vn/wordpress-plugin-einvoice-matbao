<?php
/**
 * Transactions for requests to the plugin's REST routes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

use LTTech\EinvoiceMatbao\Admin\SettingsController;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;

/**
 * Opens an `http.server` transaction named after the matched route pattern (never the concrete
 * path or query) for each request to `einvoice-matbao/v1`.
 *
 * Uses `rest_request_before_callbacks`/`rest_request_after_callbacks`: both run around every
 * matched route, for HTTP requests and internal rest_do_request() calls alike, whereas
 * `rest_post_dispatch` only runs in serve_request(). Filters always return their input unchanged.
 */
final class RestTracing {

	private const OP = 'http.server';

	/**
	 * Instance wired to the hooks; registering twice must not report twice.
	 *
	 * @var self|null
	 */
	private static ?self $registered = null;

	/**
	 * Requests whose transaction is open.
	 *
	 * @var \WeakMap<WP_REST_Request, true>
	 */
	private \WeakMap $open;

	/**
	 * Creates the tracker.
	 */
	private function __construct() {
		$this->open = new \WeakMap();
	}

	/**
	 * Hooks into the REST server.
	 */
	public static function register(): void {
		if ( null !== self::$registered ) {
			return;
		}

		$tracing          = new self();
		self::$registered = $tracing;
		add_filter( 'rest_request_before_callbacks', array( $tracing, 'on_before_callbacks' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $tracing, 'on_after_callbacks' ), 10, 3 );
	}

	/**
	 * Opens the transaction of a plugin route.
	 *
	 * @param mixed                $response Response so far (returned unchanged).
	 * @param array<string, mixed> $handler  Matched route handler.
	 * @param WP_REST_Request|null $request  Request.
	 * @return mixed The unchanged $response.
	 */
	public function on_before_callbacks( $response, $handler, $request ) {
		try {
			if ( ! $request instanceof WP_REST_Request || ! self::is_plugin_route( $request ) || ! Monitor::is_active() ) {
				return $response;
			}

			$pattern = self::matched_pattern( $request, (array) $handler );
			if ( null === $pattern ) {
				return $response;
			}

			Monitor::start_transaction( $pattern, self::OP );
			$this->open[ $request ] = true;
		} catch ( \Throwable $failure ) {
			InternalFailure::report( $failure );
		}

		return $response;
	}

	/**
	 * Closes the transaction of a plugin route.
	 *
	 * @param mixed                $response Response of the callback (returned unchanged).
	 * @param array<string, mixed> $handler  Matched route handler.
	 * @param WP_REST_Request|null $request  Request.
	 * @return mixed The unchanged $response.
	 */
	public function on_after_callbacks( $response, $handler, $request ) {
		unset( $handler );
		try {
			if ( ! $request instanceof WP_REST_Request || ! isset( $this->open[ $request ] ) ) {
				return $response;
			}

			unset( $this->open[ $request ] );
			Monitor::finish_transaction( self::status_code( $response ) >= 500 ? 'internal_error' : 'ok' );
		} catch ( \Throwable $failure ) {
			InternalFailure::report( $failure );
		}

		return $response;
	}

	/**
	 * Whether the request targets the plugin namespace.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function is_plugin_route( WP_REST_Request $request ): bool {
		return str_starts_with( $request->get_route(), '/' . SettingsController::NAMESPACE );
	}

	/**
	 * The registered route pattern owning $handler, for example `/einvoice-matbao/v1/tabs/(?P<tab>[^/]+)`.
	 *
	 * @param WP_REST_Request      $request Request.
	 * @param array<string, mixed> $handler Matched route handler.
	 */
	private static function matched_pattern( WP_REST_Request $request, array $handler ): ?string {
		$routes = rest_get_server()->get_routes( SettingsController::NAMESPACE );
		foreach ( $routes as $pattern => $handlers ) {
			if ( in_array( $handler, (array) $handlers, true ) && 1 === preg_match( '@^' . $pattern . '$@i', $request->get_route() ) ) {
				return (string) $pattern;
			}
		}

		return null;
	}

	/**
	 * HTTP status of a callback result.
	 *
	 * @param mixed $response Callback result.
	 */
	private static function status_code( $response ): int {
		if ( $response instanceof WP_Error ) {
			$data = $response->get_error_data();

			return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		}

		return $response instanceof WP_HTTP_Response ? $response->get_status() : 200;
	}
}
