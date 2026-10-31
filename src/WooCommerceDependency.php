<?php
/**
 * WooCommerce dependency guard.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao;

/**
 * Deactivates the plugin and notifies admins when WooCommerce is not active.
 *
 * Activation itself is refused by WordPress core through the `Requires Plugins` header;
 * this guard covers the case where WooCommerce disappears while this plugin stays active.
 */
final class WooCommerceDependency {

	/**
	 * Whether WooCommerce is loaded.
	 */
	public static function is_met(): bool {
		return class_exists( 'WooCommerce', false );
	}

	/**
	 * Hooks self-deactivation and the admin notice.
	 */
	public static function register_missing_handlers(): void {
		add_action( 'admin_init', array( self::class, 'deactivate_plugin' ) );
		add_action( 'admin_notices', array( self::class, 'render_missing_notice' ) );
	}

	/**
	 * Deactivates this plugin without running its deactivation hooks.
	 */
	public static function deactivate_plugin(): void {
		// admin_init also fires for unauthenticated admin-ajax.php / admin-post.php requests.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( plugin_basename( EINVOICE_MATBAO_FILE ), true );

		// Suppress the core "Plugin activated." notice if we were just activated.
		unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only removes a display flag.
	}

	/**
	 * Prints the admin notice explaining why the plugin was disabled.
	 */
	public static function render_missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Hóa đơn điện tử Mắt Bão cần WooCommerce được cài đặt và kích hoạt. Plugin đã tự tắt.', 'einvoice-matbao' )
		);
	}
}
