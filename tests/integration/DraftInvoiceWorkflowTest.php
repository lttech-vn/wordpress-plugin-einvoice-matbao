<?php
/**
 * Completed orders create one Mat Bao draft invoice for a walk-in buyer.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Coupon;
use WC_Order_Item_Fee;
use WC_Order_Item_Shipping;

/**
 * The workflow seam: real WooCommerce orders and Action Scheduler, Mat Bao faked over HTTP.
 */
final class DraftInvoiceWorkflowTest extends InvoiceWorkflowTestCase {

	public function test_completed_order_creates_one_draft_for_the_walk_in_buyer(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok( 'ZmFrZS1pZC0x' );
		$order = $this->create_order( array( array( $this->create_product( 'Gói dịch vụ A', '100000', '', 'taxable', 'SKU-A' ), 1 ) ) );

		$this->complete_and_run( $order );

		$sent = $this->sent_invoices();
		$this->assertCount( 1, $sent );
		$invoice = $sent[0];
		$today   = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Ho_Chi_Minh' ) ) )->format( 'Y-m-d' );

		$this->assertSame( 0, $invoice['LoaiHDon'] );
		$this->assertSame( 1, $invoice['KTraMTChieuTrung'] );
		$this->assertSame( 0, $invoice['TCHDon'] );
		$this->assertSame( 704, $invoice['DVTTe'] );
		$this->assertSame( 1, $invoice['TGia'] );
		$this->assertSame( self::TEMPLATE_FORM, $invoice['KHMSHDon'] );
		$this->assertSame( self::TEMPLATE_SERIES, $invoice['KHHDon'] );
		$this->assertSame( $today . 'T00:00:00', $invoice['NLap'] );
		$this->assertSame( 'WC' . $order->get_id(), $invoice['MTChieu'] );
		$this->assertSame( 'Khách hàng không lấy hóa đơn', $invoice['NMua_Ten'] );
		$this->assertSame( array( 'NMua_Ten' ), array_values( array_filter( array_keys( $invoice ), static fn( string $key ): bool => str_starts_with( $key, 'NMua_' ) ) ) );
		$this->assertArrayNotHasKey( 'MaTraCuu', $invoice );

		$this->assertSame(
			array(
				array(
					'TChat'        => 1,
					'STT'          => 1,
					'MHHDVu'       => 'SKU-A',
					'THHDVu'       => 'Gói dịch vụ A',
					'DVTinh'       => 'Gói',
					'SLuong'       => 1,
					'DGia'         => 100000,
					'ThTienChuaCK' => 100000,
					'TLCKhau'      => 0,
					'STCKhau'      => 0,
					'ThTien'       => 100000,
					'TSuat'        => 10,
					'TThue'        => 10000,
					'TgTien'       => 110000,
				),
			),
			$invoice['DSHHDVu']
		);
		$this->assertSame( 100000, $invoice['TgThTien'] );
		$this->assertSame( 10000, $invoice['TgTThue'] );
		$this->assertSame( 110000, $invoice['TgTTTBSo'] );
		$this->assertSame( 0, $invoice['TTCKTMai'] );
		$this->assertSame( 0, $invoice['TGTKhac'] );
		$this->assertSame( 'Một trăm mười nghìn đồng', $invoice['TgTTTBChu'] );

		$record = $this->invoice_of( $order );
		$this->assertNotNull( $record );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $record->status );
		$this->assertSame( 'ZmFrZS1pZC0x', $record->matbao_invoice_id );
		$this->assertSame( 'WC' . $order->get_id(), $record->reference );
		$this->assertSame( $today, $record->issued_on );

		$attempts = $this->attempts_of( $record );
		$this->assertCount( 1, $attempts );
		$this->assertSame( 'success', $attempts[0]['outcome'] );
	}

	public function test_reference_uses_the_prefix_saved_when_the_order_completed(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		( new Settings() )->save_invoicing( 'SHOP', '' );
		$order = $this->create_order( array( array( $this->create_product( 'A', '50000' ), 2 ) ) );

		$order->update_status( 'completed' );
		( new Settings() )->save_invoicing( 'OTHER', '' );
		$this->run_queued_draft_actions();

		$this->assertSame( 'SHOPWC' . $order->get_id(), $this->sent_invoices()[0]['MTChieu'] );
		$this->assertSame( 'SHOPWC' . $order->get_id(), $this->invoice_of( $order )?->reference );
	}

	public function test_completing_the_order_again_does_not_create_another_invoice(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );
		$order->update_status( 'processing' );
		$this->complete_and_run( $order );

		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
	}

	public function test_two_completion_hooks_for_one_order_reserve_once_and_queue_one_action(): void {
		$this->configure_matbao();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_status( 'completed' );
		$order->save();
		$this->run_queued_draft_actions();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test resets the reservation on purpose.
		$wpdb->delete( $wpdb->prefix . 'einvoice_matbao_invoice', array( 'order_id' => $order->get_id() ) );

		do_action( 'woocommerce_order_status_completed', $order->get_id(), $order );
		do_action( 'woocommerce_order_status_completed', $order->get_id(), $order );

		$this->assertCount( 1, $this->queued_draft_action_ids() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test inspects raw storage on purpose.
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}einvoice_matbao_invoice WHERE order_id = %d", $order->get_id() ) ) );
	}

	public function test_running_the_draft_action_twice_sends_once(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->update_status( 'completed' );

		do_action( DraftInvoiceWorkflow::ACTION, $order->get_id() );
		do_action( DraftInvoiceWorkflow::ACTION, $order->get_id() );

		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
	}

	public function test_zero_total_order_is_skipped_with_a_reason_and_nothing_is_sent(): void {
		$this->configure_matbao();
		$order = $this->create_order( array( array( $this->create_product( 'Miễn phí', '0' ), 1 ) ) );

		$order->update_status( 'completed' );

		$this->assertSame( array(), $this->queued_draft_action_ids() );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$record = $this->invoice_of( $order );
		$this->assertNotNull( $record );
		$this->assertSame( InvoiceStatus::SKIPPED, $record->status );
		$this->assertStringContainsString( '0 đồng', $record->status_reason );
	}

	public function test_coupon_lines_are_sent_at_the_discounted_price_without_discount_lines(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$coupon = new WC_Coupon();
		$coupon->set_code( 'einv10' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();
		$order = $this->create_order( array( array( $this->create_product( 'A', '200000' ), 3 ) ) );
		$order->apply_coupon( 'einv10' );
		$order->save();

		$this->complete_and_run( $order );

		$invoice = $this->sent_invoices()[0];
		$this->assertCount( 1, $invoice['DSHHDVu'] );
		$line = $invoice['DSHHDVu'][0];
		$this->assertSame( 3, $line['SLuong'] );
		$this->assertSame( 180000, $line['DGia'] );
		$this->assertSame( 540000, $line['ThTienChuaCK'] );
		$this->assertSame( 540000, $line['ThTien'] );
		$this->assertSame( 0, $line['TLCKhau'] );
		$this->assertSame( 0, $line['STCKhau'] );
		$this->assertSame( 54000, $line['TThue'] );
		$this->assertSame( 594000, $invoice['TgTTTBSo'] );
		$this->assertSame( (float) $order->get_total(), (float) $invoice['TgTTTBSo'] );
	}

	public function test_rates_map_to_tsuat_and_untaxed_products_are_kct(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order(
			array(
				array( $this->create_product( 'Mười', '100000' ), 1 ),
				array( $this->create_product( 'Năm', '100000', self::CLASS_FIVE ), 1 ),
				array( $this->create_product( 'Tám', '100000', self::CLASS_EIGHT ), 1 ),
				array( $this->create_product( 'Không', '100000', self::CLASS_ZERO ), 1 ),
				array( $this->create_product( 'KCT', '100000', '', 'none' ), 1 ),
			)
		);

		$this->complete_and_run( $order );

		$lines = $this->sent_invoices()[0]['DSHHDVu'];
		$this->assertSame( array( 10, 5, 8, 0, -1 ), array_column( $lines, 'TSuat' ) );
		$this->assertSame( array( 10000, 5000, 8000, 0, 0 ), array_column( $lines, 'TThue' ) );
		$this->assertSame( array( 1, 2, 3, 4, 5 ), array_column( $lines, 'STT' ) );
		$this->assertSame( 23000, $this->sent_invoices()[0]['TgTThue'] );
		$this->assertSame( 523000, $this->sent_invoices()[0]['TgTTTBSo'] );
	}

	public function test_fees_and_shipping_are_separate_lines_and_totals_match_the_order_total(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Phí xử lý' );
		$fee->set_amount( '20000' );
		$fee->set_total( '20000' );
		$fee->set_tax_status( 'taxable' );
		$order->add_item( $fee );
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Giao hàng' );
		$shipping->set_total( '30000' );
		$order->add_item( $shipping );
		$order->calculate_totals();
		$order->save();

		$this->complete_and_run( $order );

		$invoice = $this->sent_invoices()[0];
		$this->assertSame( array( 'A', 'Phí xử lý', 'Giao hàng' ), array_column( $invoice['DSHHDVu'], 'THHDVu' ) );
		$this->assertSame( array( 100000, 20000, 30000 ), array_column( $invoice['DSHHDVu'], 'ThTien' ) );
		$this->assertSame( array( 10, 10, 10 ), array_column( $invoice['DSHHDVu'], 'TSuat' ) );
		$this->assertSame( 150000, $invoice['TgThTien'] );
		$this->assertSame( 15000, $invoice['TgTThue'] );
		$this->assertSame( 165000, $invoice['TgTTTBSo'] );
		$this->assertSame( (float) $order->get_total(), (float) $invoice['TgTTTBSo'] );
	}

	public function test_unusual_tax_rate_is_not_sent_and_marks_the_invoice_failed(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'Bảy', '100000', self::CLASS_SEVEN ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::FAILED, $record?->status );
		$this->assertStringContainsString( '7', (string) $record?->status_reason );
		$this->assertSame( array(), $this->attempts_of( $record ) );
	}

	public function test_line_totals_that_do_not_match_the_order_total_are_not_sent(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_total( '120000' );
		$order->save();

		$this->complete_and_run( $order );

		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::FAILED, $this->invoice_of( $order )?->status );
	}

	public function test_non_vnd_order_is_not_sent(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_currency( 'USD' );
		$order->save();

		$this->complete_and_run( $order );

		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::FAILED, $this->invoice_of( $order )?->status );
	}

	public function test_without_credentials_nothing_is_sent_and_status_is_not_configured(): void {
		$this->with_encryption_key();
		( new Settings() )->save_default_template( self::TEMPLATE_FORM, self::TEMPLATE_SERIES );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( InvoiceStatus::NOT_CONFIGURED, $this->invoice_of( $order )?->status );
	}

	public function test_without_a_default_template_nothing_is_sent_and_status_is_not_configured(): void {
		$this->login_as( 'administrator' );
		$this->with_encryption_key();
		$this->save_matbao_credentials();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( InvoiceStatus::NOT_CONFIGURED, $this->invoice_of( $order )?->status );
	}

	public function test_http_error_records_a_failed_attempt_and_is_not_retried(): void {
		$this->configure_matbao();
		$this->http->respond_json( self::CREATE_ROUTE, 500, array( 'message' => 'Lỗi hệ thống' ) );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::FAILED, $record?->status );
		$attempts = $this->attempts_of( $record );
		$this->assertCount( 1, $attempts );
		$this->assertSame( 'failed', $attempts[0]['outcome'] );
		$this->assertSame( 'http', $attempts[0]['error_kind'] );
		$this->assertSame( '500', (string) $attempts[0]['http_status'] );
	}

	public function test_timeout_records_a_failed_attempt_with_unknown_outcome_kind(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_timeout();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::FAILED, $record?->status );
		$attempts = $this->attempts_of( $record );
		$this->assertCount( 1, $attempts );
		$this->assertSame( 'failed', $attempts[0]['outcome'] );
		$this->assertSame( 'transport', $attempts[0]['error_kind'] );
	}

	public function test_rejected_invoice_in_a_successful_envelope_is_a_failure(): void {
		$this->configure_matbao();
		$this->http->respond_json(
			self::CREATE_ROUTE,
			200,
			array(
				'errorCode' => 200,
				'message'   => 'Thành công',
				'data'      => array(
					array(
						'errorCode' => 305,
						'message'   => 'Trùng mã tham chiếu',
						'data'      => null,
						'success'   => false,
					),
				),
				'success'   => true,
			)
		);
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::FAILED, $record?->status );
		$this->assertStringContainsString( 'Trùng mã tham chiếu', (string) $record?->status_reason );
		$this->assertSame( '305', (string) $this->attempts_of( $record )[0]['api_code'] );
	}

	public function test_order_that_left_completed_before_sending_is_stopped(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$order->update_status( 'completed' );
		$order->update_status( 'cancelled' );
		$this->run_queued_draft_actions();

		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::STOPPED, $record?->status );
		$this->assertNotSame( '', $record?->status_reason );
	}

	public function test_refunded_order_is_stopped_before_sending(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$order->update_status( 'completed' );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '10000',
			)
		);
		$this->run_queued_draft_actions();

		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::STOPPED, $this->invoice_of( $order )?->status );
	}

	public function test_orders_completed_before_activation_are_not_scanned(): void {
		remove_action( 'woocommerce_order_status_completed', array( DraftInvoiceWorkflow::class, 'on_order_completed' ) );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->update_status( 'completed' );
		add_action( 'woocommerce_order_status_completed', array( DraftInvoiceWorkflow::class, 'on_order_completed' ), 10, 2 );

		deactivate_plugins( 'einvoice-matbao/einvoice-matbao.php', true );
		activate_plugin( 'einvoice-matbao/einvoice-matbao.php' );

		$this->assertNull( $this->invoice_of( $order ) );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
	}
}
