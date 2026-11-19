<?php
/**
 * Result of a batch lookup by `MTChieu`.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Matbao;

/**
 * What Mat Bao answered to `invoice-detail-batch`: the invoices it found and the references it
 * confirmed it does not have. A reference in neither list proves nothing.
 */
final class MatbaoLookup {

	/**
	 * Creates the result.
	 *
	 * @param MatbaoInvoice[] $invoices  Rows of `data.invoices`.
	 * @param string[]        $not_found `MTChieu` values of `data.notFound`; every reference sent when Mat Bao answered 301.
	 */
	public function __construct(
		public readonly array $invoices,
		public readonly array $not_found,
	) {
	}
}
