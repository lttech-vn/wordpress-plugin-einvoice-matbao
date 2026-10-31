<?php
/**
 * Order edits and refunds after the invoice data was sent.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshotter;
use LTTech\EinvoiceMatbao\Settings;
use WC_Order;

/**
 * Compares an order with the state remembered when its invoice was sent (OrderState).
 *
 * After the draft exists a significant change (lines, totals, tax, fees, buyer, refund) only
 * records a warning, fires the needs-attention hook once and adds a note without personal data:
 * the draft is never updated, cancelled or created again; an accountant handles it by hand.
 * Before the draft exists and while no earlier attempt may have reached Mat Bao, an
 * administrator's buyer edit captures the snapshot again (validated first). Once an attempt may
 * have reached Mat Bao the run can adopt a draft that carries the old buyer, so the snapshot is
 * kept; the change is warned about as soon as the draft exists.
 *
 * Saves only queue the order; the comparison runs once per request at `shutdown` (flush()), so
 * several saves of one request give one warning. The state is replaced with a compare-and-swap,
 * so a change is handled once even if several requests notice it. Works for HPOS and the legacy
 * posts storage because it only uses the CRUD hooks WooCommerce fires for both.
 */
final class OrderChangeWatcher {

	/**
	 * Order IDs saved during this request.
	 *
	 * @var array<int, true>
	 */
	private static array $queued = array();

	/**
	 * Whether the hooks are registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Hooks the order saves, item saves and refunds; the queue is flushed at `shutdown`.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'woocommerce_update_order', array( self::class, 'queue' ), 10, 1 );
		add_action( 'woocommerce_saved_order_items', array( self::class, 'queue' ), 10, 1 );
		add_action( 'woocommerce_order_refunded', array( self::class, 'queue' ), 10, 1 );
		add_action( 'shutdown', array( self::class, 'flush' ), 5 );
	}

	/**
	 * Forgets queued orders and the registration (tests).
	 */
	public static function reset(): void {
		self::$queued     = array();
		self::$registered = false;
		remove_action( 'woocommerce_update_order', array( self::class, 'queue' ), 10 );
		remove_action( 'woocommerce_saved_order_items', array( self::class, 'queue' ), 10 );
		remove_action( 'woocommerce_order_refunded', array( self::class, 'queue' ), 10 );
		remove_action( 'shutdown', array( self::class, 'flush' ), 5 );
	}

	/**
	 * Queues an order whose data was saved.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function queue( int $order_id ): void {
		self::$queued[ $order_id ] = true;
	}

	/**
	 * Compares every queued order with its invoice.
	 */
	public static function flush(): void {
		$order_ids    = array_keys( self::$queued );
		self::$queued = array();
		foreach ( $order_ids as $order_id ) {
			try {
				self::check( $order_id );
			} catch ( \Throwable $failure ) {
				// A failing check must not break the save that triggered it; only the class is logged.
				error_log( 'einvoice-matbao: order change check failed (' . get_class( $failure ) . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Compares one order with its invoice and reacts to a change.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function check( int $order_id ): void {
		$repository = new InvoiceRepository();
		$invoice    = $repository->find_by_order( $order_id );
		$order      = wc_get_order( $order_id );
		if ( null === $invoice || ! $order instanceof WC_Order || InvoiceStatus::SENDING === $invoice->status ) {
			return;
		}

		$current = OrderState::encode( $order );
		if ( $current === $invoice->order_state ) {
			return;
		}
		if ( '' === $invoice->order_state ) {
			// Nothing to compare with (row from before this check existed): remember it silently.
			$repository->replace_order_state( $invoice->id, '', $current );
			return;
		}

		if ( InvoiceStatus::DRAFT_CREATED === $invoice->status ) {
			self::warn( $repository, $invoice, $order, $current );
			return;
		}

		self::recapture_buyer( $repository, $invoice, $order, $current );
	}

	/**
	 * Checks an order right after its draft was created or adopted: changes made while the create
	 * call was in flight, or since the earlier attempt that an adopted draft came from, are warned about.
	 *
	 * @param InvoiceRecord $invoice Invoice, now `draft_created`.
	 */
	public static function check_after_draft( InvoiceRecord $invoice ): void {
		$order = wc_get_order( $invoice->order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$current = OrderState::encode( $order );
		if ( '' !== $invoice->order_state && $current !== $invoice->order_state ) {
			self::warn( new InvoiceRepository(), $invoice, $order, $current );
		}
	}

	/**
	 * Warns about the most significant difference: refund, then amounts, then buyer.
	 *
	 * @param InvoiceRepository $repository Storage.
	 * @param InvoiceRecord     $invoice    Invoice with the state as read.
	 * @param WC_Order          $order      Order.
	 * @param string            $current    State of the order now.
	 */
	private static function warn( InvoiceRepository $repository, InvoiceRecord $invoice, WC_Order $order, string $current ): void {
		$differences = OrderState::differences( $invoice->order_state, $current );
		if ( $differences['refunded'] ) {
			$code   = InvoiceErrorCode::ORDER_REFUNDED_AFTER_DRAFT;
			$reason = __( 'Đơn hàng đã hoàn tiền sau khi có hóa đơn nháp. Hóa đơn nháp không tự thay đổi hay bị hủy; kế toán xử lý trên Mắt Bão.', 'einvoice-matbao' );
		} elseif ( $differences['amounts'] ) {
			$code   = InvoiceErrorCode::ORDER_AMOUNTS_CHANGED;
			$reason = __( 'Dòng hàng, tổng tiền, thuế hoặc phí của đơn hàng đã đổi sau khi có hóa đơn nháp. Hóa đơn nháp không tự thay đổi; kế toán xử lý trên Mắt Bão.', 'einvoice-matbao' );
		} elseif ( $differences['buyer'] ) {
			$code   = InvoiceErrorCode::ORDER_BUYER_CHANGED;
			$reason = __( 'Thông tin người mua của đơn hàng đã đổi so với hóa đơn nháp. Hóa đơn nháp không tự thay đổi; kế toán xử lý trên Mắt Bão.', 'einvoice-matbao' );
		} else {
			$repository->replace_order_state( $invoice->id, $invoice->order_state, $current );
			return;
		}

		if ( ! $repository->replace_order_state( $invoice->id, $invoice->order_state, $current, $code, $reason ) ) {
			return;
		}

		$order->add_order_note( __( 'Đơn hàng đã đổi sau khi có hóa đơn nháp: hệ thống chỉ cảnh báo, không tự cập nhật hay hủy hóa đơn.', 'einvoice-matbao' ) );
		$updated = $repository->find_by_order( $invoice->order_id );
		if ( null !== $updated ) {
			self::notify( $updated, $code );
		}
	}

	/**
	 * Before the draft exists: an administrator's buyer edit captures the snapshot again when no
	 * earlier attempt may have reached Mat Bao. A buyer that no longer validates keeps the old
	 * snapshot, stops the pending send and alerts. Any other change only moves the remembered state.
	 *
	 * @param InvoiceRepository $repository Storage.
	 * @param InvoiceRecord     $invoice    Invoice with the state as read.
	 * @param WC_Order          $order      Order.
	 * @param string            $current    State of the order now.
	 */
	private static function recapture_buyer( InvoiceRepository $repository, InvoiceRecord $invoice, WC_Order $order, string $current ): void {
		$buyer_changed = OrderState::differences( $invoice->order_state, $current )['buyer'];
		if ( ! $buyer_changed || ! current_user_can( 'manage_options' ) || $repository->may_have_been_created( $invoice ) ) {
			if ( ! $buyer_changed ) {
				$repository->replace_order_state( $invoice->id, $invoice->order_state, $current );
			}
			return;
		}
		if ( ! $repository->replace_order_state( $invoice->id, $invoice->order_state, $current ) ) {
			return;
		}

		$snapshotter = new BuyerSnapshotter();
		$errors      = $snapshotter->validate( $order );
		if ( array() === $errors ) {
			$snapshotter->capture( $order, ( new Settings() )->walk_in_buyer_name() );
			return;
		}
		if ( InvoiceStatus::PENDING !== $invoice->status ) {
			return;
		}

		$code = InvoiceErrorCode::BUYER_INVALID;
		$repository->record_error( $invoice->id, $code, DraftInvoiceWorkflow::invalid_buyer_reason( array_keys( $errors ) ) );
		$updated = $repository->find_by_order( $invoice->order_id );
		if ( null !== $updated ) {
			self::notify( $updated, $code );
		}
	}

	/**
	 * Fires the needs-attention hook; a failing listener must not break the save.
	 *
	 * @param InvoiceRecord    $invoice Updated invoice.
	 * @param InvoiceErrorCode $code    The warning or error.
	 */
	private static function notify( InvoiceRecord $invoice, InvoiceErrorCode $code ): void {
		try {
			do_action( 'einvoice_matbao_invoice_needs_attention', $invoice, $code );
		} catch ( \Throwable $listener_error ) {
			error_log( 'einvoice-matbao: needs_attention listener failed (' . get_class( $listener_error ) . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
