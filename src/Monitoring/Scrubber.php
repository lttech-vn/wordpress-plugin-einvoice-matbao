<?php
/**
 * Default redactor: known buyer values first, then patterns.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

use LTTech\EinvoiceMatbao\Matbao\MatbaoCredentials;

/**
 * Masks known buyer values, then emails, URLs, tax codes, ID numbers, bearer tokens and long opaque strings.
 */
final class Scrubber implements Redactor {

	public const FILTERED = '[filtered]';

	public const TAG_ALLOWLIST = array( 'site', 'order_id', 'invoice.id', 'invoice.status', 'error_code', 'error_kind', 'matbao.env', 'component' );

	/**
	 * Known values shorter than this would mangle unrelated text.
	 */
	private const MIN_KNOWN_LENGTH = 3;

	/**
	 * Ordered pattern list; URLs are handled separately because Mat Bao hosts survive.
	 */
	private const PATTERNS = array(
		'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
		'/Bearer\s+\S+/i',
		'/\b\d{10}(?:-\d{3})?\b/',
		'/\b\d{12}\b/',
		// A whole run of 32+ token characters with at least one digit; digit-free runs are code
		// identifiers such as hook names, not secrets.
		'/(?<![A-Za-z0-9+\/=_\-])(?=[A-Za-z0-9+\/=_\-]*\d)[A-Za-z0-9+\/=_\-]{32,}/',
	);

	/**
	 * Known values, longest first.
	 *
	 * @var array<int, string>
	 */
	private array $known;

	/**
	 * Creates a scrubber, optionally with known values.
	 *
	 * @param array<int, string> $known_values Exact values to mask (case-insensitive).
	 */
	public function __construct( array $known_values = array() ) {
		$known = array_values(
			array_unique(
				array_filter(
					array_map( 'trim', $known_values ),
					static fn ( string $v ): bool => mb_strlen( $v ) >= self::MIN_KNOWN_LENGTH
				)
			)
		);
		usort( $known, static fn ( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );
		$this->known = $known;
	}

	/**
	 * Returns a new scrubber for the given values; it replaces, not merges with, the current ones.
	 *
	 * @param array<int, string> $values Known sensitive values.
	 */
	public function with_known_values( array $values ): static { // phpcs:ignore Universal.CodeAnalysis.StaticInFinalClass.ReturnType -- Redactor declares `static`; PHP 8.2 rejects `self` as an override.
		return new self( $values );
	}

	/**
	 * Masks known values and patterns in a string.
	 *
	 * @param string $s Raw text.
	 */
	public function text( string $s ): string {
		foreach ( $this->known as $known ) {
			$s = (string) preg_replace( '/' . preg_quote( $known, '/' ) . '/iu', self::FILTERED, $s );
		}

		$s = (string) preg_replace_callback( '#https?://[^\s"\'<>]+#i', array( $this, 'url' ), $s );

		return (string) preg_replace( self::PATTERNS, self::FILTERED, $s );
	}

	/**
	 * Masks strings inside scalars and nested arrays.
	 *
	 * @param mixed $v Raw value.
	 */
	public function value( mixed $v ): mixed {
		if ( is_string( $v ) ) {
			return $this->text( $v );
		}
		if ( is_array( $v ) ) {
			return array_map( array( $this, 'value' ), $v );
		}

		return $v;
	}

	/**
	 * Keeps allow-listed tags and masks their values.
	 *
	 * @param array<string, mixed> $tags Raw tags.
	 * @return array<string, mixed>
	 */
	public function tags( array $tags ): array {
		$kept = array_intersect_key( $tags, array_flip( self::TAG_ALLOWLIST ) );

		return array_map( array( $this, 'value' ), $kept );
	}

	/**
	 * Mat Bao URLs keep scheme and host (useful for debugging); every other URL is dropped.
	 *
	 * @param array<int, string> $matches Regex match.
	 */
	private function url( array $matches ): string {
		$parts = wp_parse_url( $matches[0] );
		$host  = is_array( $parts ) ? strtolower( $parts['host'] ?? '' ) : '';

		if ( '' !== $host && in_array( $host, self::matbao_hosts(), true ) ) {
			return strtolower( $parts['scheme'] ?? 'https' ) . '://' . $host;
		}

		return self::FILTERED;
	}

	/**
	 * Hosts of the Mat Bao API environments.
	 *
	 * @return list<string>
	 */
	private static function matbao_hosts(): array {
		$hosts = array();
		foreach ( MatbaoCredentials::BASE_URLS as $url ) {
			$hosts[] = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		}

		return $hosts;
	}
}
