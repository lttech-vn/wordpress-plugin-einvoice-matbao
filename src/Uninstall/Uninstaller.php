<?php
/**
 * What deleting the plugin removes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Uninstall;

/**
 * Run by `uninstall.php` when WordPress deletes the plugin, on each site of a network.
 *
 * Always removed: the encrypted Mat Bao and Lark credentials, every plugin transient (the cached
 * Mat Bao token and templates list) and the plugin's queued Action Scheduler actions.
 *
 * Kept by default: the invoice and attempt tables, the buyer order meta, the per-product unit, the
 * settings and the alert records (`einvoice_matbao_alert_*`), so a reinstall resumes with the same `MTChieu` and history.
 *
 * With the "delete all data on uninstall" setting on, also removed: both tables, every
 * `einvoice_matbao_*` option, the buyer order meta (legacy and HPOS tables) and the product unit
 * meta. WooCommerce orders and products themselves are never removed.
 *
 * This class must not depend on WooCommerce or on other plugin classes: `uninstall.php` loads it
 * directly, so it works without `vendor/` and with WooCommerce gone. The names below therefore
 * repeat the plugin's own constants (a test keeps them equal).
 */
final class Uninstaller {

	public const CREDENTIALS_OPTION = 'einvoice_matbao_credentials';

	public const SETTINGS_OPTION = 'einvoice_matbao_settings';

	/** Settings key of the "delete all data on uninstall" switch; '1' when on. */
	public const DELETE_ALL_SETTING = 'delete_data_on_uninstall';

	/** Every plugin option and transient name starts with this. */
	public const OPTION_PREFIX = 'einvoice_matbao_';

	/** Checkout request and buyer snapshot. */
	public const ORDER_META_KEYS = array( '_einvoice_buyer_request', '_einvoice_buyer_snapshot' );

	/** Per-product unit of measure override. */
	public const PRODUCT_META_KEY = '_einvoice_matbao_unit';

	/** Action Scheduler group of every plugin action (draft, sweep, alerts). */
	public const ACTION_GROUP = 'einvoice-matbao';

	/** Table names without the site prefix. */
	public const TABLES = array( 'einvoice_matbao_invoice', 'einvoice_matbao_attempt' );

	/**
	 * Uninstalls on the current site, or on every site of a network.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return;
		}

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::uninstall_site();
			restore_current_blog();
		}
	}

	/**
	 * Cancels the plugin's pending Action Scheduler actions. Also run on deactivation.
	 * Without Action Scheduler loaded (WooCommerce removed) it cancels them in its tables directly.
	 */
	public static function cancel_scheduled_actions(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) && class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized() ) {
			as_unschedule_all_actions( '', array(), self::ACTION_GROUP );
			return;
		}

		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		if ( ! self::table_exists( $actions ) || ! self::table_exists( $groups ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Action Scheduler is not loaded; its tables are updated as its own cancel does.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i a INNER JOIN %i g ON g.group_id = a.group_id SET a.status = 'canceled' WHERE g.slug = %s AND a.status IN ('pending', 'in-progress')",
				$actions,
				$groups,
				self::ACTION_GROUP
			)
		);
	}

	/**
	 * Uninstalls on the current site.
	 */
	private static function uninstall_site(): void {
		$delete_all = self::delete_all_enabled();

		self::cancel_scheduled_actions();
		delete_option( self::CREDENTIALS_OPTION );
		foreach ( self::option_names( '_transient_' . self::OPTION_PREFIX ) as $transient ) {
			delete_transient( substr( $transient, strlen( '_transient_' ) ) );
		}

		if ( ! $delete_all ) {
			return;
		}

		self::drop_tables();
		self::delete_order_meta();
		delete_post_meta_by_key( self::PRODUCT_META_KEY );
		foreach ( self::option_names( self::OPTION_PREFIX ) as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Whether the current site turned on "delete all data on uninstall".
	 */
	private static function delete_all_enabled(): bool {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		return is_array( $settings ) && '1' === ( $settings[ self::DELETE_ALL_SETTING ] ?? '' );
	}

	/**
	 * Drops the invoice and attempt tables.
	 */
	private static function drop_tables(): void {
		global $wpdb;

		foreach ( self::TABLES as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall removes the plugin's own tables.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
		}
	}

	/**
	 * Deletes the buyer order meta from the legacy post meta and the HPOS order meta tables.
	 * WooCommerce may be gone, so its CRUD is not used.
	 */
	private static function delete_order_meta(): void {
		global $wpdb;

		foreach ( self::ORDER_META_KEYS as $key ) {
			delete_post_meta_by_key( $key );
		}

		$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
		if ( ! self::table_exists( $hpos_meta ) ) {
			return;
		}
		foreach ( self::ORDER_META_KEYS as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WooCommerce may not be loaded during uninstall; one-off.
			$wpdb->delete( $hpos_meta, array( 'meta_key' => $key ) );
		}
	}

	/**
	 * Option names starting with a prefix.
	 *
	 * @param string $prefix Literal prefix.
	 * @return list<string>
	 */
	private static function option_names( string $prefix ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- names are enumerated once on uninstall.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );

		return array_values( array_map( 'strval', $names ) );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
