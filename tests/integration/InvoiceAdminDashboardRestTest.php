<?php
/**
 * Dashboard route of the admin REST seam.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\DashboardStats;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\Schema;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * The dashboard counts errors, waiting and created invoices from the invoice table, and each
 * count links to the matching filtered list.
 */
final class InvoiceAdminDashboardRestTest extends SettingsRestTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}einvoice_matbao_invoice" ); // phpcs:ignore WordPress.DB -- test cleanup.
		update_option( 'timezone_string', 'Asia/Ho_Chi_Minh' );
	}

	public function tear_down(): void {
		delete_option( 'timezone_string' );
		parent::tear_down();
	}

	public function test_anonymous_request_is_rejected(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->htmx( 'GET', '/dashboard' )->get_status() );
	}

	public function test_non_administrator_is_rejected(): void {
		$this->login_as( 'shop_manager' );

		$this->assertSame( 403, $this->htmx( 'GET', '/dashboard' )->get_status() );
	}

	public function test_invalid_nonce_is_rejected(): void {
		$this->login_as( 'administrator' );

		$this->assertSame( 403, $this->htmx( 'GET', '/dashboard', array(), 'not-a-nonce' )->get_status() );
		$this->assertSame( 403, $this->htmx( 'GET', '/dashboard', array(), '' )->get_status() );
	}

	public function test_empty_table_shows_three_zero_counts(): void {
		$this->login_as( 'administrator' );

		$html = $this->html( $this->htmx( 'GET', '/dashboard' ) );

		$this->assertSame( '0', $this->metric( $html, 'error' ) );
		$this->assertSame( '0', $this->metric( $html, 'waiting' ) );
		$this->assertSame( '0', $this->metric( $html, 'created' ) );
		$this->assertSame( '0', $this->metric( $html, 'created-today' ) );
	}

	public function test_counts_follow_the_invoice_table(): void {
		$this->login_as( 'administrator' );
		$statuses = array(
			InvoiceStatus::FAILED          => 2,
			InvoiceStatus::NEEDS_ATTENTION => 1,
			InvoiceStatus::NOT_CONFIGURED  => 1,
			InvoiceStatus::PENDING         => 3,
			InvoiceStatus::SENDING         => 1,
			InvoiceStatus::DRAFT_CREATED   => 4,
			InvoiceStatus::SKIPPED         => 5,
			InvoiceStatus::STOPPED         => 6,
		);
		$order_id = 100;
		foreach ( $statuses as $status => $count ) {
			for ( $i = 0; $i < $count; $i++ ) {
				++$order_id;
				$this->assertTrue( ( new InvoiceRepository() )->reserve( $order_id, 'WC' . $order_id, 'Khách lẻ', $status ) );
			}
		}

		$html = $this->html( $this->htmx( 'GET', '/dashboard' ) );

		$this->assertSame( '4', $this->metric( $html, 'error' ) );
		$this->assertSame( '4', $this->metric( $html, 'waiting' ) );
		$this->assertSame( '4', $this->metric( $html, 'created' ) );
		$this->assertSame( '4', $this->metric( $html, 'created-today' ), 'Rows were just written, so all are from today.' );
	}

	public function test_each_metric_links_to_the_matching_filtered_list(): void {
		$this->login_as( 'administrator' );
		$repository = new InvoiceRepository();
		$repository->reserve( 1, 'WC1', 'Khách lẻ', InvoiceStatus::FAILED );
		$repository->reserve( 2, 'WC2', 'Khách lẻ', InvoiceStatus::PENDING );
		$repository->reserve( 3, 'WC3', 'Khách lẻ', InvoiceStatus::DRAFT_CREATED );
		$expected = array(
			'error'   => array( 1 ),
			'waiting' => array( 2 ),
			'created' => array( 3 ),
		);

		$html = $this->html( $this->htmx( 'GET', '/dashboard' ) );

		foreach ( $expected as $metric => $orders ) {
			$this->assertSame( 1, preg_match( '/data-einv-metric="' . $metric . '"[^>]*hx-get="([^"]+)"/', $html, $match ), $metric );
			$url = html_entity_decode( $match[1] );
			$this->assertStringContainsString( 'einvoice-matbao/v1/invoices', $url );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			unset( $query['rest_route'] );
			$list = $this->html( $this->htmx( 'GET', '/invoices', $query ) );
			preg_match_all( '/data-einv-invoice-row="(\d+)"/', $list, $rows );
			$this->assertEqualsCanonicalizing( $orders, array_map( 'intval', $rows[1] ), $metric );
		}
	}

	public function test_waiting_filter_covers_pending_and_sending_only(): void {
		$this->login_as( 'administrator' );
		$repository = new InvoiceRepository();
		$repository->reserve( 1, 'WC1', 'Khách lẻ', InvoiceStatus::PENDING );
		$repository->reserve( 2, 'WC2', 'Khách lẻ', InvoiceStatus::SENDING );
		$repository->reserve( 3, 'WC3', 'Khách lẻ', InvoiceStatus::FAILED );

		$list = $this->html( $this->htmx( 'GET', '/invoices', array( 'status' => InvoiceStatus::FILTER_WAITING ) ) );

		preg_match_all( '/data-einv-invoice-row="(\d+)"/', $list, $rows );
		$this->assertEqualsCanonicalizing( array( 1, 2 ), array_map( 'intval', $rows[1] ) );
	}

	public function test_created_today_uses_the_shop_timezone_around_midnight(): void {
		$this->login_as( 'administrator' );
		list( $start, $end ) = DashboardStats::today_bounds( time() );
		$repository          = new InvoiceRepository();
		$stamps              = array(
			11 => gmdate( 'Y-m-d H:i:s', $start - 1 ),
			12 => gmdate( 'Y-m-d H:i:s', $start ),
			13 => gmdate( 'Y-m-d H:i:s', $end - 1 ),
			14 => gmdate( 'Y-m-d H:i:s', $end ),
		);
		foreach ( $stamps as $order_id => $stamp ) {
			$repository->reserve( $order_id, 'WC' . $order_id, 'Khách lẻ', InvoiceStatus::DRAFT_CREATED );
			$this->set_updated_at( $order_id, $stamp );
		}

		$html = $this->html( $this->htmx( 'GET', '/dashboard' ) );

		$this->assertSame( '4', $this->metric( $html, 'created' ) );
		$this->assertSame( '2', $this->metric( $html, 'created-today' ) );
	}

	public function test_today_bounds_in_vietnam_time(): void {
		// 2026-10-30 16:59:59 UTC is 23:59:59 on the 30th in Ho Chi Minh City.
		$before = strtotime( '2026-10-30 16:59:59 UTC' );
		$after  = strtotime( '2026-10-30 17:00:00 UTC' );

		$this->assertSame( array( strtotime( '2026-10-29 17:00:00 UTC' ), strtotime( '2026-10-30 17:00:00 UTC' ) ), DashboardStats::today_bounds( $before ) );
		$this->assertSame( array( strtotime( '2026-10-30 17:00:00 UTC' ), strtotime( '2026-10-31 17:00:00 UTC' ) ), DashboardStats::today_bounds( $after ) );
	}

	public function test_dashboard_exposes_no_buyer_data(): void {
		$this->login_as( 'administrator' );
		( new InvoiceRepository() )->reserve( 1, 'WC1', 'Nguyễn Văn A 012345678901', InvoiceStatus::FAILED, 'Lỗi 0123456789' );

		$html = $this->html( $this->htmx( 'GET', '/dashboard' ) );

		$this->assertStringNotContainsString( 'Nguyễn', $html );
		$this->assertStringNotContainsString( '012345678901', $html );
	}

	/**
	 * Sets the last change time of an invoice.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $stamp    UTC datetime.
	 */
	private function set_updated_at( int $order_id, string $stamp ): void {
		global $wpdb;

		$wpdb->update( Schema::invoice_table(), array( 'updated_at' => $stamp ), array( 'order_id' => $order_id ) ); // phpcs:ignore WordPress.DB -- test setup.
	}

	/**
	 * The number shown for a metric.
	 *
	 * @param string $html   Dashboard fragment.
	 * @param string $metric Metric key.
	 */
	private function metric( string $html, string $metric ): string {
		$this->assertSame( 1, preg_match( '/data-einv-count="' . $metric . '">(\d+)</', $html, $match ), $metric );

		return $match[1];
	}
}
