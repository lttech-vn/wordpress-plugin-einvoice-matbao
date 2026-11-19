<?php
/**
 * An invoice returned by the Mat Bao lookup.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Matbao;

/**
 * One entry of `invoice-detail-batch` `data.invoices` (fields as in docs/research/demo-results.json).
 */
final class MatbaoInvoice {

	/** `maTThaiHDon` of a draft (`TThaiHDon` 1: Nháp). */
	public const STATUS_DRAFT = 1;

	/** `tenTThaiHDon` of a draft. */
	public const STATUS_DRAFT_NAME = 'Nháp';

	/**
	 * Creates the invoice.
	 *
	 * @param string      $invoice_id  `maSoHDon`, or empty when missing.
	 * @param string      $reference   `mtChieu`.
	 * @param int|null    $status      `maTThaiHDon`, when present.
	 * @param string      $status_name `tenTThaiHDon`, or empty.
	 * @param string|null $issued_on   `nLap` date (Y-m-d), when readable.
	 * @param int|null    $total       `tgTTTBSo` (total with tax), when present.
	 * @param string|null $buyer_name  Buyer name (`nMua_Ten`), when the response carries one.
	 * @param string|null $buyer_tax   Buyer tax code (`nMua_MST`), when the response carries one.
	 * @param int|null    $invoice_number `shDon`; null while the invoice has no number (0).
	 * @param string|null $tax_authority_code `mccqt`; null while empty.
	 * @param int|null    $invoice_type `maLoaiHDon`, when present.
	 * @param string|null $tax_note     `ghiChuCQT` (note of the tax authority); null while empty.
	 * @param string|null $lookup_code  `maTraCuu`; null while empty.
	 */
	public function __construct(
		public readonly string $invoice_id,
		public readonly string $reference,
		public readonly ?int $status,
		public readonly string $status_name,
		public readonly ?string $issued_on,
		public readonly ?int $total,
		public readonly ?string $buyer_name = null,
		public readonly ?string $buyer_tax = null,
		public readonly ?int $invoice_number = null,
		public readonly ?string $tax_authority_code = null,
		public readonly ?int $invoice_type = null,
		public readonly ?string $tax_note = null,
		public readonly ?string $lookup_code = null,
	) {
	}

	/**
	 * Builds the invoice from one decoded API item.
	 *
	 * @param array<mixed> $item Decoded item.
	 */
	public static function from_api( array $item ): self {
		$issued_on = $item['nLap'] ?? null;

		return new self(
			is_string( $item['maSoHDon'] ?? null ) ? $item['maSoHDon'] : '',
			is_scalar( $item['mtChieu'] ?? null ) ? (string) $item['mtChieu'] : '',
			is_numeric( $item['maTThaiHDon'] ?? null ) ? (int) $item['maTThaiHDon'] : null,
			is_string( $item['tenTThaiHDon'] ?? null ) ? $item['tenTThaiHDon'] : '',
			is_string( $issued_on ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}/', $issued_on ) ? substr( $issued_on, 0, 10 ) : null,
			is_numeric( $item['tgTTTBSo'] ?? null ) ? (int) round( (float) $item['tgTTTBSo'] ) : null,
			self::optional_text( $item['nMua_Ten'] ?? null ),
			self::optional_text( $item['nMua_MST'] ?? null ),
			is_numeric( $item['shDon'] ?? null ) && (int) $item['shDon'] > 0 ? (int) $item['shDon'] : null,
			self::optional_text( $item['mccqt'] ?? null ),
			is_numeric( $item['maLoaiHDon'] ?? null ) ? (int) $item['maLoaiHDon'] : null,
			self::optional_text( $item['ghiChuCQT'] ?? null ),
			self::optional_text( $item['maTraCuu'] ?? null ),
		);
	}

	/**
	 * A non-empty string value, or null. The demo lookup answer (docs/research/demo-results.json)
	 * carries no buyer fields; the names above follow the camelCase of the other lookup fields
	 * (`NLap` is `nLap`) and are only used when present.
	 *
	 * @param mixed $value Decoded value.
	 */
	private static function optional_text( mixed $value ): ?string {
		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
	}

	/**
	 * Whether Mat Bao reports the invoice as a draft: by `maTThaiHDon` when present, otherwise by
	 * `tenTThaiHDon`. An invoice with neither is not treated as a draft.
	 */
	public function is_draft(): bool {
		if ( null !== $this->status ) {
			return self::STATUS_DRAFT === $this->status;
		}

		return self::STATUS_DRAFT_NAME === $this->status_name;
	}
}
