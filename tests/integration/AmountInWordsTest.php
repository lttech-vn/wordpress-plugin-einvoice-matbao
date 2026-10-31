<?php
/**
 * `TgTTTBChu`: VND amounts in Vietnamese words.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\AmountInWords;
use PHPUnit\Framework\TestCase;

/**
 * Reading rules: "mười/mươi", "linh", "mốt", "lăm", "không trăm" inside larger numbers.
 */
final class AmountInWordsTest extends TestCase {

	/**
	 * Amounts and their expected reading.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function amounts(): array {
		return array(
			'zero'                          => array( 0, 'Không đồng' ),
			'units'                         => array( 7, 'Bảy đồng' ),
			'eleven'                        => array( 11, 'Mười một đồng' ),
			'ten to nineteen with lăm'      => array( 15, 'Mười lăm đồng' ),
			'hundred and one'               => array( 101, 'Một trăm linh một đồng' ),
			'one million'                   => array( 1000000, 'Một triệu đồng' ),
			'seven digits'                  => array( 1234567, 'Một triệu hai trăm ba mươi bốn nghìn năm trăm sáu mươi bảy đồng' ),
			'tens with mốt'                 => array( 21, 'Hai mươi mốt đồng' ),
			'tens with lăm'                 => array( 45, 'Bốn mươi lăm đồng' ),
			'hundreds with linh'            => array( 105, 'Một trăm linh năm đồng' ),
			'thousands'                     => array( 1000, 'Một nghìn đồng' ),
			'thousands ending in five'      => array( 115000, 'Một trăm mười lăm nghìn đồng' ),
			'hundred thousands'             => array( 594000, 'Năm trăm chín mươi bốn nghìn đồng' ),
			'millions'                      => array( 1250000, 'Một triệu hai trăm năm mươi nghìn đồng' ),
			'millions with inner zeros'     => array( 2005021, 'Hai triệu không trăm linh năm nghìn không trăm hai mươi mốt đồng' ),
			'millions ending in one'        => array( 3000001, 'Ba triệu không trăm linh một đồng' ),
			'billions'                      => array( 1000000000, 'Một tỷ đồng' ),
			'billions with skipped million' => array( 2000500000, 'Hai tỷ năm trăm nghìn đồng' ),
		);
	}

	/**
	 * Converts amounts to words.
	 *
	 * @dataProvider amounts
	 *
	 * @param int    $amount   VND amount.
	 * @param string $expected Expected words.
	 */
	public function test_reads_vnd_amounts( int $amount, string $expected ): void {
		$this->assertSame( $expected, AmountInWords::vnd( $amount ) );
	}
}
