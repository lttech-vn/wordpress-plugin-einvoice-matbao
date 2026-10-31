<?php
/**
 * Bootstrap for the integration suite: loads real WooCommerce and this plugin.
 *
 * @package EinvoiceMatbao
 */

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . dirname( __DIR__ ) . '/wp-tests-config.php' );

require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';

$einv_wp_plugins = '/var/www/html/wp-content/plugins';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $einv_wp_plugins ): void {
		require $einv_wp_plugins . '/woocommerce/woocommerce.php';
		require dirname( __DIR__, 2 ) . '/einvoice-matbao.php';
	}
);

// Install WooCommerce tables and roles once WordPress is loaded.
tests_add_filter(
	'setup_theme',
	static function (): void {
		WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reload roles created by WC_Install.
		wp_roles();
	}
);

require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';

// Persist both plugins as active before any test transaction starts, so is_plugin_active()
// and WooCommerce's compatibility checks see them while tests can still change the option.
update_option( 'active_plugins', array( 'woocommerce/woocommerce.php', 'einvoice-matbao/einvoice-matbao.php' ) );
