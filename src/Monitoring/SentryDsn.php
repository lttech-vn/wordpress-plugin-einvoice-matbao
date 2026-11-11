<?php
/**
 * A validated Sentry DSN.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

/**
 * Value object for `{http|https}://<public_key>@<host>[:port]/<path/><project_id>`.
 *
 * The key stays inside value(); label() is the only form that is safe to render or log.
 */
final class SentryDsn {

	/**
	 * Use parse().
	 *
	 * @param string $dsn        Normalised DSN.
	 * @param string $host       Host without port.
	 * @param int    $project_id Numeric project id.
	 */
	private function __construct(
		private readonly string $dsn,
		public readonly string $host,
		public readonly int $project_id
	) {}

	/**
	 * Parses a DSN, returning null for anything that is not a usable one.
	 *
	 * @param string $raw Candidate DSN; surrounding whitespace is ignored.
	 */
	public static function parse( string $raw ): ?self {
		$raw   = trim( $raw );
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] ?? '' );
		$host   = $parts['host'] ?? '';
		$key    = $parts['user'] ?? '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || '' === $key ) {
			return null;
		}

		$segments = explode( '/', rtrim( $parts['path'] ?? '', '/' ) );
		$last     = end( $segments );
		if ( 1 !== preg_match( '/^[0-9]+$/', $last ) ) {
			return null;
		}

		return new self( $raw, strtolower( $host ), (int) $last );
	}

	/**
	 * The full DSN, including the public key. Never render it.
	 */
	public function value(): string {
		return $this->dsn;
	}

	/**
	 * Human-readable identification without the key: "host · project N".
	 */
	public function label(): string {
		return sprintf( '%s · project %d', $this->host, $this->project_id );
	}
}
