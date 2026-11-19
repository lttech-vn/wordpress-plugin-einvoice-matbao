<?php
/**
 * Invoice block on the customer's My Account order page.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Customer;

use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use WC_DateTime;
use WC_Order;

/**
 * Shows the invoice status of the buyer's own order, with the PDF link once issued.
 */
final class OrderInvoiceSection {

	/**
	 * Hooks the section below the order table of the view-order endpoint.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( self::class, 'render' ) );
	}

	/**
	 * Prints the invoice block when the viewer owns the order and the order has an invoice.
	 *
	 * @param WC_Order $order Order shown on the page.
	 */
	public static function render( WC_Order $order ): void {
		if ( ! is_wc_endpoint_url( 'view-order' ) || ! self::viewer_owns( $order ) ) {
			return;
		}

		$record = ( new InvoiceRepository() )->find_by_order( $order->get_id() );
		if ( null === $record ) {
			return;
		}

		$status = CustomerInvoiceStatus::from_record( $record );

		echo '<section class="einvoice-matbao-customer-invoice">';
		echo '<h2>' . esc_html__( 'Hóa đơn điện tử', 'einvoice-matbao' ) . '</h2>';
		echo '<p class="einvoice-matbao-customer-invoice__status">' . esc_html( $status->label() ) . '</p>';

		if ( $status->allows_pdf() ) {
			self::render_issued( $order, $record );
		}

		echo '</section>';
	}

	/**
	 * Prints the number, series, issue date and PDF link of an issued invoice.
	 *
	 * @param WC_Order      $order  Order shown on the page.
	 * @param InvoiceRecord $record Invoice record of the order.
	 */
	private static function render_issued( WC_Order $order, InvoiceRecord $record ): void {
		$details = array();

		if ( null !== $record->invoice_number ) {
			$details[] = esc_html__( 'Số hóa đơn', 'einvoice-matbao' ) . ': ' . esc_html( (string) $record->invoice_number );
		}

		$symbol = $record->template_form . $record->template_series;
		if ( '' !== $symbol ) {
			$details[] = esc_html__( 'Ký hiệu', 'einvoice-matbao' ) . ': ' . esc_html( $symbol );
		}

		if ( null !== $record->issued_on ) {
			$details[] = esc_html__( 'Ngày lập', 'einvoice-matbao' ) . ': ' . esc_html( wc_format_datetime( new WC_DateTime( $record->issued_on ) ) );
		}

		if ( array() !== $details ) {
			echo '<p class="einvoice-matbao-customer-invoice__details">' . wp_kses_post( implode( '<br />', $details ) ) . '</p>';
		}

		echo '<a class="woocommerce-button button" href="' . esc_url( InvoicePdfDownload::url( $order ) ) . '">';
		echo esc_html__( 'Tải hóa đơn (PDF)', 'einvoice-matbao' ) . '</a>';
	}

	/**
	 * Whether the current visitor is the logged-in customer who placed the order.
	 * Guest orders (customer ID 0) never match.
	 *
	 * @param WC_Order $order Order shown on the page.
	 */
	private static function viewer_owns( WC_Order $order ): bool {
		$customer_id = $order->get_customer_id();

		return is_user_logged_in() && 0 !== $customer_id && get_current_user_id() === $customer_id;
	}
}
