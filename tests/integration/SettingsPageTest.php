<?php
/**
 * Admin menu entry, settings page markup and asset loading.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\AdminTabs;
use LTTech\EinvoiceMatbao\Admin\SettingsPage;
use WP_UnitTestCase;

/**
 * The settings page lives under WooCommerce, is scoped in #einv-app and wires htmx with the REST nonce.
 */
final class SettingsPageTest extends WP_UnitTestCase {

	public function tear_down(): void {
		unset( $_GET['tab'] );
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
		$this->assertStringContainsString( 'EINVOICE_MATBAO_ENCRYPTION_KEY', $page );
		$this->assertStringContainsString( 'Hóa đơn Mắt Bão', $page );

		$connection = SettingsPage::render_tabs( 'connection' );
		$this->assertStringContainsString( 'hx-post="' . esc_url( rest_url( 'einvoice-matbao/v1/settings/matbao' ) ) . '"', $connection );
		$this->assertStringContainsString( 'hx-post="' . esc_url( rest_url( 'einvoice-matbao/v1/settings/matbao/test' ) ) . '"', $connection );

		$lark = SettingsPage::render_tabs( 'lark' );
		$this->assertStringContainsString( 'hx-post="' . esc_url( rest_url( 'einvoice-matbao/v1/settings/lark/test' ) ) . '"', $lark );

		$this->assertStringContainsString( 'Khách hàng không lấy hóa đơn', SettingsPage::render_tabs( 'invoicing' ) );
	}

	public function test_default_tab_is_invoices(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$page = get_echo( array( SettingsPage::class, 'render' ) );

		$this->assertStringContainsString( 'data-einv-tab="invoices"', $page );
		$this->assertMatchesRegularExpression( '/<a [^>]*data-einv-tab-link="invoices"[^>]*aria-current="page"|<a [^>]*aria-current="page"[^>]*data-einv-tab-link="invoices"/', $page );
		$this->assertStringContainsString( 'id="einv-invoices"', $page );
		$this->assertStringNotContainsString( 'id="einv-mst"', $page );
	}

	public function test_deep_link_renders_the_lark_tab(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['tab'] = 'lark';

		$page = get_echo( array( SettingsPage::class, 'render' ) );

		$this->assertStringContainsString( 'data-einv-tab="lark"', $page );
		$this->assertStringContainsString( 'id="einv-lark-webhook"', $page );
		$this->assertStringNotContainsString( 'id="einv-invoices"', $page );
	}

	public function test_unknown_tab_falls_back_to_invoices(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( array( 'foo', 'LARK', array( 'lark' ) ) as $value ) {
			$_GET['tab'] = $value;
			$page        = get_echo( array( SettingsPage::class, 'render' ) );
			$this->assertStringContainsString( 'data-einv-tab="invoices"', $page );
		}
	}

	public function test_tab_links_have_real_hrefs_and_push_url(): void {
		$html = SettingsPage::render_tabs( 'invoices' );

		foreach ( array_keys( AdminTabs::labels() ) as $key ) {
			$url = esc_url( AdminTabs::url( $key ) );
			$this->assertStringContainsString( 'href="' . $url . '"', $html );
			$this->assertStringContainsString( 'hx-push-url="' . $url . '"', $html );
			$this->assertStringContainsString( 'hx-get="' . esc_url( rest_url( 'einvoice-matbao/v1/tabs/' . $key ) ) . '"', $html );
		}
		$this->assertStringContainsString( 'hx-target="#einv-tabs"', $html );
	}

	public function test_encryption_banner_renders_above_the_tab_bar(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$page = get_echo( array( SettingsPage::class, 'render' ) );

		$this->assertNotFalse( strpos( $page, 'EINVOICE_MATBAO_ENCRYPTION_KEY' ) );
		$this->assertLessThan( strpos( $page, 'id="einv-tabs"' ), strpos( $page, 'EINVOICE_MATBAO_ENCRYPTION_KEY' ) );
	}

	public function test_tabs_group_the_existing_forms(): void {
		$connection = SettingsPage::render_tabs( 'connection' );
		$this->assertStringContainsString( 'settings/matbao', $connection );
		$this->assertStringContainsString( 'einv-reload-templates', $connection );
		$this->assertStringContainsString( 'einv-matbao-saved from:body', $connection );

		$invoicing = SettingsPage::render_tabs( 'invoicing' );
		$this->assertStringContainsString( 'settings/invoicing', $invoicing );
		$this->assertStringContainsString( 'settings/unit', $invoicing );
		$this->assertStringNotContainsString( 'settings/lark', $invoicing );

		$this->assertStringContainsString( 'data-einv-section="uninstall"', SettingsPage::render_tabs( 'advanced' ) );
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
