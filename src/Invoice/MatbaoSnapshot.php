<?php
/**
 * Mat Bao status fields as read at one lookup.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

use LTTech\EinvoiceMatbao\Matbao\MatbaoInvoice;

/**
 * The values InvoiceRepository::record_matbao_status() stores for one lookup result.
 */
final class MatbaoSnapshot {

	/**
	 * Creates the snapshot.
	 *
	 * @param int|null    $status             `maTThaiHDon`, or 0 for deleted on Mat Bao.
	 * @param string      $status_name        `tenTThaiHDon`, or empty.
	 * @param int|null    $invoice_number     `shDon`, or null while there is none.
	 * @param string|null $tax_authority_code `mccqt`.
	 * @param int|null    $invoice_type       `maLoaiHDon`.
	 * @param string|null $tax_note           `ghiChuCQT`.
	 * @param string|null $lookup_code        `maTraCuu`.
	 */
	public function __construct(
		public readonly ?int $status,
		public readonly string $status_name,
		public readonly ?int $invoice_number,
		public readonly ?string $tax_authority_code,
		public readonly ?int $invoice_type,
		public readonly ?string $tax_note,
		public readonly ?string $lookup_code = null,
	) {
	}

	/**
	 * Snapshot of a looked-up invoice.
	 *
	 * @param MatbaoInvoice $invoice Matched lookup row.
	 */
	public static function from_invoice( MatbaoInvoice $invoice ): self {
		return new self( $invoice->status, $invoice->status_name, $invoice->invoice_number, $invoice->tax_authority_code, $invoice->invoice_type, $invoice->tax_note, $invoice->lookup_code );
	}

	/**
	 * Snapshot of an invoice Mat Bao confirmed it does not have.
	 */
	public static function deleted(): self {
		return new self( MatbaoStatus::DELETED->value, '', null, null, null, null, null );
	}
}
