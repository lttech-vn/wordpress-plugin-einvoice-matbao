<?php
/**
 * An invoice template (form number + series) registered on Mat Bao.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Matbao;

/**
 * One entry of `/api/invoice/templates`.
 */
final class MatbaoTemplate {

	/** `KHMSHDon` of VAT (GTGT) invoices. */
	public const VAT_FORM = '1';

	/**
	 * Creates the template.
	 *
	 * @param string $name      Template name (`thDon`).
	 * @param string $form      Form number (`KHMSHDon` / `khmshDon`).
	 * @param string $series    Series (`KHHDon` / `khhDon`).
	 * @param int    $total     Total invoice numbers (`sLuong`).
	 * @param int    $remaining Remaining invoice numbers (`cLai`).
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $form,
		public readonly string $series,
		public readonly int $total,
		public readonly int $remaining,
	) {
	}

	/**
	 * Builds a template from one decoded API item.
	 *
	 * @param array<mixed> $item Decoded item.
	 */
	public static function from_api( array $item ): self {
		return new self(
			is_string( $item['thDon'] ?? null ) ? $item['thDon'] : '',
			is_scalar( $item['khmshDon'] ?? null ) ? (string) $item['khmshDon'] : '',
			is_scalar( $item['khhDon'] ?? null ) ? (string) $item['khhDon'] : '',
			is_numeric( $item['sLuong'] ?? null ) ? (int) $item['sLuong'] : 0,
			is_numeric( $item['cLai'] ?? null ) ? (int) $item['cLai'] : 0,
		);
	}

	/**
	 * Whether this is a VAT (GTGT) invoice template.
	 */
	public function is_vat(): bool {
		return self::VAT_FORM === $this->form;
	}

	/**
	 * Whether invoices can still be numbered with this template.
	 */
	public function has_remaining_numbers(): bool {
		return $this->remaining > 0;
	}
}
