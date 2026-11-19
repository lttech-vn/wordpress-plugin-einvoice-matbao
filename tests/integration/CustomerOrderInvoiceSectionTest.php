<?php
/**
 * Invoice block on the customer's order page.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Customer\InvoicePdfDownload;
use LTTech\EinvoiceMatbao\Customer\OrderInvoiceSection;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\Schema;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_DateTime;
use WC_Order;

/**
 * Renders the block the way the view-order endpoint does, and checks what the buyer may see.
 */
final class CustomerOrderInvoiceSectionTest extends InvoiceWorkflowTestCase {

	private const REFERENCE = 'WC-REF-7741';

	/**
	 * Saved `$wp->query_vars`, restored in tear_down.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved_query_vars = array();

	public function set_up(): void {
		parent::set_up();

		$this->saved_query_vars = $GLOBALS['wp']->query_vars;
	}

	public function tear_down(): void {
		$GLOBALS['wp']->query_vars = $this->saved_query_vars;
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_walk_in_order_shows_processing(): void {
		$customer = $this->customer();
		$order    = $this->order_of( $customer );
		$this->insert_invoice( $order, array( 'status' => InvoiceStatus::PENDING ) );

		$html = $this->render_as( $customer, $order );

		$this->assertStringContainsString( 'Hóa đơn điện tử', $html );
		$this->assertStringContainsString( 'Đang xuất hóa đơn', $html );
	}

	public function test_no_invoice_record_renders_nothing(): void {
		$customer = $this->customer();
		$order    = $this->order_of( $customer );

		$this->assertSame( '', $this->render_as( $customer, $order ) );
	}

	public function test_order_received_renders_nothing(): void {
		$customer = $this->customer();
		$order    = $this->order_of( $customer );
		$this->insert_invoice( $order, array( 'status' => InvoiceStatus::PENDING ) );

		$this->assertSame( '', $this->render_as( $customer, $order, 'order-received' ) );
	}

	public function test_not_owner_renders_nothing(): void {
		$owner = $this->customer();
		$other = $this->customer();
		$order = $this->order_of( $owner );
		$guest = $this->order_of( null );
		$this->insert_invoice( $order, array( 'status' => InvoiceStatus::PENDING ) );
		$this->insert_invoice( $guest, array( 'status' => InvoiceStatus::PENDING ) );

		$this->assertSame( '', $this->render_as( $other, $order ) );
		$this->assertSame( '', $this->render_as( $other, $guest ) );
	}

	public function test_issued_shows_details_and_link(): void {
		$customer = $this->customer();
		$order    = $this->order_of( $customer );
		$this->insert_invoice(
			$order,
			array(
				'status'              => InvoiceStatus::DRAFT_CREATED,
				'reference'           => self::REFERENCE,
				'matbao_status'       => 4,
				'matbao_invoice_type' => 2,
				'matbao_invoice_id'   => 'abc',
				'invoice_number'      => 125,
				'template_form'       => self::TEMPLATE_FORM,
				'template_series'     => self::TEMPLATE_SERIES,
				'issued_on'           => '2026-11-19',
				'lookup_code'         => 'FM80520C49',
				'tax_authority_code'  => '00ABC',
			)
		);

		$html = $this->render_as( $customer, $order );

		$this->assertStringContainsString( '125', $html );
		$this->assertStringContainsString( '1C26TAT', $html );
		$this->assertStringContainsString( wc_format_datetime( new WC_DateTime( '2026-11-19' ) ), $html );
		$this->assertStringContainsString( 'Tải hóa đơn (PDF)', $html );
		$this->assertStringContainsString( 'einvoice-matbao-pdf=1', $html );
		$this->assertStringNotContainsString( '00ABC', $html );
		$this->assertStringNotContainsString( 'FM80520C49', $html );
		$this->assertStringNotContainsString( self::REFERENCE, $html );
		$this->assertStringNotContainsString( 'abc', $html );
	}

	public function test_failed_hides_error_details(): void {
		$customer = $this->customer();
		$order    = $this->order_of( $customer );
		$this->insert_invoice(
			$order,
			array(
				'status'        => InvoiceStatus::NEEDS_ATTENTION,
				'reference'     => self::REFERENCE,
				'status_reason' => 'Thiếu mã số thuế người mua',
				'error_code'    => 'buyer_tax_code_missing',
			)
		);

		$html = $this->render_as( $customer, $order );

		$this->assertStringNotContainsString( 'Thiếu mã số thuế người mua', $html );
		$this->assertStringNotContainsString( 'buyer_tax_code_missing', $html );
		$this->assertStringNotContainsString( 'MTChieu', $html );
		$this->assertStringNotContainsString( self::REFERENCE, $html );
		$this->assertStringContainsString( 'Đang xử lý, cửa hàng sẽ liên hệ nếu cần', $html );
		$this->assertStringNotContainsString( 'einvoice-matbao-pdf', $html );
	}

	public function test_issued_without_issue_date_omits_date(): void {
		$customer = $this->customer();
		$order    = $this->order_of( $customer );
		$this->insert_invoice(
			$order,
			array(
				'status'              => InvoiceStatus::DRAFT_CREATED,
				'matbao_status'       => 4,
				'matbao_invoice_type' => 2,
				'matbao_invoice_id'   => 'abc',
				'invoice_number'      => 125,
				'template_form'       => self::TEMPLATE_FORM,
				'template_series'     => self::TEMPLATE_SERIES,
				'issued_on'           => null,
			)
		);

		$html = $this->render_as( $customer, $order );

		$this->assertStringContainsString( 'Đã xuất hóa đơn', $html );
		$this->assertStringContainsString( 'einvoice-matbao-pdf=1', $html );
		$this->assertStringNotContainsString( 'Ngày lập', $html );
		$this->assertStringNotContainsString( '<span class="einvoice-matbao-customer-invoice__date"></span>', $html );
	}

	public function test_cancelled_shows_adjusted_without_link(): void {
		$customer = $this->customer();
		$order    = $this->order_of( $customer );
		$this->insert_invoice(
			$order,
			array(
				'status'              => InvoiceStatus::DRAFT_CREATED,
				'matbao_status'       => 4,
				'matbao_invoice_type' => 3,
				'matbao_invoice_id'   => 'abc',
				'invoice_number'      => 125,
				'template_form'       => self::TEMPLATE_FORM,
				'template_series'     => self::TEMPLATE_SERIES,
				'issued_on'           => '2026-11-19',
			)
		);

		$html = $this->render_as( $customer, $order );

		$this->assertStringContainsString( 'Hóa đơn đã được điều chỉnh, vui lòng liên hệ cửa hàng', $html );
		$this->assertStringNotContainsString( 'einvoice-matbao-pdf', $html );
	}

	/**
	 * Creates a customer account.
	 */
	private function customer(): \WP_User {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Creates a pending order, owned by the customer or a guest when `$customer` is null.
	 *
	 * @param \WP_User|null $customer Owner, or null for a guest order.
	 */
	private function order_of( ?\WP_User $customer ): WC_Order {
		$product = $this->create_product( 'Sản phẩm kiểm thử', '100000' );
		$order   = $this->create_order( array( array( $product, 1 ) ) );
		$order->set_customer_id( $customer ? $customer->ID : 0 );
		$order->save();

		return $order;
	}

	/**
	 * Inserts the invoice row of an order. Columns not given take the table defaults.
	 *
	 * @param WC_Order             $order   Order.
	 * @param array<string, mixed> $columns Column values.
	 */
	private function insert_invoice( WC_Order $order, array $columns ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test seeds the invoice row directly.
		$wpdb->insert(
			Schema::invoice_table(),
			array_merge(
				array(
					'order_id'   => $order->get_id(),
					'reference'  => self::REFERENCE,
					'status'     => InvoiceStatus::PENDING,
					'created_at' => $now,
					'updated_at' => $now,
				),
				$columns
			)
		);
	}

	/**
	 * Renders the block as `$viewer` on the given endpoint of `$order`.
	 *
	 * @param \WP_User|null $viewer   Logged-in viewer, or null for a visitor.
	 * @param WC_Order      $order    Order shown on the page.
	 * @param string        $endpoint Endpoint query var, `view-order` by default.
	 */
	private function render_as( ?\WP_User $viewer, WC_Order $order, string $endpoint = 'view-order' ): string {
		wp_set_current_user( $viewer ? $viewer->ID : 0 );
		$GLOBALS['wp']->query_vars = array( $endpoint => (string) $order->get_id() );

		ob_start();
		OrderInvoiceSection::render( $order );

		return (string) ob_get_clean();
	}
}
