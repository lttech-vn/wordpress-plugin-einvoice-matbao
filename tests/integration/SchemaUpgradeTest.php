<?php
/**
 * Upgrade of the invoice table from schema v1.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\OrderInvoiceMetaBox;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\Schema;
use WC_Order;
use WP_UnitTestCase;

/**
 * A site still on schema v1 gets the error columns on the next `plugins_loaded`, keeping its rows.
 * The v1 table is a temporary table that shadows the real one for this test's connection.
 */
final class SchemaUpgradeTest extends WP_UnitTestCase {

	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture table.
		$wpdb->query( $wpdb->prepare( 'DROP TEMPORARY TABLE IF EXISTS %i', Schema::invoice_table() ) );
		update_option( Schema::VERSION_OPTION, Schema::VERSION );

		parent::tear_down();
	}

	public function test_v1_table_gets_the_error_columns_and_keeps_its_rows(): void {
		global $wpdb;
		$table = Schema::invoice_table();
		$this->create_v1_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture row.
		$wpdb->insert(
			$table,
			array(
				'order_id'      => 7001,
				'reference'     => 'WC7001',
				'status'        => InvoiceStatus::FAILED,
				'status_reason' => 'Lỗi cũ trước bản v2',
				'buyer_name'    => 'Khách lẻ',
				'created_at'    => '2026-01-02 03:04:05',
				'updated_at'    => '2026-01-02 03:04:05',
			)
		);
		update_option( Schema::VERSION_OPTION, '1' );
		$this->assertNotContains( 'error_code', $this->columns() );

		Schema::maybe_install();

		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		$columns = $this->columns();
		$this->assertContains( 'error_code', $columns );
		$this->assertContains( 'error_kind', $columns );

		$invoice = ( new InvoiceRepository() )->find_by_order( 7001 );
		$this->assertNotNull( $invoice );
		$this->assertSame( 'WC7001', $invoice->reference );
		$this->assertSame( InvoiceStatus::FAILED, $invoice->status );
		$this->assertSame( 'Lỗi cũ trước bản v2', $invoice->status_reason );
		$this->assertSame( 'Khách lẻ', $invoice->buyer_name );
		$this->assertSame( '', $invoice->error_code );
		$this->assertSame( '', $invoice->error_kind );
		$this->assertNull( $invoice->error() );
	}

	public function test_old_failed_row_without_a_code_shows_only_its_reason(): void {
		global $wpdb;
		$this->create_v1_table();
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture row.
		$wpdb->insert(
			Schema::invoice_table(),
			array(
				'order_id'      => $order->get_id(),
				'reference'     => 'WC' . $order->get_id(),
				'status'        => InvoiceStatus::FAILED,
				'status_reason' => 'Lỗi cũ trước bản v2',
				'created_at'    => '2026-01-02 03:04:05',
				'updated_at'    => '2026-01-02 03:04:05',
			)
		);
		update_option( Schema::VERSION_OPTION, '1' );

		Schema::maybe_install();
		ob_start();
		OrderInvoiceMetaBox::render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Lỗi cũ trước bản v2', $html );
		$this->assertStringNotContainsString( 'data-einv-error-code', $html );
	}

	public function test_v2_table_gets_the_retry_cycle_column_and_keeps_its_rows(): void {
		global $wpdb;
		$this->create_v1_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL: the v2 columns.
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD error_code varchar(40) NOT NULL DEFAULT '', ADD error_kind varchar(20) NOT NULL DEFAULT ''", Schema::invoice_table() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture row.
		$wpdb->insert(
			Schema::invoice_table(),
			array(
				'order_id'      => 7002,
				'reference'     => 'WC7002',
				'status'        => InvoiceStatus::FAILED,
				'status_reason' => 'Lỗi bản v2',
				'error_code'    => 'matbao_http',
				'error_kind'    => 'matbao',
				'created_at'    => '2026-01-02 03:04:05',
				'updated_at'    => '2026-01-02 03:04:05',
			)
		);
		update_option( Schema::VERSION_OPTION, '2' );
		$this->assertNotContains( 'retry_cycle_start', $this->columns() );

		Schema::maybe_install();

		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertContains( 'retry_cycle_start', $this->columns() );
		$invoice = ( new InvoiceRepository() )->find_by_order( 7002 );
		$this->assertNotNull( $invoice );
		$this->assertSame( 'matbao_http', $invoice->error_code );
		$this->assertSame( 0, $invoice->retry_cycle_start );
	}

	public function test_v3_table_gets_the_alert_cycle_column_and_keeps_its_rows(): void {
		global $wpdb;
		$this->create_v1_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL: the v2 and v3 columns.
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD error_code varchar(40) NOT NULL DEFAULT '', ADD error_kind varchar(20) NOT NULL DEFAULT '', ADD retry_cycle_start bigint(20) unsigned NOT NULL DEFAULT 0", Schema::invoice_table() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture row.
		$wpdb->insert(
			Schema::invoice_table(),
			array(
				'order_id'          => 7003,
				'reference'         => 'WC7003',
				'status'            => InvoiceStatus::FAILED,
				'error_code'        => 'matbao_http',
				'retry_cycle_start' => 5,
				'created_at'        => '2026-01-02 03:04:05',
				'updated_at'        => '2026-01-02 03:04:05',
			)
		);
		update_option( Schema::VERSION_OPTION, '3' );
		$this->assertNotContains( 'alert_cycle', $this->columns() );

		Schema::maybe_install();

		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertContains( 'alert_cycle', $this->columns() );
		$invoice = ( new InvoiceRepository() )->find_by_order( 7003 );
		$this->assertNotNull( $invoice );
		$this->assertSame( 5, $invoice->retry_cycle_start );
		$this->assertSame( 0, $invoice->alert_cycle );
	}

	public function test_v4_table_gets_the_order_state_column_and_keeps_its_rows(): void {
		global $wpdb;
		$this->create_v1_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL: the v2 to v4 columns.
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD error_code varchar(40) NOT NULL DEFAULT '', ADD error_kind varchar(20) NOT NULL DEFAULT '', ADD retry_cycle_start bigint(20) unsigned NOT NULL DEFAULT 0, ADD alert_cycle int(10) unsigned NOT NULL DEFAULT 0", Schema::invoice_table() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture row.
		$wpdb->insert(
			Schema::invoice_table(),
			array(
				'order_id'    => 7004,
				'reference'   => 'WC7004',
				'status'      => InvoiceStatus::DRAFT_CREATED,
				'alert_cycle' => 2,
				'created_at'  => '2026-01-02 03:04:05',
				'updated_at'  => '2026-01-02 03:04:05',
			)
		);
		update_option( Schema::VERSION_OPTION, '4' );
		$this->assertNotContains( 'order_state', $this->columns() );

		Schema::maybe_install();

		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertContains( 'order_state', $this->columns() );
		$invoice = ( new InvoiceRepository() )->find_by_order( 7004 );
		$this->assertNotNull( $invoice );
		$this->assertSame( 2, $invoice->alert_cycle );
		$this->assertSame( '', $invoice->order_state );
	}

	public function test_v5_table_gets_matbao_columns_and_keeps_its_rows(): void {
		global $wpdb;
		$this->create_v5_table();
		$new_columns = array( 'matbao_status', 'matbao_status_name', 'invoice_number', 'tax_authority_code', 'matbao_invoice_type', 'tax_note', 'matbao_synced_at', 'matbao_checked_at' );
		foreach ( $new_columns as $column ) {
			$this->assertNotContains( $column, $this->columns(), $column );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture row.
		$wpdb->insert(
			Schema::invoice_table(),
			array(
				'order_id'          => 7005,
				'reference'         => 'WC7005',
				'status'            => InvoiceStatus::DRAFT_CREATED,
				'matbao_invoice_id' => 'abc',
				'alert_cycle'       => 3,
				'created_at'        => '2026-01-02 03:04:05',
				'updated_at'        => '2026-01-02 03:04:05',
			)
		);
		update_option( Schema::VERSION_OPTION, '5' );

		Schema::maybe_install();

		$this->assertSame( '6', get_option( Schema::VERSION_OPTION ) );
		foreach ( $new_columns as $column ) {
			$this->assertContains( $column, $this->columns(), $column );
		}
		$invoice = ( new InvoiceRepository() )->find_by_order( 7005 );
		$this->assertNotNull( $invoice );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $invoice->status );
		$this->assertSame( 'abc', $invoice->matbao_invoice_id );
		$this->assertSame( 3, $invoice->alert_cycle );
		$this->assertNull( $invoice->matbao_status );
		$this->assertSame( '', $invoice->matbao_status_name );
		$this->assertNull( $invoice->invoice_number );
		$this->assertNull( $invoice->tax_authority_code );
		$this->assertNull( $invoice->matbao_invoice_type );
		$this->assertSame( '', $invoice->tax_note );
		$this->assertNull( $invoice->matbao_synced_at );
		$this->assertNull( $invoice->matbao_checked_at );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test inspects the schema.
		$indexes = $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i', Schema::invoice_table() ), 2 );
		$this->assertContains( 'matbao_sync', $indexes );
	}

	/**
	 * Creates the v5 invoice table: every column of the schema before the Mat Bao status columns.
	 */
	private function create_v5_table(): void {
		global $wpdb;
		$table = Schema::invoice_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL; the collation clause cannot be a placeholder.
		$wpdb->query(
			"CREATE TABLE {$table} (
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
  order_state varchar(120) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id),
  KEY reference (reference),
  KEY status (status)
) {$wpdb->get_charset_collate()}"
		);
		// phpcs:enable
	}

	/**
	 * Creates the v1 invoice table (without `error_code` / `error_kind`).
	 */
	private function create_v1_table(): void {
		global $wpdb;
		$table = Schema::invoice_table();

		// The test case turns CREATE TABLE into CREATE TEMPORARY TABLE, shadowing the real table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL; the collation clause cannot be a placeholder.
		$wpdb->query(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  reference varchar(20) NOT NULL,
  status varchar(20) NOT NULL,
  status_reason text NULL,
  buyer_name varchar(400) NOT NULL DEFAULT '',
  template_form varchar(6) NOT NULL DEFAULT '',
  template_series varchar(6) NOT NULL DEFAULT '',
  matbao_invoice_id varchar(100) NULL,
  issued_on date NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id),
  KEY reference (reference),
  KEY status (status)
) {$wpdb->get_charset_collate()}"
		);
		// phpcs:enable
	}

	/**
	 * Column names of the invoice table as this connection sees it.
	 *
	 * @return list<string>
	 */
	private function columns(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test inspects the schema.
		return array_values( array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', Schema::invoice_table() ) ) ) );
	}
}
