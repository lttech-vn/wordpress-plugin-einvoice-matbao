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
}
