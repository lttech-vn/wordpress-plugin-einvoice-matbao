<?php
/**
 * Admin notice listing invoices that need an admin.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Alert;

use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;

/**
 * Shown to users who can `manage_options`. An alert listed here is still true: the invoice still
 * has that error in that retry cycle. Resending the invoice or dismissing the alert removes it.
 */
final class AdminAlertNotice {

	public const DISMISS_ACTION = 'einvoice_matbao_dismiss_alert';

	private const NOTICE_LIMIT = 10;

	/**
	 * Prints the notice.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$repository = new InvoiceRepository();
		$items      = array();
		foreach ( AlertStore::recent() as $alert ) {
			if ( $alert['dismissed'] ) {
				continue;
			}
			$invoice = $repository->find_by_order( $alert['order_id'] );
			$order   = wc_get_order( $alert['order_id'] );
			$code    = InvoiceErrorCode::tryFrom( $alert['code'] );
			if (
				null === $invoice || ! $order instanceof \WC_Order || null === $code
				|| $invoice->error_code !== $alert['code']
				|| $invoice->alert_cycle !== $alert['cycle']
				|| ! in_array( $invoice->status, array( InvoiceStatus::FAILED, InvoiceStatus::NEEDS_ATTENTION, InvoiceStatus::NOT_CONFIGURED ), true )
			) {
				continue;
			}

			$items[] = sprintf(
				'<li data-einv-alert="%1$s"><a href="%2$s">%3$s</a> – %4$s%5$s <a href="%6$s">%7$s</a></li>',
				esc_attr( $alert['code'] ),
				esc_url( $order->get_edit_order_url() ),
				esc_html( '#' . $alert['order_id'] ),
				esc_html( $code->label() ),
				esc_html( self::lark_note( $alert['lark'] ) ),
				esc_url( self::dismiss_url( $alert['order_id'] ) ),
				esc_html__( 'Ẩn', 'einvoice-matbao' )
			);
			if ( count( $items ) >= self::NOTICE_LIMIT ) {
				break;
			}
		}

		if ( array() === $items ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong></p><ul>%2$s</ul></div>',
			esc_html__( 'Có hóa đơn điện tử cần xử lý:', 'einvoice-matbao' ),
			implode( '', $items ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped above.
		);
	}

	/**
	 * Hides the alerts of an order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function dismiss( int $order_id ): void {
		foreach ( AlertStore::recent() as $key => $alert ) {
			if ( $alert['order_id'] === $order_id ) {
				AlertStore::set( $key, 'dismissed', true );
			}
		}
	}

	/**
	 * Handles the dismiss link, then returns to the previous screen.
	 */
	public static function handle_dismiss(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền thực hiện thao tác này.', 'einvoice-matbao' ), 403 );
		}
		check_admin_referer( self::DISMISS_ACTION . '_' . $order_id );

		self::dismiss( $order_id );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Dismiss link of an order.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function dismiss_url( int $order_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::DISMISS_ACTION,
					'order_id' => $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION . '_' . $order_id
		);
	}

	/**
	 * Short note when the Lark message did not go out.
	 *
	 * @param string $state Lark delivery state of the alert.
	 */
	private static function lark_note( string $state ): string {
		return match ( $state ) {
			'failed', 'queue_failed' => ' ' . __( '(Không gửi được tin Lark.)', 'einvoice-matbao' ),
			'not_configured'         => ' ' . __( '(Chưa cấu hình Lark nên chưa gửi tin.)', 'einvoice-matbao' ),
			default                  => '',
		};
	}
}
