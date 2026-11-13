<?php
/**
 * Admin menu entry, settings page markup and asset loading.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\AdminTabs;
use LTTech\EinvoiceMatbao\Admin\InvoicePage;
use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Lark\LarkWebhook;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Admin\SettingsPage;
use WP_UnitTestCase;

/**
 * The settings page is a top-level menu, is scoped in #einv-app and wires htmx with the REST nonce.
 */
final class SettingsPageTest extends WP_UnitTestCase {

	public function tear_down(): void {
		unset( $_GET['tab'] );
		global $menu, $submenu, $_registered_pages, $_wp_submenu_nopriv;
		foreach ( (array) $menu as $position => $item ) {
			if ( SettingsPage::SLUG === ( $item[2] ?? '' ) ) {
				unset( $menu[ $position ] );
			}
		}
		unset( $submenu['woocommerce'], $submenu[ SettingsPage::SLUG ], $submenu[''], $_wp_submenu_nopriv[''] );
		unset( $_registered_pages[ SettingsPage::TOP_LEVEL_HOOK ] );
		unset( $_registered_pages['admin_page_einvoice-matbao-invoices'] );
		remove_all_actions( 'load-admin_page_einvoice-matbao-invoices' );
		remove_all_filters( 'wp_redirect' );
		wp_dequeue_style( SettingsPage::HANDLE );
		wp_dequeue_script( SettingsPage::HANDLE );
		wp_deregister_style( SettingsPage::HANDLE );
		wp_deregister_script( SettingsPage::HANDLE );
		parent::tear_down();
	}

	public function test_menu_is_top_level_for_administrators_only(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		SettingsPage::register_menu();

		global $menu, $submenu;
		$entries = array_column( $menu, 1, 2 );
		$this->assertArrayHasKey( SettingsPage::SLUG, $entries );
		$this->assertSame( 'manage_options', $entries[ SettingsPage::SLUG ] );
		$this->assertSame( 'Hóa đơn Mắt Bão', array_column( $menu, 3, 2 )[ SettingsPage::SLUG ] );
		$this->assertArrayNotHasKey( SettingsPage::SLUG, array_column( $submenu['woocommerce'] ?? array(), 1, 2 ) );

		InvoicePage::register_menu();
		$this->assertArrayNotHasKey( InvoicePage::SLUG, array_column( $menu, 1, 2 ) );
		$this->assertArrayNotHasKey( InvoicePage::SLUG, array_column( $submenu['woocommerce'] ?? array(), 1, 2 ) );
	}

	public function test_old_invoices_page_redirects_to_invoices_tab(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$seen = array();
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) use ( &$seen ) {
				$seen = array( $location, $status );
				return false;
			},
			10,
			2
		);

		InvoicePage::register_menu();
		InvoicePage::redirect_to_tab();

		$this->assertSame( array( AdminTabs::url( 'invoices' ), 302 ), $seen );
		$this->assertSame( 0, did_action( 'deprecated_function_run' ) + did_action( 'deprecated_argument_run' ) );
	}

	public function test_tab_container_owns_history_snapshots(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertStringContainsString( 'id="einv-tabs" hx-history-elt', get_echo( array( SettingsPage::class, 'render' ) ) );
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

		$lark = SettingsPage::render_tabs( 'notifications' );
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

	/**
	 * The notifications tab and its two legacy keys.
	 *
	 * @return array<string, array{string}>
	 */
	public static function notification_keys(): array {
		return array(
			'notifications' => array( 'notifications' ),
			'lark'          => array( 'lark' ),
			'monitoring'    => array( 'monitoring' ),
		);
	}

	/**
	 * Deep links, including the legacy keys, render the notifications tab with both cards.
	 *
	 * @dataProvider notification_keys
	 *
	 * @param string $key Tab key in the URL.
	 */
	public function test_deep_link_renders_the_notifications_tab( string $key ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['tab'] = $key;

		$page = get_echo( array( SettingsPage::class, 'render' ) );

		$this->assertStringContainsString( 'data-einv-tab="notifications"', $page );
		$this->assertMatchesRegularExpression( '/<a [^>]*data-einv-tab-link="notifications"[^>]*aria-current="page"/', $page );
		$this->assertStringContainsString( 'id="einv-lark-webhook"', $page );
		$this->assertStringContainsString( 'id="einv-sentry-dsn"', $page );
		$this->assertStringNotContainsString( 'id="einv-invoices"', $page );
	}

	public function test_tab_bar_has_five_tabs_with_notifications_before_advanced(): void {
		$html = SettingsPage::render_tabs( 'invoices' );

		preg_match_all( '/data-einv-tab-link="([a-z]+)"/', $html, $found );
		$this->assertSame( array( 'invoices', 'connection', 'invoicing', 'notifications', 'advanced' ), $found[1] );
	}

	public function test_unknown_tab_falls_back_to_invoices(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( array( 'foo', 'LARK', array( 'lark' ), 'lark1' ) as $value ) {
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

	public function test_tab_link_colours_use_important_utilities(): void {
		$html = SettingsPage::render_tabs( 'invoices' );

		$this->assertSame( 1, substr_count( $html, '!text-einv-primary' ) - substr_count( $html, 'focus:!text-einv-primary' ) );
		$this->assertSame( 1, substr_count( $html, 'focus:!text-einv-primary' ) );
		$this->assertSame( count( AdminTabs::labels() ) - 1, substr_count( $html, ' !text-einv-ink-mute' ) );
		$this->assertStringContainsString( 'hover:!text-einv-ink', $html );
		$this->assertStringContainsString( 'focus:!shadow-none', $html );
		$this->assertStringContainsString( 'focus-visible:!outline-einv-primary', $html );
	}

	public function test_encryption_banner_renders_above_the_tab_bar(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$page = get_echo( array( SettingsPage::class, 'render' ) );

		$this->assertNotFalse( strpos( $page, 'EINVOICE_MATBAO_ENCRYPTION_KEY' ) );
		$this->assertLessThan( strpos( $page, 'id="einv-tabs"' ), strpos( $page, 'EINVOICE_MATBAO_ENCRYPTION_KEY' ) );
	}

	public function test_notifications_tab_contents(): void {
		$html = SettingsPage::render_tabs( 'notifications' );

		$this->assertStringContainsString( 'data-einv-tab="notifications"', $html );
		$this->assertStringContainsString( 'hx-post="' . esc_url( rest_url( 'einvoice-matbao/v1/settings/lark' ) ) . '"', $html );
		$this->assertStringContainsString( 'Bật cảnh báo Lark', $html );
		$this->assertSame( 2, substr_count( $html, 'class="einv-result"' ), 'Each card has its own result area.' );
		$this->assertStringNotContainsString( 'id="einv-mst"', $html );
		$this->assertStringContainsString( 'hx-post="' . esc_url( rest_url( 'einvoice-matbao/v1/settings/monitoring' ) ) . '"', $html );
		$this->assertStringContainsString( 'hx-post="' . esc_url( rest_url( 'einvoice-matbao/v1/settings/monitoring/test' ) ) . '"', $html );
		$this->assertStringContainsString( 'name="enabled"', $html );
		$this->assertStringContainsString( 'name="dsn"', $html );
		$this->assertStringContainsString( 'name="traces_sample_rate"', $html );
		$this->assertStringContainsString( 'Gửi event thử', $html );
	}

	public function test_lark_switch_is_checked_for_a_site_with_a_saved_webhook_and_unchecked_for_a_new_site(): void {
		add_filter( 'einvoice_matbao_encryption_key', static fn(): string => 'test-only-key-material-0123456789abcdef' );
		$this->assertDoesNotMatchRegularExpression( '/name="enabled"[^>]*checked[^>]*>\s*Bật cảnh báo Lark/s', SettingsPage::render_tabs( 'notifications' ) );

		( new CredentialStore() )->save_lark( new LarkWebhook( 'https://open.larksuite.com/open-apis/bot/v2/hook/fake', '' ) );
		$this->assertMatchesRegularExpression( '/id="einv-lark-enabled"[^>]*checked/', SettingsPage::render_tabs( 'notifications' ) );

		( new Settings() )->save_lark_enabled( false );
		$this->assertDoesNotMatchRegularExpression( '/id="einv-lark-enabled"[^>]*checked/', SettingsPage::render_tabs( 'notifications' ) );
		remove_all_filters( 'einvoice_matbao_encryption_key' );
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
		$this->assertStringNotContainsString( 'einv-sentry-dsn', $invoicing );

		$this->assertStringContainsString( 'data-einv-section="uninstall"', SettingsPage::render_tabs( 'advanced' ) );
	}

	/**
	 * Config tab keys that must render inside the width wrapper.
	 *
	 * @return array<string, array{string}>
	 */
	public static function config_tabs(): array {
		return array(
			'connection'    => array( 'connection' ),
			'invoicing'     => array( 'invoicing' ),
			'notifications' => array( 'notifications' ),
			'advanced'      => array( 'advanced' ),
		);
	}

	/**
	 * Config tab panels sit inside a max-w-3xl container.
	 *
	 * @dataProvider config_tabs
	 * @param string $tab Tab key.
	 */
	public function test_config_tab_panel_is_wrapped_in_max_w_3xl( string $tab ): void {
		$html = SettingsPage::render_tabs( $tab );

		$this->assertMatchesRegularExpression(
			'#<div id="einv-tab-panel"[^>]*data-einv-tab="' . $tab . '">\s*<div class="max-w-3xl">#',
			$html
		);
	}

	public function test_invoices_tab_panel_is_full_width(): void {
		$html = SettingsPage::render_tabs( 'invoices' );

		$this->assertStringNotContainsString( 'class="max-w-3xl"', $html );
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
		foreach ( array( 'woocommerce_page_', 'admin_page_', 'toplevel_page_' ) as $prefix ) {
			SettingsPage::enqueue_assets( $prefix . InvoicePage::SLUG );
			$this->assertFalse( wp_script_is( SettingsPage::HANDLE, 'enqueued' ) );
		}

		SettingsPage::enqueue_assets( SettingsPage::TOP_LEVEL_HOOK );
		$this->assertTrue( wp_script_is( SettingsPage::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( SettingsPage::HANDLE, 'enqueued' ) );

		wp_dequeue_script( SettingsPage::HANDLE );
		SettingsPage::enqueue_assets( 'woocommerce_page_' . SettingsPage::SLUG );
		$this->assertFalse( wp_script_is( SettingsPage::HANDLE, 'enqueued' ) );
		SettingsPage::enqueue_assets( SettingsPage::TOP_LEVEL_HOOK );

		$inline = implode( "\n", (array) wp_scripts()->get_data( SettingsPage::HANDLE, 'after' ) );
		$this->assertStringContainsString( 'htmx:configRequest', $inline );
		$this->assertStringContainsString( 'X-WP-Nonce', $inline );
		$this->assertStringContainsString( 'refreshOnHistoryMiss', $inline );
		$this->assertStringContainsString( wp_create_nonce( 'wp_rest' ), $inline );
		$this->assertStringNotContainsString( '//cdn', (string) wp_scripts()->registered[ SettingsPage::HANDLE ]->src );
	}
}
