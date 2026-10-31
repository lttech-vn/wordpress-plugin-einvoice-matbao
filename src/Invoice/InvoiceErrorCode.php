<?php
/**
 * Stable codes of invoice errors.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

use LTTech\EinvoiceMatbao\Matbao\MatbaoError;

/**
 * Why a draft was not created. Stored in the `error_code` column next to the free-text
 * `status_reason`, so filters, alerts (#7) and retries (#6) never parse messages. Values are
 * stable; add new cases instead of renaming.
 */
enum InvoiceErrorCode: string {

	case CURRENCY_NOT_VND      = 'currency_not_vnd';
	case NO_LINES              = 'no_lines';
	case LINE_TYPE_UNSUPPORTED = 'line_type_unsupported';
	case LINE_AMOUNT_INVALID   = 'line_amount_invalid';
	case AMOUNT_NOT_WHOLE_VND  = 'amount_not_whole_vnd';
	case LINE_TAX_RATE_COUNT   = 'line_tax_rate_count';
	case TAX_RATE_NOT_STORED   = 'tax_rate_not_stored';
	case TAX_RATE_UNSUPPORTED  = 'tax_rate_unsupported';
	case UNTAXED_LINE_WITH_TAX = 'untaxed_line_with_tax';
	case TOTALS_MISMATCH       = 'totals_mismatch';
	case REFERENCE_TOO_LONG    = 'reference_too_long';
	case CREDENTIALS_MISSING   = 'credentials_missing';
	case TEMPLATE_MISSING      = 'template_missing';
	case TEMPLATE_UNAVAILABLE  = 'template_unavailable';
	case TEMPLATE_REJECTED     = 'template_rejected';
	case ORDER_NOT_COMPLETED   = 'order_not_completed';
	case ORDER_REFUNDED        = 'order_refunded';
	case MATBAO_TRANSPORT      = 'matbao_transport';
	case MATBAO_HTTP           = 'matbao_http';
	case MATBAO_API            = 'matbao_api';
	case QUEUE_FAILED          = 'queue_failed';
	case UNEXPECTED            = 'unexpected';

	/** Mat Bao `errorCode` values meaning the template (`KHMSHDon`/`KHHDon`) cannot be used. */
	private const MATBAO_TEMPLATE_CODES = array(
		308, // Không tìm thấy mẫu số kí hiệu.
		327, // Mẫu hóa đơn đã hết số.
	);

	/**
	 * Code of a failed Mat Bao call.
	 *
	 * @param MatbaoError $error Error.
	 */
	public static function from_matbao( MatbaoError $error ): self {
		if ( in_array( $error->api_code, self::MATBAO_TEMPLATE_CODES, true ) ) {
			return self::TEMPLATE_REJECTED;
		}

		return match ( $error->kind ) {
			MatbaoError::KIND_TRANSPORT => self::MATBAO_TRANSPORT,
			MatbaoError::KIND_HTTP      => self::MATBAO_HTTP,
			default                     => self::MATBAO_API,
		};
	}

	/**
	 * Kind of the error.
	 */
	public function kind(): InvoiceErrorKind {
		return match ( $this ) {
			self::CURRENCY_NOT_VND,
			self::NO_LINES,
			self::LINE_TYPE_UNSUPPORTED,
			self::LINE_AMOUNT_INVALID,
			self::AMOUNT_NOT_WHOLE_VND,
			self::LINE_TAX_RATE_COUNT,
			self::TAX_RATE_NOT_STORED,
			self::TAX_RATE_UNSUPPORTED,
			self::UNTAXED_LINE_WITH_TAX,
			self::TOTALS_MISMATCH,
			self::REFERENCE_TOO_LONG    => InvoiceErrorKind::DATA,
			self::CREDENTIALS_MISSING,
			self::TEMPLATE_MISSING,
			self::TEMPLATE_UNAVAILABLE,
			self::TEMPLATE_REJECTED     => InvoiceErrorKind::CONFIG,
			self::ORDER_NOT_COMPLETED,
			self::ORDER_REFUNDED        => InvoiceErrorKind::ORDER,
			self::MATBAO_TRANSPORT,
			self::MATBAO_HTTP,
			self::MATBAO_API            => InvoiceErrorKind::MATBAO,
			self::QUEUE_FAILED,
			self::UNEXPECTED            => InvoiceErrorKind::SYSTEM,
		};
	}

	/**
	 * Whether an admin has to act. An order the shop cancelled or moved back on purpose needs no
	 * invoice and no alert; a refund before sending still needs a decision.
	 */
	public function needs_attention(): bool {
		return self::ORDER_NOT_COMPLETED !== $this;
	}

	/**
	 * Short Vietnamese label; the stored `status_reason` carries the details.
	 */
	public function label(): string {
		return match ( $this ) {
			self::CURRENCY_NOT_VND      => __( 'Tiền tệ không phải VND', 'einvoice-matbao' ),
			self::NO_LINES              => __( 'Không có dòng hàng hóa', 'einvoice-matbao' ),
			self::LINE_TYPE_UNSUPPORTED => __( 'Loại dòng không hỗ trợ', 'einvoice-matbao' ),
			self::LINE_AMOUNT_INVALID   => __( 'Số tiền hoặc số lượng không hợp lệ', 'einvoice-matbao' ),
			self::AMOUNT_NOT_WHOLE_VND  => __( 'Số tiền không nguyên đồng', 'einvoice-matbao' ),
			self::LINE_TAX_RATE_COUNT   => __( 'Dòng không có đúng một thuế suất', 'einvoice-matbao' ),
			self::TAX_RATE_NOT_STORED   => __( 'Thiếu thuế suất đã lưu', 'einvoice-matbao' ),
			self::TAX_RATE_UNSUPPORTED  => __( 'Thuế suất không hỗ trợ', 'einvoice-matbao' ),
			self::UNTAXED_LINE_WITH_TAX => __( 'Dòng không chịu thuế có tiền thuế', 'einvoice-matbao' ),
			self::TOTALS_MISMATCH       => __( 'Tổng tiền lệch tổng đơn hàng', 'einvoice-matbao' ),
			self::REFERENCE_TOO_LONG    => __( 'Mã tham chiếu quá dài', 'einvoice-matbao' ),
			self::CREDENTIALS_MISSING   => __( 'Chưa cấu hình kết nối Mắt Bão', 'einvoice-matbao' ),
			self::TEMPLATE_MISSING      => __( 'Chưa chọn mẫu hóa đơn', 'einvoice-matbao' ),
			self::TEMPLATE_UNAVAILABLE  => __( 'Mẫu hóa đơn hết hiệu lực', 'einvoice-matbao' ),
			self::TEMPLATE_REJECTED     => __( 'Mắt Bão từ chối mẫu hóa đơn', 'einvoice-matbao' ),
			self::ORDER_NOT_COMPLETED   => __( 'Đơn hàng không còn hoàn thành', 'einvoice-matbao' ),
			self::ORDER_REFUNDED        => __( 'Đơn hàng đã hoàn tiền', 'einvoice-matbao' ),
			self::MATBAO_TRANSPORT      => __( 'Không kết nối được Mắt Bão', 'einvoice-matbao' ),
			self::MATBAO_HTTP           => __( 'Mắt Bão trả lỗi HTTP', 'einvoice-matbao' ),
			self::MATBAO_API            => __( 'Mắt Bão từ chối hóa đơn', 'einvoice-matbao' ),
			self::QUEUE_FAILED          => __( 'Không xếp được hàng đợi', 'einvoice-matbao' ),
			self::UNEXPECTED            => __( 'Lỗi không xác định', 'einvoice-matbao' ),
		};
	}
}
