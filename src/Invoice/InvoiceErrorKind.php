<?php
/**
 * Kinds of invoice errors.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

/**
 * Groups InvoiceErrorCode values by what has to change before the draft can be sent, which also
 * decides the invoice status. Whether a background retry may resend is decided per code
 * (InvoiceErrorCode::is_auto_retryable()). Stored in the `error_kind` column; values are stable.
 */
enum InvoiceErrorKind: string {

	/** The order data cannot become a correct invoice (tax rate, totals, currency, `MTChieu`). */
	case DATA = 'data';

	/** Mat Bao credentials or the default template are missing, unavailable or rejected. */
	case CONFIG = 'config';

	/** The order left `completed` or was refunded before sending. */
	case ORDER = 'order';

	/** A Mat Bao call failed (transport, HTTP status, rejected response). */
	case MATBAO = 'matbao';

	/** The plugin failed (queueing, unexpected exception). */
	case SYSTEM = 'system';

	/**
	 * Invoice status for an error of this kind.
	 */
	public function status(): string {
		return match ( $this ) {
			self::CONFIG => InvoiceStatus::NOT_CONFIGURED,
			self::ORDER  => InvoiceStatus::STOPPED,
			default      => InvoiceStatus::FAILED,
		};
	}

	/**
	 * Vietnamese label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::DATA   => __( 'Dữ liệu đơn hàng', 'einvoice-matbao' ),
			self::CONFIG => __( 'Cấu hình', 'einvoice-matbao' ),
			self::ORDER  => __( 'Đơn hàng thay đổi', 'einvoice-matbao' ),
			self::MATBAO => __( 'Mắt Bão', 'einvoice-matbao' ),
			self::SYSTEM => __( 'Hệ thống', 'einvoice-matbao' ),
		};
	}
}
