<?php
/**
 * Invoice error filter on the WooCommerce order list.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableQuery;
use LTTech\EinvoiceMatbao\Admin\OrderListInvoiceFilter;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use WP_Query;
use WP_UnitTestCase;

/**
 * Both order screens (HPOS list table and legacy posts list) can be narrowed to orders whose
 * invoice has an error; only Administrators get the filter.
 */
final class OrderListInvoiceFilterTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->empty_invoice_table();

		$repository = new InvoiceRepository();
		$repository->reserve( 9011, 'WC11', 'Khách lẻ', InvoiceStatus::FAILED );
		$repository->reserve( 9012, 'WC12', 'Khách lẻ', InvoiceStatus::NEEDS_ATTENTION );
		$repository->reserve( 9013, 'WC13', 'Khách lẻ', InvoiceStatus::DRAFT_CREATED );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		OrderListInvoiceFilter::register(); // Hooked only in wp-admin; the hooks are restored after each test.
	}


	/**
	 * Starts from an empty invoice table: earlier tests may have committed rows.
	 */
	private function empty_invoice_table(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}einvoice_matbao_invoice" ); // phpcs:ignore WordPress.DB -- test cleanup.
	}
	public function tear_down(): void {
		unset( $_GET[ OrderListInvoiceFilter::PARAM ] );
		$this->empty_invoice_table();
		parent::tear_down();
	}

	public function test_hpos_query_carries_the_statuses_and_keeps_other_arguments(): void {
		$_GET[ OrderListInvoiceFilter::PARAM ] = 'error';

		$args = OrderListInvoiceFilter::filter_hpos_query_args(
			array(
				'status'   => 'all',
				'post__in' => array( 9011, 9013 ),
			)
		);

		$this->assertSame( InvoiceStatus::error_statuses(), $args[ OrderListInvoiceFilter::QUERY_VAR ] );
		$this->assertSame( array( 9011, 9013 ), $args['post__in'], 'Another filter\'s post__in is left alone.' );
		$this->assertSame( 'all', $args['status'] );
	}

	public function test_hpos_orders_table_query_keeps_only_matching_orders_within_an_existing_post_in(): void {
		$synchronizer = wc_get_container()->get( DataSynchronizer::class );
		$synchronizer->create_database_tables();
		global $wpdb;
		foreach ( array( 9011, 9012, 9013, 9014 ) as $id ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- minimal HPOS rows for the query under test.
				OrdersTableDataStore::get_orders_table_name(),
				array(
					'id'               => $id,
					'status'           => 'wc-completed',
					'type'             => 'shop_order',
					'date_created_gmt' => '2026-10-01 00:00:00',
				)
			);
		}

		$query = new OrdersTableQuery(
			array(
				'type'                            => 'shop_order',
				'status'                          => array( 'wc-completed' ),
				'post__in'                        => array( 9012, 9013, 9014 ),
				'limit'                           => 2,
				'paginate'                        => true,
				OrderListInvoiceFilter::QUERY_VAR => InvoiceStatus::error_statuses(),
			)
		);

		$synchronizer->delete_database_tables();
		$this->assertSame( array( 9012 ), $query->orders );
		$this->assertSame( 1, $query->found_orders );
	}

	public function test_hpos_orders_table_query_has_no_cap_on_matching_orders(): void {
		$clauses = OrderListInvoiceFilter::filter_hpos_clauses(
			array( 'where' => '1=1' ),
			null,
			array( OrderListInvoiceFilter::QUERY_VAR => array( InvoiceStatus::FAILED ) )
		);

		$this->assertStringContainsString( 'EXISTS (SELECT 1 FROM', $clauses['where'] );
		$this->assertStringNotContainsStringIgnoringCase( 'LIMIT', $clauses['where'] );
		$this->assertSame( array( 'where' => '1=1' ), OrderListInvoiceFilter::filter_hpos_clauses( array( 'where' => '1=1' ), null, array() ) );
	}

	public function test_hpos_query_with_no_match_returns_nothing(): void {
		$_GET[ OrderListInvoiceFilter::PARAM ] = InvoiceStatus::PENDING;

		$args = OrderListInvoiceFilter::filter_hpos_query_args( array() );

		$this->assertSame( array( InvoiceStatus::PENDING ), $args[ OrderListInvoiceFilter::QUERY_VAR ] );
	}

	public function test_hpos_query_is_untouched_without_filter_or_with_unknown_value(): void {
		$this->assertSame( array( 'a' => 1 ), OrderListInvoiceFilter::filter_hpos_query_args( array( 'a' => 1 ) ) );

		$_GET[ OrderListInvoiceFilter::PARAM ] = "x' OR 1=1";
		$this->assertSame( array( 'a' => 1 ), OrderListInvoiceFilter::filter_hpos_query_args( array( 'a' => 1 ) ) );
	}

	public function test_non_administrator_does_not_get_the_filter(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$_GET[ OrderListInvoiceFilter::PARAM ] = 'error';

		$this->assertSame( array(), OrderListInvoiceFilter::filter_hpos_query_args( array() ) );
		ob_start();
		OrderListInvoiceFilter::render_dropdown();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_legacy_query_is_limited_to_orders_with_invoice_errors(): void {
		set_current_screen( 'edit-shop_order' );
		$_GET[ OrderListInvoiceFilter::PARAM ] = 'error';
		$query                                 = new WP_Query();
		$query->query_vars['post_type']        = 'shop_order';
		$GLOBALS['wp_the_query']               = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- main query for the test.

		OrderListInvoiceFilter::filter_legacy_query( $query );

		$this->assertSame( InvoiceStatus::error_statuses(), $query->get( OrderListInvoiceFilter::QUERY_VAR ) );
		$this->assertSame( '', $query->get( 'post__in' ) );
	}

	public function test_legacy_posts_query_keeps_only_matching_orders_within_an_existing_post_in(): void {
		$this->empty_invoice_table();
		$ids        = array_map( static fn(): int => wc_create_order()->get_id(), range( 1, 4 ) );
		$repository = new InvoiceRepository();
		$repository->reserve( $ids[0], 'WCA', 'Khách lẻ', InvoiceStatus::FAILED );
		$repository->reserve( $ids[1], 'WCB', 'Khách lẻ', InvoiceStatus::NEEDS_ATTENTION );
		$repository->reserve( $ids[2], 'WCC', 'Khách lẻ', InvoiceStatus::DRAFT_CREATED );

		$query = new WP_Query(
			array(
				'post_type'                       => 'shop_order',
				'post_status'                     => array_keys( wc_get_order_statuses() ),
				'post__in'                        => array( $ids[1], $ids[2], $ids[3] ),
				'fields'                          => 'ids',
				OrderListInvoiceFilter::QUERY_VAR => InvoiceStatus::error_statuses(),
			)
		);

		$this->assertSame( array( $ids[1] ), array_map( 'intval', $query->posts ) );
	}

	public function test_dropdown_lists_error_group_and_statuses_and_keeps_selection(): void {
		$_GET[ OrderListInvoiceFilter::PARAM ] = 'error';

		ob_start();
		OrderListInvoiceFilter::render_dropdown();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . OrderListInvoiceFilter::PARAM . '"', $html );
		$this->assertMatchesRegularExpression( '/<option value="error"\s+selected=\'selected\'/', $html );
		$this->assertStringContainsString( 'Lỗi cần xử lý', $html );
	}
}
