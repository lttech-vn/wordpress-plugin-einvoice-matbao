<?php
/**
 * Resend and status check routes of the admin REST seam.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshotter;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;

/**
 * Resending goes through the retry workflow and its lookup gate, so it never creates a second
 * invoice; the status check only reads Mat Bao.
 */
final class InvoiceAdminActionsRestTest extends InvoiceWorkflowTestCase {

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}einvoice_matbao_invoice" ); // phpcs:ignore WordPress.DB -- rows committed by other tests would collide on order IDs.
	}

	/**
	 * A completed order whose create call timed out, so Mat Bao may already hold the draft.
	 */
	private function order_after_timeout(): WC_Order {
		$this->configure_matbao();
		$this->fake_create_invoice_timeout();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$this->complete_and_run( $order );
		as_unschedule_all_actions( DraftInvoiceWorkflow::ACTION ); // Drop the automatic retry; the tests resend by hand.

		return $order;
	}

	/**
	 * A completed order with a draft created.
	 */
	private function order_with_draft(): WC_Order {
		$this->configure_matbao();
		$this->fake_create_invoice_ok( 'ZHJhZnQtMQ==' );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$this->complete_and_run( $order );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );

		return $order;
	}

	private function record( WC_Order $order ): InvoiceRecord {
		$record = $this->invoice_of( $order );
		$this->assertNotNull( $record );

		return $record;
	}

	private function force_status( WC_Order $order, string $status ): void {
		( new InvoiceRepository() )->update_status( $this->record( $order )->id, $status );
	}

	public function test_resend_after_uncertain_create_adopts_the_existing_draft_without_creating(): void {
		$order = $this->order_after_timeout();
		$this->force_status( $order, InvoiceStatus::NEEDS_ATTENTION );
		$this->fake_lookup_found( 'ZXhpc3Rpbmc=' );

		$response = $this->htmx( 'POST', '/invoices/' . $this->record( $order )->id . '/resend', array( 'confirm' => '1' ) );
		$this->assertSame( 200, $response->get_status(), $this->html( $response ) );
		$this->run_queued_draft_actions();

		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ), 'Resend must not create a second invoice.' );
		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->record( $order )->status );
		$this->assertSame( 'ZXhpc3Rpbmc=', $this->record( $order )->matbao_invoice_id );
	}

	public function test_resend_requires_confirmation(): void {
		$order = $this->order_after_timeout();
		$this->force_status( $order, InvoiceStatus::FAILED );

		$response = $this->htmx( 'POST', '/invoices/' . $this->record( $order )->id . '/resend' );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( InvoiceStatus::FAILED, $this->record( $order )->status );
	}

	public function test_resend_twice_queues_one_action(): void {
		$order = $this->order_after_timeout();
		$this->force_status( $order, InvoiceStatus::FAILED );
		$route = '/invoices/' . $this->record( $order )->id . '/resend';

		$first  = $this->htmx( 'POST', $route, array( 'confirm' => '1' ) );
		$second = $this->htmx( 'POST', $route, array( 'confirm' => '1' ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 409, $second->get_status() );
		$this->assertCount( 1, $this->queued_draft_action_ids() );
	}

	public function test_resend_is_refused_while_pending_or_when_skipped(): void {
		$order = $this->order_after_timeout();
		$route = '/invoices/' . $this->record( $order )->id . '/resend';
		$this->assertSame( InvoiceStatus::PENDING, $this->record( $order )->status );

		$this->assertSame( 409, $this->htmx( 'POST', $route, array( 'confirm' => '1' ) )->get_status() );

		$this->force_status( $order, InvoiceStatus::SKIPPED );
		$this->assertSame( 409, $this->htmx( 'POST', $route, array( 'confirm' => '1' ) )->get_status() );
	}

	public function test_resend_of_a_created_draft_is_refused_without_the_lost_draft_confirmation(): void {
		$order = $this->order_with_draft();
		$this->fake_lookup_not_found();

		$response = $this->htmx( 'POST', '/invoices/' . $this->record( $order )->id . '/resend', array( 'confirm' => '1' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->record( $order )->status );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
	}

	public function test_resend_of_a_created_draft_is_refused_while_the_draft_still_exists(): void {
		$order = $this->order_with_draft();
		$this->fake_lookup_found( 'ZHJhZnQtMQ==' );

		$response = $this->htmx(
			'POST',
			'/invoices/' . $this->record( $order )->id . '/resend',
			array(
				'confirm'      => '1',
				'confirm_lost' => '1',
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
	}

	public function test_resend_of_a_lost_draft_after_confirmation_looks_up_then_creates_again(): void {
		$order = $this->order_with_draft();
		$this->fake_lookup_not_found();
		$this->fake_create_invoice_ok( 'bmV3LWRyYWZ0' );

		$response = $this->htmx(
			'POST',
			'/invoices/' . $this->record( $order )->id . '/resend',
			array(
				'confirm'      => '1',
				'confirm_lost' => '1',
			)
		);
		$this->assertSame( 200, $response->get_status(), $this->html( $response ) );
		$this->run_queued_draft_actions();

		$this->assertCount( 2, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->record( $order )->status );
		$this->assertSame( 'bmV3LWRyYWZ0', $this->record( $order )->matbao_invoice_id );
	}

	public function test_check_status_reports_a_draft_that_still_exists_and_changes_nothing(): void {
		$order  = $this->order_with_draft();
		$before = $this->record( $order );
		$this->fake_lookup_found( 'ZHJhZnQtMQ==' );

		$html = $this->html( $this->htmx( 'POST', '/invoices/' . $before->id . '/check' ) );

		$this->assertStringContainsString( 'data-einv-check="draft"', $html );
		$this->assertEquals( $before, $this->record( $order ) );
		$lookups = $this->http->requests_to( self::LOOKUP_ROUTE );
		$this->assertSame( array( $before->reference ), $lookups[0]['body']['danhSach'] );
		$this->assertSame( self::TEMPLATE_SERIES, $lookups[0]['body']['kiHieu'] );
	}

	public function test_check_status_warns_when_the_draft_is_gone_and_never_recreates_it(): void {
		$order = $this->order_with_draft();
		$this->fake_lookup_not_found();

		$html = $this->html( $this->htmx( 'POST', '/invoices/' . $this->record( $order )->id . '/check' ) );

		$this->assertStringContainsString( 'data-einv-check="missing"', $html );
		$this->assertStringContainsString( 'name="confirm_lost"', $html, 'Offers a resend that needs explicit confirmation.' );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->record( $order )->status );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
	}

	public function test_check_status_warns_when_the_invoice_is_no_longer_a_draft(): void {
		$order = $this->order_with_draft();
		$this->fake_lookup_rows(
			array(
				array(
					'maTThaiHDon'  => 2,
					'tenTThaiHDon' => 'Đã phát hành <b>x</b>',
				),
			)
		);

		$html = $this->html( $this->htmx( 'POST', '/invoices/' . $this->record( $order )->id . '/check' ) );

		$this->assertStringContainsString( 'data-einv-check="not_draft"', $html );
		$this->assertStringContainsString( 'Đã phát hành &lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( 'name="confirm_lost"', $html );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->record( $order )->status );
	}

	public function test_check_status_without_a_stored_template_asks_nothing_of_mat_bao(): void {
		$this->configure_matbao();
		$repository = new InvoiceRepository();
		$repository->reserve( 777, 'WC777', 'Khách lẻ', InvoiceStatus::NOT_CONFIGURED );
		$invoice = $repository->find_by_order( 777 );
		$this->assertNotNull( $invoice );

		$response = $this->htmx( 'POST', '/invoices/' . $invoice->id . '/check' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertCount( 0, $this->http->requests_to( self::LOOKUP_ROUTE ) );
	}

	/**
	 * An order stopped with `template_changed`: the create under C26TAT timed out, the default
	 * moved to C26TBB, and the lookup under C26TAT found nothing.
	 */
	private function order_stopped_for_changed_template(): WC_Order {
		$order = $this->order_after_timeout();
		$this->http->respond_json(
			'GET /api/invoice/templates',
			200,
			array(
				'errorCode' => 200,
				'message'   => 'Thành công',
				'data'      => array(
					array(
						'thDon'    => 'Hóa đơn giá trị gia tăng',
						'khmshDon' => '1',
						'khhDon'   => 'C26TAT',
						'sLuong'   => 100000,
						'cLai'     => 54518,
					),
					array(
						'thDon'    => 'Hóa đơn giá trị gia tăng',
						'khmshDon' => '1',
						'khhDon'   => 'C26TBB',
						'sLuong'   => 1000,
						'cLai'     => 1000,
					),
				),
				'success'   => true,
			)
		);
		( new Settings() )->save_default_template( '1', 'C26TBB' );
		$this->fake_lookup_not_found();
		$this->fake_create_invoice_ok( 'bmV3LXRlbXBsYXRl' );
		as_enqueue_async_action( DraftInvoiceWorkflow::ACTION, array( $order->get_id() ), DraftInvoiceWorkflow::GROUP ); // The retry the helper dropped.
		$this->run_queued_draft_actions();
		$this->assertSame( 'template_changed', $this->record( $order )->error_code );

		return $order;
	}

	/**
	 * Escape-hatch parameters for an invoice.
	 *
	 * @param InvoiceRecord $record Invoice stopped with `template_changed`.
	 * @return array<string, string>
	 */
	private function no_invoice_params( InvoiceRecord $record ): array {
		return array(
			'confirm'            => '1',
			'confirm_no_invoice' => '1',
			'confirm_reference'  => $record->reference,
		);
	}

	/**
	 * Outcomes of the attempts of an order's invoice.
	 *
	 * @param WC_Order $order Order.
	 * @return list<string>
	 */
	private function outcomes( WC_Order $order ): array {
		return array_values( array_map( static fn( array $attempt ): string => (string) $attempt['outcome'], $this->attempts_of( $this->record( $order ) ) ) );
	}

	public function test_template_changed_blocks_a_plain_resend_but_an_admin_can_confirm_no_invoice_exists(): void {
		$order  = $this->order_stopped_for_changed_template();
		$record = $this->record( $order );
		$route  = '/invoices/' . $record->id . '/resend';

		// A plain resend still stops at the lookup gate.
		$this->assertSame( 200, $this->htmx( 'POST', $route, array( 'confirm' => '1' ) )->get_status() );
		$this->run_queued_draft_actions();
		$this->assertSame( 'template_changed', $this->record( $order )->error_code );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );

		// The escape needs the reference typed back.
		$params = array(
			'confirm'            => '1',
			'confirm_no_invoice' => '1',
		);
		$this->assertSame( 422, $this->htmx( 'POST', $route, $params )->get_status() );
		$this->assertSame( 422, $this->htmx( 'POST', $route, $params + array( 'confirm_reference' => 'WC999' ) )->get_status() );
		$this->assertSame( array(), $this->queued_draft_action_ids() );

		$ok = $this->htmx( 'POST', $route, $params + array( 'confirm_reference' => $record->reference ) );
		$this->assertSame( 200, $ok->get_status(), $this->html( $ok ) );
		$lookups_before = count( $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->run_queued_draft_actions();

		$lookups = $this->http->requests_to( self::LOOKUP_ROUTE );
		$this->assertCount( $lookups_before + 1, $lookups, 'The run still looks up, now under the current template.' );
		$this->assertSame( 'C26TBB', end( $lookups )['body']['kiHieu'] );
		$this->assertCount( 2, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( 'C26TBB', $this->sent_invoices()[1]['KHHDon'] );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->record( $order )->status );

		$detail = $this->html( $this->htmx( 'GET', '/invoices/' . $record->id ) );
		$this->assertStringContainsString( 'data-einv-attempt="admin_absent"', $detail );
	}

	public function test_no_invoice_confirmation_is_refused_when_mat_bao_has_the_invoice_under_the_old_template(): void {
		$order  = $this->order_stopped_for_changed_template();
		$record = $this->record( $order );
		$this->fake_lookup_found( 'b2xkLXRlbXBsYXRl' );
		$lookups_before = count( $this->http->requests_to( self::LOOKUP_ROUTE ) );

		$response = $this->htmx( 'POST', '/invoices/' . $record->id . '/resend', $this->no_invoice_params( $record ) );

		$this->assertSame( 409, $response->get_status(), $this->html( $response ) );
		$lookups = $this->http->requests_to( self::LOOKUP_ROUTE );
		$this->assertCount( $lookups_before + 1, $lookups, 'The escape asks Mat Bao again.' );
		$this->assertSame( 'C26TAT', end( $lookups )['body']['kiHieu'], 'Under the old template.' );
		$after = $this->record( $order );
		$this->assertSame( array( 'C26TAT', InvoiceStatus::NOT_CONFIGURED ), array( $after->template_series, $after->status ) );
		$this->assertNotContains( 'admin_absent', $this->outcomes( $order ) );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
	}

	public function test_no_invoice_confirmation_is_refused_when_the_lookup_fails(): void {
		$order  = $this->order_stopped_for_changed_template();
		$record = $this->record( $order );
		$this->http->respond_json( self::LOOKUP_ROUTE, 500, array( 'message' => 'Lỗi máy chủ' ) );

		$response = $this->htmx( 'POST', '/invoices/' . $record->id . '/resend', $this->no_invoice_params( $record ) );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 'C26TAT', $this->record( $order )->template_series );
		$this->assertNotContains( 'admin_absent', $this->outcomes( $order ) );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
	}

	public function test_no_invoice_confirmation_that_loses_the_restart_race_changes_nothing(): void {
		$order  = $this->order_stopped_for_changed_template();
		$record = $this->record( $order );
		$this->fake_lookup_not_found();
		$this->lose_the_restart_race( $record );

		$response = $this->htmx( 'POST', '/invoices/' . $record->id . '/resend', $this->no_invoice_params( $record ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'C26TAT', $this->record( $order )->template_series, 'The old template stays for the next lookup.' );
		$this->assertNotContains( 'admin_absent', $this->outcomes( $order ) );
	}

	public function test_resend_after_invalid_buyer_is_refused_with_field_names_while_billing_is_still_invalid(): void {
		$order = $this->order_with_invalid_buyer();

		$response = $this->htmx( 'POST', '/invoices/' . $this->record( $order )->id . '/resend', array( 'confirm' => '1' ) );

		$this->assertSame( 422, $response->get_status() );
		$html = $this->html( $response );
		$this->assertStringContainsString( 'số điện thoại', $html );
		$this->assertStringNotContainsString( '12345', $html );
		$this->assertSame( InvoiceStatus::FAILED, $this->record( $order )->status );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
	}

	public function test_resend_after_invalid_buyer_captures_the_fixed_billing_and_creates_once(): void {
		$order = $this->order_with_invalid_buyer();
		$order->set_billing_phone( '0900000003' );
		$order->save();

		$response = $this->htmx( 'POST', '/invoices/' . $this->record( $order )->id . '/resend', array( 'confirm' => '1' ) );
		$this->assertSame( 200, $response->get_status(), $this->html( $response ) );
		$this->run_queued_draft_actions();

		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( '0900000003', $this->sent_invoices()[0]['NMua_SDThoai'] );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->record( $order )->status );
	}

	public function test_resend_after_an_uncertain_create_never_captures_the_buyer_again(): void {
		$order = $this->order_after_timeout();
		$settings = new Settings();
		$settings->save_invoicing( $settings->reference_prefix(), 'Khách đã đổi tên' ); // A capture now would change the snapshot.
		$before = (string) $order->get_meta( BuyerSnapshotter::META_KEY );
		$this->assertNotSame( '', $before );
		$invoice = $this->record( $order );
		( new InvoiceRepository() )->record_error( $invoice->id, InvoiceErrorCode::BUYER_SNAPSHOT_INVALID, 'Snapshot unusable.' );
		$this->fake_lookup_found( 'ZXhpc3Rpbmc=' );

		$response = $this->htmx( 'POST', '/invoices/' . $invoice->id . '/resend', array( 'confirm' => '1' ) );
		$this->assertSame( 200, $response->get_status(), $this->html( $response ) );

		$this->assertSame( $before, (string) wc_get_order( $order->get_id() )?->get_meta( BuyerSnapshotter::META_KEY ) );
		$this->run_queued_draft_actions();
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->record( $order )->status );
	}

	/**
	 * A completed order whose billing phone was edited to an invalid value after an individual
	 * buyer asked for an invoice, so the draft failed with `buyer_invalid` and no snapshot.
	 */
	private function order_with_invalid_buyer(): WC_Order {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$_POST = array(
			'einv_request_invoice' => '1',
			'einv_buyer_type'      => 'individual',
			'einv_address'         => 'Số 1 Đường Thử, Quận Thử',
			'einv_id_number'       => '000000001234',
		);
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_billing_phone( '0900000001' );
		$billing = array(
			'billing_first_name' => $order->get_billing_first_name(),
			'billing_last_name'  => $order->get_billing_last_name(),
			'billing_phone'      => '0900000001',
			'billing_email'      => $order->get_billing_email(),
		);
		do_action( 'woocommerce_checkout_create_order', $order, $billing ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fires a WooCommerce hook.
		$_POST = array();
		$order->set_billing_phone( '12345' );
		$order->save();
		$this->complete_and_run( $order );
		$this->assertSame( 'buyer_invalid', $this->record( $order )->error_code );
		$this->assertSame( '', (string) $order->get_meta( BuyerSnapshotter::META_KEY ) );

		return $order;
	}

	/**
	 * Makes the next restart of an invoice lose as if a concurrent request claimed it first.
	 *
	 * @param InvoiceRecord $record Invoice.
	 */
	private function lose_the_restart_race( InvoiceRecord $record ): void {
		$filter = static function ( string $query ) use ( $record, &$filter ): string {
			if ( ! str_starts_with( $query, 'UPDATE' ) || ! str_contains( $query, 'retry_cycle_start = (SELECT' ) ) {
				return $query;
			}
			remove_filter( 'query', $filter );
			( new InvoiceRepository() )->update_status( $record->id, InvoiceStatus::PENDING );

			return $query;
		};
		add_filter( 'query', $filter );
	}

	public function test_no_invoice_confirmation_is_only_accepted_for_template_changed(): void {
		$order = $this->order_after_timeout();
		$this->force_status( $order, InvoiceStatus::FAILED );

		$response = $this->htmx(
			'POST',
			'/invoices/' . $this->record( $order )->id . '/resend',
			array(
				'confirm'            => '1',
				'confirm_no_invoice' => '1',
				'confirm_reference'  => $this->record( $order )->reference,
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
	}
}
