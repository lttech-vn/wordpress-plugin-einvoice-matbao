<?php
/**
 * Status of an invoice on Mat Bao.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

/**
 * `maTThaiHDon` 1-9 of Mat Bao plus the plugin's own 0 "deleted on Mat Bao" (Mat Bao never uses 0).
 * The single place that classifies a status: final, abnormal (alerting) and badge group.
 */
enum MatbaoStatus: int {

	case DELETED               = 0;
	case DRAFT                 = 1;
	case SIGN_FAILED           = 2;
	case NOT_SENT_TO_AUTHORITY = 3;
	case ISSUED                = 4;
	case AUTHORITY_NO_RESPONSE = 5;
	case NOT_ELIGIBLE          = 6;
	case FORM04_NO_DECLARATION = 7;
	case FORM04_NOT_RECEIVED   = 8;
	case FORM04_APPROVED       = 9;

	/** Values after which an invoice is no longer synced. */
	public const FINAL_VALUES = array( 0, 4 );

	/**
	 * Vietnamese label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::DELETED               => __( 'Đã xóa trên Mắt Bão', 'einvoice-matbao' ),
			self::DRAFT                 => __( 'Nháp', 'einvoice-matbao' ),
			self::SIGN_FAILED           => __( 'Lỗi ký', 'einvoice-matbao' ),
			self::NOT_SENT_TO_AUTHORITY => __( 'Chưa gửi CQT', 'einvoice-matbao' ),
			self::ISSUED                => __( 'Đã cấp mã/tiếp nhận', 'einvoice-matbao' ),
			self::AUTHORITY_NO_RESPONSE => __( 'CQT chưa phản hồi', 'einvoice-matbao' ),
			self::NOT_ELIGIBLE          => __( 'Không đủ điều kiện cấp mã/tiếp nhận', 'einvoice-matbao' ),
			self::FORM04_NO_DECLARATION => __( '[Mẫu 04/SS] Chưa tạo tờ khai', 'einvoice-matbao' ),
			self::FORM04_NOT_RECEIVED   => __( '[Mẫu 04/SS] CQT chưa tiếp nhận', 'einvoice-matbao' ),
			self::FORM04_APPROVED       => __( '[Mẫu 04/SS] CQT đã phê duyệt', 'einvoice-matbao' ),
		};
	}

	/**
	 * Whether the status never changes again, so the invoice is not looked up any more.
	 */
	public function is_final(): bool {
		return in_array( $this->value, self::FINAL_VALUES, true );
	}

	/**
	 * Error code to alert with, or null for a status that is not abnormal.
	 */
	public function alert_code(): ?InvoiceErrorCode {
		return match ( $this ) {
			self::SIGN_FAILED  => InvoiceErrorCode::MATBAO_SIGN_FAILED,
			self::NOT_ELIGIBLE => InvoiceErrorCode::MATBAO_NOT_ELIGIBLE,
			self::DELETED      => InvoiceErrorCode::MATBAO_DRAFT_DELETED,
			default            => null,
		};
	}

	/**
	 * Badge group: `issued`, `error` (abnormal) or `progress` (everything in between).
	 */
	public function group(): string {
		if ( self::ISSUED === $this ) {
			return 'issued';
		}

		return null === $this->alert_code() ? 'progress' : 'error';
	}

	/**
	 * Label of an invoice type (`maLoaiHDon`), or empty when unknown.
	 *
	 * @param int|null $code `maLoaiHDon`.
	 */
	public static function invoice_type_label( ?int $code ): string {
		return match ( $code ) {
			1       => __( 'Nháp', 'einvoice-matbao' ),
			2       => __( 'Hóa đơn mới', 'einvoice-matbao' ),
			3       => __( 'Hóa đơn xóa bỏ', 'einvoice-matbao' ),
			4       => __( 'Hóa đơn thay thế', 'einvoice-matbao' ),
			5       => __( 'Hóa đơn bị thay thế', 'einvoice-matbao' ),
			6       => __( 'Hóa đơn điều chỉnh tăng', 'einvoice-matbao' ),
			7       => __( 'Hóa đơn điều chỉnh giảm', 'einvoice-matbao' ),
			8       => __( 'Hóa đơn điều chỉnh thông tin', 'einvoice-matbao' ),
			9       => __( 'Hóa đơn điều chỉnh tăng/giảm', 'einvoice-matbao' ),
			10      => __( 'Hóa đơn bị điều chỉnh', 'einvoice-matbao' ),
			default => '',
		};
	}
}
