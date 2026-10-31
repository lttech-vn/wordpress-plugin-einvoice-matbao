<?php
/**
 * Admin menu entry, settings page markup and asset loading.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\SettingsPage;
use WP_UnitTestCase;

/**
 * The settings page lives under WooCommerce, is scoped in #einv-app and wires htmx with the REST nonce.
 */
final class SettingsPageTest extends WP_UnitTestCase {

	public function tear_down(): void {
		global $submenu;
		unset( $submenu['woocommerce'] );
		wp_dequeue_style( SettingsPage::HANDLE );
		wp_dequeue_script( SettingsPage::HANDLE );
		wp_deregister_style( SettingsPage::HANDLE );
		wp_deregister_script( SettingsPage::HANDLE );
		parent::tear_down();
	}

	public function test_submenu_is_registered_under_woocommerce_for_administrators_only(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		SettingsPage::register_menu();

		global $submenu;
		$entries = array_column( $submenu['woocommerce'] ?? array(), 1, 2 );
		$this->assertArrayHasKey( SettingsPage::SLUG, $entries );
		$this->assertSame( 'manage_options', $entries[ SettingsPage::SLUG ] );
	}

	public function test_page_is_scoped_inside_einv_app_and_targets_plugin_rest_routes(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$page = get_echo( array( SettingsPage::class, 'render' ) );

		$this->assertStringContainsString( 'id="einv-app"', $page );
		$this->assertStringContainsString( 'hx-post="' . esc_url( rest_url( 'einvoice-matbao/v1/settings/matbao' ) ) . '"', $page );
		$this->assertStringContainsString( 'hx-post="' . esc_url( rest_url( 'einvoice-matbao/v1/settings/matbao/test' ) ) . '"', $page );
		$this->assertStringContainsString( 'hx-post="' . esc_url( rest_url( 'einvoice-matbao/v1/settings/lark/test' ) ) . '"', $page );
		$this->assertStringContainsString( 'Khách hàng không lấy hóa đơn', $page );
		$this->assertStringContainsString( 'EINVOICE_MATBAO_ENCRYPTION_KEY', $page );
	}

	public function test_page_refuses_users_without_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$this->assertSame( '', get_echo( array( SettingsPage::class, 'render' ) ) );
	}

	public function test_assets_load_only_on_the_plugin_page_with_nonce_attached_by_htmx_config_request(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		SettingsPage::enqueue_assets( 'dashboard' );
		$this->assertFalse( wp_script_is( SettingsPage::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( SettingsPage::HANDLE, 'enqueued' ) );

		SettingsPage::enqueue_assets( 'woocommerce_page_' . SettingsPage::SLUG );
		$this->assertTrue( wp_script_is( SettingsPage::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( SettingsPage::HANDLE, 'enqueued' ) );

		$inline = implode( "\n", (array) wp_scripts()->get_data( SettingsPage::HANDLE, 'after' ) );
		$this->assertStringContainsString( 'htmx:configRequest', $inline );
		$this->assertStringContainsString( 'X-WP-Nonce', $inline );
		$this->assertStringContainsString( wp_create_nonce( 'wp_rest' ), $inline );
		$this->assertStringNotContainsString( '//cdn', (string) wp_scripts()->registered[ SettingsPage::HANDLE ]->src );
	}
}
