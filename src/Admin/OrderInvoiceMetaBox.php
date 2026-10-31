<?php
/**
 * Invoice box on the order edit screen.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use WC_Order;
use WP_Post;

/**
 * Shows the invoice status, `MTChieu`, Mat Bao invoice ID and the last error (kind, label and
 * reason) of an order.
 * Works on both the HPOS and the legacy (posts) order screens. Read-only: there is no create or
 * resend button in this ticket, and skipped 0 VND orders never get one.
 */
final class OrderInvoiceMetaBox {

	public const ID = 'einvoice-matbao-invoice';

	/**
	 * Hooks the meta box.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
	}

	/**
	 * Adds the box to the order screen in use (HPOS or posts).
	 */
	public static function add_meta_box(): void {
		add_meta_box(
			self::ID,
			__( 'Hóa đơn Mắt Bão', 'einvoice-matbao' ),
			array( self::class, 'render' ),
			OrderUtil::get_order_admin_screen(),
			'side',
			'default'
		);
	}

	/**
	 * Renders the box. WooCommerce passes the order on HPOS screens and the post otherwise.
	 *
	 * @param WC_Order|WP_Post|mixed $order_or_post Order or post.
	 */
	public static function render( mixed $order_or_post ): void {
		$order = $order_or_post instanceof WP_Post ? wc_get_order( $order_or_post->ID ) : $order_or_post;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$invoice = ( new InvoiceRepository() )->find_by_order( $order->get_id() );
		if ( null === $invoice ) {
			echo '<p data-einv-invoice-status="none">' . esc_html__( 'Chưa có hóa đơn. Hóa đơn nháp được tạo khi đơn chuyển sang Hoàn thành.', 'einvoice-matbao' ) . '</p>';
			return;
		}

		printf(
			'<p data-einv-invoice-status="%1$s"><strong>%2$s</strong> %3$s</p>',
			esc_attr( $invoice->status ),
			esc_html__( 'Trạng thái:', 'einvoice-matbao' ),
			esc_html( InvoiceStatus::label( $invoice->status ) )
		);
		printf(
			'<p>%1$s <code>%2$s</code></p>',
			esc_html__( 'Mã tham chiếu (MTChieu):', 'einvoice-matbao' ),
			esc_html( $invoice->reference )
		);
		if ( null !== $invoice->matbao_invoice_id ) {
			printf(
				'<p>%1$s <code style="word-break:break-all">%2$s</code></p>',
				esc_html__( 'Mã hóa đơn Mắt Bão:', 'einvoice-matbao' ),
				esc_html( $invoice->matbao_invoice_id )
			);
		}
		if ( null !== $invoice->issued_on ) {
			printf(
				'<p>%1$s %2$s %3$s</p>',
				esc_html__( 'Mẫu/ký hiệu:', 'einvoice-matbao' ),
				esc_html( $invoice->template_form . '/' . $invoice->template_series ),
				/* translators: %s: invoice date. */
				esc_html( sprintf( __( '– ngày lập %s', 'einvoice-matbao' ), $invoice->issued_on ) )
			);
		}
		$error = $invoice->error();
		if ( null !== $error ) {
			printf(
				'<p data-einv-error-code="%1$s" data-einv-error-kind="%2$s"><strong>%3$s</strong> %4$s – %5$s</p>',
				esc_attr( $error->value ),
				esc_attr( $error->kind()->value ),
				esc_html__( 'Loại lỗi:', 'einvoice-matbao' ),
				esc_html( $error->kind()->label() ),
				esc_html( $error->label() )
			);
		}
		if ( '' !== $invoice->status_reason ) {
			printf( '<p class="einv-invoice-reason">%s</p>', esc_html( $invoice->status_reason ) );
		}
	}
}
