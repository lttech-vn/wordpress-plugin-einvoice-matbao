<?php
/**
 * Invoice and attempt tables.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

/**
 * Creates and upgrades the plugin tables with dbDelta (ADR 0002). One invoice row per order
 * (`UNIQUE(order_id)`); attempts record every create call made for that invoice.
 */
final class Schema {

	public const VERSION        = '4';
	public const VERSION_OPTION = 'einvoice_matbao_db_version';

	/**
	 * Invoice table name with the site prefix.
	 */
	public static function invoice_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'einvoice_matbao_invoice';
	}

	/**
	 * Attempt table name with the site prefix.
	 */
	public static function attempt_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'einvoice_matbao_attempt';
	}

	/**
	 * Installs or upgrades the tables when the stored schema version differs.
	 */
	public static function maybe_install(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Runs dbDelta for both tables and records the schema version.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$invoice         = self::invoice_table();
		$attempt         = self::attempt_table();

		// dbDelta format: two spaces after PRIMARY KEY, one column or key per line.
		dbDelta(
			"CREATE TABLE {$invoice} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  reference varchar(20) NOT NULL,
  status varchar(20) NOT NULL,
  status_reason text NULL,
  error_code varchar(40) NOT NULL DEFAULT '',
  error_kind varchar(20) NOT NULL DEFAULT '',
  buyer_name varchar(400) NOT NULL DEFAULT '',
  template_form varchar(6) NOT NULL DEFAULT '',
  template_series varchar(6) NOT NULL DEFAULT '',
  matbao_invoice_id varchar(100) NULL,
  issued_on date NULL,
  retry_cycle_start bigint(20) unsigned NOT NULL DEFAULT 0,
  alert_cycle int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id),
  KEY reference (reference),
  KEY status (status)
) {$charset_collate};
CREATE TABLE {$attempt} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL,
  started_at datetime NOT NULL,
  finished_at datetime NULL,
  outcome varchar(20) NULL,
  error_kind varchar(20) NULL,
  http_status smallint(5) unsigned NULL,
  api_code int(11) NULL,
  message text NULL,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id)
) {$charset_collate};"
		);

		update_option( self::VERSION_OPTION, self::VERSION );
	}
}
