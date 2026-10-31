<?php
/**
 * Behaviour when WooCommerce is not installed or not active.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\WithoutWooCommerce;

use LTTech\EinvoiceMatbao\Plugin;
use LTTech\EinvoiceMatbao\WooCommerceDependency;
use WP_UnitTestCase;

/**
 * Plugin must deactivate itself and tell the admin, never fatal.
 */
final class MissingWooCommerceTest extends WP_UnitTestCase {

	private const PLUGIN = 'einvoice-matbao/einvoice-matbao.php';

	public function test_woocommerce_is_not_loaded(): void {
		$this->assertFalse( class_exists( 'WooCommerce', false ) );
		$this->assertFalse( WooCommerceDependency::is_met() );
	}

	public function test_activation_is_refused_with_a_message(): void {
		$result = activate_plugin( self::PLUGIN );

		$this->assertWPError( $result );
		$this->assertNotEmpty( $result->get_error_message() );
		$this->assertFalse( is_plugin_active( self::PLUGIN ) );
	}

	public function test_already_active_plugin_deactivates_itself_and_shows_vietnamese_notice(): void {
		update_option( 'active_plugins', array( self::PLUGIN ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Limitation: firing the whole admin_init action would also run core header callbacks,
		// so this calls the plugin's own callbacks directly and only asserts they are hooked.
		$deactivate = array( WooCommerceDependency::class, 'deactivate_plugin' );
		$notice     = array( WooCommerceDependency::class, 'render_missing_notice' );
		$this->assertNotFalse( has_action( 'admin_init', $deactivate ) );
		$this->assertNotFalse( has_action( 'admin_notices', $notice ) );

		WooCommerceDependency::deactivate_plugin();
		$notices = get_echo( $notice );

		$this->assertFalse( is_plugin_active( self::PLUGIN ) );
		$this->assertStringContainsString( 'Hóa đơn điện tử Mắt Bão', $notices );
		$this->assertStringContainsString( 'WooCommerce', $notices );
	}

	public function test_anonymous_request_does_not_deactivate_plugin(): void {
		update_option( 'active_plugins', array( self::PLUGIN ) );
		wp_set_current_user( 0 );

		WooCommerceDependency::deactivate_plugin();

		$this->assertTrue( is_plugin_active( self::PLUGIN ) );
	}

	public function test_subscriber_does_not_deactivate_plugin(): void {
		update_option( 'active_plugins', array( self::PLUGIN ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		WooCommerceDependency::deactivate_plugin();

		$this->assertTrue( is_plugin_active( self::PLUGIN ) );
	}

	public function test_declaring_woocommerce_compatibility_without_woocommerce_is_a_no_op(): void {
		$before = get_option( 'active_plugins' );

		Plugin::declare_woocommerce_compatibility();

		$this->assertFalse( class_exists( 'Automattic\WooCommerce\Utilities\FeaturesUtil', false ) );
		$this->assertSame( $before, get_option( 'active_plugins' ) );
	}
}
