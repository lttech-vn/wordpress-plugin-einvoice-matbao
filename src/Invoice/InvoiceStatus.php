<?php
/**
 * Invoice statuses.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

/**
 * Status values of the invoice table and their Vietnamese labels.
 */
final class InvoiceStatus {

	/** Reserved when the order completed; the draft action is queued. */
	public const PENDING = 'pending';

	/** Claimed by the draft action; a create call may be in flight. Back to `pending` while a retry waits. */
	public const SENDING = 'sending';

	/** Mat Bao created the draft (`LoaiHDon=0`). */
	public const DRAFT_CREATED = 'draft_created';

	/** Not retried: bad data, or Mat Bao rejected the invoice or the credentials. */
	public const FAILED = 'failed';

	/** Retries are used up; a person must look at it (hook `einvoice_matbao_retries_exhausted`). */
	public const NEEDS_ATTENTION = 'needs_attention';

	/** Never sent on purpose, e.g. a 0 VND order. */
	public const SKIPPED = 'skipped';

	/** Not sent because Mat Bao credentials or the default template are missing. */
	public const NOT_CONFIGURED = 'not_configured';

	/** Not sent because the order left `completed` or was refunded before sending. */
	public const STOPPED = 'stopped';

	/**
	 * Vietnamese label of a status.
	 *
	 * @param string $status One of the constants.
	 */
	public static function label( string $status ): string {
		return match ( $status ) {
			self::PENDING        => __( 'Chờ gửi', 'einvoice-matbao' ),
			self::SENDING        => __( 'Đang gửi', 'einvoice-matbao' ),
			self::DRAFT_CREATED  => __( 'Đã tạo nháp', 'einvoice-matbao' ),
			self::FAILED         => __( 'Lỗi', 'einvoice-matbao' ),
			self::NEEDS_ATTENTION => __( 'Lỗi cần xử lý', 'einvoice-matbao' ),
			self::SKIPPED        => __( 'Bỏ qua', 'einvoice-matbao' ),
			self::NOT_CONFIGURED => __( 'Chưa cấu hình', 'einvoice-matbao' ),
			self::STOPPED        => __( 'Đã dừng', 'einvoice-matbao' ),
			default              => $status,
		};
	}
}
