<?php
/**
 * Output buffer cleaning of the PDF responder.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Customer\PdfResponder;
use WP_UnitTestCase;

/**
 * PdfResponder::send() must close stray output buffers before the PDF bytes.
 */
final class PdfResponderBuffersTest extends WP_UnitTestCase {

	public function test_clean_buffers_closes_only_down_to_the_kept_level(): void {
		$baseline = ob_get_level();
		ob_start();
		echo 'stray';
		ob_start();

		$responder = new class() extends PdfResponder {
			/**
			 * Exposes the cleaning with a kept level.
			 *
			 * @param int $keep Levels to keep open.
			 */
			public function clean( int $keep ): void {
				$this->clean_buffers( $keep );
			}
		};
		$responder->clean( $baseline );

		$this->assertSame( $baseline, ob_get_level() );
	}

	/**
	 * Server-side gzip would make Content-Length lie about the bytes on the wire.
	 *
	 * @dataProvider compression_settings
	 *
	 * @param string|false $setting  Value of `zlib.output_compression`.
	 * @param bool         $expected Whether it must be turned off.
	 */
	public function test_compression_turned_off_only_when_on( $setting, bool $expected ): void {
		$this->assertSame( $expected, PdfResponder::compression_is_on( $setting ) );
	}

	/**
	 * `zlib.output_compression` values as ini_get() returns them.
	 *
	 * @return array<string, array{0: string|false, 1: bool}>
	 */
	public static function compression_settings(): array {
		return array(
			'on as 1'         => array( '1', true ),
			'on as word'      => array( 'On', true ),
			'buffer size'     => array( '4096', true ),
			'off as 0'        => array( '0', false ),
			'off as word'     => array( 'Off', false ),
			'empty'           => array( '', false ),
			'zlib not loaded' => array( false, false ),
		);
	}
}
