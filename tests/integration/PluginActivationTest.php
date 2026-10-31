<?php
/**
 * Activation and WooCommerce feature compatibility on real WordPress + WooCommerce.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use Automattic\WooCommerce\Internal\Features\FeaturesController;
use WP_UnitTestCase;

/**
 * Plugin activation with WooCommerce present.
 */
final class PluginActivationTest extends WP_UnitTestCase {

	private const PLUGIN = 'einvoice-matbao/einvoice-matbao.php';

	public function test_activates_without_error_when_woocommerce_is_active(): void {
		deactivate_plugins( self::PLUGIN, true );

		$result = activate_plugin( self::PLUGIN );

		$this->assertNull( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( is_plugin_active( self::PLUGIN ) );
	}

	public function test_woocommerce_lists_no_hpos_or_blocks_incompatibility(): void {
		$features = wc_get_container()->get( FeaturesController::class );
		$plugins  = get_plugins();

		$this->assertArrayHasKey( self::PLUGIN, $plugins );
		$this->assertArrayNotHasKey( self::PLUGIN, $features->get_incompatible_plugins( 'custom_order_tables', $plugins ) );
		$this->assertArrayNotHasKey( self::PLUGIN, $features->get_incompatible_plugins( 'cart_checkout_blocks', $plugins ) );
	}

	public function test_declares_hpos_and_blocks_compatibility(): void {
		$compatibility = wc_get_container()->get( FeaturesController::class )->get_compatible_features_for_plugin( self::PLUGIN );

		$this->assertContains( 'custom_order_tables', $compatibility['compatible'] );
		$this->assertContains( 'cart_checkout_blocks', $compatibility['compatible'] );
	}
}
