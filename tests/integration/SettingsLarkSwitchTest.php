<?php
/**
 * Lark alert switch stored in the plugin settings.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Settings;
use WP_UnitTestCase;

/**
 * Without a stored value the switch follows whether a webhook is saved; a stored value always wins.
 */
final class SettingsLarkSwitchTest extends WP_UnitTestCase {

	public function test_unset_switch_follows_the_saved_webhook(): void {
		$settings = new Settings();

		$this->assertTrue( $settings->lark_enabled( true ) );
		$this->assertFalse( $settings->lark_enabled( false ) );
	}

	public function test_stored_off_wins_over_a_saved_webhook(): void {
		$settings = new Settings();
		$settings->save_lark_enabled( false );

		$this->assertFalse( $settings->lark_enabled( true ) );
	}

	public function test_stored_on_wins_over_a_missing_webhook(): void {
		$settings = new Settings();
		$settings->save_lark_enabled( true );

		$this->assertTrue( $settings->lark_enabled( false ) );
	}

	public function test_saving_the_switch_keeps_other_settings(): void {
		$settings = new Settings();
		$settings->save_default_unit( 'Cái' );
		$settings->save_lark_enabled( false );

		$this->assertSame( 'Cái', $settings->default_unit() );
	}
}
