<?php
/**
 * Resend and status check routes of the admin REST seam.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
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

	public function test_template_changed_blocks_a_plain_resend_but_an_admin_can_confirm_no_invoice_exists(): void {
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
		$record = $this->record( $order );
		$this->assertSame( 'template_changed', $record->error_code );
		$route = '/invoices/' . $record->id . '/resend';

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
