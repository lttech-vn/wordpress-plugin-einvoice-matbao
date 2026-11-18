<?php
/**
 * Tax mapping groups, lookup and the `TSuat` each group accepts.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\TaxRateMap;
use WP_UnitTestCase;

/**
 * A stored percent and line tax must agree with the group, otherwise `tsuat_for()` is null.
 */
final class TaxRateMapTest extends WP_UnitTestCase {

	public function test_groups_and_labels(): void {
		$this->assertSame( array( 'vat10', 'vat8', 'vat5', 'vat0', 'kct', 'kkknt', 'other' ), TaxRateMap::groups() );

		$labels = array_map( array( TaxRateMap::class, 'label' ), TaxRateMap::groups() );
		$this->assertSame( array( '10%', '8%', '5%', '0%', 'KCT', 'KKKNT', 'Khác' ), $labels );
	}

	public function test_group_of(): void {
		$map = new TaxRateMap(
			array(
				'vat10' => array( 3, 4 ),
				'kct'   => array( 9 ),
			)
		);

		$this->assertSame( 'vat10', $map->group_of( 4 ) );
		$this->assertSame( 'kct', $map->group_of( 9 ) );
		$this->assertNull( $map->group_of( 5 ) );
	}

	public function test_tsuat_for_percent_groups(): void {
		$this->assertSame( 10, TaxRateMap::tsuat_for( 'vat10', '10.0000', 1000 ) );
		$this->assertSame( 8, TaxRateMap::tsuat_for( 'vat8', '8', 800 ) );
		$this->assertSame( 5, TaxRateMap::tsuat_for( 'vat5', '5.0000', 500 ) );
		$this->assertNull( TaxRateMap::tsuat_for( 'vat8', '10.0000', 1000 ) );
		$this->assertNull( TaxRateMap::tsuat_for( 'vat10', null, 1000 ) );
		$this->assertNull( TaxRateMap::tsuat_for( 'vat10', '', 1000 ) );
	}

	public function test_tsuat_for_zero_groups(): void {
		$this->assertSame( 0, TaxRateMap::tsuat_for( 'vat0', '0.0000', 0 ) );
		$this->assertSame( -1, TaxRateMap::tsuat_for( 'kct', null, 0 ) );
		$this->assertSame( -2, TaxRateMap::tsuat_for( 'kkknt', '0', 0 ) );
		$this->assertNull( TaxRateMap::tsuat_for( 'kct', '0.0000', 100 ) );
		$this->assertSame( 0, TaxRateMap::tsuat_for( 'vat0', null, 0 ) );
	}

	public function test_tsuat_for_other(): void {
		$this->assertSame( 3.5, TaxRateMap::tsuat_for( 'other', '3.5000', 3500 ) );
		$this->assertSame( 7, TaxRateMap::tsuat_for( 'other', '7.0000', 7000 ) );

		foreach ( array( '5.0000', '8.0000', '10.0000', '0', '-3', null, '' ) as $percent ) {
			$this->assertNull( TaxRateMap::tsuat_for( 'other', $percent, 100 ), (string) $percent );
		}
	}

	public function test_unknown_group(): void {
		$this->assertNull( TaxRateMap::tsuat_for( 'vat7', '7', 700 ) );
	}
}
