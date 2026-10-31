<?php
/**
 * Plugin Name:          Hóa đơn điện tử Mắt Bão
 * Description:          Tạo nháp hóa đơn GTGT trên Mắt Bão cho đơn hàng WooCommerce đã hoàn thành.
 * Version:              0.1.0
 * Author:               LTTech
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          einvoice-matbao
 * Domain Path:          /languages
 * Requires at least:    7.0
 * Requires PHP:         8.5
 * Requires Plugins:     woocommerce
 * WC requires at least: 11.0
 * WC tested up to:      11.2
 *
 * @package EinvoiceMatbao
 */

defined( 'ABSPATH' ) || exit;

define( 'EINVOICE_MATBAO_FILE', __FILE__ );
define( 'EINVOICE_MATBAO_VERSION', '0.1.0' );

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	// A source checkout without `composer install`: stay inert instead of fataling.
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

LTTech\EinvoiceMatbao\Plugin::register();
