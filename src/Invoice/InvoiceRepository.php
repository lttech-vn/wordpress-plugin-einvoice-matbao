<?php
/**
 * Database access for invoices and attempts.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables (ADR 0002); status rows must never be served from a cache.

/**
 * Reads and writes the invoice and attempt tables. Reservation and claiming rely on single
 * atomic statements (`INSERT IGNORE` against `UNIQUE(order_id)`, conditional `UPDATE`), so two
 * hooks or two queue runners for the same order cannot both win.
 */
final class InvoiceRepository {

	/**
	 * Inserts the invoice row of an order unless one already exists.
	 *
	 * @param int    $order_id   WooCommerce order ID.
	 * @param string $reference  `MTChieu`.
	 * @param string $buyer_name `NMua_Ten` snapshot.
	 * @param string $status     Initial InvoiceStatus.
	 * @param string $reason     Status reason, or empty.
	 * @return bool Whether this call inserted the row.
	 */
	public function reserve( int $order_id, string $reference, string $buyer_name, string $status, string $reason = '' ): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (order_id, reference, status, status_reason, buyer_name, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s)',
				Schema::invoice_table(),
				$order_id,
				$reference,
				$status,
				$reason,
				$buyer_name,
				$now,
				$now
			)
		);

		return 1 === $inserted;
	}

	/**
	 * Moves an invoice from one status to another only if it is still in the expected status.
	 *
	 * @param int    $invoice_id Invoice row ID.
	 * @param string $from       Expected current status.
	 * @param string $to         New status.
	 * @return bool Whether this call made the change.
	 */
	public function claim( int $invoice_id, string $from, string $to ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status = %s',
				Schema::invoice_table(),
				$to,
				current_time( 'mysql', true ),
				$invoice_id,
				$from
			)
		);

		return 1 === $updated;
	}

	/**
	 * Moves an invoice to another status only if neither its status nor `updated_at` changed since
	 * it was read, so the recovery sweep cannot take back a row another runner has just claimed.
	 *
	 * @param InvoiceRecord $invoice Invoice as read.
	 * @param string        $to      New status.
	 * @return bool Whether this call made the change.
	 */
	public function claim_unchanged( InvoiceRecord $invoice, string $to ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status = %s AND updated_at = %s',
				Schema::invoice_table(),
				$to,
				current_time( 'mysql', true ),
				$invoice->id,
				$invoice->status,
				$invoice->updated_at
			)
		);

		return 1 === $updated;
	}

	/**
	 * Moves an invoice from a stopped or final status back to `pending` and starts a new retry
	 * cycle: attempts before this call no longer count as tries, but still decide whether the
	 * next run looks the invoice up first.
	 *
	 * @param int    $invoice_id Invoice row ID.
	 * @param string $from       Expected current status.
	 * @return bool Whether this call made the change.
	 */
	public function restart( int $invoice_id, string $from ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, error_code = %s, error_kind = %s, retry_cycle_start = (SELECT COALESCE(MAX(id), 0) + 1 FROM %i WHERE invoice_id = %d), updated_at = %s WHERE id = %d AND status = %s',
				Schema::invoice_table(),
				InvoiceStatus::PENDING,
				'',
				'',
				Schema::attempt_table(),
				$invoice_id,
				current_time( 'mysql', true ),
				$invoice_id,
				$from
			)
		);

		return 1 === $updated;
	}

	/**
	 * Sets the status and reason of an invoice and clears any recorded error.
	 *
	 * @param int                  $invoice_id Invoice row ID.
	 * @param string               $status     InvoiceStatus.
	 * @param string               $reason     Reason, or empty.
	 * @param array<string, mixed> $fields     Other columns to set.
	 */
	public function update_status( int $invoice_id, string $status, string $reason = '', array $fields = array() ): void {
		global $wpdb;

		$wpdb->update(
			Schema::invoice_table(),
			array_merge(
				array(
					'error_code' => '',
					'error_kind' => '',
				),
				$fields,
				array(
					'status'        => $status,
					'status_reason' => $reason,
					'updated_at'    => current_time( 'mysql', true ),
				)
			),
			array( 'id' => $invoice_id )
		);
	}

	/**
	 * Stores the template a create call is about to be sent with, without touching the status,
	 * so a later lookup by `MTChieu` uses the same template.
	 *
	 * @param int    $invoice_id Invoice row ID.
	 * @param string $form       `KHMSHDon`.
	 * @param string $series     `KHHDon`.
	 */
	public function remember_template( int $invoice_id, string $form, string $series ): void {
		global $wpdb;

		$wpdb->update(
			Schema::invoice_table(),
			array(
				'template_form'   => $form,
				'template_series' => $series,
			),
			array( 'id' => $invoice_id )
		);
	}

	/**
	 * Records why an invoice was not created: the code, its kind, the status of that kind and the
	 * Vietnamese reason.
	 *
	 * @param int              $invoice_id Invoice row ID.
	 * @param InvoiceErrorCode $code       Error code.
	 * @param string           $reason     Vietnamese reason, without secrets or personal data.
	 * @param string|null      $status     InvoiceStatus instead of the one of the code's kind, e.g. `pending`
	 *                                     while a retry waits or `needs_attention` once retries are used up.
	 */
	public function record_error( int $invoice_id, InvoiceErrorCode $code, string $reason, ?string $status = null ): void {
		$this->update_status(
			$invoice_id,
			$status ?? $code->kind()->status(),
			$reason,
			array(
				'error_code' => $code->value,
				'error_kind' => $code->kind()->value,
			)
		);
	}

	/**
	 * The invoice of an order, or null.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function find_by_order( int $order_id ): ?InvoiceRecord {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', Schema::invoice_table(), $order_id ), ARRAY_A );

		return is_array( $row ) ? InvoiceRecord::from_row( $row ) : null;
	}

	/**
	 * Invoices in a status that have not changed for a while.
	 *
	 * @param string $status        InvoiceStatus.
	 * @param int    $older_than    Minimum age of the last change, in seconds.
	 * @return list<InvoiceRecord>
	 */
	public function unchanged_for( string $status, int $older_than ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND updated_at < %s ORDER BY id LIMIT 100',
				Schema::invoice_table(),
				$status,
				gmdate( 'Y-m-d H:i:s', time() - $older_than )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( array_map( array( InvoiceRecord::class, 'from_row' ), $rows ) ) : array();
	}

	/**
	 * Records the start of a create call and returns the attempt ID.
	 *
	 * @param int $invoice_id Invoice row ID.
	 */
	public function start_attempt( int $invoice_id ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::attempt_table(),
			array(
				'invoice_id' => $invoice_id,
				'started_at' => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Closes an attempt with its outcome.
	 *
	 * @param int         $attempt_id  Attempt ID.
	 * @param string      $outcome     `success`, `failed`, `not_sent` (never reached create-invoice), `lookup_failed`,
	 *                                 `lookup_mismatch` or `abandoned` (closed by the recovery sweep).
	 * @param string|null $error_kind  MatbaoError kind on failure.
	 * @param int|null    $http_status HTTP status, when a response was received.
	 * @param int|null    $api_code    Mat Bao `errorCode`, when present.
	 * @param string      $message     Mat Bao message or fixed text; never secrets.
	 */
	public function finish_attempt( int $attempt_id, string $outcome, ?string $error_kind = null, ?int $http_status = null, ?int $api_code = null, string $message = '' ): void {
		global $wpdb;

		$wpdb->update(
			Schema::attempt_table(),
			array(
				'finished_at' => current_time( 'mysql', true ),
				'outcome'     => $outcome,
				'error_kind'  => $error_kind,
				'http_status' => $http_status,
				'api_code'    => $api_code,
				'message'     => $message,
			),
			array( 'id' => $attempt_id )
		);
	}

	/**
	 * Attempts of an invoice, oldest first.
	 *
	 * @param int $invoice_id Invoice row ID.
	 * @return list<array<string, mixed>>
	 */
	public function attempts( int $invoice_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE invoice_id = %d ORDER BY id', Schema::attempt_table(), $invoice_id ), ARRAY_A );

		return is_array( $rows ) ? array_values( $rows ) : array();
	}
}
