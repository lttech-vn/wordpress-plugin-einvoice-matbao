<?php
/**
 * Fake Sentry ingestion endpoint for integration tests.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Support;

use WP_Error;

/**
 * Answers `POST /api/<project>/envelope/` through FakeHttp and parses every envelope it receives
 * into items, so tests can assert on what would have reached the monitoring server.
 */
final class SentrySink {

	/**
	 * Status of the next responses.
	 *
	 * @var int
	 */
	private int $status = 200;

	/**
	 * Headers of the next responses.
	 *
	 * @var array<string, string>
	 */
	private array $headers = array();

	/**
	 * Whether the transport fails (no HTTP response at all).
	 *
	 * @var bool
	 */
	private bool $transport_fails = false;

	/**
	 * Use install().
	 *
	 * @param FakeHttp $http    HTTP fake the sink is routed through.
	 * @param string   $host    Host of the sink.
	 * @param int      $project Project ID in the envelope path.
	 */
	private function __construct(
		private readonly FakeHttp $http,
		private readonly string $host,
		private readonly int $project
	) {}

	/**
	 * Routes the envelope endpoint of the given host and project to a new sink.
	 *
	 * @param FakeHttp $http    HTTP fake.
	 * @param string   $host    Sink host.
	 * @param int      $project Project ID.
	 */
	public static function install( FakeHttp $http, string $host = 'sentry.test', int $project = 3 ): self {
		$sink = new self( $http, $host, $project );
		$http->respond_with( $sink->route(), array( $sink, 'respond' ) );

		return $sink;
	}

	/**
	 * Answers the next requests with this status and headers.
	 *
	 * @param int                   $status  HTTP status.
	 * @param array<string, string> $headers Response headers.
	 */
	public function respond_with( int $status, array $headers = array() ): void {
		$this->status  = $status;
		$this->headers = array_change_key_case( $headers );
	}

	/**
	 * Fails the next requests with a transport error.
	 */
	public function fail_transport(): void {
		$this->transport_fails = true;
	}

	/**
	 * FakeHttp handler.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function respond() {
		if ( $this->transport_fails ) {
			return new WP_Error( 'http_request_failed', 'SentrySink: connection refused' );
		}

		$response            = FakeHttp::json_response( $this->status, array( 'id' => '' ) );
		$response['headers'] = array_merge( $response['headers'], $this->headers );

		return $response;
	}

	/**
	 * Requests that reached the sink route.
	 */
	public function request_count(): int {
		return count( $this->raw() );
	}

	/**
	 * Error and message event payloads.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function events(): array {
		return $this->payloads( 'event' );
	}

	/**
	 * Transaction payloads.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function transactions(): array {
		return $this->payloads( 'transaction' );
	}

	/**
	 * Log items (flattened across envelopes).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function logs(): array {
		$logs = array();
		foreach ( $this->payloads( 'log' ) as $payload ) {
			foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
				$logs[] = (array) $item;
			}
		}

		return $logs;
	}

	/**
	 * Check-in payloads.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function check_ins(): array {
		return $this->payloads( 'check_in' );
	}

	/**
	 * Every parsed item of every envelope.
	 *
	 * @return list<array{type: string, payload: array<string, mixed>}>
	 */
	public function items(): array {
		$items = array();
		foreach ( $this->raw() as $request ) {
			array_push( $items, ...self::parse( (string) $request['body'] ) );
		}

		return $items;
	}

	/**
	 * Payloads of items of one type.
	 *
	 * @param string $type Envelope item type.
	 * @return list<array<string, mixed>>
	 */
	private function payloads( string $type ): array {
		$payloads = array();
		foreach ( $this->items() as $item ) {
			if ( $type === $item['type'] ) {
				$payloads[] = $item['payload'];
			}
		}

		return $payloads;
	}

	/**
	 * Raw requests sent to the sink route.
	 *
	 * @return list<array{method: string, url: string, args: array<string, mixed>, body: string|null}>
	 */
	private function raw(): array {
		$route = $this->route();

		return array_values(
			array_filter(
				$this->http->raw_requests(),
				fn( array $request ): bool => $request['method'] . ' ' . (string) wp_parse_url( $request['url'], PHP_URL_PATH ) === $route
					&& wp_parse_url( $request['url'], PHP_URL_HOST ) === $this->host
			)
		);
	}

	/**
	 * Parses an envelope: header line, then item header / payload line pairs.
	 *
	 * @param string $body Serialized envelope.
	 * @return list<array{type: string, payload: array<string, mixed>}>
	 */
	private static function parse( string $body ): array {
		$lines = explode( "\n", $body );
		array_shift( $lines );
		$items = array();
		$count = count( $lines );
		for ( $i = 0; $i + 1 < $count; $i += 2 ) {
			$header  = json_decode( $lines[ $i ], true );
			$payload = json_decode( $lines[ $i + 1 ], true );
			$items[] = array(
				'type'    => is_array( $header ) ? (string) ( $header['type'] ?? '' ) : '',
				'payload' => is_array( $payload ) ? $payload : array(),
			);
		}

		return $items;
	}

	/**
	 * FakeHttp route key of the envelope endpoint.
	 */
	private function route(): string {
		return 'POST /api/' . $this->project . '/envelope/';
	}
}
