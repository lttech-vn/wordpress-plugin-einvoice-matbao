<?php
/**
 * Only the Sentry backend and its HTTP client touch the Sentry SDK.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use WP_UnitTestCase;

/**
 * Keeps the SDK behind the `Backend` seam, so a disabled or replaced backend never loads it.
 */
final class SdkBoundaryTest extends WP_UnitTestCase {

	private const ALLOWED = array( 'Monitoring/SentryBackend.php', 'Monitoring/WpHttpClient.php' );

	public function test_only_the_backend_and_its_http_client_import_sentry(): void {
		$root     = dirname( __DIR__, 3 ) . '/src';
		$offender = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$relative = substr( (string) $file, strlen( $root ) + 1 );
			if ( 'php' !== $file->getExtension() || in_array( $relative, self::ALLOWED, true ) ) {
				continue;
			}
			if ( 1 === preg_match( '/^\s*use\s+\\\\?Sentry\\\\|\\\\Sentry\\\\/m', (string) file_get_contents( (string) $file ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local source files.
				$offender[] = $relative;
			}
		}

		$this->assertSame( array(), $offender );
	}
}
