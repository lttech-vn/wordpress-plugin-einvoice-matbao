<?php
/**
 * Invoice status on the order edit screen.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use LTTech\EinvoiceMatbao\Admin\InvoicePage;
use LTTech\EinvoiceMatbao\Admin\OrderInvoiceMetaBox;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\HintText;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;

/**
 * The meta box shows status, Mat Bao invoice ID and errors for HPOS and legacy order screens.
 */
final class OrderInvoiceMetaBoxTest extends InvoiceWorkflowTestCase {

	use HintText;

	public function test_meta_box_is_registered_on_the_order_screen(): void {
		global $wp_meta_boxes;
		require_once ABSPATH . 'wp-admin/includes/template.php';
		set_current_screen( 'dashboard' );
		$screen = OrderUtil::get_order_admin_screen();

		OrderInvoiceMetaBox::add_meta_box();

		$this->assertArrayHasKey( OrderInvoiceMetaBox::ID, $wp_meta_boxes[ $screen ]['side']['default'] ?? array() );
	}

	public function test_shows_draft_status_and_invoice_id(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok( 'ZmFrZS1pZC1tZXRh' );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$this->complete_and_run( $order );

		$html = $this->render( $order );

		$this->assertStringContainsString( 'data-einv-invoice-status="draft_created"', $html );
		$this->assertStringContainsString( 'Đã tạo nháp', $html );
		$this->assertStringContainsString( 'ZmFrZS1pZC1tZXRh', $html );
		$this->assertStringContainsString( 'WC' . $order->get_id(), $html );
	}

	public function test_shows_the_error_escaped(): void {
		$this->configure_matbao();
		$this->http->respond_json( self::CREATE_ROUTE, 400, array( 'message' => '<b>Lỗi</b> hệ thống' ) );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$this->complete_and_run( $order );

		$html = $this->render( $order );

		$this->assertStringContainsString( 'data-einv-invoice-status="failed"', $html );
		$this->assertStringContainsString( '&lt;b&gt;Lỗi&lt;/b&gt; hệ thống', $html );
	}

	public function test_error_with_hint(): void {
		$order      = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$repository = new InvoiceRepository();
		$this->assertTrue( $repository->reserve( $order->get_id(), 'WC' . $order->get_id(), 'Khách lẻ', InvoiceStatus::PENDING, '' ) );
		$invoice = $repository->find_by_order( $order->get_id() );
		$this->assertNotNull( $invoice );
		$repository->record_error( $invoice->id, InvoiceErrorCode::LINE_TAX_RATE_COUNT, 'Dòng cần đúng một thuế suất', InvoiceStatus::NEEDS_ATTENTION );

		$html = $this->render( $order );

		$hint = InvoiceErrorCode::LINE_TAX_RATE_COUNT->hint();
		$this->assertSame( $hint, $this->meta_box_hint_text( $html ) );
		$this->assertStringContainsString( 'data-einv-hint="' . esc_attr( $hint ) . '"', $html );
	}

	public function test_zero_total_order_shows_the_skip_reason_and_no_create_button(): void {
		$this->configure_matbao();
		$order = $this->create_order( array( array( $this->create_product( 'Miễn phí', '0' ), 1 ) ) );
		$order->update_status( 'completed' );

		$html = $this->render( $order );

		$this->assertStringContainsString( 'data-einv-invoice-status="skipped"', $html );
		$this->assertStringContainsString( '0 đồng', $html );
		$this->assertStringNotContainsString( '<button', $html );
	}

	public function test_order_without_invoice_says_so(): void {
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$html = $this->render( $order );

		$this->assertStringContainsString( 'data-einv-invoice-status="none"', $html );
	}

	public function test_issued_invoice(): void {
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$id    = $this->synced_invoice( $order );

		$html = $this->render( $order );

		$this->assertStringContainsString( 'Đã cấp mã/tiếp nhận', $html );
		$this->assertStringContainsString( '125', $html );
		$this->assertStringContainsString( '00ABC', $html );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_matbao_text_is_escaped(): void {
		global $wpdb;
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$id    = $this->synced_invoice( $order );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test setup.
			$wpdb->prefix . 'einvoice_matbao_invoice',
			array(
				'matbao_status'      => 12,
				'matbao_status_name' => '<img src=x onerror=alert(1)>',
				'tax_authority_code' => '<b>x</b>',
				'tax_note'           => '<script>alert(2)</script>',
			),
			array( 'id' => $id )
		);

		$html = $this->render( $order );

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<b>x', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
	}

	public function test_admin_sees_invoice_link(): void {
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$id    = $this->synced_invoice( $order );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render( $order );

		$this->assertStringContainsString( 'href="' . esc_url( InvoicePage::detail_url( $id ) ) . '"', $html );
	}

	public function test_shop_manager_has_no_invoice_link(): void {
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$this->synced_invoice( $order );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$html = $this->render( $order );

		$this->assertStringNotContainsString( 'tab=invoices', $html );
		$this->assertStringContainsString( 'Đã cấp mã/tiếp nhận', $html );
	}

	/**
	 * Reserves a draft_created invoice already issued on Mat Bao.
	 *
	 * @param WC_Order $order Order.
	 * @return int Invoice ID.
	 */
	private function synced_invoice( WC_Order $order ): int {
		global $wpdb;

		$repository = new InvoiceRepository();
		$this->assertTrue( $repository->reserve( $order->get_id(), 'WC' . $order->get_id(), 'Khách lẻ', InvoiceStatus::DRAFT_CREATED, '' ) );
		$invoice = $repository->find_by_order( $order->get_id() );
		$this->assertNotNull( $invoice );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test setup.
			$wpdb->prefix . 'einvoice_matbao_invoice',
			array(
				'matbao_status'      => 4,
				'invoice_number'     => 125,
				'tax_authority_code' => '00ABC',
				'matbao_synced_at'   => '2026-01-02 03:04:05',
			),
			array( 'id' => $invoice->id )
		);

		return $invoice->id;
	}

	/**
	 * Renders the meta box for an order, as WooCommerce passes it on the HPOS screen.
	 *
	 * @param WC_Order $order Order.
	 */
	private function render( WC_Order $order ): string {
		ob_start();
		OrderInvoiceMetaBox::render( $order );

		return (string) ob_get_clean();
	}
}
