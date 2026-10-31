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
 * Every reason a draft is not created is recorded as an InvoiceErrorCode (code, kind, status and
 * Vietnamese reason) and announced through NEEDS_ATTENTION_ACTION. Failures are not retried here
 * (background retry of InvoiceErrorKind::MATBAO errors and recovery of rows left in `sending` are
 * ticket #6).
 */
final class DraftInvoiceWorkflow {

	public const ACTION = 'einvoice_matbao_create_draft';
	public const GROUP  = 'einvoice-matbao';

	/**
	 * Fired once each time an invoice records an error an admin has to act on
	 * (InvoiceErrorCode::needs_attention()), with the updated InvoiceRecord and the
	 * InvoiceErrorCode. Admin and Lark alerts (#7) hook here.
	 */
	public const NEEDS_ATTENTION_ACTION = 'einvoice_matbao_invoice_needs_attention';

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
			$code = InvoiceErrorCode::REFERENCE_TOO_LONG;
			if ( $repository->reserve( $order_id, substr( $reference, 0, Settings::MAX_REFERENCE_LENGTH ), $buyer_name, $code->kind()->status() ) ) {
				/* translators: %s: reference (MTChieu). */
				self::record_error( $repository, $order_id, $code, sprintf( __( 'Mã tham chiếu %s dài hơn 20 ký tự.', 'einvoice-matbao' ), $reference ) );
			}
			return;
		}

		if ( (float) $order->get_total() <= 0 ) {
			$repository->reserve( $order_id, $reference, $buyer_name, InvoiceStatus::SKIPPED, __( 'Đơn hàng 0 đồng: không tạo hóa đơn nháp, cần admin kiểm tra.', 'einvoice-matbao' ) );
			return;
		}

		if ( $repository->reserve( $order_id, $reference, $buyer_name, InvoiceStatus::PENDING ) ) {
			$action_id = as_enqueue_async_action( self::ACTION, array( $order_id ), self::GROUP );
			if ( 0 === $action_id ) {
				self::record_error( $repository, $order_id, InvoiceErrorCode::QUEUE_FAILED, __( 'Không xếp được tác vụ tạo hóa đơn nháp vào hàng đợi.', 'einvoice-matbao' ) );
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
			self::record_error( $this->repository, $order_id, InvoiceErrorCode::UNEXPECTED, __( 'Lỗi không xác định khi gửi hóa đơn nháp; cần admin kiểm tra.', 'einvoice-matbao' ) );
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
			self::record_error( $this->repository, $order_id, InvoiceErrorCode::ORDER_NOT_COMPLETED, __( 'Đơn hàng không còn ở trạng thái hoàn thành khi gửi.', 'einvoice-matbao' ) );
			return;
		}
		if ( (float) $order->get_total_refunded() > 0 ) {
			self::record_error( $this->repository, $order_id, InvoiceErrorCode::ORDER_REFUNDED, __( 'Đơn hàng đã hoàn tiền trước khi gửi.', 'einvoice-matbao' ) );
			return;
		}

		$credentials = $this->store->matbao();
		if ( null === $credentials ) {
			self::record_error( $this->repository, $order_id, InvoiceErrorCode::CREDENTIALS_MISSING, __( 'Chưa cấu hình kết nối Mắt Bão.', 'einvoice-matbao' ) );
			return;
		}
		$template = $this->settings->default_template();
		if ( null === $template ) {
			self::record_error( $this->repository, $order_id, InvoiceErrorCode::TEMPLATE_MISSING, __( 'Chưa chọn mẫu hóa đơn mặc định.', 'einvoice-matbao' ) );
			return;
		}

		$issued_on = new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Ho_Chi_Minh' ) );
		try {
			$payload = $this->builder->build( $order, $invoice->reference, $invoice->buyer_name, $template, $issued_on );
		} catch ( InvoiceDataError $error ) {
			self::record_error( $this->repository, $order_id, $error->error_code, $error->getMessage() );
			return;
		}

		$client = new MatbaoClient( $credentials );
		try {
			$template_available = $this->template_available( $client, $template, $issued_on );
		} catch ( MatbaoError $error ) {
			self::record_error( $this->repository, $order_id, InvoiceErrorCode::from_matbao( $error ), $error->getMessage() );
			return;
		}
		if ( ! $template_available ) {
			self::record_error(
				$this->repository,
				$order_id,
				InvoiceErrorCode::TEMPLATE_UNAVAILABLE,
				sprintf(
					/* translators: 1: template form number (KHMSHDon), 2: template series (KHHDon), 3: year. */
					__( 'Mẫu hóa đơn %1$s / %2$s không còn trong danh sách mẫu năm %3$s của Mắt Bão hoặc đã hết số; chọn lại mẫu mặc định.', 'einvoice-matbao' ),
					$template['form'],
					$template['series'],
					$issued_on->format( 'Y' )
				)
			);
			return;
		}

		$attempt = $this->repository->start_attempt( $invoice->id );
		try {
			$invoice_id = $client->create_invoice( $payload );
		} catch ( MatbaoError $error ) {
			$this->repository->finish_attempt( $attempt, 'failed', $error->kind, $error->http_status, $error->api_code, $error->getMessage() );
			$code   = InvoiceErrorCode::from_matbao( $error );
			$reason = InvoiceErrorCode::TEMPLATE_REJECTED === $code
				/* translators: 1: Mat Bao message, 2: template form number (KHMSHDon), 3: template series (KHHDon). */
				? sprintf( __( 'Mắt Bão từ chối mẫu hóa đơn %2$s / %3$s (%1$s); chọn lại mẫu mặc định.', 'einvoice-matbao' ), $error->getMessage(), $template['form'], $template['series'] )
				: $error->getMessage();
			self::record_error( $this->repository, $order_id, $code, $reason );
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

	/**
	 * Whether Mat Bao still offers the default template for the invoice year with numbers left.
	 * A template of last year (series C25...) is no longer offered once the year changes.
	 *
	 * @param MatbaoClient                        $client    Client.
	 * @param array{form: string, series: string} $template  Default template.
	 * @param \DateTimeImmutable                  $issued_on `NLap`, Vietnam time.
	 * @throws MatbaoError When the templates call fails.
	 */
	private function template_available( MatbaoClient $client, array $template, \DateTimeImmutable $issued_on ): bool {
		foreach ( $client->templates( (int) $issued_on->format( 'Y' ) ) as $offered ) {
			if ( $offered->is_vat() && $offered->form === $template['form'] && $offered->series === $template['series'] ) {
				return $offered->has_remaining_numbers();
			}
		}

		return false;
	}

	/**
	 * Records an error on the invoice of an order and fires NEEDS_ATTENTION_ACTION when an admin
	 * has to act.
	 *
	 * @param InvoiceRepository $repository Invoice storage.
	 * @param int               $order_id   Order ID.
	 * @param InvoiceErrorCode  $code       Error code.
	 * @param string            $reason     Vietnamese reason.
	 */
	private static function record_error( InvoiceRepository $repository, int $order_id, InvoiceErrorCode $code, string $reason ): void {
		$invoice = $repository->find_by_order( $order_id );
		if ( null === $invoice ) {
			return;
		}

		$repository->record_error( $invoice->id, $code, $reason );
		$invoice = $repository->find_by_order( $order_id );
		if ( null !== $invoice && $code->needs_attention() ) {
			do_action( 'einvoice_matbao_invoice_needs_attention', $invoice, $code );
		}
	}
}
