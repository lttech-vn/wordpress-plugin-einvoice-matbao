<?php
/**
 * Link that downloads the PDF of the buyer's own invoice.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Customer;

use WC_Order;

/**
 * Builds the download URL on the My Account order page. The request handler lives with it.
 */
final class InvoicePdfDownload {

	public const QUERY_ARG           = 'einvoice-matbao-pdf';
	public const NONCE_ACTION_PREFIX = 'einvoice_matbao_invoice_pdf_';

	/**
	 * Download URL of an order's invoice PDF, on its My Account order page.
	 *
	 * @param WC_Order $order Order of the buyer.
	 */
	public static function url( WC_Order $order ): string {
		$order_id = $order->get_id();
		$base_url = wc_get_endpoint_url( 'view-order', (string) $order_id, wc_get_page_permalink( 'myaccount' ) );

		return add_query_arg(
			array(
				self::QUERY_ARG => '1',
				'_wpnonce'      => wp_create_nonce( self::NONCE_ACTION_PREFIX . $order_id ),
			),
			$base_url
		);
	}
}
