<?php
/**
 * Store-wide default unit of measure through the settings REST routes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\SettingsPage;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * The default `DVTinh`: saved by Administrators only, bounded to 50 characters, escaped when shown.
 */
final class SettingsUnitRestTest extends SettingsRestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->login_as( 'administrator' );
	}

	public function test_default_unit_is_goi_before_anything_is_saved(): void {
		$this->assertSame( 'Gói', ( new Settings() )->default_unit() );
	}

	public function test_default_unit_is_saved(): void {
		$response = $this->htmx( 'POST', '/settings/unit', array( 'default_unit' => '  Cái ' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-status="success"', $this->html( $response ) );
		$this->assertSame( 'Cái', ( new Settings() )->default_unit() );
	}

	public function test_empty_default_unit_falls_back_to_goi(): void {
		( new Settings() )->save_default_unit( 'Cái' );

		$response = $this->htmx( 'POST', '/settings/unit', array( 'default_unit' => '   ' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Gói', ( new Settings() )->default_unit() );
	}

	public function test_unit_of_exactly_50_characters_is_accepted_and_51_is_refused(): void {
		$this->assertSame( 200, $this->htmx( 'POST', '/settings/unit', array( 'default_unit' => str_repeat( 'á', 50 ) ) )->get_status() );

		$response = $this->htmx( 'POST', '/settings/unit', array( 'default_unit' => str_repeat( 'b', 51 ) ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-status="error"', $this->html( $response ) );
		$this->assertSame( str_repeat( 'á', 50 ), ( new Settings() )->default_unit() );
	}

	public function test_non_administrator_cannot_save_the_default_unit(): void {
		$this->login_as( 'shop_manager' );

		$response = $this->htmx( 'POST', '/settings/unit', array( 'default_unit' => 'Cái' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'Gói', ( new Settings() )->default_unit() );
	}

	public function test_invalid_nonce_cannot_save_the_default_unit(): void {
		$response = $this->htmx( 'POST', '/settings/unit', array( 'default_unit' => 'Cái' ), 'bad-nonce' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'Gói', ( new Settings() )->default_unit() );
	}

	public function test_markup_in_the_unit_is_stripped_and_the_page_escapes_the_value(): void {
		$this->htmx( 'POST', '/settings/unit', array( 'default_unit' => '<script>x</script>"onfocus="y' ) );

		$page = get_echo( array( SettingsPage::class, 'render' ) );

		$this->assertStringNotContainsString( '<script>x</script>', $page );
		$this->assertStringNotContainsString( 'value=""onfocus', $page );
		$this->assertStringContainsString( 'name="default_unit"', $page );
	}
}
