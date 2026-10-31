<?php
/**
 * Invoice list and detail routes of the admin REST seam.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * Administrators list, filter, search and open invoices through htmx fragments; nobody else can.
 */
final class InvoiceAdminListRestTest extends SettingsRestTestCase {

	private const RAW_CCCD = '012345678901';

	/**
	 * Every invoice admin route, as [method, route].
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function invoice_routes(): array {
		return array(
			'list'   => array( 'GET', '/invoices' ),
			'detail' => array( 'GET', '/invoices/1' ),
			'check'  => array( 'POST', '/invoices/1/check' ),
			'resend' => array( 'POST', '/invoices/1/resend' ),
		);
	}

	/**
	 * @dataProvider invoice_routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 */
	public function test_anonymous_request_is_rejected( string $method, string $route ): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->htmx( $method, $route )->get_status() );
	}

	/**
	 * @dataProvider invoice_routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 */
	public function test_non_administrator_is_rejected( string $method, string $route ): void {
		$this->login_as( 'shop_manager' );

		$this->assertSame( 403, $this->htmx( $method, $route )->get_status() );
	}

	/**
	 * @dataProvider invoice_routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 */
	public function test_missing_or_invalid_nonce_is_rejected( string $method, string $route ): void {
		$this->login_as( 'administrator' );

		$this->assertSame( 403, $this->htmx( $method, $route, array(), 'not-a-nonce' )->get_status() );
		$this->assertSame( 403, $this->htmx( $method, $route, array(), '' )->get_status() );
	}

	public function test_list_shows_invoices_newest_first_with_status_labels(): void {
		$this->login_as( 'administrator' );
		$this->reserve( 101, 'WC101', InvoiceStatus::DRAFT_CREATED );
		$this->reserve( 102, 'WC102', InvoiceStatus::FAILED );

		$html = $this->html( $this->htmx( 'GET', '/invoices' ) );

		$this->assertStringContainsString( 'data-einv-total="2"', $html );
		$this->assertStringContainsString( 'data-einv-invoice-row="101"', $html );
		$this->assertStringContainsString( 'data-einv-invoice-row="102"', $html );
		$this->assertLessThan( strpos( $html, 'data-einv-invoice-row="101"' ), strpos( $html, 'data-einv-invoice-row="102"' ) );
		$this->assertStringContainsString( 'Đã tạo nháp', $html );
		$this->assertStringContainsString( 'WC102', $html );
	}

	public function test_list_filters_by_status_and_by_error_group(): void {
		$this->login_as( 'administrator' );
		$this->reserve( 101, 'WC101', InvoiceStatus::DRAFT_CREATED );
		$this->reserve( 102, 'WC102', InvoiceStatus::FAILED );
		$this->reserve( 103, 'WC103', InvoiceStatus::NEEDS_ATTENTION );
		$this->reserve( 104, 'WC104', InvoiceStatus::PENDING );

		$draft = $this->html( $this->htmx( 'GET', '/invoices', array( 'status' => 'draft_created' ) ) );
		$this->assertSame( array( 101 ), $this->row_orders( $draft ) );

		$errors = $this->html( $this->htmx( 'GET', '/invoices', array( 'status' => 'error' ) ) );
		$this->assertEqualsCanonicalizing( array( 102, 103 ), $this->row_orders( $errors ) );

		$unknown = $this->html( $this->htmx( 'GET', '/invoices', array( 'status' => "x' OR '1'='1" ) ) );
		$this->assertCount( 4, $this->row_orders( $unknown ), 'An unknown status is ignored, not injected.' );
	}

	public function test_list_searches_by_order_id_or_reference(): void {
		$this->login_as( 'administrator' );
		$this->reserve( 101, 'WC101', InvoiceStatus::DRAFT_CREATED );
		$this->reserve( 202, 'SHOPWC202', InvoiceStatus::FAILED );

		$by_order = $this->html( $this->htmx( 'GET', '/invoices', array( 'q' => '101' ) ) );
		$this->assertSame( array( 101 ), $this->row_orders( $by_order ) );

		$by_reference = $this->html( $this->htmx( 'GET', '/invoices', array( 'q' => 'SHOPWC' ) ) );
		$this->assertSame( array( 202 ), $this->row_orders( $by_reference ) );

		$wildcard = $this->html( $this->htmx( 'GET', '/invoices', array( 'q' => '%' ) ) );
		$this->assertSame( array(), $this->row_orders( $wildcard ), 'LIKE wildcards are escaped.' );

		$injection = $this->html( $this->htmx( 'GET', '/invoices', array( 'q' => "' OR 1=1 -- " ) ) );
		$this->assertSame( array(), $this->row_orders( $injection ) );
	}

	public function test_list_is_paginated(): void {
		$this->login_as( 'administrator' );
		for ( $i = 1; $i <= 25; $i++ ) {
			$this->reserve( 1000 + $i, 'WC' . ( 1000 + $i ), InvoiceStatus::DRAFT_CREATED );
		}

		$first = $this->html( $this->htmx( 'GET', '/invoices' ) );
		$this->assertCount( 20, $this->row_orders( $first ) );
		$this->assertStringContainsString( 'data-einv-total="25"', $first );
		$this->assertStringContainsString( 'page=2', $first );

		$second = $this->html( $this->htmx( 'GET', '/invoices', array( 'page' => '2' ) ) );
		$this->assertCount( 5, $this->row_orders( $second ) );

		$beyond = $this->html( $this->htmx( 'GET', '/invoices', array( 'page' => '-3' ) ) );
		$this->assertCount( 20, $this->row_orders( $beyond ), 'A bad page falls back to the first.' );
	}

	public function test_detail_shows_state_template_error_and_attempt_history(): void {
		$this->login_as( 'administrator' );
		$repository = new InvoiceRepository();
		$this->reserve( 301, 'WC301', InvoiceStatus::PENDING );
		$invoice = $repository->find_by_order( 301 );
		$this->assertNotNull( $invoice );
		$repository->remember_template( $invoice->id, '1', 'C26TAT' );
		$repository->record_error( $invoice->id, InvoiceErrorCode::MATBAO_API, 'Mắt Bão từ chối hóa đơn', InvoiceStatus::FAILED );
		$attempt = $repository->start_attempt( $invoice->id );
		$repository->finish_attempt( $attempt, 'failed', 'api', 400, 123, 'Dữ liệu sai' );

		$html = $this->html( $this->htmx( 'GET', '/invoices/' . $invoice->id ) );

		$this->assertStringContainsString( 'data-einv-invoice-detail="' . $invoice->id . '"', $html );
		$this->assertStringContainsString( 'WC301', $html );
		$this->assertStringContainsString( 'Lỗi', $html );
		$this->assertStringContainsString( 'data-einv-error-code="matbao_api"', $html );
		$this->assertStringContainsString( ( InvoiceErrorCode::MATBAO_API )->label(), $html );
		$this->assertStringContainsString( '1 / C26TAT', $html );
		$this->assertStringContainsString( 'Mắt Bão từ chối hóa đơn', $html );
		$this->assertStringContainsString( 'data-einv-attempt="failed"', $html );
		$this->assertStringContainsString( 'Dữ liệu sai', $html );
	}

	public function test_detail_of_unknown_invoice_is_not_found(): void {
		$this->login_as( 'administrator' );

		$this->assertSame( 404, $this->htmx( 'GET', '/invoices/987654' )->get_status() );
	}

	public function test_detail_masks_national_id_numbers_everywhere(): void {
		$this->login_as( 'administrator' );
		$repository = new InvoiceRepository();
		$this->reserve( 302, 'WC302', InvoiceStatus::FAILED, 'Người mua ' . self::RAW_CCCD . ' sai định dạng' );
		$invoice = $repository->find_by_order( 302 );
		$this->assertNotNull( $invoice );
		$attempt = $repository->start_attempt( $invoice->id );
		$repository->finish_attempt( $attempt, 'failed', 'api', 400, 9, 'CCCD ' . self::RAW_CCCD . ' không hợp lệ' );

		$detail = $this->html( $this->htmx( 'GET', '/invoices/' . $invoice->id ) );
		$list   = $this->html( $this->htmx( 'GET', '/invoices' ) );

		foreach ( array( $detail, $list ) as $html ) {
			$this->assertStringNotContainsString( self::RAW_CCCD, $html );
		}
		$this->assertStringContainsString( '********8901', $detail );
	}

	public function test_detail_escapes_error_messages_and_hides_urls(): void {
		$this->login_as( 'administrator' );
		$repository = new InvoiceRepository();
		$this->reserve( 303, 'WC303', InvoiceStatus::FAILED, '<script>alert(1)</script>' );
		$invoice = $repository->find_by_order( 303 );
		$this->assertNotNull( $invoice );
		$attempt = $repository->start_attempt( $invoice->id );
		$repository->finish_attempt( $attempt, 'failed', 'api', 400, 9, '<img src=x onerror=alert(2)> lỗi tại https://matbao.example/api?token=SECRET123' );

		$html = $this->html( $this->htmx( 'GET', '/invoices/' . $invoice->id ) );

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( 'SECRET123', $html );
		$this->assertStringNotContainsString( 'matbao.example', $html );
	}

	/**
	 * Reserves an invoice row with a status and reason.
	 *
	 * @param int    $order_id  Order ID (no WooCommerce order needed for list tests).
	 * @param string $reference `MTChieu`.
	 * @param string $status    InvoiceStatus.
	 * @param string $reason    Status reason.
	 */
	private function reserve( int $order_id, string $reference, string $status, string $reason = '' ): void {
		$this->assertTrue( ( new InvoiceRepository() )->reserve( $order_id, $reference, 'Khách lẻ', $status, $reason ) );
	}

	/**
	 * Order IDs of the rows in a list fragment.
	 *
	 * @param string $html List fragment.
	 * @return list<int>
	 */
	private function row_orders( string $html ): array {
		preg_match_all( '/data-einv-invoice-row="(\d+)"/', $html, $matches );

		return array_map( 'intval', $matches[1] );
	}
}
