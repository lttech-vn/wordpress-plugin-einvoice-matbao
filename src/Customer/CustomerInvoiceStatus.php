<?php
/**
 * Customer-facing status of an invoice.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Customer;

use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\MatbaoStatus;

/**
 * The one friendly status shown to the buyer for an invoice.
 */
enum CustomerInvoiceStatus: string {

	case PROCESSING   = 'processing';
	case NEEDS_STORE  = 'needs_store';
	case NOT_REQUIRED = 'not_required';
	case ISSUED       = 'issued';
	case ADJUSTED     = 'adjusted';

	/** Mat Bao invoice types that cancel or replace an issued invoice. */
	private const ADJUSTING_TYPES = array( 3, 5 );

	/**
	 * Maps an invoice record to its customer status.
	 *
	 * @param InvoiceRecord $record Invoice record of the order.
	 */
	public static function from_record( InvoiceRecord $record ): self {
		if ( InvoiceStatus::SKIPPED === $record->status ) {
			return self::NOT_REQUIRED;
		}

		if ( InvoiceStatus::PENDING === $record->status || InvoiceStatus::SENDING === $record->status ) {
			return self::PROCESSING;
		}

		if ( InvoiceStatus::DRAFT_CREATED !== $record->status ) {
			return self::NEEDS_STORE;
		}

		return self::from_draft( $record );
	}

	/**
	 * Maps a created draft by its Mat Bao status and invoice type.
	 *
	 * @param InvoiceRecord $record Record with status `draft_created`.
	 */
	private static function from_draft( InvoiceRecord $record ): self {
		$matbao = null === $record->matbao_status ? null : MatbaoStatus::tryFrom( $record->matbao_status );

		if ( null !== $matbao && null !== $matbao->alert_code() ) {
			return self::NEEDS_STORE;
		}

		if ( MatbaoStatus::ISSUED !== $matbao ) {
			return self::PROCESSING;
		}

		if ( in_array( $record->matbao_invoice_type, self::ADJUSTING_TYPES, true ) ) {
			return self::ADJUSTED;
		}

		// An issued invoice without its Mat Bao invoice ID is not shown as issued yet.
		return null === $record->matbao_invoice_id ? self::PROCESSING : self::ISSUED;
	}

	/**
	 * Vietnamese label shown to the buyer.
	 */
	public function label(): string {
		return match ( $this ) {
			self::PROCESSING   => __( 'Đang xuất hóa đơn', 'einvoice-matbao' ),
			self::NEEDS_STORE  => __( 'Đang xử lý, cửa hàng sẽ liên hệ nếu cần', 'einvoice-matbao' ),
			self::NOT_REQUIRED => __( 'Đơn hàng không cần xuất hóa đơn', 'einvoice-matbao' ),
			self::ISSUED       => __( 'Đã xuất hóa đơn', 'einvoice-matbao' ),
			self::ADJUSTED     => __( 'Hóa đơn đã được điều chỉnh, vui lòng liên hệ cửa hàng', 'einvoice-matbao' ),
		};
	}

	/**
	 * Whether the buyer may download the PDF.
	 */
	public function allows_pdf(): bool {
		return self::ISSUED === $this;
	}
}
