<?php
/**
 * Atomic reserve and claim of invoice rows.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\MatbaoSnapshot;
use LTTech\EinvoiceMatbao\Invoice\RecordResult;
use LTTech\EinvoiceMatbao\Invoice\Schema;
use WP_UnitTestCase;

/**
 * The statements that stop two hooks or two runners from both winning.
 */
final class InvoiceRepositoryTest extends WP_UnitTestCase {

	public function test_second_reserve_for_the_same_order_loses(): void {
		$repository = new InvoiceRepository();

		$this->assertTrue( $repository->reserve( 9001, 'WC9001', 'Khách', InvoiceStatus::PENDING ) );
		$this->assertFalse( $repository->reserve( 9001, 'WC9001', 'Khách', InvoiceStatus::PENDING ) );
	}

	public function test_second_claim_of_the_same_row_loses(): void {
		$repository = new InvoiceRepository();
		$repository->reserve( 9002, 'WC9002', 'Khách', InvoiceStatus::PENDING );
		$invoice = $repository->find_by_order( 9002 );
		$this->assertNotNull( $invoice );

		$this->assertTrue( $repository->claim( $invoice->id, InvoiceStatus::PENDING, InvoiceStatus::SENDING ) );
		$this->assertFalse( $repository->claim( $invoice->id, InvoiceStatus::PENDING, InvoiceStatus::SENDING ) );
	}

	public function test_due_for_status_sync_ordering_and_filters(): void {
		$repository = new InvoiceRepository();
		$today      = gmdate( 'Y-m-d' );
		$never      = $this->draft( 9101, array( 'issued_on' => $today ) );
		$two_hours  = $this->draft(
			9102,
			array(
				'issued_on'         => $today,
				'matbao_checked_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			)
		);
		$this->draft(
			9103,
			array(
				'issued_on'     => $today,
				'matbao_status' => 4,
			)
		);
		$this->draft( 9104, array( 'issued_on' => gmdate( 'Y-m-d', time() - 31 * DAY_IN_SECONDS ) ) );
		$this->draft(
			9105,
			array(
				'issued_on' => $today,
				'status'    => InvoiceStatus::FAILED,
			)
		);
		$this->draft(
			9106,
			array(
				'issued_on'         => $today,
				'matbao_invoice_id' => null,
			)
		);
		$this->draft(
			9107,
			array(
				'issued_on'     => $today,
				'matbao_status' => 0,
			)
		);
		$this->draft(
			9108,
			array(
				'issued_on'     => $today,
				'template_form' => '',
			)
		);
		// A later check sorts after an earlier one.
		$one_hour = $this->draft(
			9109,
			array(
				'issued_on'         => $today,
				'matbao_checked_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		$due = $repository->due_for_status_sync( 200, gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) );

		$this->assertSame( array( $never, $two_hours, $one_hour ), array_map( static fn( InvoiceRecord $i ): int => $i->id, $due ) );
	}

	public function test_due_for_status_sync_falls_back_to_creation_date(): void {
		$recent = $this->draft( 9111, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) );
		$this->draft( 9112, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) );

		$due = ( new InvoiceRepository() )->due_for_status_sync( 200, gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) );

		$this->assertSame( array( $recent ), array_map( static fn( InvoiceRecord $i ): int => $i->id, $due ) );
	}

	public function test_due_for_status_sync_respects_limit(): void {
		$this->draft( 9121, array( 'issued_on' => gmdate( 'Y-m-d' ) ) );
		$this->draft( 9122, array( 'issued_on' => gmdate( 'Y-m-d' ) ) );
		$this->draft( 9123, array( 'issued_on' => gmdate( 'Y-m-d' ) ) );

		$this->assertCount( 2, ( new InvoiceRepository() )->due_for_status_sync( 2, '2000-01-01' ) );
	}

	public function test_touch_matbao_checked_moves_invoices_to_the_back(): void {
		$first      = $this->draft( 9131, array( 'issued_on' => gmdate( 'Y-m-d' ) ) );
		$second     = $this->draft( 9132, array( 'issued_on' => gmdate( 'Y-m-d' ) ) );
		$repository = new InvoiceRepository();

		$repository->touch_matbao_checked( array( $first ) );

		$due = $repository->due_for_status_sync( 200, '2000-01-01' );
		$this->assertSame( array( $second, $first ), array_map( static fn( InvoiceRecord $i ): int => $i->id, $due ) );
		$this->assertNotNull( $due[1]->matbao_checked_at );
		$this->assertNull( $due[1]->matbao_synced_at );
	}

	public function test_record_matbao_status_writes_snapshot(): void {
		$repository = new InvoiceRepository();
		$read       = $this->record( $this->draft( 9141 ) );

		$result = $repository->record_matbao_status( $read, new MatbaoSnapshot( 4, 'Đã cấp mã', 125, '00ABC', 2, 'Ghi chú' ) );

		$this->assertSame( RecordResult::WRITTEN, $result );
		$after = $this->record( $read->id );
		$this->assertSame( 4, $after->matbao_status );
		$this->assertSame( 'Đã cấp mã', $after->matbao_status_name );
		$this->assertSame( 125, $after->invoice_number );
		$this->assertSame( '00ABC', $after->tax_authority_code );
		$this->assertSame( 2, $after->matbao_invoice_type );
		$this->assertSame( 'Ghi chú', $after->tax_note );
		$this->assertNotNull( $after->matbao_synced_at );
		$this->assertNotNull( $after->matbao_checked_at );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $after->status );
		$this->assertSame( '', $after->error_code );
	}

	public function test_two_writers_only_one_wins(): void {
		$repository = new InvoiceRepository();
		$read       = $this->record( $this->draft( 9151, array( 'matbao_status' => 1 ) ) );
		$sign_error = new MatbaoSnapshot( 2, 'Lỗi ký', null, null, 2, null );

		$first  = $repository->record_matbao_status( $read, $sign_error, 'Lỗi ký' );
		$second = $repository->record_matbao_status( $read, $sign_error, 'Lỗi ký' );

		$this->assertSame( RecordResult::WRITTEN, $first );
		$this->assertSame( RecordResult::STALE, $second );
		$after = $this->record( $read->id );
		$this->assertSame( $read->alert_cycle + 1, $after->alert_cycle );
		$this->assertSame( 'matbao_sign_failed', $after->error_code );
		$this->assertSame( 'matbao', $after->error_kind );
		$this->assertSame( 'Lỗi ký', $after->status_reason );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $after->status );
		$this->assertTrue( $after->needs_admin() );
	}

	public function test_first_lookup_with_null_status_is_guarded_by_is_null(): void {
		$repository = new InvoiceRepository();
		$read       = $this->record( $this->draft( 9152 ) );
		$this->assertNull( $read->matbao_status );

		$this->assertSame( RecordResult::WRITTEN, $repository->record_matbao_status( $read, new MatbaoSnapshot( 1, 'Nháp', null, null, 1, null ) ) );
		// The same stale read no longer matches: the status is 1 now.
		$this->assertSame( RecordResult::STALE, $repository->record_matbao_status( $read, new MatbaoSnapshot( 3, 'Chưa gửi CQT', null, null, 1, null ) ) );
		$this->assertSame( 1, $this->record( $read->id )->matbao_status );
	}

	public function test_write_ignored_when_invoice_id_changed(): void {
		$repository = new InvoiceRepository();
		$read       = $this->record( $this->draft( 9161, array( 'matbao_status' => 0 ) ) );
		$repository->update_status( $read->id, InvoiceStatus::DRAFT_CREATED, '', array( 'matbao_invoice_id' => 'B' ) );

		$result = $repository->record_matbao_status( $read, new MatbaoSnapshot( 2, 'Lỗi ký', null, null, 2, null ) );

		$this->assertSame( RecordResult::STALE, $result );
		$after = $this->record( $read->id );
		$this->assertSame( 'B', $after->matbao_invoice_id );
		$this->assertNull( $after->matbao_status );
		$this->assertSame( '', $after->error_code );
	}

	public function test_write_ignored_when_status_left_draft_created(): void {
		$repository = new InvoiceRepository();
		$read       = $this->record( $this->draft( 9162, array( 'status' => InvoiceStatus::FAILED ) ) );

		$this->assertSame( RecordResult::STALE, $repository->record_matbao_status( $read, new MatbaoSnapshot( 4, 'x', 1, null, 2, null ) ) );
	}

	public function test_recovery_clears_only_matbao_warning(): void {
		$repository = new InvoiceRepository();
		$issued     = new MatbaoSnapshot( 4, 'Đã cấp mã', 7, '00X', 2, null );

		$sign = $this->record(
			$this->draft(
				9171,
				array(
					'matbao_status' => 2,
					'error_code'    => 'matbao_sign_failed',
					'error_kind'    => 'matbao',
					'status_reason' => 'Lỗi ký',
				)
			)
		);
		$this->assertSame( RecordResult::WRITTEN, $repository->record_matbao_status( $sign, $issued ) );
		$after = $this->record( $sign->id );
		$this->assertSame( '', $after->error_code );
		$this->assertSame( '', $after->error_kind );
		$this->assertSame( '', $after->status_reason );
		$this->assertSame( 4, $after->matbao_status );

		$order = $this->record(
			$this->draft(
				9172,
				array(
					'matbao_status' => 2,
					'error_code'    => 'order_amounts_changed',
					'error_kind'    => 'order',
					'status_reason' => 'Đổi tiền',
				)
			)
		);
		$this->assertSame( RecordResult::WRITTEN, $repository->record_matbao_status( $order, $issued ) );
		$kept = $this->record( $order->id );
		$this->assertSame( 'order_amounts_changed', $kept->error_code );
		$this->assertSame( 'order', $kept->error_kind );
		$this->assertSame( 'Đổi tiền', $kept->status_reason );
		$this->assertSame( 4, $kept->matbao_status );
		$this->assertSame( 7, $kept->invoice_number );
	}

	public function test_abnormal_status_replaces_order_warning(): void {
		$repository = new InvoiceRepository();
		$read       = $this->record(
			$this->draft(
				9173,
				array(
					'matbao_status' => 1,
					'error_code'    => 'order_buyer_changed',
					'error_kind'    => 'order',
				)
			)
		);

		$this->assertSame( RecordResult::WRITTEN, $repository->record_matbao_status( $read, new MatbaoSnapshot( 2, 'Lỗi ký', null, null, 2, null ), 'Lỗi ký' ) );

		$this->assertSame( 'matbao_sign_failed', $this->record( $read->id )->error_code );
	}

	/**
	 * Every Mat Bao column with a non-empty value.
	 *
	 * @return array<string, mixed>
	 */
	private static function filled_matbao_columns(): array {
		return array(
			'matbao_status'       => 4,
			'matbao_status_name'  => 'Đã cấp mã',
			'invoice_number'      => 125,
			'tax_authority_code'  => '00ABC',
			'matbao_invoice_type' => 2,
			'tax_note'            => 'Ghi chú',
			'lookup_code'         => 'FM80520C49',
			'matbao_synced_at'    => '2026-10-14 01:02:03',
			'matbao_checked_at'   => '2026-10-14 01:02:03',
		);
	}

	public function test_record_matbao_status_stores_lookup_code(): void {
		$repository = new InvoiceRepository();
		$read       = $this->record( $this->draft( 9183, array( 'matbao_status' => 1 ) ) );

		$repository->record_matbao_status( $read, new MatbaoSnapshot( 4, 'Đã cấp mã', 125, '00ABC', 2, null, 'FM80520C49' ) );

		$this->assertSame( 'FM80520C49', $this->record( $read->id )->lookup_code );
	}

	public function test_deleted_snapshot_clears_lookup_code(): void {
		$repository = new InvoiceRepository();
		$read       = $this->record( $this->draft( 9184, array( 'matbao_status' => 1 ) ) );
		$repository->record_matbao_status( $read, new MatbaoSnapshot( 4, 'Đã cấp mã', 125, '00ABC', 2, null, 'FM80520C49' ) );

		$repository->record_matbao_status( $this->record( $read->id ), MatbaoSnapshot::deleted() );

		$this->assertNull( $this->record( $read->id )->lookup_code );
	}

	public function test_new_invoice_id_resets_matbao_fields(): void {
		global $wpdb;
		$repository = new InvoiceRepository();
		$id         = $this->draft( 9181, self::filled_matbao_columns() );

		$repository->update_status( $id, InvoiceStatus::DRAFT_CREATED, '', array( 'matbao_invoice_id' => 'NEW' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test reads the raw row.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::invoice_table(), $id ), ARRAY_A );
		$this->assertIsArray( $row );
		$this->assertSame( 'NEW', $row['matbao_invoice_id'] );
		foreach ( array_keys( self::filled_matbao_columns() ) as $column ) {
			$this->assertNull( $row[ $column ], $column );
		}
	}

	public function test_update_status_without_new_invoice_id_keeps_matbao_fields(): void {
		$repository = new InvoiceRepository();
		$id         = $this->draft( 9182, self::filled_matbao_columns() );

		$repository->update_status( $id, InvoiceStatus::DRAFT_CREATED, 'x' );

		$after = $this->record( $id );
		$this->assertSame( 4, $after->matbao_status );
		$this->assertSame( 'Đã cấp mã', $after->matbao_status_name );
		$this->assertSame( 125, $after->invoice_number );
		$this->assertSame( '00ABC', $after->tax_authority_code );
		$this->assertSame( 2, $after->matbao_invoice_type );
		$this->assertSame( 'Ghi chú', $after->tax_note );
		$this->assertSame( '2026-10-14 01:02:03', $after->matbao_synced_at );
		$this->assertSame( '2026-10-14 01:02:03', $after->matbao_checked_at );
	}

	public function test_same_abnormal_status_keeps_warning(): void {
		$repository = new InvoiceRepository();
		$first_read = $this->record( $this->draft( 9191, array( 'matbao_status' => 1 ) ) );
		$sign_error = new MatbaoSnapshot( 2, 'Lỗi ký', null, null, 2, null );
		$repository->record_matbao_status( $first_read, $sign_error, 'Lý do một' );
		$read = $this->record( $first_read->id );

		$repository->record_matbao_status( $read, $sign_error, 'Lý do hai' );

		$after = $this->record( $read->id );
		$this->assertSame( 'matbao_sign_failed', $after->error_code );
		$this->assertSame( 'Lý do một', $after->status_reason );
		$this->assertSame( $read->alert_cycle, $after->alert_cycle );
	}

	public function test_repeat_write_is_never_failed(): void {
		$repository = new InvoiceRepository();
		$first_read = $this->record( $this->draft( 9195, array( 'matbao_status' => 1 ) ) );
		$sign_error = new MatbaoSnapshot( 2, 'Lỗi ký', null, null, 2, null );
		$this->assertSame( RecordResult::WRITTEN, $repository->record_matbao_status( $first_read, $sign_error, 'Lỗi ký' ) );
		$read = $this->record( $first_read->id );

		$second = $repository->record_matbao_status( $read, $sign_error, 'Lỗi ký' );

		$this->assertContains( $second, array( RecordResult::WRITTEN, RecordResult::STALE ) );
		$after = $this->record( $read->id );
		$this->assertSame( 'matbao_sign_failed', $after->error_code );
		$this->assertSame( $read->alert_cycle, $after->alert_cycle );
	}

	public function test_needs_admin_for_matbao_warning(): void {
		$warned = $this->record(
			$this->draft(
				9197,
				array(
					'error_code' => 'matbao_sign_failed',
					'error_kind' => 'matbao',
				)
			)
		);
		$clean  = $this->record( $this->draft( 9198 ) );

		$this->assertTrue( $warned->needs_admin() );
		$this->assertFalse( $clean->needs_admin() );
		$this->assertSame( InvoiceErrorCode::MATBAO_SIGN_FAILED, $warned->error() );
	}

	/**
	 * Inserts a `draft_created` invoice, overridable column by column.
	 *
	 * @param int                  $order_id Order ID.
	 * @param array<string, mixed> $columns  Columns replacing the defaults.
	 * @return int Row ID.
	 */
	private function draft( int $order_id, array $columns = array() ): int {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture row.
		$wpdb->insert(
			Schema::invoice_table(),
			array_merge(
				array(
					'order_id'          => $order_id,
					'reference'         => 'WC' . $order_id,
					'status'            => InvoiceStatus::DRAFT_CREATED,
					'matbao_invoice_id' => 'A' . $order_id,
					'template_form'     => '1',
					'template_series'   => 'C26TAT',
					'created_at'        => $now,
					'updated_at'        => $now,
				),
				$columns
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Reads an invoice back.
	 *
	 * @param int $invoice_id Row ID.
	 */
	private function record( int $invoice_id ): InvoiceRecord {
		$record = ( new InvoiceRepository() )->find( $invoice_id );
		$this->assertNotNull( $record );

		return $record;
	}
}
