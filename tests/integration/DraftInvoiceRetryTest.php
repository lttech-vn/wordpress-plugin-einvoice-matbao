<?php
/**
 * Background retry, MTChieu lookup before every uncertain send, and recovery of stuck rows.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;

/**
 * The workflow seam with scripted Mat Bao failures over fake HTTP.
 */
final class DraftInvoiceRetryTest extends InvoiceWorkflowTestCase {

	private function completed_order(): WC_Order {
		$this->configure_matbao();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->update_status( 'completed' );

		return $order;
	}

	public function test_timeout_then_existing_draft_is_adopted_without_creating_another(): void {
		$this->fake_create_invoice_timeout();
		$order = $this->completed_order();

		$this->run_queued_draft_actions();

		$this->assertCount( 0, $this->http->requests_to( self::LOOKUP_ROUTE ), 'A first send with no doubt needs no lookup.' );
		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::PENDING, $record?->status );
		$this->assertCount( 1, $this->queued_draft_action_ids() );

		$this->fake_lookup_found( 'ZXhpc3Rpbmc=', '2026-10-14T00:00:00' );
		$this->run_queued_draft_actions();

		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $record?->status );
		$this->assertSame( 'ZXhpc3Rpbmc=', $record->matbao_invoice_id );
		$this->assertSame( '2026-10-14', $record->issued_on );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
		$this->assertSame(
			array(
				'loai'     => 1,
				'mauSo'    => self::TEMPLATE_FORM,
				'kiHieu'   => self::TEMPLATE_SERIES,
				'danhSach' => array( 'WC' . $order->get_id() ),
			),
			$this->http->requests_to( self::LOOKUP_ROUTE )[0]['body']
		);
	}

	public function test_5xx_then_not_found_resends_the_same_reference_and_succeeds(): void {
		$this->http->respond_json( self::CREATE_ROUTE, 503, array( 'message' => 'Dịch vụ tạm ngưng' ) );
		$order = $this->completed_order();
		$this->run_queued_draft_actions();

		$this->fake_lookup_not_found();
		$this->fake_create_invoice_ok( 'bmV3' );
		$this->run_queued_draft_actions();

		$sent = $this->sent_invoices();
		$this->assertCount( 2, $sent );
		$this->assertSame( $sent[0]['MTChieu'], $sent[1]['MTChieu'] );
		$this->assertSame( 1, $sent[1]['KTraMTChieuTrung'] );
		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $record?->status );
		$this->assertSame( 'bmV3', $record->matbao_invoice_id );
		$this->assertSame( array( 'failed', 'success' ), array_column( $this->attempts_of( $record ), 'outcome' ) );
	}

	public function test_error_305_looks_up_again_and_adopts_the_draft_with_the_same_reference(): void {
		$this->http->respond_json( self::CREATE_ROUTE, 200, $this->rejected_item( 305, 'Trùng mã tham chiếu' ) );
		$this->fake_lookup_found( 'ZHVw', '2026-10-15T00:00:00' );
		$order = $this->completed_order();

		$this->run_queued_draft_actions();

		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->assertSame( 'WC' . $order->get_id(), $this->http->requests_to( self::LOOKUP_ROUTE )[0]['body']['danhSach'][0] );
		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $record?->status );
		$this->assertSame( 'ZHVw', $record->matbao_invoice_id );
	}

	public function test_error_305_with_nothing_found_is_retried_later_with_the_same_reference(): void {
		$this->http->respond_json( self::CREATE_ROUTE, 200, $this->rejected_item( 305, 'Trùng mã tham chiếu' ) );
		$this->fake_lookup_not_found();
		$order = $this->completed_order();

		$this->run_queued_draft_actions();

		$this->assertSame( InvoiceStatus::PENDING, $this->invoice_of( $order )?->status );
		$this->assertCount( 1, $this->queued_draft_action_ids() );

		$this->run_queued_draft_actions();

		$sent = $this->sent_invoices();
		$this->assertCount( 2, $sent );
		$this->assertSame( $sent[0]['MTChieu'], $sent[1]['MTChieu'] );
	}

	public function test_backoff_follows_the_schedule_and_stops_after_five_retries(): void {
		$this->fake_create_invoice_timeout();
		$this->fake_lookup_not_found();
		$exhausted = array();
		add_action(
			DraftInvoiceWorkflow::EXHAUSTED_HOOK,
			static function ( int $order_id, string $reference ) use ( &$exhausted ): void {
				$exhausted[] = array( $order_id, $reference );
			},
			10,
			2
		);
		$order = $this->completed_order();

		$delays = array();
		for ( $run = 0; $run < 6; $run++ ) {
			$this->assertSame( 1, $this->run_queued_draft_actions() );
			$delays[] = $this->seconds_until_next_draft_action();
		}

		$expected = array( 60, 300, 900, 3600, 21600, null );
		foreach ( $expected as $index => $seconds ) {
			if ( null === $seconds ) {
				$this->assertNull( $delays[ $index ] );
				continue;
			}
			$this->assertEqualsWithDelta( $seconds, $delays[ $index ], 5, 'Delay after failure ' . ( $index + 1 ) );
		}

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::NEEDS_ATTENTION, $record?->status );
		$this->assertCount( 6, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertCount( 6, $this->attempts_of( $record ) );
		$this->assertSame( array( array( $order->get_id(), 'WC' . $order->get_id() ) ), $exhausted );
		$this->assertSame( 0, $this->run_queued_draft_actions() );
	}

	public function test_data_errors_are_not_retried(): void {
		$this->http->respond_json( self::CREATE_ROUTE, 400, array( 'message' => 'Sai dữ liệu' ) );
		$order = $this->completed_order();
		$this->run_queued_draft_actions();

		$this->assertSame( InvoiceStatus::FAILED, $this->invoice_of( $order )?->status );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
		$this->assertNull( $this->seconds_until_next_draft_action() );
	}

	public function test_rejected_invoice_with_an_error_code_is_not_retried(): void {
		$this->http->respond_json( self::CREATE_ROUTE, 200, $this->rejected_item( 306, 'Mã số thuế không hợp lệ' ) );
		$order = $this->completed_order();
		$this->run_queued_draft_actions();

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::FAILED, $record?->status );
		$this->assertStringContainsString( 'Mã số thuế', $record->status_reason );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
	}

	public function test_response_without_invoice_id_is_treated_as_possibly_created(): void {
		$this->http->respond_json(
			self::CREATE_ROUTE,
			200,
			array(
				'errorCode' => 200,
				'data'      => array(
					array(
						'errorCode' => 200,
						'data'      => array( 'mtChieu' => 'x' ),
						'success'   => true,
					),
				),
				'success'   => true,
			)
		);
		$order = $this->completed_order();
		$this->run_queued_draft_actions();
		$this->assertSame( InvoiceStatus::PENDING, $this->invoice_of( $order )?->status );

		$this->fake_lookup_found();
		$this->run_queued_draft_actions();

		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
	}

	public function test_login_failure_means_nothing_was_created_so_the_retry_skips_the_lookup(): void {
		$this->fake_create_invoice_ok();
		$order = $this->completed_order();
		$this->http->respond_json( 'POST /api/auth/login', 503, array( 'message' => 'Bảo trì' ) );
		$this->run_queued_draft_actions();

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::PENDING, $record?->status );
		$this->assertSame( array( 'not_sent' ), array_column( $this->attempts_of( $record ), 'outcome' ) );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );

		$this->fake_matbao_login_ok();
		$this->run_queued_draft_actions();

		$this->assertCount( 0, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
	}

	public function test_lookup_failure_is_retried_without_creating(): void {
		$this->fake_create_invoice_timeout();
		$order = $this->completed_order();
		$this->run_queued_draft_actions();

		$this->http->respond_json( self::LOOKUP_ROUTE, 502, array( 'message' => 'Bad gateway' ) );
		$this->run_queued_draft_actions();

		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::PENDING, $this->invoice_of( $order )?->status );
		$this->assertCount( 1, $this->queued_draft_action_ids() );
	}

	public function test_the_same_retry_action_running_twice_creates_once(): void {
		$this->fake_create_invoice_timeout();
		$order = $this->completed_order();
		$this->run_queued_draft_actions();
		$this->fake_lookup_found();

		do_action( 'einvoice_matbao_create_draft', $order->get_id() );
		do_action( 'einvoice_matbao_create_draft', $order->get_id() );

		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
	}

	public function test_sending_row_stuck_after_the_claim_is_looked_up_before_any_resend(): void {
		$this->fake_create_invoice_ok();
		$order  = $this->completed_order();
		$record = $this->invoice_of( $order );
		$this->assertNotNull( $record );
		$repository = new InvoiceRepository();
		foreach ( $this->queued_draft_action_ids() as $id ) {
			as_unschedule_all_actions( DraftInvoiceWorkflow::ACTION );
		}
		$repository->claim( $record->id, InvoiceStatus::PENDING, InvoiceStatus::SENDING );
		$repository->start_attempt( $record->id );

		( new DraftInvoiceWorkflow() )->recover_stuck();
		$this->assertSame( InvoiceStatus::SENDING, $this->invoice_of( $order )?->status, 'A fresh claim may still be running.' );

		$this->age_invoice( $record, HOUR_IN_SECONDS );
		( new DraftInvoiceWorkflow() )->recover_stuck();
		$this->assertSame( InvoiceStatus::PENDING, $this->invoice_of( $order )?->status );
		$this->assertCount( 1, $this->queued_draft_action_ids() );

		$this->fake_lookup_found();
		$this->run_queued_draft_actions();

		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
	}

	public function test_pending_row_without_an_action_is_recovered_through_the_lookup(): void {
		$this->fake_create_invoice_ok();
		$this->fake_lookup_not_found();
		add_filter( 'pre_as_enqueue_async_action', static fn(): int => 0 );
		$order = $this->completed_order();
		remove_all_filters( 'pre_as_enqueue_async_action' );

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::PENDING, $record?->status );
		$this->assertSame( array(), $this->queued_draft_action_ids() );

		( new DraftInvoiceWorkflow() )->recover_stuck();
		$this->assertSame( array(), $this->queued_draft_action_ids(), 'A just-reserved row is not an orphan yet.' );

		$this->age_invoice( $record, HOUR_IN_SECONDS );
		( new DraftInvoiceWorkflow() )->recover_stuck();
		( new DraftInvoiceWorkflow() )->recover_stuck();
		$this->assertCount( 1, $this->queued_draft_action_ids() );

		$this->run_queued_draft_actions();

		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
	}

	public function test_pending_row_with_a_scheduled_retry_is_not_an_orphan(): void {
		$this->fake_create_invoice_timeout();
		$order = $this->completed_order();
		$this->run_queued_draft_actions();
		$record = $this->invoice_of( $order );
		$this->assertNotNull( $record );

		$this->age_invoice( $record, HOUR_IN_SECONDS );
		( new DraftInvoiceWorkflow() )->recover_stuck();

		$this->assertCount( 1, $this->queued_draft_action_ids() );
		$this->assertCount( 1, $this->attempts_of( $record ) );
	}

	public function test_order_completed_again_after_being_stopped_is_sent(): void {
		$this->fake_create_invoice_ok();
		$order = $this->completed_order();
		$order->update_status( 'cancelled' );
		$this->run_queued_draft_actions();
		$this->assertSame( InvoiceStatus::STOPPED, $this->invoice_of( $order )?->status );

		$order->update_status( 'completed' );
		$this->run_queued_draft_actions();

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $record?->status );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
	}

	public function test_recovery_sweep_is_scheduled_once(): void {
		DraftInvoiceWorkflow::schedule_recovery();
		DraftInvoiceWorkflow::schedule_recovery();

		$pending = as_get_scheduled_actions(
			array(
				'hook'   => DraftInvoiceWorkflow::RECOVERY_ACTION,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $pending );
	}

	public function test_first_create_stores_its_template_on_the_row(): void {
		$this->fake_create_invoice_timeout();
		$order = $this->completed_order();

		$this->run_queued_draft_actions();

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::PENDING, $record?->status );
		$this->assertSame( self::TEMPLATE_FORM, $record->template_form );
		$this->assertSame( self::TEMPLATE_SERIES, $record->template_series );
	}

	public function test_template_changed_between_attempts_still_looks_up_under_the_stored_template(): void {
		$this->fake_create_invoice_timeout();
		$order = $this->completed_order();
		$this->run_queued_draft_actions();

		$this->offer_another_vat_template();
		( new Settings() )->save_default_template( '1', 'C26TBB' );
		$this->fake_lookup_found( 'b2xk' );
		$this->run_queued_draft_actions();

		$lookups = $this->http->requests_to( self::LOOKUP_ROUTE );
		$this->assertCount( 1, $lookups );
		$this->assertSame( '1', $lookups[0]['body']['mauSo'] );
		$this->assertSame( 'C26TAT', $lookups[0]['body']['kiHieu'] );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $record?->status );
		$this->assertSame( 'b2xk', $record->matbao_invoice_id );
		$this->assertSame( 'C26TAT', $record->template_series );
	}

	public function test_template_changed_and_nothing_found_under_the_stored_template_stops_for_an_admin(): void {
		$this->fake_create_invoice_timeout();
		$order = $this->completed_order();
		$this->run_queued_draft_actions();
		$attention = $this->record_attention();

		$this->offer_another_vat_template();
		( new Settings() )->save_default_template( '1', 'C26TBB' );
		$this->fake_lookup_not_found();
		$this->fake_create_invoice_ok();
		$this->run_queued_draft_actions();

		$this->assertSame( 'C26TAT', $this->http->requests_to( self::LOOKUP_ROUTE )[0]['body']['kiHieu'] );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ), 'Never create under another template after an uncertain create.' );
		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::NOT_CONFIGURED, $record?->status );
		$this->assertSame( 'template_changed', $record->error_code );
		$this->assertSame( 'config', $record->error_kind );
		$this->assertStringContainsString( 'C26TAT', $record->status_reason );
		$this->assertStringContainsString( 'C26TBB', $record->status_reason );
		$this->assertSame( 'C26TAT', $record->template_series );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
		$this->assertSame( array( 'template_changed' ), $attention->codes );
	}

	/**
	 * Adds a second VAT template, 1/C26TBB, to the templates Mat Bao offers.
	 */
	private function offer_another_vat_template(): void {
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
	}

	/**
	 * Collects the codes passed to the needs-attention hook from now on.
	 */
	private function record_attention(): \stdClass {
		$attention        = new \stdClass();
		$attention->codes = array();
		add_action(
			DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION,
			static function ( $invoice, InvoiceErrorCode $code ) use ( $attention ): void {
				$attention->codes[] = $code->value;
			},
			10,
			2
		);

		return $attention;
	}

	/**
	 * Create-invoice response whose envelope succeeds but whose single item is rejected.
	 *
	 * @param int    $code    Item `errorCode`.
	 * @param string $message Item message.
	 * @return array<string, mixed>
	 */
	private function rejected_item( int $code, string $message ): array {
		return array(
			'errorCode' => 200,
			'message'   => 'Thành công',
			'data'      => array(
				array(
					'errorCode' => $code,
					'message'   => $message,
					'data'      => null,
					'success'   => false,
				),
			),
			'success'   => true,
		);
	}
}
