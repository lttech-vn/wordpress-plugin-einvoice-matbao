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
use LTTech\EinvoiceMatbao\Invoice\SyncOutcome;
use LTTech\EinvoiceMatbao\Lark\LarkWebhook;
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

	public function test_lookup_503_keeps_status_and_continues(): void {
		$a = $this->draft( array( 'template_series' => 'C26TAA' ) );
		$b = $this->draft( array( 'template_series' => 'C26TBB' ) );
		$this->http->respond_with(
			self::LOOKUP_ROUTE,
			function ( array $args ) use ( $b ): array {
				$sent = json_decode( (string) $args['body'], true );
				if ( 'C26TAA' === $sent['kiHieu'] ) {
					return FakeHttp::json_response( 503, array( 'message' => 'down' ) );
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
		$alerts = did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION );

		( new MatbaoStatusSync() )->sync_batch();

		$this->assertNull( $this->reload( $a )->matbao_status );
		$this->assertNotNull( $this->reload( $a )->matbao_checked_at );
		$this->assertSame( 4, $this->reload( $b )->matbao_status );
		$this->assertSame( $alerts, did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION ) );
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
