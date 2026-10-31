<?php
/**
 * Completed order to Mat Bao draft invoice.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Matbao\MatbaoClient;
use LTTech\EinvoiceMatbao\Matbao\MatbaoCredentials;
use LTTech\EinvoiceMatbao\Matbao\MatbaoError;
use LTTech\EinvoiceMatbao\Matbao\MatbaoInvoice;
use LTTech\EinvoiceMatbao\Matbao\TemplateCache;
use LTTech\EinvoiceMatbao\Settings;
use WC_Order;

/**
 * When an order moves to `completed`, reserves its single invoice row (fixed `MTChieu`) and
 * queues one Action Scheduler action that sends the draft. The action claims the row
 * (`pending` → `sending`) before calling Mat Bao, so a duplicate run cannot send twice.
 *
 * Temporary failures (no response, 5xx/408/429, duplicate reference 305) put the row back to
 * `pending` and queue the same action again after 1 min, 5 min, 15 min, 1 h and 6 h; after the
 * fifth retry the row becomes `needs_attention` and the `einvoice_matbao_retries_exhausted` action
 * fires. Whenever an earlier create call may have reached Mat Bao, the next run first looks the
 * invoice up by `MTChieu` and adopts an existing draft instead of creating another (ADR 0001).
 * Rows stuck in `sending` (the runner died after the claim) or in `pending` without a queued
 * action are put through the same lookup by a recurring sweep.
 *
 * Only orders completing while the plugin is active are handled; older orders are never scanned.
 * Every reason a draft is not created is recorded as an InvoiceErrorCode (code, kind, status and
 * Vietnamese reason). NEEDS_ATTENTION_ACTION fires only when an admin has to act: an error that is
 * not retried, or the retries are used up (then EXHAUSTED_HOOK fires too). A temporary error
 * that will be retried keeps the row `pending` with its code and fires nothing.
 */
final class DraftInvoiceWorkflow {

	public const ACTION = 'einvoice_matbao_create_draft';
	public const GROUP  = 'einvoice-matbao';

	/** Recurring sweep that recovers stuck rows. */
	public const RECOVERY_ACTION = 'einvoice_matbao_recover_stuck_invoices';

	/** Fired once with `( int $order_id, string $reference )` when the retries are used up. */
	public const EXHAUSTED_HOOK = 'einvoice_matbao_retries_exhausted';

	/** Seconds to wait before retry 1..5. */
	public const BACKOFF_SECONDS = array( 60, 300, 900, 3600, 21600 );

	/** A claim or reservation older than this with no live action is considered stuck. */
	private const STUCK_AFTER_SECONDS = 15 * MINUTE_IN_SECONDS;

	private const SWEEP_INTERVAL_SECONDS = 15 * MINUTE_IN_SECONDS;

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
		add_action( self::RECOVERY_ACTION, array( self::class, 'sweep' ) );
		add_action( 'action_scheduler_init', array( self::class, 'schedule_recovery' ) );
	}

	/**
	 * Queues the recurring recovery sweep unless it is already queued.
	 */
	public static function schedule_recovery(): void {
		if ( ! as_has_scheduled_action( self::RECOVERY_ACTION, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + self::SWEEP_INTERVAL_SECONDS, self::SWEEP_INTERVAL_SECONDS, self::RECOVERY_ACTION, array(), self::GROUP );
		}
	}

	/**
	 * Runs the recovery sweep. Hooked to the recurring action.
	 */
	public static function sweep(): void {
		( new self() )->recover_stuck();
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
		$existing   = $repository->find_by_order( $order_id );
		if ( null !== $existing ) {
			// Cancelled and then completed again: send it again in a new retry cycle. An earlier
			// attempt may have reached Mat Bao, so the run looks the invoice up first when needed.
			if ( InvoiceStatus::STOPPED === $existing->status ) {
				self::restart( $order_id, InvoiceStatus::STOPPED );
			}
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
			self::enqueue( $repository, $order_id, false );
		}
	}

	/**
	 * Sends the invoice of an order again from a stopped or final status (STOPPED after the order
	 * is completed again, or an admin resend, #9) in a new retry cycle. Earlier attempts still
	 * decide whether the run looks the invoice up by `MTChieu` first.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Status the invoice must be in.
	 * @return bool Whether this call restarted it; false when it is in another status or another call won.
	 */
	public static function restart( int $order_id, string $from ): bool {
		$repository = new InvoiceRepository();
		$invoice    = $repository->find_by_order( $order_id );
		if ( null === $invoice || ! $repository->restart( $invoice->id, $from ) ) {
			return false;
		}

		self::enqueue( $repository, $order_id, false );

		return true;
	}

	/**
	 * Queues the draft action of an order. When queueing
	 * fails the row stays `pending` with QUEUE_FAILED and no alert, and the recovery sweep tries
	 * again; when the sweep cannot queue it either, QUEUE_FAILED becomes final and alerts.
	 *
	 * @param InvoiceRepository $repository Invoice storage.
	 * @param int               $order_id   Order ID.
	 * @param bool              $from_sweep Whether the recovery sweep is queueing it.
	 */
	private static function enqueue( InvoiceRepository $repository, int $order_id, bool $from_sweep ): bool {
		if ( 0 !== as_enqueue_async_action( self::ACTION, array( $order_id ), self::GROUP ) ) {
			return true;
		}

		if ( $from_sweep ) {
			self::record_error( $repository, $order_id, InvoiceErrorCode::QUEUE_FAILED, __( 'Không xếp được tác vụ tạo hóa đơn nháp vào hàng đợi, kể cả khi quét lại; cần admin gửi lại.', 'einvoice-matbao' ) );
		} else {
			self::record_error( $repository, $order_id, InvoiceErrorCode::QUEUE_FAILED, __( 'Không xếp được tác vụ tạo hóa đơn nháp vào hàng đợi; tác vụ quét sẽ thử lại.', 'einvoice-matbao' ), InvoiceStatus::PENDING );
		}

		return false;
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
	 * @param TemplateCache       $templates  Cached Mat Bao templates list.
	 */
	public function __construct(
		private readonly InvoiceRepository $repository = new InvoiceRepository(),
		private readonly Settings $settings = new Settings(),
		private readonly CredentialStore $store = new CredentialStore(),
		private readonly DraftPayloadBuilder $builder = new DraftPayloadBuilder(),
		private readonly TemplateCache $templates = new TemplateCache(),
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
	 * Puts rows stuck after a dead runner or a failed enqueue back through the lookup and queues them:
	 * `sending` older than the claim window, and `pending` with no queued action. A row that
	 * waits for a scheduled retry has an action and is left alone.
	 */
	public function recover_stuck(): void {
		foreach ( $this->repository->unchanged_for( InvoiceStatus::SENDING, self::STUCK_AFTER_SECONDS ) as $invoice ) {
			if ( $this->repository->claim( $invoice->id, InvoiceStatus::SENDING, InvoiceStatus::PENDING ) ) {
				$this->mark_uncertain( $invoice, __( 'Tác vụ gửi dừng giữa chừng; sẽ tra cứu theo mã tham chiếu trước khi gửi lại.', 'einvoice-matbao' ) );
				self::enqueue( $this->repository, $invoice->order_id, true );
			}
		}

		foreach ( $this->repository->unchanged_for( InvoiceStatus::PENDING, self::STUCK_AFTER_SECONDS ) as $invoice ) {
			if ( ! as_has_scheduled_action( self::ACTION, array( $invoice->order_id ), self::GROUP ) ) {
				$this->mark_uncertain( $invoice, __( 'Hóa đơn chờ gửi nhưng không có tác vụ; sẽ tra cứu theo mã tham chiếu trước khi gửi.', 'einvoice-matbao' ) );
				self::enqueue( $this->repository, $invoice->order_id, true );
			}
		}
	}

	/**
	 * Records why a stuck row is queued again. An attempt left open by a dead runner may have sent
	 * create-invoice, so it is closed as `abandoned` and the next run looks the invoice up first. No
	 * attempt is added: the sweep sends nothing, and the earlier attempts already decide whether a
	 * lookup is needed.
	 *
	 * @param InvoiceRecord $invoice Invoice.
	 * @param string        $reason  Admin-facing reason.
	 */
	private function mark_uncertain( InvoiceRecord $invoice, string $reason ): void {
		foreach ( $this->repository->attempts( $invoice->id ) as $attempt ) {
			if ( null === $attempt['outcome'] ) {
				$this->repository->finish_attempt( (int) $attempt['id'], 'abandoned', null, null, null, $reason );
			}
		}
		$this->repository->update_status( $invoice->id, InvoiceStatus::PENDING, $reason );
	}

	/**
	 * Tries used in the current retry cycle (from `retry_cycle_start`): attempts that called Mat
	 * Bao and failed (`failed`, `lookup_failed`, `not_sent`). Attempts closed by the sweep
	 * (`abandoned`) are not counted.
	 *
	 * @param InvoiceRecord $invoice Invoice.
	 */
	private function tries( InvoiceRecord $invoice ): int {
		$tries = 0;
		foreach ( $this->repository->attempts( $invoice->id ) as $attempt ) {
			if ( (int) $attempt['id'] >= $invoice->retry_cycle_start && in_array( $attempt['outcome'], array( 'failed', 'lookup_failed', 'not_sent' ), true ) ) {
				++$tries;
			}
		}

		return $tries;
	}

	/**
	 * Whether an earlier run may have created the invoice at Mat Bao. Attempts that never got as far
	 * as sending (`not_sent`) say nothing; the latest other attempt decides, and anything but a
	 * success (a failed or unanswered create, or a lookup that failed) means "maybe created".
	 *
	 * @param InvoiceRecord $invoice Invoice.
	 */
	private function may_have_been_created( InvoiceRecord $invoice ): bool {
		foreach ( array_reverse( $this->repository->attempts( $invoice->id ) ) as $attempt ) {
			if ( 'not_sent' !== $attempt['outcome'] ) {
				return 'success' !== $attempt['outcome'];
			}
		}

		return false;
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
			$template_available = $this->template_available( $client, $credentials, $template, $issued_on );
		} catch ( MatbaoError $error ) {
			// Before create-invoice: nothing can have been created, so a retry needs no lookup.
			$this->fail( $invoice, $this->repository->start_attempt( $invoice->id ), $error, 'not_sent', $template );
			return;
		}
		if ( ! $template_available ) {
			TemplateCache::forget();
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

		// An earlier create was sent with the template stored on the row; look it up there, never
		// under a default template changed since.
		$sent_template = '' !== $invoice->template_form && '' !== $invoice->template_series
			? array(
				'form'   => $invoice->template_form,
				'series' => $invoice->template_series,
			)
			: null;
		$look_up_first = $this->may_have_been_created( $invoice );
		$attempt       = $this->repository->start_attempt( $invoice->id );

		$order_total = (int) round( (float) $order->get_total() );
		if ( $look_up_first ) {
			$lookup_template = $sent_template ?? $template;
			try {
				$settled = $this->settle_by_lookup( $client, $invoice, $attempt, $lookup_template, $order_total, $issued_on );
			} catch ( MatbaoError $error ) {
				$this->fail( $invoice, $attempt, $error, $error->target_sent ? 'lookup_failed' : 'not_sent', $lookup_template );
				return;
			}
			if ( $settled ) {
				return;
			}
			if ( $lookup_template !== $template ) {
				$this->stop_for_changed_template( $invoice, $attempt, $lookup_template, $template );
				return;
			}
		}

		$this->repository->remember_template( $invoice->id, $template['form'], $template['series'] );
		try {
			$invoice_id = $client->create_invoice( $payload );
		} catch ( MatbaoError $error ) {
			if ( ! $error->target_sent ) {
				$this->fail( $invoice, $attempt, $error, 'not_sent', $template );
				return;
			}
			if ( $error->is_duplicate_reference() ) {
				// Already created under this MTChieu: look it up, never change the reference.
				try {
					$settled = $this->settle_by_lookup( $client, $invoice, $attempt, $template, $order_total, $issued_on );
				} catch ( MatbaoError ) {
					$settled = false;
				}
				if ( $settled ) {
					return;
				}
			}
			$this->fail( $invoice, $attempt, $error, 'failed', $template );
			return;
		}

		$this->repository->finish_attempt( $attempt, 'success' );
		$this->mark_created( $invoice, $invoice_id, $issued_on->format( 'Y-m-d' ), $template );
	}

	/**
	 * Stops a send whose default template changed after a create that may have reached Mat Bao
	 * under the stored template, and the lookup there found nothing. Creating under the new
	 * template could make a second invoice, so an admin decides.
	 *
	 * @param InvoiceRecord                       $invoice Invoice.
	 * @param int                                 $attempt Attempt ID; closed as `not_sent`.
	 * @param array{form: string, series: string} $sent    Template of the earlier create.
	 * @param array{form: string, series: string} $current Current default template.
	 */
	private function stop_for_changed_template( InvoiceRecord $invoice, int $attempt, array $sent, array $current ): void {
		$reason = sprintf(
			/* translators: 1: form (KHMSHDon) of the earlier send, 2: its series (KHHDon), 3: current default form, 4: current default series. */
			__( 'Hóa đơn đã được gửi với mẫu %1$s / %2$s nhưng mẫu mặc định nay là %3$s / %4$s, và không tìm thấy hóa đơn theo mẫu cũ. Không tự gửi bằng mẫu mới để tránh trùng; chọn lại mẫu %1$s / %2$s hoặc kiểm tra trên Mắt Bão rồi gửi lại.', 'einvoice-matbao' ),
			$sent['form'],
			$sent['series'],
			$current['form'],
			$current['series']
		);
		$this->repository->finish_attempt( $attempt, 'not_sent', null, null, null, $reason );
		self::record_error( $this->repository, $invoice->order_id, InvoiceErrorCode::TEMPLATE_CHANGED, $reason );
	}

	/**
	 * Looks the invoice up by `MTChieu` and settles the run when Mat Bao has something under it:
	 * adopts the single draft of this order, or stops for an admin when what Mat Bao returns may
	 * not be that draft (several rows, another `MTChieu`, not a draft, another total).
	 *
	 * @param MatbaoClient                        $client      Client.
	 * @param InvoiceRecord                       $invoice     Invoice.
	 * @param int                                 $attempt     Attempt ID.
	 * @param array{form: string, series: string} $template    Template the earlier create was sent with.
	 * @param int                                 $order_total Order total, VND.
	 * @param \DateTimeImmutable                  $issued_on   `NLap` of this run, used when Mat Bao reports none.
	 * @return bool Whether the run is settled; false when Mat Bao has no invoice with the reference.
	 * @throws MatbaoError When the lookup fails or its answer is unusable.
	 */
	private function settle_by_lookup( MatbaoClient $client, InvoiceRecord $invoice, int $attempt, array $template, int $order_total, \DateTimeImmutable $issued_on ): bool {
		$found = $client->find_invoices_by_reference( $invoice->reference, $template['form'], $template['series'] );
		if ( array() === $found ) {
			return false;
		}

		$mismatch = $this->lookup_mismatch( $invoice, $found, $order_total );
		if ( null !== $mismatch ) {
			$this->repository->finish_attempt( $attempt, 'lookup_mismatch', null, null, null, $mismatch );
			self::record_error( $this->repository, $invoice->order_id, InvoiceErrorCode::MATBAO_LOOKUP_MISMATCH, $mismatch, InvoiceStatus::NEEDS_ATTENTION );
			return true;
		}

		$draft = $found[0];
		if ( '' === $draft->invoice_id ) {
			throw new MatbaoError( MatbaoError::KIND_API, 'Lookup response has no invoice ID.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered.
		}

		$this->repository->finish_attempt( $attempt, 'success', null, null, null, __( 'Hóa đơn nháp đã có trên Mắt Bão; đã nhận lại theo mã tham chiếu.', 'einvoice-matbao' ) );
		$this->mark_created( $invoice, $draft->invoice_id, $draft->issued_on ?? $issued_on->format( 'Y-m-d' ), $template );

		return true;
	}

	/**
	 * Why the invoices Mat Bao found under the reference may not be this order's draft, or null
	 * when there is exactly one draft that matches. The reason names only the reference and the
	 * Mat Bao status of what was found.
	 *
	 * @param InvoiceRecord   $invoice     Invoice.
	 * @param MatbaoInvoice[] $found       Lookup rows, at least one.
	 * @param int             $order_total Order total, VND.
	 */
	private function lookup_mismatch( InvoiceRecord $invoice, array $found, int $order_total ): ?string {
		if ( count( $found ) > 1 ) {
			/* translators: 1: number of invoices, 2: reference (MTChieu). */
			return sprintf( __( 'Mắt Bão trả về %1$d hóa đơn cùng mã tham chiếu %2$s; cần admin kiểm tra trên Mắt Bão, không tự nhận hóa đơn nào.', 'einvoice-matbao' ), count( $found ), $invoice->reference );
		}

		$draft = reset( $found );
		if ( false === $draft ) {
			return null;
		}
		if ( $draft->reference !== $invoice->reference ) {
			/* translators: 1: reference returned by Mat Bao, 2: reference of this invoice. */
			return sprintf( __( 'Mắt Bão trả về hóa đơn có mã tham chiếu %1$s, khác %2$s; cần admin kiểm tra.', 'einvoice-matbao' ), $draft->reference, $invoice->reference );
		}
		if ( ! $draft->is_draft() ) {
			/* translators: 1: reference (MTChieu), 2: Mat Bao status name. */
			return sprintf( __( 'Hóa đơn mã tham chiếu %1$s trên Mắt Bão ở trạng thái "%2$s", không phải Nháp; cần admin kiểm tra.', 'einvoice-matbao' ), $invoice->reference, '' !== $draft->status_name ? $draft->status_name : (string) $draft->status );
		}
		if ( null !== $draft->total && $draft->total !== $order_total ) {
			/* translators: %s: reference (MTChieu). */
			return sprintf( __( 'Hóa đơn nháp mã tham chiếu %s trên Mắt Bão có tổng tiền khác tổng đơn hàng; cần admin kiểm tra.', 'einvoice-matbao' ), $invoice->reference );
		}

		return null;
	}

	/**
	 * Stores a created or adopted draft.
	 *
	 * @param InvoiceRecord                       $invoice    Invoice.
	 * @param string                              $invoice_id `maSoHDon`.
	 * @param string                              $issued_on  `NLap` date (Y-m-d).
	 * @param array{form: string, series: string} $template  Template of the draft.
	 */
	private function mark_created( InvoiceRecord $invoice, string $invoice_id, string $issued_on, array $template ): void {
		$this->repository->update_status(
			$invoice->id,
			InvoiceStatus::DRAFT_CREATED,
			'',
			array(
				'matbao_invoice_id' => $invoice_id,
				'issued_on'         => $issued_on,
				'template_form'     => $template['form'],
				'template_series'   => $template['series'],
			)
		);
	}

	/**
	 * Whether Mat Bao still offers the default template for the invoice year with numbers left.
	 * A template of last year (series C25...) is no longer offered once the year changes. The list
	 * may be up to TemplateCache's lifetime old; a rejection on create drops it.
	 *
	 * @param MatbaoClient                        $client      Client.
	 * @param MatbaoCredentials                   $credentials Account of the client.
	 * @param array{form: string, series: string} $template    Default template.
	 * @param \DateTimeImmutable                  $issued_on   `NLap`, Vietnam time.
	 * @throws MatbaoError When the templates call fails.
	 */
	private function template_available( MatbaoClient $client, MatbaoCredentials $credentials, array $template, \DateTimeImmutable $issued_on ): bool {
		foreach ( $this->templates->templates( $client, $credentials, (int) $issued_on->format( 'Y' ) ) as $offered ) {
			if ( $offered->is_vat() && $offered->form === $template['form'] && $offered->series === $template['series'] ) {
				return $offered->has_remaining_numbers();
			}
		}

		return false;
	}

	/**
	 * Records a failed Mat Bao call on the attempt and the invoice. A code that is not auto-retried
	 * is final and alerts; a retryable one leaves the row `pending` and queues the same action after
	 * the next backoff delay, or, once the retries are used up, sets `needs_attention` and fires
	 * both hooks.
	 *
	 * @param InvoiceRecord                       $invoice  Invoice.
	 * @param int                                 $attempt  Attempt ID.
	 * @param MatbaoError                         $error    The failure.
	 * @param string                              $outcome  `failed` (create was sent), `lookup_failed` or `not_sent`.
	 * @param array{form: string, series: string} $template Template of the call.
	 */
	private function fail( InvoiceRecord $invoice, int $attempt, MatbaoError $error, string $outcome, array $template ): void {
		$this->repository->finish_attempt( $attempt, $outcome, $error->kind, $error->http_status, $error->api_code, $error->getMessage() );

		$code = InvoiceErrorCode::from_matbao( $error );
		if ( InvoiceErrorCode::TEMPLATE_REJECTED === $code ) {
			TemplateCache::forget();
			/* translators: 1: Mat Bao message, 2: template form number (KHMSHDon), 3: template series (KHHDon). */
			$reason = sprintf( __( 'Mắt Bão từ chối mẫu hóa đơn %2$s / %3$s (%1$s); chọn lại mẫu mặc định.', 'einvoice-matbao' ), $error->getMessage(), $template['form'], $template['series'] );
		} else {
			$reason = $error->getMessage();
		}

		if ( ! $code->is_auto_retryable() ) {
			self::record_error( $this->repository, $invoice->order_id, $code, $reason );
			return;
		}

		$tries = $this->tries( $invoice );
		if ( $tries > count( self::BACKOFF_SECONDS ) ) {
			self::record_error(
				$this->repository,
				$invoice->order_id,
				$code,
				/* translators: %s: last Mat Bao error. */
				sprintf( __( 'Đã thử lại tối đa nhưng chưa tạo được hóa đơn nháp. Lỗi gần nhất: %s', 'einvoice-matbao' ), $reason ),
				InvoiceStatus::NEEDS_ATTENTION
			);
			do_action( 'einvoice_matbao_retries_exhausted', $invoice->order_id, $invoice->reference );
			return;
		}

		$delay = self::BACKOFF_SECONDS[ $tries - 1 ];
		self::record_error(
			$this->repository,
			$invoice->order_id,
			$code,
			/* translators: 1: seconds until the retry, 2: last Mat Bao error. */
			sprintf( __( 'Lỗi tạm thời, sẽ thử lại sau %1$d giây. Lỗi: %2$s', 'einvoice-matbao' ), $delay, $reason ),
			InvoiceStatus::PENDING
		);
		if ( 0 === as_schedule_single_action( time() + $delay, self::ACTION, array( $invoice->order_id ), self::GROUP ) ) {
			self::record_error( $this->repository, $invoice->order_id, InvoiceErrorCode::QUEUE_FAILED, __( 'Không xếp được lần thử lại vào hàng đợi; tác vụ quét sẽ thử lại.', 'einvoice-matbao' ), InvoiceStatus::PENDING );
		}
	}

	/**
	 * Records an error on the invoice of an order and fires NEEDS_ATTENTION_ACTION when an admin
	 * has to act. A row left `pending` is still being retried or recovered, so nothing fires.
	 *
	 * @param InvoiceRepository $repository Invoice storage.
	 * @param int               $order_id   Order ID.
	 * @param InvoiceErrorCode  $code       Error code.
	 * @param string            $reason     Vietnamese reason.
	 * @param string|null       $status     InvoiceStatus to set instead of the one of the code's kind.
	 */
	private static function record_error( InvoiceRepository $repository, int $order_id, InvoiceErrorCode $code, string $reason, ?string $status = null ): void {
		$invoice = $repository->find_by_order( $order_id );
		if ( null === $invoice ) {
			return;
		}

		$repository->record_error( $invoice->id, $code, $reason, $status );
		$invoice = $repository->find_by_order( $order_id );
		if ( null !== $invoice && InvoiceStatus::PENDING !== $invoice->status && $code->needs_attention() ) {
			do_action( 'einvoice_matbao_invoice_needs_attention', $invoice, $code );
		}
	}
}
