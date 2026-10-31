<?php
/**
 * The "delete all data on uninstall" switch on the settings page.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\SettingsPage;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;
use LTTech\EinvoiceMatbao\Uninstall\UninstallSettingsController;

/**
 * Off by default; turning it on needs an Administrator, the REST nonce and the typed confirmation.
 */
final class SettingsUninstallRestTest extends SettingsRestTestCase {

	private const ROUTE = '/settings/uninstall';

	public function set_up(): void {
		parent::set_up();
		$this->login_as( 'administrator' );
	}

	public function test_switch_is_off_by_default(): void {
		$this->assertFalse( ( new Settings() )->delete_data_on_uninstall() );
	}

	public function test_turning_it_on_requires_the_typed_confirmation(): void {
		$response = $this->htmx( 'POST', self::ROUTE, array( 'delete_data_on_uninstall' => '1' ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertFalse( ( new Settings() )->delete_data_on_uninstall() );

		$response = $this->htmx(
			'POST',
			self::ROUTE,
			array(
				'delete_data_on_uninstall' => '1',
				'confirmation'             => 'xoa',
			)
		);
		$this->assertSame( 422, $response->get_status() );
		$this->assertFalse( ( new Settings() )->delete_data_on_uninstall() );
	}

	public function test_turning_it_on_with_the_confirmation_saves_it(): void {
		$response = $this->htmx(
			'POST',
			self::ROUTE,
			array(
				'delete_data_on_uninstall' => '1',
				'confirmation'             => UninstallSettingsController::CONFIRMATION,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-status="success"', $this->html( $response ) );
		$this->assertTrue( ( new Settings() )->delete_data_on_uninstall() );
	}

	public function test_turning_it_off_needs_no_confirmation(): void {
		( new Settings() )->save_delete_data_on_uninstall( true );

		$response = $this->htmx( 'POST', self::ROUTE );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( ( new Settings() )->delete_data_on_uninstall() );
	}

	public function test_shop_manager_cannot_turn_it_on(): void {
		$this->login_as( 'shop_manager' );

		$response = $this->htmx(
			'POST',
			self::ROUTE,
			array(
				'delete_data_on_uninstall' => '1',
				'confirmation'             => UninstallSettingsController::CONFIRMATION,
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertFalse( ( new Settings() )->delete_data_on_uninstall() );
	}

	public function test_invalid_nonce_cannot_turn_it_on(): void {
		$response = $this->htmx(
			'POST',
			self::ROUTE,
			array(
				'delete_data_on_uninstall' => '1',
				'confirmation'             => UninstallSettingsController::CONFIRMATION,
			),
			'bad-nonce'
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertFalse( ( new Settings() )->delete_data_on_uninstall() );
	}

	public function test_settings_page_shows_the_switch_unchecked_by_default(): void {
		$page = get_echo( array( SettingsPage::class, 'render' ) );

		$this->assertStringContainsString( rest_url( 'einvoice-matbao/v1' . self::ROUTE ), $page );
		$this->assertMatchesRegularExpression( '/<input[^>]*name="delete_data_on_uninstall"(?![^>]*checked)[^>]*>/', $page );
		$this->assertStringContainsString( 'name="confirmation"', $page );
	}

	public function test_settings_page_shows_the_switch_checked_once_enabled(): void {
		( new Settings() )->save_delete_data_on_uninstall( true );

		$page = get_echo( array( SettingsPage::class, 'render' ) );

		$this->assertMatchesRegularExpression( '/<input[^>]*name="delete_data_on_uninstall"[^>]*checked/', $page );
	}
}
