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
	 * @param string      $buyer_name        `NMua_Ten` captured when the order completed.
	 * @param string      $template_form     `KHMSHDon` sent, or empty before sending.
	 * @param string      $template_series   `KHHDon` sent, or empty before sending.
	 * @param string|null $matbao_invoice_id `maSoHDon` returned by Mat Bao.
	 * @param string|null $issued_on         `NLap` date (Y-m-d) of the successful create call.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly string $reference,
		public readonly string $status,
		public readonly string $status_reason,
		public readonly string $buyer_name,
		public readonly string $template_form,
		public readonly string $template_series,
		public readonly ?string $matbao_invoice_id,
		public readonly ?string $issued_on,
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
			self::text( $row['buyer_name'] ?? null ),
			self::text( $row['template_form'] ?? null ),
			self::text( $row['template_series'] ?? null ),
			is_string( $row['matbao_invoice_id'] ?? null ) ? $row['matbao_invoice_id'] : null,
			is_string( $row['issued_on'] ?? null ) ? $row['issued_on'] : null,
		);
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
