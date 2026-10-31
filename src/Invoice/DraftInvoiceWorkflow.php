<?php
/**
 * Completed order to Mat Bao draft invoice.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Matbao\MatbaoClient;
use LTTech\EinvoiceMatbao\Matbao\MatbaoError;
use LTTech\EinvoiceMatbao\Settings;
use WC_Order;

/**
 * When an order moves to `completed`, reserves its single invoice row (fixed `MTChieu`) and
 * queues one Action Scheduler action that sends the draft. The action claims the row
 * (`pending` → `sending`) before calling Mat Bao, so a duplicate run cannot send twice.
 *
 * Only orders completing while the plugin is active are handled; older orders are never scanned.
 * Failures are recorded once and not retried here (background retry and recovery of rows left
 * in `sending` are ticket #6).
 */
final class DraftInvoiceWorkflow {

	public const ACTION = 'einvoice_matbao_create_draft';
	public const GROUP  = 'einvoice-matbao';

	/**
	 * Hooks the order status change and the queued action.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_status_completed', array( self::class, 'on_order_completed' ), 10, 2 );
		add_action( self::ACTION, array( self::class, 'run' ) );
	}

	/**
	 * Reserves the invoice of a newly completed order and queues the draft action.
	 * A second completion (or a concurrent hook) finds the row already reserved and does nothing.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order, when WooCommerce passes it.
	 */
	public static function on_order_completed( int $order_id, ?WC_Order $order = null ): void {
		$order ??= wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$repository = new InvoiceRepository();
		if ( null !== $repository->find_by_order( $order_id ) ) {
			return;
		}

		$settings   = new Settings();
		$reference  = $settings->reference_prefix() . Settings::REFERENCE_MARKER . $order_id;
		$buyer_name = $settings->walk_in_buyer_name();

		if ( strlen( $reference ) > Settings::MAX_REFERENCE_LENGTH ) {
			$repository->reserve(
				$order_id,
				substr( $reference, 0, Settings::MAX_REFERENCE_LENGTH ),
				$buyer_name,
				InvoiceStatus::FAILED,
				/* translators: %s: reference (MTChieu). */
				sprintf( __( 'Mã tham chiếu %s dài hơn 20 ký tự.', 'einvoice-matbao' ), $reference )
			);
			return;
		}

		if ( (float) $order->get_total() <= 0 ) {
			$repository->reserve( $order_id, $reference, $buyer_name, InvoiceStatus::SKIPPED, __( 'Đơn hàng 0 đồng: không tạo hóa đơn nháp, cần admin kiểm tra.', 'einvoice-matbao' ) );
			return;
		}

		if ( $repository->reserve( $order_id, $reference, $buyer_name, InvoiceStatus::PENDING ) ) {
			$action_id = as_enqueue_async_action( self::ACTION, array( $order_id ), self::GROUP );
			if ( 0 === $action_id ) {
				$invoice = ( new InvoiceRepository() )->find_by_order( $order_id );
				if ( null !== $invoice ) {
					$repository->update_status( $invoice->id, InvoiceStatus::FAILED, __( 'Không xếp được tác vụ tạo hóa đơn nháp vào hàng đợi.', 'einvoice-matbao' ) );
				}
			}
		}
	}

	/**
	 * Sends the draft of one order. Hooked to the queued action.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function run( int $order_id ): void {
		( new self() )->send( $order_id );
	}

	/**
	 * Creates the services.
	 *
	 * @param InvoiceRepository   $repository Invoice storage.
	 * @param Settings            $settings   Invoicing settings.
	 * @param CredentialStore     $store      Mat Bao credentials.
	 * @param DraftPayloadBuilder $builder    Payload builder.
	 */
	public function __construct(
		private readonly InvoiceRepository $repository = new InvoiceRepository(),
		private readonly Settings $settings = new Settings(),
		private readonly CredentialStore $store = new CredentialStore(),
		private readonly DraftPayloadBuilder $builder = new DraftPayloadBuilder(),
	) {
	}

	/**
	 * Claims the pending invoice of an order and sends it once.
	 *
	 * @param int $order_id Order ID.
	 */
	public function send( int $order_id ): void {
		$invoice = $this->repository->find_by_order( $order_id );
		if ( null === $invoice || ! $this->repository->claim( $invoice->id, InvoiceStatus::PENDING, InvoiceStatus::SENDING ) ) {
			return;
		}

		try {
			$this->send_claimed( $invoice, $order_id );
		} catch ( \Throwable ) {
			// Details may carry URLs or tokens, so the stored reason stays generic.
			$this->repository->update_status( $invoice->id, InvoiceStatus::FAILED, __( 'Lỗi không xác định khi gửi hóa đơn nháp; cần admin kiểm tra.', 'einvoice-matbao' ) );
		}
	}

	/**
	 * Sends an invoice this run has already claimed.
	 *
	 * @param InvoiceRecord $invoice  Claimed invoice.
	 * @param int           $order_id Order ID.
	 */
	private function send_claimed( InvoiceRecord $invoice, int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'completed' !== $order->get_status() ) {
			$this->repository->update_status( $invoice->id, InvoiceStatus::STOPPED, __( 'Đơn hàng không còn ở trạng thái hoàn thành khi gửi.', 'einvoice-matbao' ) );
			return;
		}
		if ( (float) $order->get_total_refunded() > 0 ) {
			$this->repository->update_status( $invoice->id, InvoiceStatus::STOPPED, __( 'Đơn hàng đã hoàn tiền trước khi gửi.', 'einvoice-matbao' ) );
			return;
		}

		$credentials = $this->store->matbao();
		$template    = $this->settings->default_template();
		if ( null === $credentials || null === $template ) {
			$this->repository->update_status( $invoice->id, InvoiceStatus::NOT_CONFIGURED, __( 'Chưa cấu hình kết nối Mắt Bão hoặc mẫu hóa đơn mặc định.', 'einvoice-matbao' ) );
			return;
		}

		$issued_on = new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Ho_Chi_Minh' ) );
		try {
			$payload = $this->builder->build( $order, $invoice->reference, $invoice->buyer_name, $template, $issued_on );
		} catch ( InvoiceDataError $error ) {
			$this->repository->update_status( $invoice->id, InvoiceStatus::FAILED, $error->getMessage() );
			return;
		}

		$attempt = $this->repository->start_attempt( $invoice->id );
		try {
			$invoice_id = ( new MatbaoClient( $credentials ) )->create_invoice( $payload );
		} catch ( MatbaoError $error ) {
			$this->repository->finish_attempt( $attempt, 'failed', $error->kind, $error->http_status, $error->api_code, $error->getMessage() );
			$this->repository->update_status( $invoice->id, InvoiceStatus::FAILED, $error->getMessage() );
			return;
		}

		$this->repository->finish_attempt( $attempt, 'success' );
		$this->repository->update_status(
			$invoice->id,
			InvoiceStatus::DRAFT_CREATED,
			'',
			array(
				'matbao_invoice_id' => $invoice_id,
				'issued_on'         => $issued_on->format( 'Y-m-d' ),
				'template_form'     => $template['form'],
				'template_series'   => $template['series'],
			)
		);
	}
}
