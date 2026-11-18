<?php
/**
 * Storage of the tax mapping table in the settings option.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Settings;
use WP_UnitTestCase;

/**
 * Only known groups and positive integer rate IDs are read back; a rate keeps its first group.
 */
final class SettingsTaxRateMapTest extends WP_UnitTestCase {

	public function test_empty_before_saving(): void {
		$this->assertSame( array(), ( new Settings() )->tax_rate_map() );
	}

	public function test_round_trip(): void {
		$map = array(
			'vat10' => array( 3 ),
			'other' => array( 11 ),
		);
		( new Settings() )->save_tax_rate_map( $map );

		$this->assertSame( $map, ( new Settings() )->tax_rate_map() );
	}

	public function test_defensive_read(): void {
		update_option(
			Settings::OPTION,
			array(
				'tax_rate_map' => array(
					'vat10' => array( '3', 3, 'x', 0, -1, '0009' ),
					'bogus' => array( 5 ),
					'kct'   => 'oops',
					'vat8'  => array( 3, 6 ),
				),
			)
		);

		$this->assertSame(
			array(
				'vat10' => array( 3 ),
				'vat8'  => array( 6 ),
			),
			( new Settings() )->tax_rate_map()
		);
	}

	public function test_non_array_option_value_reads_as_empty(): void {
		update_option( Settings::OPTION, array( 'tax_rate_map' => 'oops' ) );

		$this->assertSame( array(), ( new Settings() )->tax_rate_map() );
	}

	public function test_int_max_string_is_accepted_and_overflow_or_leading_zeros_are_not(): void {
		$this->assertSame( PHP_INT_MAX, Settings::parse_rate_id( (string) PHP_INT_MAX ) );
		$this->assertNull( Settings::parse_rate_id( '9223372036854775808' ) );
		$this->assertNull( Settings::parse_rate_id( '0009' ) );
	}
}
