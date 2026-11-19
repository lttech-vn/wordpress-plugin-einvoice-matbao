<?php
/**
 * Mat Bao status markup shared by the invoice list, detail and the order meta box.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\MatbaoStatus;
use LTTech\EinvoiceMatbao\Invoice\Redactor;
use WC_Order;

/**
 * Renders what was last read from Mat Bao. Every stored text goes through Redactor.
 */
final class MatbaoStatusView {

	/**
	 * Badge group to the plugin status group whose colours it shares.
	 *
	 * @var array<string, string>
	 */
	private const COLOR_GROUP = array(
		'issued'   => InvoiceStatus::GROUP_CREATED,
		'error'    => InvoiceStatus::GROUP_ERROR,
		'progress' => InvoiceStatus::GROUP_WAITING,
	);

	/**
	 * Badge of the Mat Bao status, or a dash before the first lookup.
	 *
	 * @param InvoiceRecord $invoice Invoice.
	 * @return string Escaped HTML.
	 */
	public static function badge( InvoiceRecord $invoice ): string {
		$label = self::label( $invoice );
		if ( '' === $label ) {
			return '—';
		}
		$group = $invoice->matbao_status()?->group() ?? 'progress';

		return sprintf(
			'<span class="%1$s %2$s" data-einv-matbao-badge="%3$s">%4$s</span>',
			esc_attr( InvoicePage::BADGE ),
			esc_attr( InvoicePage::BADGE_COLORS[ self::COLOR_GROUP[ $group ] ] ),
			esc_attr( $group ),
			esc_html( $label )
		);
	}

	/**
	 * Mat Bao rows as label => plain text (escape at render); empty values are left out.
	 *
	 * @param InvoiceRecord $invoice Invoice.
	 * @return array<string, string>
	 */
	public static function rows( InvoiceRecord $invoice ): array {
		$rows = array(
			self::row_labels()['status']                 => self::label( $invoice ),
			self::row_labels()['number']                 => null === $invoice->invoice_number ? '' : (string) $invoice->invoice_number,
			self::row_labels()['authority']              => $invoice->tax_authority_code ?? '',
			__( 'Loại hóa đơn', 'einvoice-matbao' )      => MatbaoStatus::invoice_type_label( $invoice->matbao_invoice_type ),
			__( 'Ghi chú CQT', 'einvoice-matbao' )       => Redactor::redact( $invoice->tax_note ),
			/* translators: UTC time of the last Mat Bao lookup. */
			__( 'Đồng bộ lúc (UTC)', 'einvoice-matbao' ) => $invoice->matbao_synced_at ?? '',
		);

		return array_filter( $rows, static fn( string $value ): bool => '' !== $value );
	}

	/**
	 * The Mat Bao lines of the order meta box: status, invoice number and MCCQT.
	 *
	 * @param InvoiceRecord $invoice Invoice.
	 * @return string Escaped HTML.
	 */
	public static function meta_box_rows( InvoiceRecord $invoice ): string {
		$rows = self::rows( $invoice );
		$html = '';
		foreach ( array( 'status', 'number', 'authority' ) as $key ) {
			$label = self::row_labels()[ $key ];
			if ( isset( $rows[ $label ] ) ) {
				$html .= sprintf( '<p data-einv-matbao-row>%1$s: <strong>%2$s</strong></p>', esc_html( $label ), esc_html( $rows[ $label ] ) );
			}
		}

		return $html;
	}

	/**
	 * Row labels by key, shared by rows() and meta_box_rows().
	 *
	 * @return array<string, string>
	 */
	private static function row_labels(): array {
		return array(
			'status'    => __( 'Trạng thái Mắt Bão', 'einvoice-matbao' ),
			'number'    => __( 'Số hóa đơn', 'einvoice-matbao' ),
			'authority' => 'MCCQT',
		);
	}

	/**
	 * Label of the stored status: the enum label, or the stored name for a code outside the enum.
	 *
	 * @param InvoiceRecord $invoice Invoice.
	 */
	private static function label( InvoiceRecord $invoice ): string {
		if ( null === $invoice->matbao_status ) {
			return '';
		}
		$status = $invoice->matbao_status();
		if ( null !== $status ) {
			return $status->label();
		}

		return Redactor::redact( '' !== $invoice->matbao_status_name ? $invoice->matbao_status_name : (string) $invoice->matbao_status );
	}

	/**
	 * Link to the order edit screen, or plain text when the order no longer exists.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $prefix   Plain text put before `#N` inside the link or text.
	 * @return string Escaped HTML.
	 */
	public static function order_link( int $order_id, string $prefix = '' ): string {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return esc_html( $prefix . '#' . $order_id );
		}

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $order->get_edit_order_url() ), esc_html( $prefix . '#' . $order_id ) );
	}
}
