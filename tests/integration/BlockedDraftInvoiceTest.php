<?php
/**
 * Bad data, missing configuration and order changes stop the draft with a classified error.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\OrderInvoiceMetaBox;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Tax;

/**
 * The workflow seam: real WooCommerce orders and Action Scheduler, Mat Bao faked over HTTP.
 * Every blocked case keeps the order `completed` (unless the shop changed it), sends nothing,
 * records a stable error code and kind, and shows the reason in the order meta box.
 */
final class BlockedDraftInvoiceTest extends InvoiceWorkflowTestCase {

	/**
	 * Calls of the needs-attention hook.
	 *
	 * @var list<array{0: InvoiceRecord, 1: InvoiceErrorCode}>
	 */
	private array $attention = array();

	public function set_up(): void {
		parent::set_up();

		$this->attention = array();
		add_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION, array( $this, 'record_attention' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION, array( $this, 'record_attention' ), 10 );

		parent::tear_down();
	}

	/**
	 * Hook listener.
	 *
	 * @param InvoiceRecord    $invoice Invoice after the error was recorded.
	 * @param InvoiceErrorCode $code    Error code.
	 */
	public function record_attention( InvoiceRecord $invoice, InvoiceErrorCode $code ): void {
		$this->attention[] = array( $invoice, $code );
	}

	public function test_unusual_tax_rate_blocks_the_draft(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'Bảy', '100000', self::CLASS_SEVEN ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assert_blocked( $order, InvoiceErrorCode::TAX_RATE_UNSUPPORTED, 'data', InvoiceStatus::FAILED, '7%' );
	}

	public function test_line_with_several_tax_rates_blocks_the_draft(): void {
		$this->create_tax_rate( 'einv-double', 5 );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => '5',
				'tax_rate_name'     => 'Second 5',
				'tax_rate_priority' => 2,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => 'einv-double',
			)
		);
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'Hai thuế', '100000', 'einv-double' ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assert_blocked( $order, InvoiceErrorCode::LINE_TAX_RATE_COUNT, 'data', InvoiceStatus::FAILED, 'đúng một thuế suất' );
	}

	public function test_line_totals_that_differ_from_the_order_total_block_the_draft(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_total( '120000' );
		$order->save();

		$this->complete_and_run( $order );

		$this->assert_blocked( $order, InvoiceErrorCode::TOTALS_MISMATCH, 'data', InvoiceStatus::FAILED, 'không khớp' );
	}

	public function test_fractional_money_blocks_the_draft(): void {
		update_option( 'woocommerce_price_num_decimals', '2' );
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'Lẻ', '100.5', self::CLASS_ZERO ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assert_blocked( $order, InvoiceErrorCode::AMOUNT_NOT_WHOLE_VND, 'data', InvoiceStatus::FAILED, 'số nguyên' );
	}

	public function test_non_vnd_currency_blocks_the_draft(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_currency( 'USD' );
		$order->save();

		$this->complete_and_run( $order );

		$this->assert_blocked( $order, InvoiceErrorCode::CURRENCY_NOT_VND, 'data', InvoiceStatus::FAILED, 'USD' );
	}

	public function test_reference_longer_than_20_characters_blocks_the_draft_without_queueing(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		// Bypasses the settings validation, as an older or edited option could.
		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION, array() ), array( 'reference_prefix' => 'ABCDEFGHIJKLMNOPQR' ) ) );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$order->update_status( 'completed' );

		$this->assertSame( array(), $this->queued_draft_action_ids() );
		$this->assert_blocked( $order, InvoiceErrorCode::REFERENCE_TOO_LONG, 'data', InvoiceStatus::FAILED, '20 ký tự' );
	}

	public function test_template_no_longer_offered_by_matbao_blocks_the_draft(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		( new Settings() )->save_default_template( '1', 'C25TAT' );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$year      = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Ho_Chi_Minh' ) ) )->format( 'Y' );
		$templates = $this->http->requests_to( 'GET /api/invoice/templates' );
		$this->assertCount( 1, $templates );
		$this->assertStringContainsString( 'year=' . $year, $templates[0]['url'] );
		$this->assert_blocked( $order, InvoiceErrorCode::TEMPLATE_UNAVAILABLE, 'config', InvoiceStatus::NOT_CONFIGURED, 'C25TAT' );
	}

	public function test_template_without_remaining_numbers_blocks_the_draft(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		( new Settings() )->save_default_template( '1', 'C26TXX' );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assert_blocked( $order, InvoiceErrorCode::TEMPLATE_UNAVAILABLE, 'config', InvoiceStatus::NOT_CONFIGURED, 'C26TXX' );
	}

	public function test_template_rejected_by_matbao_on_create_is_a_configuration_error(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_rejected( 308, 'Không tìm thấy mẫu số kí hiệu' );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$record = $this->assert_classified( $order, InvoiceErrorCode::TEMPLATE_REJECTED, 'config', InvoiceStatus::NOT_CONFIGURED, 'mẫu' );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$attempts = $this->attempts_of( $record );
		$this->assertCount( 1, $attempts );
		$this->assertSame( '308', (string) $attempts[0]['api_code'] );
	}

	public function test_missing_credentials_block_the_draft(): void {
		$this->with_encryption_key();
		( new Settings() )->save_default_template( self::TEMPLATE_FORM, self::TEMPLATE_SERIES );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assertSame( array(), $this->http->requests );
		$this->assert_blocked( $order, InvoiceErrorCode::CREDENTIALS_MISSING, 'config', InvoiceStatus::NOT_CONFIGURED, 'kết nối' );
	}

	public function test_missing_default_template_blocks_the_draft(): void {
		$this->login_as( 'administrator' );
		$this->with_encryption_key();
		$this->save_matbao_credentials();
		$this->http->requests = array();
		$order                = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assertSame( array(), $this->http->requests );
		$this->assert_blocked( $order, InvoiceErrorCode::TEMPLATE_MISSING, 'config', InvoiceStatus::NOT_CONFIGURED, 'mẫu' );
	}

	public function test_order_cancelled_before_sending_is_stopped_without_attention(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$order->update_status( 'completed' );
		$order->update_status( 'cancelled' );
		$this->run_queued_draft_actions();

		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::STOPPED, $record?->status );
		$this->assertSame( 'order_not_completed', $record?->error_code );
		$this->assertSame( 'order', $record?->error_kind );
		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( (string) $record?->status_reason, $this->render_meta_box( $order ) );
		$this->assertSame( array(), $this->attention );
	}

	public function test_order_refunded_before_sending_is_stopped_and_needs_attention(): void {
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

		$this->assert_blocked( $order, InvoiceErrorCode::ORDER_REFUNDED, 'order', InvoiceStatus::STOPPED, 'hoàn tiền' );
	}

	public function test_matbao_http_5xx_is_a_temporary_matbao_error(): void {
		$this->configure_matbao();
		$this->http->respond_json( self::CREATE_ROUTE, 500, array( 'message' => 'Lỗi hệ thống' ) );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$record = $this->assert_classified( $order, InvoiceErrorCode::MATBAO_HTTP_TEMPORARY, 'matbao', InvoiceStatus::FAILED, 'Lỗi hệ thống' );
		$this->assertSame( 'matbao_http_temporary', $record->error_code );
		$this->assertTrue( $record->error()?->is_auto_retryable() );
	}

	public function test_matbao_http_4xx_is_a_matbao_error_that_is_not_retried(): void {
		$this->configure_matbao();
		$this->http->respond_json( self::CREATE_ROUTE, 400, array( 'message' => 'Dữ liệu không hợp lệ' ) );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$record = $this->assert_classified( $order, InvoiceErrorCode::MATBAO_HTTP, 'matbao', InvoiceStatus::FAILED, 'Dữ liệu không hợp lệ' );
		$this->assertSame( 'matbao_http', $record->error_code );
		$this->assertFalse( $record->error()?->is_auto_retryable() );
	}

	public function test_invoice_rejected_by_matbao_is_a_matbao_api_error_that_is_not_retried(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_rejected( 400, 'Sai thông tin hóa đơn' );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$record = $this->assert_classified( $order, InvoiceErrorCode::MATBAO_API, 'matbao', InvoiceStatus::FAILED, 'Sai thông tin hóa đơn' );
		$this->assertSame( 'matbao_api', $record->error_code );
		$this->assertFalse( $record->error()?->is_auto_retryable() );
	}

	public function test_template_out_of_numbers_on_create_is_a_configuration_error(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_rejected( 327, 'Mẫu hóa đơn đã hết số' );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$record = $this->assert_classified( $order, InvoiceErrorCode::TEMPLATE_REJECTED, 'config', InvoiceStatus::NOT_CONFIGURED, 'hết số' );
		$this->assertSame( '327', (string) $this->attempts_of( $record )[0]['api_code'] );
	}

	public function test_order_without_lines_blocks_the_draft(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array() );
		$order->set_total( '100000' );
		$order->save();

		$this->complete_and_run( $order );

		$this->assert_blocked( $order, InvoiceErrorCode::NO_LINES, 'data', InvoiceStatus::FAILED, 'không có dòng' );
	}

	public function test_unsupported_line_type_blocks_the_draft(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order           = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$add_coupon_line = static function ( array $items, WC_Order $filtered, array $types ) use ( $order ): array {
			if ( $filtered->get_id() === $order->get_id() && in_array( 'fee', $types, true ) ) {
				$items[] = new \WC_Order_Item_Coupon();
			}
			return $items;
		};
		add_filter( 'woocommerce_order_get_items', $add_coupon_line, 10, 3 );

		$this->complete_and_run( $order );

		remove_filter( 'woocommerce_order_get_items', $add_coupon_line, 10 );
		$this->assert_blocked( $order, InvoiceErrorCode::LINE_TYPE_UNSUPPORTED, 'data', InvoiceStatus::FAILED, 'loại dòng' );
	}

	public function test_negative_line_amount_blocks_the_draft(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Giảm tay' );
		$fee->set_total( '-10000' );
		$fee->set_tax_status( 'none' );
		$order->add_item( $fee );
		$order->calculate_totals( false );
		$order->save();

		$this->complete_and_run( $order );

		$this->assert_blocked( $order, InvoiceErrorCode::LINE_AMOUNT_INVALID, 'data', InvoiceStatus::FAILED, 'Giảm tay' );
	}

	public function test_untaxed_line_with_tax_blocks_the_draft(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$taxed = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $taxed );
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Phí lạ' );
		$fee->set_total( '10000' );
		$fee->set_tax_status( 'none' );
		$fee->set_taxes( array( 'total' => $taxed->get_taxes()['total'] ) );
		$order->add_item( $fee );
		$order->save();

		$this->complete_and_run( $order );

		$this->assert_blocked( $order, InvoiceErrorCode::UNTAXED_LINE_WITH_TAX, 'data', InvoiceStatus::FAILED, 'Phí lạ' );
	}

	public function test_line_whose_rate_percent_is_not_stored_blocks_the_draft(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		// The line keeps its tax by rate ID, but the order has no tax item for that rate.
		foreach ( $order->get_taxes() as $tax ) {
			$order->remove_item( $tax->get_id() );
		}
		$order->save();
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( array(), $order->get_taxes() );

		$this->complete_and_run( $order );

		$this->assert_blocked( $order, InvoiceErrorCode::TAX_RATE_NOT_STORED, 'data', InvoiceStatus::FAILED, 'thuế suất đã lưu' );
	}

	public function test_failing_to_queue_the_action_is_a_system_error(): void {
		$this->configure_matbao();
		add_filter( 'pre_as_enqueue_async_action', static fn(): int => 0 );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$order->update_status( 'completed' );

		$record = $this->assert_classified( $order, InvoiceErrorCode::QUEUE_FAILED, 'system', InvoiceStatus::FAILED, 'hàng đợi' );
		$this->assertFalse( $record->error()?->is_auto_retryable() );
	}

	public function test_unexpected_exception_is_a_system_error(): void {
		$this->configure_matbao();
		$this->http->respond_with(
			self::CREATE_ROUTE,
			static function (): never {
				throw new \RuntimeException( 'boom' );
			}
		);
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$record = $this->assert_classified( $order, InvoiceErrorCode::UNEXPECTED, 'system', InvoiceStatus::FAILED, 'không xác định' );
		$this->assertFalse( $record->error()?->is_auto_retryable() );
	}

	public function test_templates_call_that_does_not_complete_is_a_matbao_error_and_nothing_is_created(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$this->http->respond_with( 'GET /api/invoice/templates', static fn(): \WP_Error => new \WP_Error( 'http_request_failed', 'cURL error 28' ) );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$record = $this->assert_classified( $order, InvoiceErrorCode::MATBAO_TRANSPORT, 'matbao', InvoiceStatus::FAILED, '' );
		$this->assertTrue( $record->error()?->is_auto_retryable() );
	}

	public function test_data_errors_are_not_retried(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'Bảy', '100000', self::CLASS_SEVEN ), 1 ) ) );

		$this->complete_and_run( $order );
		do_action( 'einvoice_matbao_create_draft', $order->get_id() );
		$this->run_queued_draft_actions();

		$record = $this->invoice_of( $order );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( 'data', $record?->error_kind );
		$this->assertFalse( $record?->error()?->is_auto_retryable() );
		$this->assertCount( 1, $this->attention );
	}

	public function test_successful_draft_has_no_error_and_no_attention(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $record?->status );
		$this->assertSame( '', $record?->error_code );
		$this->assertSame( '', $record?->error_kind );
		$this->assertSame( array(), $this->attention );
	}

	/**
	 * Asserts a case blocked before any create call: nothing sent, nothing queued, order still
	 * `completed`, error classified, shown in the meta box and announced once.
	 *
	 * @param WC_Order         $order    Order.
	 * @param InvoiceErrorCode $code     Expected error code.
	 * @param string           $kind     Expected stored kind.
	 * @param string           $status   Expected InvoiceStatus.
	 * @param string           $fragment Text the reason must contain.
	 */
	private function assert_blocked( WC_Order $order, InvoiceErrorCode $code, string $kind, string $status, string $fragment ): void {
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$record = $this->assert_classified( $order, $code, $kind, $status, $fragment );
		$this->assertSame( array(), $this->attempts_of( $record ) );
	}

	/**
	 * Asserts the recorded error, the unchanged order status, the meta box and the hook.
	 *
	 * @param WC_Order         $order    Order.
	 * @param InvoiceErrorCode $code     Expected error code.
	 * @param string           $kind     Expected stored kind.
	 * @param string           $status   Expected InvoiceStatus.
	 * @param string           $fragment Text the reason must contain.
	 */
	private function assert_classified( WC_Order $order, InvoiceErrorCode $code, string $kind, string $status, string $fragment ): InvoiceRecord {
		$record = $this->invoice_of( $order );
		$this->assertNotNull( $record );
		$this->assertSame( $status, $record->status );
		$this->assertSame( $code->value, $record->error_code );
		$this->assertSame( $kind, $record->error_kind );
		$this->assertNotSame( '', $record->status_reason );
		$this->assertStringContainsString( $fragment, $record->status_reason );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );

		$html = $this->render_meta_box( $order );
		$this->assertStringContainsString( 'data-einv-invoice-status="' . $status . '"', $html );
		$this->assertStringContainsString( 'data-einv-error-code="' . $code->value . '"', $html );
		$this->assertStringContainsString( 'data-einv-error-kind="' . $kind . '"', $html );
		$this->assertStringContainsString( esc_html( $record->status_reason ), $html );
		$this->assertStringContainsString( esc_html( $code->kind()->label() ), $html );

		$this->assertCount( 1, $this->attention );
		$this->assertSame( $record->id, $this->attention[0][0]->id );
		$this->assertSame( $code, $this->attention[0][1] );

		return $record;
	}

	/**
	 * Makes create-invoice reject the invoice inside a successful envelope.
	 *
	 * @param int    $api_code Mat Bao item `errorCode`.
	 * @param string $message  Mat Bao item message.
	 */
	private function fake_create_invoice_rejected( int $api_code, string $message ): void {
		$this->http->respond_json(
			self::CREATE_ROUTE,
			200,
			array(
				'errorCode' => 200,
				'message'   => 'Thành công',
				'data'      => array(
					array(
						'errorCode' => $api_code,
						'message'   => $message,
						'data'      => null,
						'success'   => false,
					),
				),
				'success'   => true,
			)
		);
	}

	/**
	 * Renders the order meta box.
	 *
	 * @param WC_Order $order Order.
	 */
	private function render_meta_box( WC_Order $order ): string {
		ob_start();
		OrderInvoiceMetaBox::render( $order );

		return (string) ob_get_clean();
	}
}
