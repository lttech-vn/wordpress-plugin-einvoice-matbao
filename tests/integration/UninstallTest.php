<?php
/**
 * What uninstalling the plugin removes and keeps, and deactivation cleanup.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\ProductUnitField;
use LTTech\EinvoiceMatbao\Alert\AlertStore;
use LTTech\EinvoiceMatbao\Alert\InvoiceAlerts;
use LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore;
use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshotter;
use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\Schema;
use LTTech\EinvoiceMatbao\Lark\LarkWebhook;
use LTTech\EinvoiceMatbao\Matbao\MatbaoCredentials;
use LTTech\EinvoiceMatbao\Plugin;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Uninstall\Uninstaller;
use WC_Order;
use WP_UnitTestCase;

/**
 * Runs the uninstaller against real options, order and product meta, invoice rows and Action
 * Scheduler. Dropping a real table would commit the test transaction, so the invoice tables are
 * shadowed by temporary tables here and the DROP statements are asserted.
 */
final class UninstallTest extends WP_UnitTestCase {

	private const TEST_ENCRYPTION_KEY = 'test-only-key-material-0123456789abcdef';

	/**
	 * Queries seen while the uninstaller ran.
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'einvoice_matbao_encryption_key', static fn(): string => self::TEST_ENCRYPTION_KEY );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_all_filters( 'einvoice_matbao_encryption_key' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- test fixture tables.
		$wpdb->query( $wpdb->prepare( 'DROP TEMPORARY TABLE IF EXISTS %i', Schema::invoice_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TEMPORARY TABLE IF EXISTS %i', Schema::attempt_table() ) );
		// phpcs:enable
		update_option( Schema::VERSION_OPTION, Schema::VERSION );

		parent::tear_down();
	}

	public function test_uninstaller_names_match_the_plugin_storage(): void {
		$this->assertSame( CredentialStore::OPTION, Uninstaller::CREDENTIALS_OPTION );
		$this->assertSame( Settings::OPTION, Uninstaller::SETTINGS_OPTION );
		$this->assertSame( array( BuyerRequestStore::META_KEY, BuyerSnapshotter::META_KEY ), Uninstaller::ORDER_META_KEYS );
		$this->assertSame( ProductUnitField::META_KEY, Uninstaller::PRODUCT_META_KEY );
		$this->assertSame( DraftInvoiceWorkflow::GROUP, Uninstaller::ACTION_GROUP );
		$this->assertSame( array( 'einvoice_matbao_invoice', 'einvoice_matbao_attempt' ), Uninstaller::TABLES );
	}

	public function test_default_uninstall_removes_credentials_and_caches_and_keeps_invoice_data(): void {
		$fixture = $this->store_plugin_data();
		$version = get_option( Schema::VERSION_OPTION );

		Uninstaller::run();

		$this->assertFalse( get_option( CredentialStore::OPTION ) );
		$this->assertFalse( get_transient( 'einvoice_matbao_token_abc' ) );
		$this->assertFalse( get_transient( 'einvoice_matbao_templates' ) );
		$this->assertSame( array(), $this->pending_plugin_actions() );

		$this->assertNotNull( ( new InvoiceRepository() )->find_by_order( $fixture['order']->get_id() ), 'Invoice rows are kept.' );
		$this->assertSame( array(), $this->drop_queries() );
		$order = wc_get_order( $fixture['order']->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertNotSame( '', (string) $order->get_meta( BuyerRequestStore::META_KEY ) );
		$this->assertNotSame( '', (string) $order->get_meta( BuyerSnapshotter::META_KEY ) );
		$this->assertSame( 'Thùng', get_post_meta( $fixture['product_id'], ProductUnitField::META_KEY, true ) );
		$this->assertSame( 'INV', ( new Settings() )->reference_prefix() );
		$this->assertSame( $version, get_option( Schema::VERSION_OPTION ) );
		$this->assertNotNull( AlertStore::get( AlertStore::key( $fixture['order']->get_id(), 'matbao_http', 0 ) ), 'Alert records (#7) are kept.' );
	}

	public function test_delete_all_uninstall_removes_every_plugin_record(): void {
		global $wpdb;
		$fixture = $this->store_plugin_data();
		( new Settings() )->save_delete_data_on_uninstall( true );
		update_option( 'unrelated_option', 'kept' );

		Uninstaller::run();

		$drops = implode( "\n", $this->drop_queries() );
		$this->assertStringContainsString( Schema::invoice_table(), $drops );
		$this->assertStringContainsString( Schema::attempt_table(), $drops );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the temporary shadow table is gone, so this reads the empty real table.
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::invoice_table() ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test inspects raw storage.
		$options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%einvoice\\_matbao%'" );
		$this->assertSame( array(), $options );
		$this->assertSame( 'kept', get_option( 'unrelated_option' ) );

		$this->assertSame( array(), $this->order_meta_rows() );
		$this->assertSame( '', get_post_meta( $fixture['product_id'], ProductUnitField::META_KEY, true ) );
		$this->assertSame( array(), $this->pending_plugin_actions() );
		$this->assertInstanceOf( WC_Order::class, wc_get_order( $fixture['order']->get_id() ), 'Orders themselves stay.' );
	}

	public function test_uninstall_file_runs_the_uninstaller_when_wordpress_deletes_the_plugin(): void {
		$this->store_plugin_data();
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'einvoice-matbao/einvoice-matbao.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's uninstall constant.
		}

		include dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_option( CredentialStore::OPTION ) );
	}

	public function test_uninstall_file_does_nothing_when_requested_directly(): void {
		$output = array();
		$status = 1;

		exec( 'php ' . escapeshellarg( dirname( __DIR__, 2 ) . '/uninstall.php' ) . ' 2>&1', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- runs the file outside WordPress on purpose.

		$this->assertSame( 0, $status );
		$this->assertSame( array(), $output );
	}

	public function test_deactivation_cancels_the_plugin_scheduled_actions(): void {
		DraftInvoiceWorkflow::schedule_recovery();
		as_enqueue_async_action( DraftInvoiceWorkflow::ACTION, array( 123 ), DraftInvoiceWorkflow::GROUP );
		$other = as_schedule_single_action( time() + 600, 'some_other_plugin_hook' );
		$this->assertNotSame( array(), $this->pending_plugin_actions() );

		Plugin::deactivate();

		$this->assertSame( array(), $this->pending_plugin_actions() );
		$this->assertSame( 'pending', \ActionScheduler::store()->get_status( (string) $other ) );
	}

	/**
	 * Stores one of everything the plugin keeps, and starts recording DROP statements.
	 *
	 * @return array{order: WC_Order, product_id: int}
	 */
	private function store_plugin_data(): array {
		$this->shadow_invoice_tables();

		$store = new CredentialStore();
		$store->save_matbao( new MatbaoCredentials( 'demo', '0000000000', 'fake-user', 'fake-password' ) );
		$store->save_lark( new LarkWebhook( 'https://open.larksuite.com/open-apis/bot/v2/hook/00000000-fake', 'fake-secret' ) );
		set_transient( 'einvoice_matbao_token_abc', 'v1.fake-encrypted-token', HOUR_IN_SECONDS );
		set_transient( 'einvoice_matbao_templates', array( 'account' => 'x' ), HOUR_IN_SECONDS );
		( new Settings() )->save_invoicing( 'INV', '' );

		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$alert = AlertStore::key( $order->get_id(), 'matbao_http', 0 );
		AlertStore::create( $alert, $order->get_id(), 'matbao_http', 0 );
		as_enqueue_async_action( InvoiceAlerts::SEND_ACTION, array( $alert ), DraftInvoiceWorkflow::GROUP );
		$order->update_meta_data( BuyerRequestStore::META_KEY, '{"type":"individual"}' );
		$order->update_meta_data( BuyerSnapshotter::META_KEY, '{"type":"individual"}' );
		$order->save();
		( new InvoiceRepository() )->reserve( $order->get_id(), 'INVWC' . $order->get_id(), 'Nguyễn Văn A', InvoiceStatus::PENDING );

		$product_id = self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, ProductUnitField::META_KEY, 'Thùng' );

		DraftInvoiceWorkflow::schedule_recovery();
		as_enqueue_async_action( DraftInvoiceWorkflow::ACTION, array( $order->get_id() ), DraftInvoiceWorkflow::GROUP );

		add_filter(
			'query',
			function ( string $query ): string {
				$this->queries[] = $query;

				return $query;
			}
		);

		return array(
			'order'      => $order,
			'product_id' => $product_id,
		);
	}

	/**
	 * Creates temporary copies of the invoice tables for this connection.
	 */
	private function shadow_invoice_tables(): void {
		global $wpdb;

		// The test case turns CREATE TABLE into CREATE TEMPORARY TABLE, shadowing the real table.
		foreach ( array( Schema::invoice_table(), Schema::attempt_table() ) as $table ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- test fixture DDL copied from the real table.
			$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
			$this->assertIsArray( $create );
			$wpdb->query( (string) $create[1] );
			// phpcs:enable
		}
	}

	/**
	 * DROP statements the uninstaller issued.
	 *
	 * @return list<string>
	 */
	private function drop_queries(): array {
		return array_values( array_filter( $this->queries, static fn( string $query ): bool => 1 === preg_match( '/^\s*DROP\s/i', $query ) ) );
	}

	/**
	 * Pending or running Action Scheduler actions in the plugin group.
	 *
	 * @return array<int, mixed>
	 */
	private function pending_plugin_actions(): array {
		return as_get_scheduled_actions(
			array(
				'group'    => DraftInvoiceWorkflow::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
	}

	/**
	 * Plugin order meta rows left in the legacy and HPOS meta tables.
	 *
	 * @return list<string>
	 */
	private function order_meta_rows(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- test inspects raw storage.
		$like = $wpdb->esc_like( '_einvoice_buyer_' ) . '%';
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_key FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $like ) );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders_meta' ) ) ) {
			$rows = array_merge( $rows, $wpdb->get_col( $wpdb->prepare( 'SELECT meta_key FROM %i WHERE meta_key LIKE %s', $wpdb->prefix . 'wc_orders_meta', $like ) ) );
		}
		// phpcs:enable

		return array_values( array_map( 'strval', $rows ) );
	}
}
