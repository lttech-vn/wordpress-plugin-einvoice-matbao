<?php
/**
 * Invoice status on the order edit screen.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use LTTech\EinvoiceMatbao\Admin\OrderInvoiceMetaBox;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;

/**
 * The meta box shows status, Mat Bao invoice ID and errors for HPOS and legacy order screens.
 */
final class OrderInvoiceMetaBoxTest extends InvoiceWorkflowTestCase {

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
