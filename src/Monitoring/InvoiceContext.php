<?php
/**
 * Invoice context block of monitoring events.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;

/**
 * Builds the `invoice` context an administrator needs to find and fix an invoice.
 */
final class InvoiceContext {

	/**
	 * Query parameters that identify an admin screen; every other one (nonces, filters) is dropped.
	 */
	private const ADMIN_QUERY_KEYS = array( 'page', 'action', 'id', 'post' );

	/**
	 * Context of an invoice.
	 *
	 * @param InvoiceRecord $invoice Invoice.
	 * @return array{order_id:int,invoice_id:int,reference:string,status:string,error_code:string,label:string,hint:string,status_reason:string,admin_path:string}
	 */
	public static function for_invoice( InvoiceRecord $invoice ): array {
		$code  = InvoiceErrorCode::tryFrom( $invoice->error_code );
		$order = wc_get_order( $invoice->order_id );

		return array(
			'order_id'      => $invoice->order_id,
			'invoice_id'    => $invoice->id,
			'reference'     => $invoice->reference,
			'status'        => $invoice->status,
			'error_code'    => $invoice->error_code,
			'label'         => null === $code ? '' : $code->label(),
			'hint'          => null === $code ? '' : $code->hint(),
			'status_reason' => $invoice->status_reason,
			'admin_path'    => $order instanceof \WC_Order ? self::admin_path( $order->get_edit_order_url() ) : '',
		);
	}

	/**
	 * Reduces an admin URL to its path and the query parameters that identify the screen, so
	 * neither the host nor a nonce reaches the monitoring server.
	 *
	 * @param string $url Admin URL.
	 * @return string Empty when the URL cannot be parsed.
	 */
	public static function admin_path( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['path'] ) ) {
			return '';
		}

		$query = array();
		wp_parse_str( $parts['query'] ?? '', $query );
		$kept = array();
		foreach ( $query as $key => $value ) {
			if ( in_array( $key, self::ADMIN_QUERY_KEYS, true ) && is_scalar( $value ) ) {
				$kept[ $key ] = (string) $value;
			}
		}

		return $parts['path'] . ( array() === $kept ? '' : '?' . http_build_query( $kept, '', '&' ) );
	}

	/**
	 * Context of the invoice of an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, array<string, mixed>> Empty when the order has no invoice.
	 */
	public static function for_order( int $order_id ): array {
		$invoice = ( new InvoiceRepository() )->find_by_order( $order_id );

		return null === $invoice ? array() : array( 'invoice' => self::for_invoice( $invoice ) );
	}
}
