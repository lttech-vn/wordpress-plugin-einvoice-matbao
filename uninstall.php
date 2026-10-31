<?php
/**
 * Runs when WordPress deletes the plugin (not on deactivation).
 *
 * Loads the Uninstaller file directly so it works without `vendor/` and without WooCommerce.
 *
 * @package EinvoiceMatbao
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! class_exists( LTTech\EinvoiceMatbao\Uninstall\Uninstaller::class, false ) ) {
	require_once __DIR__ . '/src/Uninstall/Uninstaller.php';
}

LTTech\EinvoiceMatbao\Uninstall\Uninstaller::run();
