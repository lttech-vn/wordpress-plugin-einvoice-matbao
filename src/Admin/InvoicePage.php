<?php
/**
 * Invoice list and detail UI under the WooCommerce menu.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\Redactor;
use LTTech\EinvoiceMatbao\Matbao\MatbaoInvoice;

/**
 * The page shell and the HTML fragments of InvoiceController. Every value is escaped here and
 * stored reasons and Mat Bao messages pass through Redactor first.
 */
final class InvoicePage {

	public const SLUG = 'einvoice-matbao-invoices';

	private const BUTTON    = 'inline-flex cursor-pointer items-center rounded-full border-0 bg-einv-primary px-4 py-2 text-[14px] font-normal text-white hover:bg-einv-primary-deep';
	private const SECONDARY = 'inline-flex cursor-pointer items-center rounded-full border border-einv-hairline bg-white px-4 py-2 text-[14px] font-normal text-einv-primary hover:border-einv-primary';
	private const INPUT     = 'rounded-einv-sm border border-einv-hairline-input bg-white px-3 py-2 text-[14px] text-einv-ink';
	private const CARD      = 'mb-6 rounded-einv-lg border border-einv-hairline bg-white p-6 shadow-einv-card';
	private const MUTED     = 'text-[13px] text-einv-ink-mute';

	/**
	 * Hooks the menu.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 61 );
	}

	/**
	 * Adds the submenu under WooCommerce.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Danh sách hóa đơn Mắt Bão', 'einvoice-matbao' ),
			__( 'Danh sách hóa đơn', 'einvoice-matbao' ),
			SettingsPage::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renders the page shell; the list loads through htmx.
	 */
	public static function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		$list = rest_url( SettingsController::NAMESPACE . '/invoices' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Danh sách hóa đơn Mắt Bão', 'einvoice-matbao' ); ?></h1>
			<div id="einv-app" class="mt-4 font-einv text-einv-ink" hx-swap="innerHTML">
				<form class="mb-4 flex flex-wrap items-end gap-2" hx-get="<?php echo esc_url( $list ); ?>" hx-target="#einv-invoices" hx-trigger="submit, change from:select, keyup changed delay:400ms from:input[name=q]">
					<select name="status" class="<?php echo esc_attr( self::INPUT ); ?>" aria-label="<?php esc_attr_e( 'Trạng thái', 'einvoice-matbao' ); ?>">
						<option value=""><?php esc_html_e( 'Tất cả trạng thái', 'einvoice-matbao' ); ?></option>
						<?php foreach ( InvoiceStatus::filter_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="search" name="q" maxlength="40" placeholder="<?php esc_attr_e( 'Mã đơn hoặc MTChieu', 'einvoice-matbao' ); ?>" aria-label="<?php esc_attr_e( 'Mã đơn hoặc MTChieu', 'einvoice-matbao' ); ?>" class="<?php echo esc_attr( self::INPUT ); ?>" />
					<button type="submit" class="<?php echo esc_attr( self::BUTTON ); ?>"><?php esc_html_e( 'Lọc', 'einvoice-matbao' ); ?></button>
				</form>
				<div id="einv-invoices" hx-get="<?php echo esc_url( $list ); ?>" hx-trigger="load"><p class="<?php echo esc_attr( self::MUTED ); ?>"><?php esc_html_e( 'Đang tải…', 'einvoice-matbao' ); ?></p></div>
			</div>
		</div>
		<?php
	}

	/**
	 * The list table with its pager.
	 *
	 * @param InvoiceRecord[]       $items  Rows of the page.
	 * @param int                   $total  Rows over all pages.
	 * @param int                   $page   Current page.
	 * @param int                   $pages  Number of pages.
	 * @param array<string, string> $query  Active filter and search, kept in the pager links.
	 */
	public static function render_list( array $items, int $total, int $page, int $pages, array $query ): string {
		$html = sprintf( '<p class="%1$s" data-einv-total="%2$d">%3$s</p>', esc_attr( self::MUTED ), $total, esc_html( sprintf( /* translators: %s: number of invoices. */ __( '%s hóa đơn', 'einvoice-matbao' ), number_format_i18n( $total ) ) ) );

		if ( array() === $items ) {
			return $html . '<p>' . esc_html__( 'Không có hóa đơn nào khớp.', 'einvoice-matbao' ) . '</p>';
		}

		$html .= '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Đơn', 'einvoice-matbao' ), 'MTChieu', __( 'Trạng thái', 'einvoice-matbao' ), __( 'Lỗi', 'einvoice-matbao' ), __( 'Cập nhật (UTC)', 'einvoice-matbao' ), '' ) as $heading ) {
			$html .= '<th>' . esc_html( $heading ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $items as $invoice ) {
			$error = $invoice->error();
			$html .= sprintf(
				'<tr data-einv-invoice-row="%1$d"><td>#%1$d</td><td><code>%2$s</code></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td><button type="button" class="%6$s" hx-get="%7$s" hx-target="#einv-invoices">%8$s</button></td></tr>',
				$invoice->order_id,
				esc_html( $invoice->reference ),
				esc_html( InvoiceStatus::label( $invoice->status ) ),
				esc_html( null === $error ? '' : $error->label() ),
				esc_html( $invoice->updated_at ),
				esc_attr( self::SECONDARY ),
				esc_url( rest_url( SettingsController::NAMESPACE . '/invoices/' . $invoice->id ) ),
				esc_html__( 'Chi tiết', 'einvoice-matbao' )
			);
		}
		$html .= '</tbody></table>';

		if ( $pages > 1 ) {
			$html .= '<div class="mt-3 flex items-center gap-2">';
			foreach ( array(
				$page - 1 => __( '‹ Trước', 'einvoice-matbao' ),
				$page + 1 => __( 'Sau ›', 'einvoice-matbao' ),
			) as $target => $label ) {
				if ( $target < 1 || $target > $pages ) {
					continue;
				}
				$html .= sprintf(
					'<button type="button" class="%1$s" hx-get="%2$s" hx-target="#einv-invoices">%3$s</button>',
					esc_attr( self::SECONDARY ),
					esc_url( add_query_arg( array_merge( $query, array( 'page' => $target ) ), rest_url( SettingsController::NAMESPACE . '/invoices' ) ) ),
					esc_html( $label )
				);
			}
			$html .= sprintf( '<span class="%1$s">%2$s</span></div>', esc_attr( self::MUTED ), esc_html( sprintf( /* translators: 1: page, 2: pages. */ __( 'Trang %1$d / %2$d', 'einvoice-matbao' ), $page, $pages ) ) );
		}

		return $html;
	}

	/**
	 * Detail of one invoice: state, error, template, attempts, and the status check and resend actions.
	 *
	 * @param InvoiceRecord              $invoice  Invoice.
	 * @param list<array<string, mixed>> $attempts Attempts, oldest first.
	 */
	public static function render_detail( InvoiceRecord $invoice, array $attempts ): string {
		$base  = rest_url( SettingsController::NAMESPACE . '/invoices/' . $invoice->id );
		$error = $invoice->error();

		$rows = array(
			__( 'Đơn hàng', 'einvoice-matbao' )           => '#' . $invoice->order_id,
			'MTChieu'                                     => $invoice->reference,
			__( 'Trạng thái', 'einvoice-matbao' )         => InvoiceStatus::label( $invoice->status ),
			__( 'Mã hóa đơn Mắt Bão', 'einvoice-matbao' ) => $invoice->matbao_invoice_id ?? '—',
			__( 'Mẫu số / ký hiệu đã lưu', 'einvoice-matbao' ) => '' === $invoice->template_form ? '—' : $invoice->template_form . ' / ' . $invoice->template_series,
			__( 'Người mua', 'einvoice-matbao' )          => Redactor::redact( $invoice->buyer_name ),
		);
		if ( null !== $error ) {
			$rows[ __( 'Mã lỗi', 'einvoice-matbao' ) ] = $error->value . ' – ' . $error->label();
		}
		if ( '' !== $invoice->status_reason ) {
			$rows[ __( 'Lý do', 'einvoice-matbao' ) ] = Redactor::redact( $invoice->status_reason );
		}

		$html  = sprintf( '<section class="%1$s" data-einv-invoice-detail="%2$d"', esc_attr( self::CARD ), $invoice->id );
		$html .= null === $error ? '' : sprintf( ' data-einv-error-code="%s"', esc_attr( $error->value ) );
		$html .= sprintf( '><button type="button" class="%1$s mb-3" hx-get="%2$s" hx-target="#einv-invoices">%3$s</button><dl class="m-0 grid grid-cols-[200px_1fr] gap-y-2 text-[14px]">', esc_attr( self::SECONDARY ), esc_url( rest_url( SettingsController::NAMESPACE . '/invoices' ) ), esc_html__( '‹ Danh sách', 'einvoice-matbao' ) );
		foreach ( $rows as $label => $value ) {
			$html .= sprintf( '<dt class="%1$s">%2$s</dt><dd class="m-0 break-all">%3$s</dd>', esc_attr( self::MUTED ), esc_html( $label ), esc_html( $value ) );
		}
		$html .= '</dl>';

		$html .= '<h3 class="mt-6 text-[16px] font-medium">' . esc_html__( 'Lịch sử gửi', 'einvoice-matbao' ) . '</h3>';
		if ( array() === $attempts ) {
			$html .= '<p class="' . esc_attr( self::MUTED ) . '">' . esc_html__( 'Chưa có lần gửi nào.', 'einvoice-matbao' ) . '</p>';
		} else {
			$html .= '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Bắt đầu (UTC)', 'einvoice-matbao' ) . '</th><th>' . esc_html__( 'Kết quả', 'einvoice-matbao' ) . '</th><th>' . esc_html__( 'Thông điệp', 'einvoice-matbao' ) . '</th></tr></thead><tbody>';
			foreach ( $attempts as $attempt ) {
				$outcome = is_string( $attempt['outcome'] ?? null ) ? $attempt['outcome'] : '';
				$message = is_string( $attempt['message'] ?? null ) ? $attempt['message'] : '';
				$html   .= sprintf(
					'<tr data-einv-attempt="%1$s"><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
					esc_attr( '' === $outcome ? 'open' : $outcome ),
					esc_html( is_string( $attempt['started_at'] ?? null ) ? $attempt['started_at'] : '' ),
					esc_html( self::outcome_label( $outcome ) ),
					esc_html( Redactor::redact( $message ) )
				);
			}
			$html .= '</tbody></table>';
		}

		$html .= '<div class="mt-6 flex flex-wrap gap-2">';
		if ( '' !== $invoice->template_form ) {
			$html .= sprintf( '<button type="button" class="%1$s" hx-post="%2$s" hx-target="next .einv-result" hx-params="none">%3$s</button>', esc_attr( self::SECONDARY ), esc_url( $base . '/check' ), esc_html__( 'Kiểm tra trạng thái', 'einvoice-matbao' ) );
		}
		$html .= '</div><div class="einv-result" aria-live="polite"></div>';
		$html .= self::resend_form( $invoice, $base . '/resend' );

		return $html . '</section>';
	}

	/**
	 * Result of a status check, with a resend form when the draft is gone.
	 *
	 * @param InvoiceRecord   $invoice Invoice.
	 * @param MatbaoInvoice[] $found  What Mat Bao has under the reference.
	 */
	public static function render_check( InvoiceRecord $invoice, array $found ): string {
		$base = rest_url( SettingsController::NAMESPACE . '/invoices/' . $invoice->id );

		if ( array() === $found ) {
			$message = InvoiceStatus::DRAFT_CREATED === $invoice->status
				? __( 'Cảnh báo: hóa đơn nháp không còn trên Mắt Bão (có thể đã bị xóa). Hệ thống không tự tạo lại.', 'einvoice-matbao' )
				: __( 'Mắt Bão chưa có hóa đơn nào với mã tham chiếu này.', 'einvoice-matbao' );

			return sprintf( '<div data-einv-check="missing">%s</div>', Fragment::notice( Fragment::STATUS_ERROR, $message ) )
				. ( InvoiceStatus::DRAFT_CREATED === $invoice->status ? self::lost_draft_form( $base . '/resend' ) : '' );
		}

		$first = $found[0];
		if ( 1 === count( $found ) && $first->is_draft() ) {
			return sprintf( '<div data-einv-check="draft">%s</div>', Fragment::notice( Fragment::STATUS_SUCCESS, __( 'Hóa đơn nháp vẫn còn trên Mắt Bão.', 'einvoice-matbao' ) ) );
		}

		$names = array_map( static fn( MatbaoInvoice $row ): string => '' !== $row->status_name ? $row->status_name : (string) $row->status, $found );

		return sprintf(
			'<div data-einv-check="not_draft">%s</div>',
			Fragment::notice(
				Fragment::STATUS_ERROR,
				/* translators: %s: Mat Bao status names. */
				sprintf( __( 'Cảnh báo: trên Mắt Bão hóa đơn không còn ở trạng thái Nháp (%s). Kiểm tra trực tiếp trên Mắt Bão.', 'einvoice-matbao' ), Redactor::redact( implode( ', ', $names ) ) )
			)
		);
	}

	/**
	 * Resend form for statuses a plain resend is allowed from; the `template_changed` stop gets the
	 * extra "no invoice exists" confirmation.
	 *
	 * @param InvoiceRecord $invoice Invoice.
	 * @param string        $url     Resend route.
	 */
	private static function resend_form( InvoiceRecord $invoice, string $url ): string {
		if ( in_array( $invoice->status, array( InvoiceStatus::PENDING, InvoiceStatus::SENDING, InvoiceStatus::SKIPPED, InvoiceStatus::DRAFT_CREATED ), true ) ) {
			return '';
		}

		$changed = InvoiceErrorCode::TEMPLATE_CHANGED === $invoice->error();
		$form    = sprintf( '<form class="mt-4 space-y-2" hx-post="%s" hx-target="next .einv-result" hx-confirm="%s">', esc_url( $url ), esc_attr__( 'Gửi lại hóa đơn này? Hệ thống tra cứu trên Mắt Bão trước khi tạo.', 'einvoice-matbao' ) );
		$form   .= '<input type="hidden" name="confirm" value="1" />';
		if ( $changed ) {
			$form .= sprintf(
				'<label class="block text-[13px]"><input type="checkbox" name="confirm_no_invoice" value="1" /> %1$s</label><label class="block text-[13px]">%2$s <input type="text" name="confirm_reference" autocomplete="off" class="%3$s" /></label>',
				esc_html__( 'Tôi đã kiểm tra trên Mắt Bão: CHƯA có hóa đơn nào với mẫu cũ. Gửi lại bằng mẫu mặc định hiện tại; lựa chọn này được ghi vào lịch sử.', 'einvoice-matbao' ),
				/* translators: %s: reference (MTChieu). */
				esc_html( sprintf( __( 'Nhập %s để xác nhận:', 'einvoice-matbao' ), $invoice->reference ) ),
				esc_attr( self::INPUT )
			);
		}

		return $form . sprintf( '<button type="submit" class="%1$s">%2$s</button></form><div class="einv-result" aria-live="polite"></div>', esc_attr( self::BUTTON ), esc_html__( 'Gửi lại', 'einvoice-matbao' ) );
	}

	/**
	 * Resend form shown after a check found the draft missing.
	 *
	 * @param string $url Resend route.
	 */
	private static function lost_draft_form( string $url ): string {
		return sprintf(
			'<form class="mt-3 space-y-2" hx-post="%1$s" hx-target="next .einv-result" hx-confirm="%2$s"><input type="hidden" name="confirm" value="1" /><label class="block text-[13px]"><input type="checkbox" name="confirm_lost" value="1" /> %3$s</label><button type="submit" class="%4$s">%5$s</button></form><div class="einv-result" aria-live="polite"></div>',
			esc_url( $url ),
			esc_attr__( 'Tạo lại hóa đơn nháp trên Mắt Bão?', 'einvoice-matbao' ),
			esc_html__( 'Tôi xác nhận nháp đã bị xóa và muốn tạo lại.', 'einvoice-matbao' ),
			esc_attr( self::BUTTON ),
			esc_html__( 'Gửi lại', 'einvoice-matbao' )
		);
	}

	/**
	 * Vietnamese label of an attempt outcome.
	 *
	 * @param string $outcome Stored outcome; empty while the call is open.
	 */
	private static function outcome_label( string $outcome ): string {
		return match ( $outcome ) {
			'success'                                 => __( 'Thành công', 'einvoice-matbao' ),
			'failed'                                  => __( 'Thất bại', 'einvoice-matbao' ),
			'not_sent'                                => __( 'Chưa gửi tới Mắt Bão', 'einvoice-matbao' ),
			'lookup_failed'                           => __( 'Tra cứu thất bại', 'einvoice-matbao' ),
			'lookup_mismatch'                         => __( 'Tra cứu không khớp', 'einvoice-matbao' ),
			'abandoned'                               => __( 'Bị bỏ dở', 'einvoice-matbao' ),
			InvoiceController::OUTCOME_ADMIN_ABSENT   => __( 'Admin xác nhận chưa có hóa đơn', 'einvoice-matbao' ),
			''                                        => __( 'Đang chạy', 'einvoice-matbao' ),
			default                                   => $outcome,
		};
	}
}
