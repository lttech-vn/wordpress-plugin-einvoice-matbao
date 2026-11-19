<?php
/**
 * Invoice list and detail routes of the admin REST seam.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\AdminTabs;
use LTTech\EinvoiceMatbao\Admin\InvoicePage;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\HintText;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * Administrators list, filter, search and open invoices through htmx fragments; nobody else can.
 */
final class InvoiceAdminListRestTest extends SettingsRestTestCase {

	use HintText;

	private const RAW_CCCD = '012345678901';

	public function set_up(): void {
		parent::set_up();
		$this->empty_invoice_table();
	}

	/**
	 * Starts from an empty invoice table: earlier tests may have committed rows.
	 */
	private function empty_invoice_table(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}einvoice_matbao_invoice" ); // phpcs:ignore WordPress.DB -- test cleanup.
	}

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
	 * Rejected for the route.
	 *
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
	 * Rejected for the route.
	 *
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
	 * Rejected for the route.
	 *
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

	/**
	 * Status to badge group and label.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function badge_cases(): array {
		return array(
			'failed'          => array( InvoiceStatus::FAILED, 'error', 'Lỗi' ),
			'needs_attention' => array( InvoiceStatus::NEEDS_ATTENTION, 'error', 'Lỗi cần xử lý' ),
			'pending'         => array( InvoiceStatus::PENDING, 'waiting', 'Chờ gửi' ),
			'draft_created'   => array( InvoiceStatus::DRAFT_CREATED, 'created', 'Đã tạo nháp' ),
			'skipped'         => array( InvoiceStatus::SKIPPED, 'other', 'Bỏ qua' ),
			'stopped'         => array( 'stopped', 'other', 'Đã dừng' ),
		);
	}

	/**
	 * A status renders as a badge of its group.
	 *
	 * @dataProvider badge_cases
	 *
	 * @param string $status Invoice status.
	 * @param string $group  Expected badge group.
	 * @param string $label  Expected badge label.
	 */
	public function test_status_is_shown_as_a_group_badge( string $status, string $group, string $label ): void {
		$this->login_as( 'administrator' );
		$this->reserve( 101, 'WC101', $status );

		$html = $this->html( $this->htmx( 'GET', '/invoices' ) );

		$this->assertMatchesRegularExpression( '#data-einv-badge="' . $group . '">' . preg_quote( $label, '#' ) . '</span>#u', $html );
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

	public function test_reference_search_matches_from_the_start_so_the_index_can_be_used(): void {
		$this->login_as( 'administrator' );
		$this->reserve( 202, 'SHOPWC202', InvoiceStatus::FAILED );
		$queries = array();
		$collect = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;

			return $query;
		};
		add_filter( 'query', $collect );

		$middle = $this->html( $this->htmx( 'GET', '/invoices', array( 'q' => 'WC202' ) ) );
		remove_filter( 'query', $collect );

		$this->assertSame( array(), $this->row_orders( $middle ), 'Only a prefix of MTChieu matches.' );
		$this->assertNotEmpty( preg_grep( "/reference LIKE 'WC202%'/", $queries ) );
		$this->assertEmpty( preg_grep( "/LIKE '%/", $queries ) );
		$this->assertSame( array( 202 ), $this->row_orders( $this->html( $this->htmx( 'GET', '/invoices', array( 'q' => '202' ) ) ) ), 'The order ID still matches.' );
	}

	public function test_search_box_has_an_accessible_name(): void {
		$this->login_as( 'administrator' );

		$html = InvoicePage::render_panel();

		$this->assertMatchesRegularExpression( '/<input type="search" name="q"[^>]*aria-label="Mã đơn hoặc MTChieu"/', $html );
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
		$this->assertSame( InvoiceErrorCode::MATBAO_API->hint(), $this->detail_hint_text( $html ) );
		$this->assertStringContainsString( 'data-einv-hint="' . esc_attr( InvoiceErrorCode::MATBAO_API->hint() ) . '"', $html );
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

	public function test_issued_invoice_row(): void {
		$this->login_as( 'administrator' );
		$id = $this->synced( 9401, 4, 'Đã cấp mã', 125 );

		$html = $this->html( $this->htmx( 'GET', '/invoices' ) );

		$this->assertStringContainsString( '<td>125</td>', $html );
		$this->assertMatchesRegularExpression( '#data-einv-matbao-badge="issued"[^>]*>Đã cấp mã/tiếp nhận</span>#u', $html );
		$this->assertStringContainsString( '2026-01-02 03:04:05', $html );
		$this->assertStringContainsString( 'hx-push-url="' . esc_url( InvoicePage::detail_url( $id ) ) . '"', $html );
	}

	public function test_deleted_badge_is_error(): void {
		$this->login_as( 'administrator' );
		$this->synced( 9402, 0, '' );

		$html = $this->html( $this->htmx( 'GET', '/invoices' ) );

		$this->assertMatchesRegularExpression( '#data-einv-matbao-badge="error"[^>]*>Đã xóa trên Mắt Bão</span>#u', $html );
	}

	public function test_unknown_code_badge(): void {
		$this->login_as( 'administrator' );
		$this->synced( 9403, 12, 'Trạng thái mới' );

		$html = $this->html( $this->htmx( 'GET', '/invoices' ) );

		$this->assertMatchesRegularExpression( '#data-einv-matbao-badge="progress"[^>]*>Trạng thái mới</span>#u', $html );
	}

	public function test_not_yet_synced_shows_dash(): void {
		$this->login_as( 'administrator' );
		$this->reserve( 9404, 'WC9404', InvoiceStatus::DRAFT_CREATED );

		$html = $this->html( $this->htmx( 'GET', '/invoices' ) );

		$this->assertStringNotContainsString( 'data-einv-matbao-badge', $html );
		// Cells: order, number, MTChieu, status, Mat Bao, error, synced at, detail.
		$this->assertMatchesRegularExpression( '#<tr data-einv-invoice-row="9404">(?:<td>.*?</td>){4}<td>—</td><td>[^<]*</td><td>—</td>#us', $html );
	}

	public function test_matbao_text_is_escaped_in_list_and_detail(): void {
		$this->login_as( 'administrator' );
		$id = $this->synced( 9408, 12, '<img src=x onerror=alert(1)>', 5, null, null, '<script>alert(2)</script>' );

		foreach ( array( '/invoices', '/invoices/' . $id ) as $route ) {
			$html = $this->html( $this->htmx( 'GET', $route ) );
			$this->assertStringNotContainsString( '<img src=x', $html );
			$this->assertStringNotContainsString( '<script>alert(2)', $html );
			$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		}
		$this->assertStringContainsString( '&lt;script&gt;alert(2)&lt;/script&gt;', $html );
	}

	public function test_order_id_links_to_edit_order(): void {
		$this->login_as( 'administrator' );
		$order = wc_create_order();
		$this->reserve( $order->get_id(), 'WC' . $order->get_id(), InvoiceStatus::PENDING );

		$html = $this->html( $this->htmx( 'GET', '/invoices' ) );

		$this->assertStringContainsString( 'href="' . esc_url( $order->get_edit_order_url() ) . '"', $html );
	}

	public function test_deleted_order_is_text(): void {
		$this->login_as( 'administrator' );
		$this->reserve( 987001, 'WC987001', InvoiceStatus::PENDING );

		$html = $this->html( $this->htmx( 'GET', '/invoices' ) );

		$this->assertStringContainsString( '<td>#987001</td>', $html );
	}

	public function test_detail_button_pushes_url(): void {
		$this->login_as( 'administrator' );
		$this->reserve( 9405, 'WC9405', InvoiceStatus::PENDING );
		$invoice = ( new InvoiceRepository() )->find_by_order( 9405 );
		$this->assertNotNull( $invoice );

		$html = $this->html( $this->htmx( 'GET', '/invoices' ) );

		$this->assertStringContainsString( 'hx-push-url="' . esc_url( InvoicePage::detail_url( $invoice->id ) ) . '"', $html );
	}

	public function test_back_button_pushes_list_url(): void {
		$this->login_as( 'administrator' );
		$id = $this->synced( 9406, 4, 'x', 1 );

		$html = $this->html( $this->htmx( 'GET', '/invoices/' . $id ) );

		$this->assertStringContainsString( 'hx-push-url="' . esc_url( AdminTabs::url( 'invoices' ) ) . '"', $html );
	}

	public function test_issued_invoice_detail(): void {
		$this->login_as( 'administrator' );
		$order = wc_create_order();
		$id    = $this->synced( $order->get_id(), 4, 'Đã cấp mã', 125, '00ABC', 2 );

		$html = $this->html( $this->htmx( 'GET', '/invoices/' . $id ) );

		$this->assertStringContainsString( 'Đã cấp mã/tiếp nhận', $html );
		$this->assertStringContainsString( '125', $html );
		$this->assertStringContainsString( '00ABC', $html );
		$this->assertStringContainsString( 'Hóa đơn mới', $html );
		$this->assertStringContainsString( '2026-01-02 03:04:05', $html );
		$this->assertStringContainsString( 'Xem đơn #' . $order->get_id(), $html );
		$this->assertStringContainsString( esc_url( $order->get_edit_order_url() ), $html );
	}

	public function test_tax_note_is_redacted(): void {
		$this->login_as( 'administrator' );
		$id = $this->synced( 9407, 4, 'x', 1, null, null, 'CCCD ' . self::RAW_CCCD . ' xem https://x.y/z' );

		$html = $this->html( $this->htmx( 'GET', '/invoices/' . $id ) );

		$this->assertStringNotContainsString( self::RAW_CCCD, $html );
		$this->assertStringNotContainsString( 'https://x.y', $html );
		$this->assertStringContainsString( '********8901', $html );
		$this->assertStringContainsString( '[đã ẩn]', $html );
	}

	/**
	 * Reserves a draft_created invoice that carries a Mat Bao snapshot.
	 *
	 * @param int         $order_id Order ID.
	 * @param int         $status   `maTThaiHDon`.
	 * @param string      $name     `tenTThaiHDon`.
	 * @param int|null    $number   Invoice number.
	 * @param string|null $mccqt    Tax authority code.
	 * @param int|null    $type     `maLoaiHDon`.
	 * @param string      $note     `ghiChuCQT`.
	 * @return int Invoice ID.
	 */
	private function synced( int $order_id, int $status, string $name, ?int $number = null, ?string $mccqt = null, ?int $type = null, string $note = '' ): int {
		global $wpdb;

		$this->reserve( $order_id, 'WC' . $order_id, InvoiceStatus::DRAFT_CREATED );
		$invoice = ( new InvoiceRepository() )->find_by_order( $order_id );
		$this->assertNotNull( $invoice );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test setup.
			$wpdb->prefix . 'einvoice_matbao_invoice',
			array(
				'matbao_status'       => $status,
				'matbao_status_name'  => $name,
				'invoice_number'      => $number,
				'tax_authority_code'  => $mccqt,
				'matbao_invoice_type' => $type,
				'tax_note'            => $note,
				'matbao_synced_at'    => '2026-01-02 03:04:05',
			),
			array( 'id' => $invoice->id )
		);

		return $invoice->id;
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
