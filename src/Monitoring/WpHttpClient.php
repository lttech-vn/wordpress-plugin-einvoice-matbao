<?php
/**
 * Sentry HTTP client on top of the WordPress HTTP API.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

use LTTech\EinvoiceMatbao\Uninstall\Uninstaller;
use Sentry\Client;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Request;
use Sentry\HttpClient\Response;
use Sentry\Options;
use WP_Error;

/**
 * Sends envelopes with wp_remote_post() so the site's proxy settings apply, tests can intercept
 * the call and ext-curl is not required. Short timeouts plus a circuit breaker keep a slow or
 * failing monitoring server from slowing invoices down: after any failure nothing is sent for
 * the rest of the request (per instance) and, through an option, for a backoff period.
 */
final class WpHttpClient implements HttpClientInterface {

	public const TIMEOUT         = 3.0;
	public const CONNECT_TIMEOUT = 2.0;
	public const BACKOFF_SECONDS = 60;
	public const MAX_RETRY_AFTER = 600;

	private const RATE_LIMITED = 429;

	private const ENVELOPE_CONTENT_TYPE = 'application/x-sentry-envelope';

	/**
	 * Clock returning a unix timestamp.
	 *
	 * @var callable(): int
	 */
	private $now;

	/**
	 * Whether a request of this instance already failed.
	 *
	 * @var bool
	 */
	private bool $broken = false;

	/**
	 * Creates the client.
	 *
	 * @param (callable(): int)|null $now Clock seam for tests; defaults to time().
	 */
	public function __construct( ?callable $now = null ) {
		$this->now = $now ?? 'time';
	}

	/**
	 * Posts one envelope. Never throws for HTTP failures: they come back as a Response with an error.
	 *
	 * @param Request $request Envelope request.
	 * @param Options $options Client options (DSN).
	 */
	public function sendRequest( Request $request, Options $options ): Response {
		$dsn  = $options->getDsn();
		$body = $request->getStringBody();
		if ( null === $dsn || null === $body ) {
			return new Response( 0, array(), 'einvoice_matbao_monitoring_invalid_request' );
		}
		$body = self::without_dsn( $body );
		if ( null === $body ) {
			return new Response( 0, array(), 'einvoice_matbao_monitoring_invalid_envelope' );
		}
		if ( $this->broken || $this->backing_off() ) {
			return new Response( 0, array(), 'einvoice_matbao_monitoring_backoff' );
		}

		$response = $this->post( $dsn->getEnvelopeApiEndpointUrl(), $body, $dsn->getPublicKey() );

		if ( $response instanceof WP_Error ) {
			$this->open_breaker( self::BACKOFF_SECONDS );

			return new Response( 0, array(), (string) $response->get_error_code() );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$headers = self::headers_of( $response );
		if ( $status < 200 || $status > 299 ) {
			$this->open_breaker( $this->backoff_seconds( $status, wp_remote_retrieve_header( $response, 'retry-after' ) ) );

			return new Response( $status, $headers, 'HTTP ' . $status );
		}

		return new Response( $status, $headers, '' );
	}

	/**
	 * Removes the `dsn` member from the envelope header (first line); the SDK writes the full DSN there.
	 * The member is cut out of the original bytes, so every other header member and all item lines stay
	 * exactly as the SDK wrote them, and an envelope without a `dsn` member is returned as is.
	 * Authentication is the `X-Sentry-Auth` header, which carries the public key only.
	 *
	 * @param string $envelope Serialized envelope.
	 * @return string|null Envelope without the DSN, or null (do not send) when the header is not a JSON
	 *                     object or the member cannot be removed with a verified result.
	 */
	private static function without_dsn( string $envelope ): ?string {
		$newline = strpos( $envelope, "\n" );
		$line    = false === $newline ? $envelope : substr( $envelope, 0, $newline );
		$rest    = false === $newline ? '' : substr( $envelope, $newline );
		$header  = json_decode( $line, true );
		if ( ! is_array( $header ) || '{' !== substr( ltrim( $line ), 0, 1 ) ) {
			return null;
		}
		if ( ! array_key_exists( 'dsn', $header ) ) {
			return $envelope;
		}
		unset( $header['dsn'] );
		$stripped = self::cut_dsn_member( $line, $header );

		return null === $stripped ? null : $stripped . $rest;
	}

	/**
	 * Cuts the `"dsn":"…"` member out of a header line and checks the result decodes to $expected.
	 *
	 * @param string               $line     Original header line.
	 * @param array<string, mixed> $expected Decoded header without `dsn`.
	 */
	private static function cut_dsn_member( string $line, array $expected ): ?string {
		$full = json_decode( $line, true );
		$dsn  = is_array( $full ) ? ( $full['dsn'] ?? null ) : null;
		if ( ! is_string( $dsn ) ) {
			return null;
		}
		// The SDK serialises with escaped slashes and unescaped unicode (Sentry\Util\JSON); accept both slash forms.
		foreach ( array( JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) as $flags ) {
			$member = '"dsn":' . wp_json_encode( $dsn, $flags );
			$at     = strpos( $line, $member );
			if ( false === $at ) {
				continue;
			}
			$end = $at + strlen( $member );
			foreach ( array( array( $at, $end + 1 ), array( $at - 1, $end ), array( $at, $end ) ) as list( $from, $to ) ) {
				$candidate = substr( $line, 0, max( 0, $from ) ) . substr( $line, $to );
				if ( json_decode( $candidate, true ) === $expected ) {
					return $candidate;
				}
			}
		}

		return null;
	}

	/**
	 * Performs the POST, with the short connect timeout applied to this URL only.
	 *
	 * @param string $url        Envelope endpoint.
	 * @param string $body       Serialized envelope.
	 * @param string $public_key DSN public key.
	 * @return array<string, mixed>|WP_Error
	 */
	private function post( string $url, string $body, string $public_key ) {
		// WP_Http has no connect timeout argument; Requests reads it from its options.
		$connect_timeout = static function ( &$request_url, &$headers, &$data, &$type, &$options ) use ( $url ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Requests passes every argument by reference.
			if ( $request_url === $url && is_array( $options ) ) {
				$options['connect_timeout'] = self::CONNECT_TIMEOUT;
			}
		};
		add_action( 'requests-requests.before_request', $connect_timeout, 10, 5 );

		try {
			return wp_remote_post(
				$url,
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					'headers'     => array(
						'Content-Type'  => self::ENVELOPE_CONTENT_TYPE,
						'X-Sentry-Auth' => self::auth_header( $public_key ),
					),
					'body'        => $body,
				)
			);
		} finally {
			remove_action( 'requests-requests.before_request', $connect_timeout, 10 );
		}
	}

	/**
	 * `X-Sentry-Auth` value, as the SDK's own client builds it.
	 *
	 * @param string $public_key DSN public key.
	 */
	private static function auth_header( string $public_key ): string {
		return sprintf(
			'Sentry sentry_version=%s, sentry_client=%s/%s, sentry_key=%s',
			Client::PROTOCOL_VERSION,
			Client::SDK_IDENTIFIER,
			Client::SDK_VERSION,
			$public_key
		);
	}

	/**
	 * Whether an earlier failure started a backoff that is still running.
	 */
	private function backing_off(): bool {
		return (int) get_option( Uninstaller::SENTRY_BACKOFF_OPTION, 0 ) > $this->now();
	}

	/**
	 * Stops sending for this instance and for the given number of seconds.
	 *
	 * @param int $seconds Backoff duration.
	 */
	private function open_breaker( int $seconds ): void {
		$this->broken = true;
		update_option( Uninstaller::SENTRY_BACKOFF_OPTION, $this->now() + $seconds, false );
	}

	/**
	 * Backoff for a failed response: a 429 with a positive integer `Retry-After` uses it, capped;
	 * anything else (dates, missing or malformed values, other statuses) uses the default.
	 *
	 * @param int                  $status      HTTP status.
	 * @param string|array<string> $retry_after `Retry-After` header.
	 */
	private function backoff_seconds( int $status, string|array $retry_after ): int {
		$retry_after = trim( is_array( $retry_after ) ? (string) reset( $retry_after ) : $retry_after );
		if ( self::RATE_LIMITED !== $status || 1 !== preg_match( '/^[0-9]+$/', $retry_after ) || 0 === (int) $retry_after ) {
			return self::BACKOFF_SECONDS;
		}

		return min( (int) $retry_after, self::MAX_RETRY_AFTER );
	}

	/**
	 * Current unix time.
	 */
	private function now(): int {
		return (int) ( $this->now )();
	}

	/**
	 * Response headers in the shape Sentry's Response expects: name => list of values.
	 *
	 * @param array<string, mixed> $response WordPress HTTP response.
	 * @return array<string, list<string>>
	 */
	private static function headers_of( array $response ): array {
		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ (string) $name ] = array_values( array_map( 'strval', (array) $value ) );
		}

		return $headers;
	}
}
