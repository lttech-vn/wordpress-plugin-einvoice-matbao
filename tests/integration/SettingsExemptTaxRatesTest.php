<?php
/**
 * Storage of the KCT and KKKNT tax rate assignment in the settings option.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Settings;
use WP_UnitTestCase;

/**
 * Only positive integer rate IDs are read back; a rate in both lists resolves to KKKNT.
 */
final class SettingsExemptTaxRatesTest extends WP_UnitTestCase {

	public function test_nothing_is_exempt_before_saving(): void {
		$this->assertSame( array(), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_saved_rates_map_to_tsuat(): void {
		( new Settings() )->save_exempt_tax_rates( array( 12, 13 ), array( 20 ) );

		$this->assertSame(
			array(
				12 => -1,
				13 => -1,
				20 => -2,
			),
			( new Settings() )->exempt_tax_rates()
		);
	}

	public function test_saving_replaces_previous_assignment(): void {
		( new Settings() )->save_exempt_tax_rates( array( 12 ), array( 20 ) );
		( new Settings() )->save_exempt_tax_rates( array(), array( 12 ) );

		$this->assertSame( array( 12 => -2 ), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_only_positive_integer_ids_are_read(): void {
		update_option(
			Settings::OPTION,
			array(
				'kct_tax_rate_ids'   => array( 7, '9', '7.5', 0.5, '1e3', -3, 0, 'x', '99999999999999999999' ),
				'kkknt_tax_rate_ids' => 'oops',
			)
		);

		$this->assertSame(
			array(
				7 => -1,
				9 => -1,
			),
			( new Settings() )->exempt_tax_rates()
		);
	}

	public function test_int_max_string_is_accepted_and_overflow_or_leading_zeros_are_not(): void {
		update_option(
			Settings::OPTION,
			array( 'kct_tax_rate_ids' => array( (string) PHP_INT_MAX, '9223372036854775808', '0009' ) )
		);

		$this->assertSame( array( PHP_INT_MAX => -1 ), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_id_in_both_lists_reads_as_kkknt(): void {
		update_option(
			Settings::OPTION,
			array(
				'kct_tax_rate_ids'   => array( 5 ),
				'kkknt_tax_rate_ids' => array( 5 ),
			)
		);

		$this->assertSame( array( 5 => -2 ), ( new Settings() )->exempt_tax_rates() );
	}
}
