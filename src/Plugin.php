<?php
/**
 * Plugin bootstrap.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Wires the plugin into WordPress and WooCommerce.
 */
final class Plugin {

	public const TEXT_DOMAIN = 'einvoice-matbao';

	/**
	 * Registers hooks. Called once from the main plugin file.
	 */
	public static function register(): void {
		add_action( 'before_woocommerce_init', array( self::class, 'declare_woocommerce_compatibility' ) );
		add_action( 'init', array( self::class, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( self::class, 'boot' ) );
		register_activation_hook( EINVOICE_MATBAO_FILE, array( Invoice\Schema::class, 'install' ) );
		register_deactivation_hook( EINVOICE_MATBAO_FILE, array( self::class, 'deactivate' ) );
	}

	/**
	 * Starts the plugin once all plugins are loaded, or disables it when WooCommerce is missing.
	 */
	public static function boot(): void {
		if ( ! WooCommerceDependency::is_met() ) {
			WooCommerceDependency::register_missing_handlers();
			return;
		}

		Invoice\Schema::maybe_install();
		Invoice\DraftInvoiceWorkflow::register();
		Checkout\ClassicCheckout::register();
		Checkout\BlocksCheckout::register();
		Buyer\OrderRestPrivacy::register();
		Alert\InvoiceAlerts::register();
		Invoice\OrderChangeWatcher::register();
		Privacy\BuyerPersonalData::register();

		add_action( 'rest_api_init', array( Admin\SettingsController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( Admin\InvoiceController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( Uninstall\UninstallSettingsController::class, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( Admin\SettingsController::class, 'serve_html' ), 10, 4 );

		if ( is_admin() ) {
			Admin\SettingsPage::register();
			Admin\OrderInvoiceMetaBox::register();
			Admin\OrderListInvoiceFilter::register();
			Admin\ProductUnitField::register();
		}
	}

	/**
	 * Cancels the plugin's queued actions (drafts, recovery sweep, alerts); data stays.
	 * The sweep is queued again on the next `action_scheduler_init` after reactivation.
	 */
	public static function deactivate(): void {
		Uninstall\Uninstaller::cancel_scheduled_actions();
	}

	/**
	 * Declares HPOS (custom order tables) and Cart/Checkout Blocks compatibility.
	 */
	public static function declare_woocommerce_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', EINVOICE_MATBAO_FILE, true );
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EINVOICE_MATBAO_FILE, true );
	}

	/**
	 * Loads translations from the plugin's languages directory.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( EINVOICE_MATBAO_FILE ) ) . '/languages' );
	}
}
