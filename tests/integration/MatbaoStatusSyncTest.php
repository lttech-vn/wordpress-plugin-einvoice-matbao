<?php
/**
 * Hourly Mat Bao status sync: batching, outcomes and alerts, with Mat Bao faked at the HTTP boundary.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Alert\AdminAlertNotice;
use LTTech\EinvoiceMatbao\Alert\AlertStore;
use LTTech\EinvoiceMatbao\Alert\InvoiceAlerts;
use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\MatbaoStatusSync;
use LTTech\EinvoiceMatbao\Invoice\StatusSyncBackoff;
use LTTech\EinvoiceMatbao\Invoice\SyncOutcome;
use LTTech\EinvoiceMatbao\Lark\LarkWebhook;
use LTTech\EinvoiceMatbao\Matbao\MatbaoClient;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Plugin;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;

/**
 * Invoice rows are inserted directly in `draft_created`; the lookup answers per reference.
 */
final class MatbaoStatusSyncTest extends InvoiceWorkflowTestCase {

	use MonitoringFixture;

	/**
	 * Last order ID given to a fixture invoice.
	 *
	 * @var int
	 */
	private int $next_order = 70000;

	/**
	 * Fake Sentry endpoint, when log capture is on.
	 *
	 * @var SentrySink|null
	 */
	private ?SentrySink $sink = null;

	public function tear_down(): void {
		$this->reset_monitoring();
		parent::tear_down();
	}

	public function set_up(): void {
		parent::set_up();
		$this->configure_matbao();
		InvoiceAlerts::register();
	}

	public function test_draft_gets_issued(): void {
		$invoice = $this->draft();
		$this->answer(
			array(
				$invoice->reference => array_merge(
					$this->issued_row(),
					array(
						'shDon' => 125,
						'mccqt' => 'MCCQT1',
					)
				),
			)
		);

		$this->assertSame( SyncOutcome::UPDATED, ( new MatbaoStatusSync() )->sync_one( $invoice ) );

		$after = $this->reload( $invoice );
		$this->assertSame( 4, $after->matbao_status );
		$this->assertSame( 125, $after->invoice_number );
		$this->assertSame( 'MCCQT1', $after->tax_authority_code );
		$this->assertNotEmpty( $after->matbao_synced_at );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $after->status );
	}

	public function test_final_invoice_not_polled_again(): void {
		$invoice = $this->draft();
		$this->answer( array( $invoice->reference => $this->issued_row() ) );
		( new MatbaoStatusSync() )->sync_batch();
		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
	}

	public function test_older_than_30_days_skipped(): void {
		$this->draft( array( 'issued_on' => $this->days_ago( 31 ) ) );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 0, $this->http->requests_to( self::LOOKUP_ROUTE ) );
	}

	public function test_draft_created_before_upgrade_is_synced(): void {
		$invoice = $this->draft( array( 'issued_on' => $this->days_ago( 3 ) ) );
		$this->assertNull( $invoice->matbao_status );
		$this->answer( array() );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
	}

	public function test_batch_limit_and_rotation(): void {
		$refs = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$refs[] = $this->draft()->reference;
		}
		$this->answer( array() );

		( new MatbaoStatusSync() )->sync_batch();
		$first = $this->requested_references();
		( new MatbaoStatusSync() )->sync_batch();
		$second = array_slice( $this->requested_references(), count( $first ) );

		$this->assertCount( 200, $first );
		$this->assertCount( 50, array_intersect( $second, array_diff( $refs, $first ) ) );
		$this->assertSame( array(), array_diff( array_diff( $refs, $first ), $second ) );
	}

	public function test_requests_grouped_by_template(): void {
		$expected = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$expected[ $this->draft()->reference ] = self::TEMPLATE_SERIES;
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$expected[ $this->draft( array( 'template_series' => 'C26TBB' ) )->reference ] = 'C26TBB';
		}
		$this->answer( array() );

		( new MatbaoStatusSync() )->sync_batch();

		$requests = $this->http->requests_to( self::LOOKUP_ROUTE );
		$sizes    = array_map( static fn( array $request ): int => count( $request['body']['danhSach'] ), $requests );
		sort( $sizes );
		$this->assertSame( array( 3, 5, 20 ), $sizes );
		$seen = array();
		foreach ( $requests as $request ) {
			$body   = $request['body'];
			$series = $body['kiHieu'];
			$this->assertSame( self::TEMPLATE_FORM, $body['mauSo'] );
			$this->assertContains( $series, array( self::TEMPLATE_SERIES, 'C26TBB' ) );
			foreach ( $body['danhSach'] as $reference ) {
				$this->assertSame( $series, $expected[ $reference ] ?? null, 'Reference sent under the wrong template.' );
				$seen[] = $reference;
			}
		}
		$this->assertCount( 28, array_unique( $seen ) );
	}

	public function test_other_plugin_statuses_ignored(): void {
		foreach ( array( InvoiceStatus::FAILED, InvoiceStatus::PENDING, InvoiceStatus::SKIPPED ) as $status ) {
			$this->draft( array( 'status' => $status ) );
		}

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 0, $this->http->requests_to( self::LOOKUP_ROUTE ) );
	}

	public function test_unknown_code_kept_polling(): void {
		$invoice = $this->draft();
		$this->answer(
			array(
				$invoice->reference => array_merge(
					$this->issued_row(),
					array(
						'maTThaiHDon'  => 12,
						'tenTThaiHDon' => 'Trạng thái mới',
					)
				),
			)
		);
		$alerts = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		( new MatbaoStatusSync() )->sync_batch();

		$after = $this->reload( $invoice );
		$this->assertSame( 12, $after->matbao_status );
		$this->assertSame( 'Trạng thái mới', $after->matbao_status_name );
		$this->assertCount( 1, ( new InvoiceRepository() )->due_for_status_sync( 10, $this->days_ago( 30 ) ) );
		$this->assertSame( $alerts, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
	}

	public function test_matching_row_not_sent_to_authority(): void {
		$invoice = $this->draft();
		$this->answer( array( $invoice->reference => array_merge( $this->issued_row(), array( 'maTThaiHDon' => 3 ) ) ) );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertSame( 3, $this->reload( $invoice )->matbao_status );
	}

	public function test_not_found_301_marks_deleted(): void {
		$invoice = $this->draft();
		$this->fake_lookup_not_found();

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertSame( 0, $this->reload( $invoice )->matbao_status );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
	}

	public function test_listed_as_not_found_marks_deleted(): void {
		$invoice = $this->draft();
		$this->answer( array( $invoice->reference => 'notfound' ) );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertSame( 0, $this->reload( $invoice )->matbao_status );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
	}

	public function test_unreadable_row_keeps_status(): void {
		$invoice = $this->draft();
		$this->http->respond_json( self::LOOKUP_ROUTE, 200, $this->envelope( array( 'garbage' ), array() ) );

		$this->assertSame( SyncOutcome::UNCONFIRMED, ( new MatbaoStatusSync() )->sync_one( $invoice ) );

		$this->assertNull( $this->reload( $invoice )->matbao_status );
	}

	public function test_matching_row_with_deleted_code_keeps_status_and_polling(): void {
		$this->enable_log_capture();
		$invoice = $this->draft( array( 'matbao_status' => 1 ) );
		$this->answer( array( $invoice->reference => $this->row_with_status( 0 ) ) );

		$this->assertSame( SyncOutcome::UNCONFIRMED, ( new MatbaoStatusSync() )->sync_one( $invoice ) );

		$after = $this->reload( $invoice );
		$this->assertSame( 1, $after->matbao_status );
		$this->assertNotNull( $after->matbao_checked_at );
		$this->assertContains( 'Mat Bao status lookup did not confirm the invoice', $this->warn_logs() );
		$this->assertCount( 1, ( new InvoiceRepository() )->due_for_status_sync( 10, $this->days_ago( 30 ) ) );
	}

	/**
	 * A matching row without a readable status changes nothing.
	 *
	 * @dataProvider unreadable_status_rows
	 *
	 * @param array<string, mixed> $override Fields replacing the status of the matching row.
	 */
	public function test_matching_row_with_unreadable_status_keeps_state( array $override ): void {
		$this->enable_log_capture();
		$invoice = $this->draft(
			array(
				'matbao_status' => 2,
				'error_code'    => 'matbao_sign_failed',
				'error_kind'    => 'matbao',
				'status_reason' => 'Lỗi ký',
			)
		);
		$row     = $this->row_with_status( 4 );
		unset( $row['maTThaiHDon'] );
		$this->answer( array( $invoice->reference => array_merge( $row, $override ) ) );

		$this->assertSame( SyncOutcome::UNCONFIRMED, ( new MatbaoStatusSync() )->sync_one( $invoice ) );

		$after = $this->reload( $invoice );
		$this->assertSame( 2, $after->matbao_status );
		$this->assertSame( 'matbao_sign_failed', $after->error_code );
		$this->assertContains( 'Mat Bao status lookup did not confirm the invoice', $this->warn_logs() );
	}

	/**
	 * Rows whose `maTThaiHDon` is missing or not a number.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function unreadable_status_rows(): array {
		return array(
			'missing'   => array( array() ),
			'not_a_num' => array( array( 'maTThaiHDon' => 'abc' ) ),
			'null'      => array( array( 'maTThaiHDon' => null ) ),
		);
	}

	public function test_db_failure_isolated_to_one_invoice(): void {
		$this->enable_log_capture();
		$broken = $this->draft();
		$good   = $this->draft();
		$this->answer(
			array(
				$broken->reference => $this->row_with_status( 2 ),
				$good->reference   => $this->row_with_status( 4 ),
			)
		);
		$break = static fn( string $query ): string => 1 === preg_match( '/^UPDATE .*matbao_status = .* WHERE id = ' . $broken->id . ' /', $query ) ? 'UPDATE broken_table_xyz SET a = 1' : $query;
		add_filter( 'query', $break );
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		$alerts     = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		( new MatbaoStatusSync() )->sync_batch();

		$wpdb->suppress_errors( $suppressed );
		remove_filter( 'query', $break );
		$this->assertNull( $this->reload( $broken )->matbao_status );
		$this->assertSame( '', $this->reload( $broken )->error_code );
		$this->assertSame( 4, $this->reload( $good )->matbao_status );
		$this->assertSame( $alerts, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
		$this->assertContains( 'Mat Bao status could not be stored', $this->warn_logs() );
	}

	public function test_reference_missing_from_answer_keeps_status(): void {
		$this->enable_log_capture();
		$invoice = $this->draft();
		$other   = $this->draft();
		$this->answer( array( $other->reference => $this->issued_row() ) );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertNull( $this->reload( $invoice )->matbao_status );
		$this->assertNotNull( $this->reload( $invoice )->matbao_checked_at );
		$this->assertSame( 4, $this->reload( $other )->matbao_status );
		$this->assertContains( 'Mat Bao status lookup did not confirm the invoice', $this->warn_logs() );
	}

	public function test_different_invoice_under_reference_keeps_status(): void {
		$this->enable_log_capture();
		$invoice = $this->draft();
		$this->answer( array( $invoice->reference => array_merge( $this->issued_row(), array( 'maSoHDon' => 'other-id' ) ) ) );

		$this->assertSame( SyncOutcome::UNCONFIRMED, ( new MatbaoStatusSync() )->sync_one( $invoice ) );

		$this->assertNull( $this->reload( $invoice )->matbao_status );
		$this->assertContains( 'Mat Bao status lookup did not confirm the invoice', $this->warn_logs() );
	}

	public function test_no_credentials_no_request(): void {
		delete_option( 'einvoice_matbao_credentials' );
		$this->draft();

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 0, $this->http->requests_to( self::LOOKUP_ROUTE ) );
	}

	public function test_sign_error_detected_alerts_once(): void {
		$invoice = $this->draft();
		$before  = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );
		$this->answer( array( $invoice->reference => $this->row_with_status( 2 ) ) );

		( new MatbaoStatusSync() )->sync_batch();

		$after = $this->reload( $invoice );
		$this->assertSame( $before + 1, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
		$this->assertSame( 'matbao_sign_failed', $after->error_code );
		$this->assertSame( $invoice->alert_cycle + 1, $after->alert_cycle );
	}

	public function test_same_abnormal_status_next_hour_no_new_alert(): void {
		$invoice = $this->draft();
		$this->answer( array( $invoice->reference => $this->row_with_status( 2 ) ) );
		( new MatbaoStatusSync() )->sync_batch();
		$count = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertSame( $count, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
		$this->assertSame( $invoice->alert_cycle + 1, $this->reload( $invoice )->alert_cycle );
	}

	public function test_concurrent_writers_alert_once(): void {
		$invoice = $this->draft();
		$this->answer( array( $invoice->reference => $this->row_with_status( 2 ) ) );
		$before = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		( new MatbaoStatusSync() )->sync_one( $invoice );
		( new MatbaoStatusSync() )->sync_one( $invoice );

		$this->assertSame( $before + 1, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
	}

	public function test_issued_is_not_alerted(): void {
		$invoice = $this->draft();
		$this->answer( array( $invoice->reference => $this->issued_row() ) );
		$before = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertSame( $before, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
	}

	public function test_abnormal_status_shows_notice_and_survives_cleanup(): void {
		$invoice = $this->draft( $this->real_order_columns() );
		$this->answer( array( $invoice->reference => $this->row_with_status( 2 ) ) );
		( new MatbaoStatusSync() )->sync_batch();

		$this->assertStringContainsString( '#' . $invoice->order_id, $this->notice_html() );
		AlertStore::cleanup();

		$this->assertCount( 1, AlertStore::keys_of_order( $invoice->order_id ) );
		$this->assertStringContainsString( '#' . $invoice->order_id, $this->notice_html() );
	}

	public function test_recovered_after_resigning_clears_code(): void {
		$invoice = $this->draft( $this->real_order_columns() );
		$this->answer( array( $invoice->reference => $this->row_with_status( 2 ) ) );
		( new MatbaoStatusSync() )->sync_batch();
		$this->assertStringContainsString( '#' . $invoice->order_id, $this->notice_html() );
		$this->answer( array( $invoice->reference => $this->issued_row() ) );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertSame( '', $this->reload( $invoice )->error_code );
		AlertStore::cleanup();
		$this->assertStringNotContainsString( '#' . $invoice->order_id, $this->notice_html() );
	}

	public function test_order_warning_kept_on_recovery(): void {
		$invoice = $this->draft(
			array(
				'error_code' => 'order_amounts_changed',
				'error_kind' => 'matbao',
			)
		);
		$this->answer( array( $invoice->reference => $this->issued_row() ) );

		( new MatbaoStatusSync() )->sync_batch();

		$after = $this->reload( $invoice );
		$this->assertSame( 'order_amounts_changed', $after->error_code );
		$this->assertSame( 4, $after->matbao_status );
	}

	public function test_abnormal_status_replaces_order_warning(): void {
		$invoice = $this->draft(
			array(
				'error_code' => 'order_amounts_changed',
				'error_kind' => 'matbao',
			)
		);
		$this->answer( array( $invoice->reference => $this->row_with_status( 6 ) ) );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertSame( 'matbao_not_eligible', $this->reload( $invoice )->error_code );
		$keys = AlertStore::keys_of_order( $invoice->order_id );
		$this->assertCount( 1, $keys );
		$this->assertSame( 'matbao_not_eligible', AlertStore::get( $keys[0] )['code'] ?? null );
	}

	public function test_draft_recreated_is_synced_again(): void {
		$invoice = $this->draft();
		$this->fake_lookup_not_found();
		( new MatbaoStatusSync() )->sync_batch();
		$this->assertSame( 0, $this->reload( $invoice )->matbao_status );
		$this->assertCount( 0, ( new InvoiceRepository() )->due_for_status_sync( 10, $this->days_ago( 30 ) ) );

		( new InvoiceRepository() )->update_status( $invoice->id, InvoiceStatus::DRAFT_CREATED, '', array( 'matbao_invoice_id' => 'new-id' ) );

		$due = ( new InvoiceRepository() )->due_for_status_sync( 10, $this->days_ago( 30 ) );
		$this->assertSame( array( $invoice->id ), array_map( static fn( InvoiceRecord $row ): int => $row->id, $due ) );
	}

	public function test_stale_lookup_after_recreation_not_written(): void {
		$invoice = $this->draft();
		$this->http->respond_with(
			self::LOOKUP_ROUTE,
			function () use ( $invoice ): array {
				( new InvoiceRepository() )->update_status( $invoice->id, InvoiceStatus::DRAFT_CREATED, '', array( 'matbao_invoice_id' => 'recreated-id' ) );
				return FakeHttp::json_response(
					200,
					$this->envelope(
						array(
							array_merge(
								$this->row_with_status( 2 ),
								array(
									'mtChieu'  => $invoice->reference,
									'maSoHDon' => $invoice->matbao_invoice_id,
								)
							),
						),
						array()
					)
				);
			}
		);
		$before = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		$this->assertSame( SyncOutcome::UNCHANGED, ( new MatbaoStatusSync() )->sync_one( $invoice ) );

		$after = $this->reload( $invoice );
		$this->assertNull( $after->matbao_status );
		$this->assertSame( '', $after->error_code );
		$this->assertSame( $before, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
	}

	public function test_alert_excludes_tax_note(): void {
		$this->with_encryption_key();
		( new CredentialStore() )->save_lark( new LarkWebhook( self::LARK_WEBHOOK, self::LARK_SECRET ) );
		$lark = 'POST ' . (string) wp_parse_url( self::LARK_WEBHOOK, PHP_URL_PATH );
		$this->http->respond_json(
			$lark,
			200,
			array(
				'code' => 0,
				'msg'  => 'success',
			)
		);
		$invoice = $this->draft( $this->real_order_columns() );
		$this->answer( array( $invoice->reference => array_merge( $this->row_with_status( 6 ), array( 'ghiChuCQT' => 'GhiChuBiMat-079012345678' ) ) ) );

		( new MatbaoStatusSync() )->sync_batch();
		$key = AlertStore::keys_of_order( $invoice->order_id )[0];
		InvoiceAlerts::send( $key );

		$text = (string) wp_json_encode( $this->http->requests_to( $lark ) );
		$this->assertStringContainsString( '#' . $invoice->order_id, $text );
		$this->assertStringContainsString( 'matbao_not_eligible', $text );
		$this->assertStringContainsString( trim( (string) wp_json_encode( InvoiceErrorCode::MATBAO_NOT_ELIGIBLE->hint() ), '"' ), str_replace( '\\\\u', '\\u', $text ) );
		$this->assertStringNotContainsString( 'GhiChuBiMat', $text );
		$this->assertStringNotContainsString( '079012345678', $text );
		$this->assertStringNotContainsString( 'GhiChuBiMat', $this->notice_html() );
	}

	public function test_deactivation_unschedules_status_sync(): void {
		MatbaoStatusSync::schedule();
		$this->assertTrue( as_has_scheduled_action( MatbaoStatusSync::ACTION ) );

		Plugin::deactivate();

		$this->assertFalse( as_has_scheduled_action( MatbaoStatusSync::ACTION ) );
	}

	public function test_schedule_is_idempotent(): void {
		MatbaoStatusSync::schedule();
		MatbaoStatusSync::schedule();

		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => MatbaoStatusSync::ACTION,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);
	}

	public function test_lookup_503_stops_the_run(): void {
		$a = $this->draft(
			array(
				'template_series' => 'C26TAA',
				'matbao_status'   => 3,
			)
		);
		$b = $this->draft(
			array(
				'template_series'   => 'C26TBB',
				'matbao_checked_at' => '2026-01-01 00:00:00',
			)
		);
		$this->fail_lookups( 503, '{"message":"down"}' );
		$alerts = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->assertSame( 3, $this->reload( $a )->matbao_status );
		$this->assertNotNull( $this->reload( $a )->matbao_checked_at );
		$this->assertSame( '2026-01-01 00:00:00', $this->reload( $b )->matbao_checked_at );
		$this->assertSame( $alerts, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
	}

	public function test_lookup_timeout_stops_the_run(): void {
		$a = $this->draft( array( 'template_series' => 'C26TAA' ) );
		$b = $this->draft(
			array(
				'template_series'   => 'C26TBB',
				'matbao_status'     => 1,
				'matbao_checked_at' => '2026-01-01 00:00:00',
			)
		);
		$this->fail_lookups_with_transport_error();
		$alerts = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->assertNotNull( $this->reload( $a )->matbao_checked_at );
		$this->assertSame( 1, $this->reload( $b )->matbao_status );
		$this->assertSame( '2026-01-01 00:00:00', $this->reload( $b )->matbao_checked_at );
		$this->assertSame( $alerts, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
	}

	/**
	 * Other temporary failures stop the run and pause the sync for 15 minutes.
	 *
	 * @dataProvider other_temporary_failures
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Raw body.
	 */
	public function test_other_temporary_failures_stop_the_run( int $status, string $body ): void {
		$this->draft( array( 'template_series' => 'C26TAA' ) );
		$this->draft( array( 'template_series' => 'C26TBB' ) );
		$this->fail_lookups( $status, $body );
		$alerts = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->assert_pause( 900 );
		$this->assertSame( $alerts, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
	}

	/**
	 * Temporary failures besides 5xx and transport errors.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function other_temporary_failures(): array {
		return array(
			'timeout 408'      => array( 408, '{}' ),
			'rate limit 429'   => array( 429, '{}' ),
			'no success flag'  => array( 200, '{"errorCode":200,"data":{}}' ),
			'no invoices list' => array( 200, '{"success":true,"errorCode":200,"data":{}}' ),
		);
	}

	/**
	 * A login that fails temporarily stops the run before any lookup is sent.
	 *
	 * @dataProvider login_failures
	 *
	 * @param bool $transport Whether the login fails at transport level (otherwise HTTP 503).
	 */
	public function test_login_failure_stops_the_run( bool $transport ): void {
		$a = $this->draft( array( 'template_series' => 'C26TAA' ) );
		$b = $this->draft( array( 'template_series' => 'C26TBB' ) );
		$this->forget_cached_token();
		if ( $transport ) {
			$this->http->respond_with( 'POST /api/auth/login', static fn(): \WP_Error => new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
		} else {
			$this->http->respond_json( 'POST /api/auth/login', 503, array( 'message' => 'down' ) );
		}
		$this->answer( array() );
		$alerts = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 1, $this->http->requests_to( 'POST /api/auth/login' ) );
		$this->assertCount( 0, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->assertNotNull( $this->reload( $a )->matbao_checked_at );
		$this->assertNull( $this->reload( $b )->matbao_checked_at );
		$this->assert_pause( 900 );
		$this->assertSame( $alerts, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
	}

	/**
	 * Login failure shapes.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public static function login_failures(): array {
		return array(
			'http 503'  => array( false ),
			'transport' => array( true ),
		);
	}

	public function test_expired_token_refreshed_does_not_pause(): void {
		$invoice = $this->draft();
		$calls   = 0;
		$this->http->respond_with(
			self::LOOKUP_ROUTE,
			function ( array $args ) use ( $invoice, &$calls ): array {
				++$calls;
				if ( 1 === $calls ) {
					return FakeHttp::json_response( 401, array( 'message' => 'expired' ) );
				}
				$sent = json_decode( (string) $args['body'], true );
				$this->assertContains( $invoice->reference, $sent['danhSach'] );

				return FakeHttp::json_response(
					200,
					$this->envelope(
						array(
							array_merge(
								$this->issued_row(),
								array(
									'mtChieu'  => $invoice->reference,
									'maSoHDon' => $invoice->matbao_invoice_id,
								)
							),
						),
						array()
					)
				);
			}
		);

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 2, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$first_lookup = null;
		$login_after  = false;
		foreach ( $this->http->requests as $index => $request ) {
			if ( null === $first_lookup && str_ends_with( $request['url'], '/api/invoice/invoice-detail-batch' ) ) {
				$first_lookup = $index;
			} elseif ( null !== $first_lookup && str_ends_with( $request['url'], '/api/auth/login' ) ) {
				$login_after = true;
			}
		}
		$this->assertTrue( $login_after, 'The client must log in again after the 401.' );
		$this->assertSame( 4, $this->reload( $invoice )->matbao_status );
		$this->assertFalse( $this->backoff_state() );
		$this->assertSame( array(), $this->pending_retries() );
	}

	public function test_unreadable_row_does_not_pause(): void {
		$a = $this->draft( array( 'template_series' => 'C26TAA' ) );
		$b = $this->draft( array( 'template_series' => 'C26TBB' ) );
		$this->answer(
			array(
				$a->reference => array_merge( $this->issued_row(), array( 'maTThaiHDon' => null ) ),
				$b->reference => $this->issued_row(),
			)
		);

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 2, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->assertSame( 4, $this->reload( $b )->matbao_status );
		$this->assertFalse( $this->backoff_state() );
	}

	public function test_non_temporary_failure_continues(): void {
		$a = $this->draft( array( 'template_series' => 'C26TAA' ) );
		$b = $this->draft( array( 'template_series' => 'C26TBB' ) );
		$this->http->respond_with(
			self::LOOKUP_ROUTE,
			function ( array $args ) use ( $b ): array {
				$sent = json_decode( (string) $args['body'], true );
				if ( 'C26TAA' === $sent['kiHieu'] ) {
					return FakeHttp::json_response( 400, array( 'message' => 'bad request' ) );
				}

				return FakeHttp::json_response(
					200,
					$this->envelope(
						array(
							array_merge(
								$this->issued_row(),
								array(
									'mtChieu'  => $b->reference,
									'maSoHDon' => $b->matbao_invoice_id,
								)
							),
						),
						array()
					)
				);
			}
		);

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertNull( $this->reload( $a )->matbao_status );
		$this->assertSame( 4, $this->reload( $b )->matbao_status );
		$this->assertFalse( $this->backoff_state() );
		$this->assertSame( array(), $this->pending_retries() );
	}

	public function test_first_failure_pauses_15_minutes(): void {
		$this->draft();
		$this->fail_lookups( 503 );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assert_pause( 900 );
		$this->assertSame( 1, $this->backoff_state()['failures'] );
	}

	/**
	 * The pause follows the ladder and stays at six hours.
	 *
	 * @dataProvider pause_ladder
	 *
	 * @param int $failures Failures already recorded.
	 * @param int $expected Pause after the next failure, in seconds.
	 */
	public function test_pause_grows_up_to_six_hours( int $failures, int $expected ): void {
		$this->draft();
		$this->pause_with( $failures, time() - 1 );
		$this->fail_lookups( 503 );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assert_pause( $expected );
		$this->assertSame( $failures + 1, $this->backoff_state()['failures'] );
	}

	/**
	 * Recorded failures => next pause.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function pause_ladder(): array {
		return array(
			'after 1' => array( 1, 1800 ),
			'after 2' => array( 2, 3600 ),
			'after 3' => array( 3, 7200 ),
			'after 4' => array( 4, 14400 ),
			'after 5' => array( 5, 21600 ),
			'after 6' => array( 6, 21600 ),
		);
	}

	public function test_pause_expired_without_success_keeps_counting(): void {
		$this->draft();
		$this->pause_with( 2, time() - 1 );
		$this->fail_lookups( 503 );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assert_pause( 3600 );
	}

	/**
	 * A stored state that is not valid counts as no backoff.
	 *
	 * @dataProvider unreadable_backoff_states
	 *
	 * @param mixed $stored Raw option value.
	 */
	public function test_unreadable_backoff_state_is_ignored( mixed $stored ): void {
		$this->draft();
		update_option( StatusSyncBackoff::OPTION, $stored, false );
		$this->fail_lookups( 503 );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->assert_pause( 900 );
	}

	/**
	 * Option values the backoff must not trust.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unreadable_backoff_states(): array {
		return array(
			'string'         => array( 'garbage' ),
			'empty array'    => array( array() ),
			'negative'       => array(
				array(
					'failures' => -1,
					'until'    => 'x',
				),
			),
			'numeric string' => array(
				array(
					'failures' => '2',
					'until'    => '9999999999',
				),
			),
		);
	}

	public function test_hourly_run_during_pause_sends_nothing(): void {
		$invoice = $this->draft( array( 'matbao_checked_at' => '2026-01-01 00:00:00' ) );
		$this->pause_with( 1, time() + 600 );
		$before = $this->backoff_state();

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertSame( 0, $this->matbao_requests() );
		$this->assertSame( '2026-01-01 00:00:00', $this->reload( $invoice )->matbao_checked_at );
		$this->assertSame( $before, $this->backoff_state() );
	}

	public function test_success_then_failure_in_one_run(): void {
		$a = $this->draft( array( 'template_series' => 'C26TAA' ) );
		$b = $this->draft( array( 'template_series' => 'C26TBB' ) );
		$c = $this->draft(
			array(
				'template_series'   => 'C26TCC',
				'matbao_checked_at' => '2026-01-01 00:00:00',
			)
		);
		$this->pause_with( 2, time() - 1 );
		$this->http->respond_with(
			self::LOOKUP_ROUTE,
			function ( array $args ) use ( $a ): array {
				$sent = json_decode( (string) $args['body'], true );
				if ( 'C26TAA' !== $sent['kiHieu'] ) {
					return FakeHttp::json_response( 503, array( 'message' => 'down' ) );
				}

				return FakeHttp::json_response(
					200,
					$this->envelope(
						array(
							array_merge(
								$this->row_with_status( 3 ),
								array(
									'mtChieu'  => $a->reference,
									'maSoHDon' => $a->matbao_invoice_id,
								)
							),
						),
						array()
					)
				);
			}
		);

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertSame( 3, $this->reload( $a )->matbao_status );
		$this->assertNotNull( $this->reload( $b )->matbao_checked_at );
		$this->assertNotContains( $c->reference, $this->requested_references() );
		$this->assertSame( '2026-01-01 00:00:00', $this->reload( $c )->matbao_checked_at );
		$this->assert_pause( 900 );
		$this->assertSame( 1, $this->backoff_state()['failures'] );
	}

	public function test_not_found_clears_backoff_and_alerts(): void {
		$invoice = $this->draft();
		$this->pause_with( 1, time() - 1 );
		$this->queue_retry_at( time() + 600 );
		$this->fake_lookup_not_found();
		$alerts = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertFalse( $this->backoff_state() );
		$this->assertSame( array(), $this->pending_retries() );
		$after = $this->reload( $invoice );
		$this->assertSame( 0, $after->matbao_status );
		$this->assertSame( 'matbao_draft_deleted', $after->error_code );
		$this->assertSame( $alerts + 1, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
	}

	/**
	 * A successful lookup ends the backoff whatever recording the answer does.
	 *
	 * @dataProvider apply_outcomes
	 *
	 * @param SyncOutcome $expected Outcome of recording the answer.
	 */
	public function test_successful_lookup_clears_backoff_whatever_apply_returns( SyncOutcome $expected ): void {
		$invoice = $this->draft();
		$this->pause_with( 2, time() - 1 );
		$this->queue_retry_at( time() + 600 );
		global $wpdb;
		$break      = null;
		$suppressed = null;
		if ( SyncOutcome::UNCONFIRMED === $expected ) {
			$this->answer( array() );
		} elseif ( SyncOutcome::FAILED === $expected ) {
			$this->answer( array( $invoice->reference => $this->row_with_status( 2 ) ) );
			$break      = static fn( string $query ): string => 1 === preg_match( '/^UPDATE .*matbao_status = .* WHERE id = ' . $invoice->id . ' /', $query ) ? 'UPDATE broken_table_xyz SET a = 1' : $query;
			$suppressed = $wpdb->suppress_errors( true );
			add_filter( 'query', $break );
		} else {
			$this->http->respond_with(
				self::LOOKUP_ROUTE,
				function () use ( $invoice ): array {
					( new InvoiceRepository() )->update_status( $invoice->id, InvoiceStatus::DRAFT_CREATED, '', array( 'matbao_invoice_id' => 'recreated-id' ) );

					return FakeHttp::json_response(
						200,
						$this->envelope(
							array(
								array_merge(
									$this->row_with_status( 2 ),
									array(
										'mtChieu'  => $invoice->reference,
										'maSoHDon' => $invoice->matbao_invoice_id,
									)
								),
							),
							array()
						)
					);
				}
			);
		}

		$outcome = ( new MatbaoStatusSync() )->sync_one( $invoice );

		if ( null !== $break ) {
			$wpdb->suppress_errors( (bool) $suppressed );
			remove_filter( 'query', $break );
		}
		$this->assertSame( $expected, $outcome );
		$this->assertFalse( $this->backoff_state() );
		$this->assertSame( array(), $this->pending_retries() );
		$this->assertNull( $this->reload( $invoice )->matbao_status );
	}

	/**
	 * Outcomes of recording a successful answer that do not store a status.
	 *
	 * @return array<string, array{0: SyncOutcome}>
	 */
	public static function apply_outcomes(): array {
		return array(
			'unconfirmed' => array( SyncOutcome::UNCONFIRMED ),
			'failed'      => array( SyncOutcome::FAILED ),
			'unchanged'   => array( SyncOutcome::UNCHANGED ),
		);
	}

	public function test_success_cancels_retry_without_option(): void {
		$invoice = $this->draft();
		$this->queue_retry_at( time() + 600 );
		$this->answer( array( $invoice->reference => $this->issued_row() ) );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertSame( array(), $this->pending_retries() );
		$this->assertFalse( $this->backoff_state() );
	}

	public function test_success_without_backoff_writes_no_option(): void {
		$invoice = $this->draft();
		$this->answer( array( $invoice->reference => $this->issued_row() ) );
		$touches = 0;
		$count   = static function ( mixed $value ) use ( &$touches ): mixed {
			++$touches;

			return $value;
		};
		$deleted = static function () use ( &$touches ): void {
			++$touches;
		};
		add_filter( 'pre_update_option_' . StatusSyncBackoff::OPTION, $count );
		add_action( 'delete_option', $deleted );

		( new MatbaoStatusSync() )->sync_batch();

		remove_filter( 'pre_update_option_' . StatusSyncBackoff::OPTION, $count );
		remove_action( 'delete_option', $deleted );
		$this->assertSame( 4, $this->reload( $invoice )->matbao_status );
		$this->assertSame( 0, $touches );
	}

	public function test_retry_action_runs_the_sync(): void {
		MatbaoStatusSync::register();
		$invoice = $this->draft();
		$this->answer( array( $invoice->reference => $this->issued_row() ) );

		do_action( StatusSyncBackoff::RETRY_ACTION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- constant with the plugin prefix.

		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
	}

	public function test_retry_run_after_pause_clears_backoff(): void {
		MatbaoStatusSync::register();
		MatbaoStatusSync::schedule();
		$pending = \ActionScheduler_Store::STATUS_PENDING;
		$hourly  = $this->hourly_action();
		$invoice = $this->draft();
		$this->fail_lookups( 503 );
		( new MatbaoStatusSync() )->sync_batch();
		$this->assertCount( 1, $this->pending_retries() );
		$this->pause_with( 1, time() - 1 );
		$this->answer( array( $invoice->reference => $this->row_with_status( 3 ) ) );

		do_action( StatusSyncBackoff::RETRY_ACTION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- constant with the plugin prefix.

		$this->assertSame( 3, $this->reload( $invoice )->matbao_status );
		$this->assertFalse( $this->backoff_state() );
		$this->assertSame( array(), $this->pending_retries() );
		$this->assertSame( $hourly, $this->hourly_action() );
		$this->assertSame( 3600, $hourly['recurrence'] );
		$this->assertNotSame( '', $pending );
	}

	public function test_retry_run_while_still_paused_sends_nothing(): void {
		MatbaoStatusSync::register();
		$this->draft();
		$this->pause_with( 1, time() + 600 );

		do_action( StatusSyncBackoff::RETRY_ACTION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- constant with the plugin prefix.

		$this->assertSame( 0, $this->matbao_requests() );
	}

	public function test_only_one_retry_queued(): void {
		$this->draft();
		$this->fail_lookups( 503 );
		( new MatbaoStatusSync() )->sync_batch();
		$this->pause_with( 1, time() - 1 );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertCount( 1, $this->pending_retries() );
		$this->assert_pause( 1800 );
	}

	public function test_schedule_queues_hourly_when_only_a_retry_exists(): void {
		as_unschedule_all_actions( MatbaoStatusSync::ACTION );
		$this->queue_retry_at( time() + 600 );
		$this->assertFalse( as_has_scheduled_action( MatbaoStatusSync::ACTION ) );

		MatbaoStatusSync::schedule();
		MatbaoStatusSync::schedule();

		$actions = as_get_scheduled_actions(
			array(
				'hook'   => MatbaoStatusSync::ACTION,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $actions );
		$this->assertSame( 3600, $this->hourly_action()['recurrence'] );
		$this->assertSame( DraftInvoiceWorkflow::GROUP, $this->hourly_action()['group'] );
		$this->assertCount( 1, $this->pending_retries() );
	}

	public function test_manual_check_during_pause_clears_backoff(): void {
		$x = $this->draft( array( 'template_series' => 'C26TAA' ) );
		$y = $this->draft( array( 'template_series' => 'C26TBB' ) );
		$this->fail_lookups( 503 );
		( new MatbaoStatusSync() )->sync_batch();
		$this->assertTrue( ( new StatusSyncBackoff() )->is_paused() );
		$this->assertCount( 1, $this->pending_retries() );
		$this->answer( array( $x->reference => $this->issued_row() ) );

		$outcome = ( new MatbaoStatusSync() )->sync_one( $this->reload( $x ) );

		$this->assertSame( SyncOutcome::UPDATED, $outcome );
		$this->assertSame( 4, $this->reload( $x )->matbao_status );
		$this->assertFalse( $this->backoff_state() );
		$this->assertSame( array(), $this->pending_retries() );
		$this->answer( array( $y->reference => $this->issued_row() ) );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertContains( $y->reference, $this->requested_references() );
	}

	public function test_manual_check_failure_keeps_backoff(): void {
		$invoice = $this->draft();
		$until   = time() + 1800;
		$this->pause_with( 2, $until );
		$id     = $this->queue_retry_at( $until );
		$before = $this->backoff_state();
		$this->fail_lookups( 503 );

		$outcome = ( new MatbaoStatusSync() )->sync_one( $invoice );

		$this->assertSame( SyncOutcome::FAILED, $outcome );
		$this->assertSame( $before, $this->backoff_state() );
		$this->assertSame( array( $id ), $this->pending_retries() );
	}

	public function test_deactivation_cancels_pending_retry(): void {
		$this->draft();
		$this->fail_lookups( 503 );
		( new MatbaoStatusSync() )->sync_batch();
		$this->assertCount( 1, $this->pending_retries() );

		Plugin::deactivate();

		$this->assertSame( array(), $this->pending_retries() );
	}

	/**
	 * Makes every lookup answer with the given status and raw body.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Raw body; a JSON message when empty.
	 */
	private function fail_lookups( int $status, string $body = '' ): void {
		if ( '' === $body ) {
			$this->http->respond_json( self::LOOKUP_ROUTE, $status, array( 'message' => 'failure' ) );
			return;
		}
		$this->http->respond_raw( self::LOOKUP_ROUTE, $status, $body );
	}

	/**
	 * Makes every lookup fail at transport level (timeout).
	 */
	private function fail_lookups_with_transport_error(): void {
		$this->http->respond_with( self::LOOKUP_ROUTE, static fn(): \WP_Error => new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
	}

	/**
	 * Drops the cached Mat Bao token so the next call logs in.
	 */
	private function forget_cached_token(): void {
		$credentials = ( new CredentialStore() )->matbao();
		$this->assertNotNull( $credentials );
		( new MatbaoClient( $credentials ) )->forget_token();
	}

	/**
	 * Requests of any Mat Bao route the sync can call (login and lookup).
	 */
	private function matbao_requests(): int {
		return count( $this->http->requests_to( 'POST /api/auth/login' ) ) + count( $this->http->requests_to( self::LOOKUP_ROUTE ) );
	}

	/**
	 * Raw backoff option.
	 */
	private function backoff_state(): mixed {
		return get_option( StatusSyncBackoff::OPTION );
	}

	/**
	 * Writes the backoff option directly.
	 *
	 * @param int $failures Recorded failures.
	 * @param int $until    Unix time the pause ends.
	 */
	private function pause_with( int $failures, int $until ): void {
		update_option(
			StatusSyncBackoff::OPTION,
			array(
				'failures' => $failures,
				'until'    => $until,
			),
			false
		);
	}

	/**
	 * IDs of the pending retry actions.
	 *
	 * @return list<int>
	 */
	private function pending_retries(): array {
		$ids = as_get_scheduled_actions(
			array(
				'hook'   => StatusSyncBackoff::RETRY_ACTION,
				'args'   => array(),
				'group'  => DraftInvoiceWorkflow::GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);

		return array_map( 'intval', array_values( $ids ) );
	}

	/**
	 * Queues a retry action and returns its ID.
	 *
	 * @param int $timestamp Run time.
	 */
	private function queue_retry_at( int $timestamp ): int {
		$id = as_schedule_single_action( $timestamp, StatusSyncBackoff::RETRY_ACTION, array(), DraftInvoiceWorkflow::GROUP );
		$this->assertGreaterThan( 0, $id );

		return $id;
	}

	/**
	 * The single pending hourly action: ID, run date, recurrence and group.
	 *
	 * @return array{id: int|string, date: int, recurrence: int, group: string}
	 */
	private function hourly_action(): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => MatbaoStatusSync::ACTION,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		$this->assertCount( 1, $actions );
		$id       = array_key_first( $actions );
		$action   = $actions[ $id ];
		$schedule = $action->get_schedule();
		$this->assertInstanceOf( \ActionScheduler_IntervalSchedule::class, $schedule );

		return array(
			'id'         => $id,
			'date'       => (int) $schedule->get_date()?->getTimestamp(),
			'recurrence' => (int) $schedule->get_recurrence(),
			'group'      => $action->get_group(),
		);
	}

	/**
	 * Asserts the sync is paused for about `$seconds` with exactly one retry queued at the same time.
	 *
	 * @param int $seconds Expected pause.
	 */
	private function assert_pause( int $seconds ): void {
		$state = $this->backoff_state();
		$this->assertIsArray( $state );
		$this->assertIsInt( $state['failures'] );
		$this->assertIsInt( $state['until'] );
		$this->assertGreaterThanOrEqual( time() + $seconds - 5, $state['until'] );
		$this->assertLessThanOrEqual( time() + $seconds + 5, $state['until'] );
		$this->assertCount( 1, $this->pending_retries() );
		$next = as_next_scheduled_action( StatusSyncBackoff::RETRY_ACTION, array(), DraftInvoiceWorkflow::GROUP );
		$this->assertIsInt( $next );
		$this->assertSame( $state['until'], $next );
	}

	/**
	 * Inserts a `draft_created` invoice with a Mat Bao invoice ID, as the workflow leaves it.
	 *
	 * @param array<string, mixed> $columns Column overrides.
	 */
	private function draft( array $columns = array() ): InvoiceRecord {
		global $wpdb;

		$order_id = ++$this->next_order;
		$now      = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture row.
		$wpdb->insert(
			$wpdb->prefix . 'einvoice_matbao_invoice',
			array_merge(
				array(
					'order_id'          => $order_id,
					'reference'         => 'WC' . $order_id,
					'status'            => InvoiceStatus::DRAFT_CREATED,
					'matbao_invoice_id' => 'MB' . ( $columns['order_id'] ?? $order_id ),
					'template_form'     => self::TEMPLATE_FORM,
					'template_series'   => self::TEMPLATE_SERIES,
					'issued_on'         => $this->days_ago( 0 ),
					'created_at'        => $now,
					'updated_at'        => $now,
				),
				$columns
			)
		);
		$record = ( new InvoiceRepository() )->find( (int) $wpdb->insert_id );
		$this->assertNotNull( $record );

		return $record;
	}

	private function enable_log_capture(): void {
		$this->sink = SentrySink::install( $this->http );
		$this->enable_monitoring();
	}

	/**
	 * Messages of the warning logs sent so far.
	 *
	 * @return list<string>
	 */
	private function warn_logs(): array {
		Monitor::flush();
		$this->assertNotNull( $this->sink );

		return array_values( array_map( static fn( array $log ): string => (string) $log['body'], array_filter( $this->sink->logs(), static fn( array $log ): bool => 'warn' === $log['level'] ) ) );
	}

	/**
	 * Columns tying a fixture invoice to a real order (the notice needs one).
	 *
	 * @return array<string, mixed>
	 */
	private function real_order_columns(): array {
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		return array(
			'order_id'  => $order->get_id(),
			'reference' => 'WC' . $order->get_id(),
		);
	}

	private function reload( InvoiceRecord $invoice ): InvoiceRecord {
		$record = ( new InvoiceRepository() )->find( $invoice->id );
		$this->assertNotNull( $record );

		return $record;
	}

	private function days_ago( int $days ): string {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Ho_Chi_Minh' ) ) )->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Row template of an issued invoice; `mtChieu`/`maSoHDon` are filled per reference by answer().
	 *
	 * @return array<string, mixed>
	 */
	private function issued_row(): array {
		return $this->row_with_status( 4 );
	}

	/**
	 * Row of an invoice in the given Mat Bao status.
	 *
	 * @param int $code `maTThaiHDon`.
	 * @return array<string, mixed>
	 */
	private function row_with_status( int $code ): array {
		return array(
			'khmshDon'     => self::TEMPLATE_FORM,
			'khhDon'       => self::TEMPLATE_SERIES,
			'shDon'        => 0,
			'nLap'         => '2026-10-14T00:00:00',
			'tenLoaiHDon'  => 'Hóa đơn mới',
			'tenTThaiHDon' => 'Trạng thái ' . $code,
			'maLoaiHDon'   => 2,
			'maTThaiHDon'  => $code,
		);
	}

	/**
	 * Fakes the lookup: each requested reference is answered from `$by_reference` (row overrides),
	 * listed in `notFound` (value 'notfound'), or left out of the answer (missing key).
	 *
	 * @param array<string, array<string, mixed>|string> $by_reference Reference => row template or 'notfound'.
	 */
	private function answer( array $by_reference ): void {
		$this->http->respond_with(
			self::LOOKUP_ROUTE,
			function ( array $args ) use ( $by_reference ): array {
				$sent = json_decode( (string) $args['body'], true );
				$rows = array();
				$not  = array();
				$repo = new InvoiceRepository();
				foreach ( $sent['danhSach'] as $reference ) {
					$entry = $by_reference[ $reference ] ?? null;
					if ( 'notfound' === $entry ) {
						$not[] = $reference;
					} elseif ( is_array( $entry ) ) {
						$rows[] = array_merge(
							array(
								'mtChieu'  => $reference,
								'maSoHDon' => 'MB' . substr( $reference, 2 ),
							),
							$entry
						);
					}
				}

				return FakeHttp::json_response( 200, $this->envelope( $rows, $not ) );
			}
		);
	}

	/**
	 * Envelope of a lookup answer.
	 *
	 * @param mixed[]  $rows      `data.invoices`.
	 * @param string[] $not_found `data.notFound`.
	 * @return array<string, mixed>
	 */
	private function envelope( array $rows, array $not_found ): array {
		return array(
			'errorCode' => 200,
			'message'   => 'Thành công',
			'data'      => array(
				'invoices' => $rows,
				'notFound' => $not_found,
			),
			'success'   => true,
		);
	}

	/**
	 * References sent to the lookup so far, in request order.
	 *
	 * @return list<string>
	 */
	private function requested_references(): array {
		$all = array();
		foreach ( $this->http->requests_to( self::LOOKUP_ROUTE ) as $request ) {
			array_push( $all, ...$request['body']['danhSach'] );
		}

		return $all;
	}

	private function notice_html(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		AdminAlertNotice::render();

		return (string) ob_get_clean();
	}
}
