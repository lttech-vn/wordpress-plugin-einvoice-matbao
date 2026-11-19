<?php
/**
 * One row of the invoice table.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

/**
 * The single invoice of an order and its current status.
 */
final class InvoiceRecord {

	/**
	 * Creates the record.
	 *
	 * @param int         $id                Row ID.
	 * @param int         $order_id          WooCommerce order ID.
	 * @param string      $reference         `MTChieu`, fixed at reservation.
	 * @param string      $status            InvoiceStatus constant.
	 * @param string      $status_reason     Why the status was set, or empty.
	 * @param string      $error_code        InvoiceErrorCode value, or empty when there is no error.
	 * @param string      $error_kind        InvoiceErrorKind value, or empty when there is no error.
	 * @param string      $buyer_name        `NMua_Ten` captured when the order completed.
	 * @param string      $template_form     `KHMSHDon` sent, or empty before sending.
	 * @param string      $template_series   `KHHDon` sent, or empty before sending.
	 * @param string|null $matbao_invoice_id `maSoHDon` returned by Mat Bao.
	 * @param string|null $issued_on         `NLap` date (Y-m-d) of the successful create call.
	 * @param int         $retry_cycle_start Attempt ID from which retries are counted; 0 = all attempts.
	 * @param int         $alert_cycle       Counts restarts; alerts are deduplicated per value, also when no attempt was made.
	 * @param string      $updated_at        Last change (UTC, `Y-m-d H:i:s`), compared by the recovery sweep's claim.
	 * @param string      $order_state       OrderState::encode() of the order as last sent or acknowledged, or empty.
	 * @param int|null    $matbao_status     `maTThaiHDon` last read from Mat Bao (0 = deleted on Mat Bao), or null before the first lookup.
	 * @param string      $matbao_status_name `tenTThaiHDon` last read, or empty.
	 * @param int|null    $invoice_number    `shDon` last read, or null while there is none.
	 * @param string|null $tax_authority_code `mccqt` last read.
	 * @param int|null    $matbao_invoice_type `maLoaiHDon` last read.
	 * @param string      $tax_note          `ghiChuCQT` last read, or empty. Personal data may appear in it: redact before display.
	 * @param string|null $matbao_synced_at  Last successful lookup (UTC, `Y-m-d H:i:s`).
	 * @param string|null $matbao_checked_at Last lookup attempt (UTC, `Y-m-d H:i:s`).
	 * @param string|null $lookup_code       `maTraCuu` last read; a credential for the public lookup page, never display it.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly string $reference,
		public readonly string $status,
		public readonly string $status_reason,
		public readonly string $error_code,
		public readonly string $error_kind,
		public readonly string $buyer_name,
		public readonly string $template_form,
		public readonly string $template_series,
		public readonly ?string $matbao_invoice_id,
		public readonly ?string $issued_on,
		public readonly int $retry_cycle_start = 0,
		public readonly int $alert_cycle = 0,
		public readonly string $updated_at = '',
		public readonly string $order_state = '',
		public readonly ?int $matbao_status = null,
		public readonly string $matbao_status_name = '',
		public readonly ?int $invoice_number = null,
		public readonly ?string $tax_authority_code = null,
		public readonly ?int $matbao_invoice_type = null,
		public readonly string $tax_note = '',
		public readonly ?string $matbao_synced_at = null,
		public readonly ?string $matbao_checked_at = null,
		public readonly ?string $lookup_code = null,
	) {
	}

	/**
	 * Builds a record from a database row.
	 *
	 * @param array<string, mixed> $row Row from the invoice table.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['order_id'],
			self::text( $row['reference'] ?? null ),
			self::text( $row['status'] ?? null ),
			self::text( $row['status_reason'] ?? null ),
			self::text( $row['error_code'] ?? null ),
			self::text( $row['error_kind'] ?? null ),
			self::text( $row['buyer_name'] ?? null ),
			self::text( $row['template_form'] ?? null ),
			self::text( $row['template_series'] ?? null ),
			is_string( $row['matbao_invoice_id'] ?? null ) ? $row['matbao_invoice_id'] : null,
			is_string( $row['issued_on'] ?? null ) ? $row['issued_on'] : null,
			is_numeric( $row['retry_cycle_start'] ?? null ) ? (int) $row['retry_cycle_start'] : 0,
			is_numeric( $row['alert_cycle'] ?? null ) ? (int) $row['alert_cycle'] : 0,
			self::text( $row['updated_at'] ?? null ),
			self::text( $row['order_state'] ?? null ),
			is_numeric( $row['matbao_status'] ?? null ) ? (int) $row['matbao_status'] : null,
			self::text( $row['matbao_status_name'] ?? null ),
			is_numeric( $row['invoice_number'] ?? null ) ? (int) $row['invoice_number'] : null,
			is_string( $row['tax_authority_code'] ?? null ) ? $row['tax_authority_code'] : null,
			is_numeric( $row['matbao_invoice_type'] ?? null ) ? (int) $row['matbao_invoice_type'] : null,
			self::text( $row['tax_note'] ?? null ),
			is_string( $row['matbao_synced_at'] ?? null ) ? $row['matbao_synced_at'] : null,
			is_string( $row['matbao_checked_at'] ?? null ) ? $row['matbao_checked_at'] : null,
			is_string( $row['lookup_code'] ?? null ) ? $row['lookup_code'] : null,
		);
	}

	/**
	 * The recorded error, or null when there is none or the stored code is unknown.
	 */
	public function error(): ?InvoiceErrorCode {
		return InvoiceErrorCode::tryFrom( $this->error_code );
	}

	/**
	 * The Mat Bao status last read, or null before the first lookup or for a code outside the known table.
	 */
	public function matbao_status(): ?MatbaoStatus {
		return null === $this->matbao_status ? null : MatbaoStatus::tryFrom( $this->matbao_status );
	}

	/**
	 * Whether an admin has to look at the row: an error status, or a draft that the order has
	 * since drifted away from (the order was changed or refunded after the draft, or Mat Bao reports
	 * it as signed wrongly, not accepted or deleted).
	 */
	public function needs_admin(): bool {
		if ( in_array( $this->status, InvoiceStatus::error_statuses(), true ) ) {
			return true;
		}

		if ( InvoiceStatus::DRAFT_CREATED !== $this->status ) {
			return false;
		}

		$error = $this->error();

		return true === $error?->is_post_draft_warning() || true === $error?->is_matbao_status_warning();
	}

	/**
	 * A column value as string.
	 *
	 * @param mixed $value Column value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
