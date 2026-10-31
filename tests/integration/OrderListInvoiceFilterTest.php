<?php
/**
 * Invoice error filter on the WooCommerce order list.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

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

	public function test_hpos_query_is_limited_to_orders_with_invoice_errors(): void {
		$_GET[ OrderListInvoiceFilter::PARAM ] = 'error';

		$args = OrderListInvoiceFilter::filter_hpos_query_args( array( 'status' => 'all' ) );

		$this->assertEqualsCanonicalizing( array( 9011, 9012 ), $args['post__in'] );
		$this->assertSame( 'all', $args['status'] );
	}

	public function test_hpos_query_with_no_match_returns_nothing(): void {
		$_GET[ OrderListInvoiceFilter::PARAM ] = InvoiceStatus::PENDING;

		$args = OrderListInvoiceFilter::filter_hpos_query_args( array() );

		$this->assertSame( array( 0 ), $args['post__in'] );
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

		$this->assertEqualsCanonicalizing( array( 9011, 9012 ), $query->get( 'post__in' ) );
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
