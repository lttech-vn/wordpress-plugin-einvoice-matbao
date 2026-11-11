<?php
/**
 * Slug of the recovery cron monitor.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

/**
 * Builds a per-site monitor slug of at most 50 characters.
 */
final class MonitorSlug {

	private const PREFIX         = 'einvoice-matbao-recovery-';
	private const HOST_LENGTH    = 16;
	private const HASH_LENGTH    = 8;
	private const EMPTY_FALLBACK = 'site';

	/**
	 * `einvoice-matbao-recovery-<host16>-<sha1(host)[0:8]>`; the hash keeps hosts that normalise alike apart.
	 *
	 * @param string $host Site host; may carry a port or non-ASCII characters.
	 */
	public static function for_host( string $host ): string {
		$host = strtolower( $host );
		$name = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $host ), '-' );
		$name = trim( substr( $name, 0, self::HOST_LENGTH ), '-' );
		if ( '' === $name ) {
			$name = self::EMPTY_FALLBACK;
		}

		return self::PREFIX . $name . '-' . substr( sha1( $host ), 0, self::HASH_LENGTH );
	}
}
