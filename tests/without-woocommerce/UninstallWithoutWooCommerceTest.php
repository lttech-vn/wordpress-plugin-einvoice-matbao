<?php
/**
 * Uninstalling after WooCommerce was removed.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\WithoutWooCommerce;

use LTTech\EinvoiceMatbao\Uninstall\Uninstaller;
use WP_UnitTestCase;

/**
 * Without WooCommerce (and so without Action Scheduler) the uninstaller still removes the
 * credentials, cancels the plugin's actions in the Action Scheduler tables and, when asked,
 * drops the plugin tables. Every table here is a temporary table of this connection.
 */
final class UninstallWithoutWooCommerceTest extends WP_UnitTestCase {

	/**
	 * Temporary tables created by the test.
	 *
	 * @var list<string>
	 */
	private array $tables = array();

	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture tables.
			$wpdb->query( $wpdb->prepare( 'DROP TEMPORARY TABLE IF EXISTS %i', $table ) );
		}

		parent::tear_down();
	}

	public function test_uninstall_removes_credentials_and_cancels_plugin_actions_without_woocommerce(): void {
		$this->assertFalse( class_exists( 'ActionScheduler', false ) );
		$this->create_action_scheduler_tables();
		$this->create_invoice_table();
		update_option( Uninstaller::CREDENTIALS_OPTION, array( 'matbao' => 'v1.fake' ), false );
		set_transient( 'einvoice_matbao_templates', array( 'x' ), HOUR_IN_SECONDS );

		Uninstaller::run();

		$this->assertFalse( get_option( Uninstaller::CREDENTIALS_OPTION ) );
		$this->assertFalse( get_transient( 'einvoice_matbao_templates' ) );
		$this->assertSame( array( 'canceled', 'pending' ), $this->action_statuses() );
		$this->assertSame( '1', $this->invoice_rows(), 'Invoice data is kept by default.' );
	}

	public function test_delete_all_drops_the_plugin_tables_without_woocommerce(): void {
		$this->create_invoice_table();
		update_option( Uninstaller::SETTINGS_OPTION, array( Uninstaller::DELETE_ALL_SETTING => '1' ) );

		Uninstaller::run();

		$this->assertNull( $this->invoice_rows() );
		$this->assertFalse( get_option( Uninstaller::SETTINGS_OPTION ) );
	}

	/**
	 * Action Scheduler tables with one plugin action and one action of another plugin.
	 */
	private function create_action_scheduler_tables(): void {
		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- test fixture tables.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (action_id bigint(20) unsigned NOT NULL, group_id bigint(20) unsigned NOT NULL, status varchar(20) NOT NULL)', $actions ) );
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (group_id bigint(20) unsigned NOT NULL, slug varchar(255) NOT NULL)', $groups ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (group_id, slug) VALUES (1, %s), (2, 'other-plugin')", $groups, Uninstaller::ACTION_GROUP ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (action_id, group_id, status) VALUES (1, 1, 'pending'), (2, 2, 'pending')", $actions ) );
		// phpcs:enable
		$this->tables[] = $actions;
		$this->tables[] = $groups;
	}

	/**
	 * Invoice table with one row.
	 */
	private function create_invoice_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'einvoice_matbao_invoice';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- test fixture table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id bigint(20) unsigned NOT NULL)', $table ) );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (id) VALUES (1)', $table ) );
		// phpcs:enable
		$this->tables[] = $table;
	}

	/**
	 * Statuses of the fixture actions, ordered by ID.
	 *
	 * @return list<string>
	 */
	private function action_statuses(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test inspects raw storage.
		return array_values( array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'SELECT status FROM %i ORDER BY action_id', $wpdb->prefix . 'actionscheduler_actions' ) ) ) );
	}

	/**
	 * Row count of the invoice table, or null when it no longer exists.
	 */
	private function invoice_rows(): ?string {
		global $wpdb;

		$suppressed = $wpdb->suppress_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test inspects raw storage.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'einvoice_matbao_invoice' ) );
		$wpdb->suppress_errors( $suppressed );

		return null === $count ? null : (string) $count;
	}
}
