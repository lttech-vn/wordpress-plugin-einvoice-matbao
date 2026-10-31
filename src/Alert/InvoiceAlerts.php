<?php
/**
 * Alerts for invoices that need an admin: records one per (order, error) and tells Lark.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Alert;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Lark\LarkClient;

/**
 * Only listens to the workflow's hooks; it never changes an invoice or queues a draft action.
 * The hook listener just records the alert and queues an Action Scheduler action, so the invoice
 * run never waits for Lark; the queued action calls Lark and swallows every failure.
 */
final class InvoiceAlerts {

	public const SEND_ACTION = 'einvoice_matbao_send_alert';

	/**
	 * Hooks the workflow's alert hooks and the queued Lark action.
	 */
	public static function register(): void {
		add_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION, array( self::class, 'on_needs_attention' ), 10, 2 );
		add_action( DraftInvoiceWorkflow::EXHAUSTED_HOOK, array( self::class, 'on_retries_exhausted' ), 10, 1 );
		add_action( self::SEND_ACTION, array( self::class, 'send' ), 10, 1 );
		add_action( 'admin_notices', array( AdminAlertNotice::class, 'render' ) );
		add_action( 'admin_post_' . AdminAlertNotice::DISMISS_ACTION, array( AdminAlertNotice::class, 'handle_dismiss' ) );
	}

	/**
	 * Alerts for an invoice that recorded an error an admin has to act on.
	 *
	 * @param InvoiceRecord    $invoice Updated invoice.
	 * @param InvoiceErrorCode $code    The error.
	 */
	public static function on_needs_attention( InvoiceRecord $invoice, InvoiceErrorCode $code ): void {
		if ( ! $code->needs_attention() || InvoiceStatus::PENDING === $invoice->status ) {
			return;
		}

		$key = AlertStore::key( $invoice->order_id, $code->value, $invoice->retry_cycle_start );
		if ( ! AlertStore::create( $key, $invoice->order_id, $code->value, $invoice->retry_cycle_start ) ) {
			return;
		}

		if ( 0 === as_enqueue_async_action( self::SEND_ACTION, array( $key ), DraftInvoiceWorkflow::GROUP ) ) {
			AlertStore::set( $key, 'lark', 'queue_failed' );
		}
	}

	/**
	 * Same alert as on_needs_attention(): the workflow fires both hooks, the store dedupes.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_retries_exhausted( int $order_id ): void {
		$invoice = ( new InvoiceRepository() )->find_by_order( $order_id );
		$code    = $invoice?->error();
		if ( null !== $invoice && null !== $code ) {
			self::on_needs_attention( $invoice, $code );
		}
	}

	/**
	 * Sends the Lark message of an alert. Never throws and never touches the invoice.
	 *
	 * @param string $key Alert key.
	 */
	public static function send( string $key ): void {
		$alert = AlertStore::get( $key );
		if ( null === $alert || 'sent' === $alert['lark'] ) {
			return;
		}

		try {
			$webhook = ( new CredentialStore() )->lark();
			if ( null === $webhook ) {
				AlertStore::set( $key, 'lark', 'not_configured' );
				return;
			}

			( new LarkClient( $webhook ) )->send_text( self::message( $alert['order_id'], $alert['code'] ) );
			AlertStore::set( $key, 'lark', 'sent' );
		} catch ( \Throwable $failure ) {
			AlertStore::set( $key, 'lark', 'failed' );
			error_log( 'einvoice-matbao: Lark alert failed (' . get_class( $failure ) . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Lark text: order number, alert type and admin link. Nothing else.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $error_code InvoiceErrorCode value.
	 */
	private static function message( int $order_id, string $error_code ): string {
		$code  = InvoiceErrorCode::tryFrom( $error_code );
		$order = wc_get_order( $order_id );

		return sprintf(
			/* translators: 1: order number, 2: alert type label, 3: error code, 4: admin link to the order. */
			__( 'Hóa đơn cần xử lý – Đơn #%1$d – %2$s (%3$s) – %4$s', 'einvoice-matbao' ),
			$order_id,
			null === $code ? $error_code : $code->label(),
			$error_code,
			$order instanceof \WC_Order ? $order->get_edit_order_url() : ''
		);
	}
}
