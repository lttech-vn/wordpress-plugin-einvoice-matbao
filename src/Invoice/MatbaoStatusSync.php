<?php
/**
 * Hourly sync of the Mat Bao status of drafts created by the plugin.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Matbao\MatbaoClient;
use LTTech\EinvoiceMatbao\Matbao\MatbaoError;
use LTTech\EinvoiceMatbao\Matbao\MatbaoLookup;
use LTTech\EinvoiceMatbao\Monitoring\CaughtFailure;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;

/**
 * Reads the status of recent `draft_created` invoices from Mat Bao in batches (one request per
 * template and up to LOOKUP_CHUNK invoices) and stores it next to, never instead of, the plugin
 * status. It never changes `status`, never calls create-invoice, and marks an invoice deleted only
 * when Mat Bao itself says it does not exist (errorCode 301 or the `notFound` list).
 *
 * A new abnormal status (sign error, not eligible, deleted) fires the needs-attention hook once:
 * only the writer that actually changed the row alerts (see InvoiceRepository::record_matbao_status()).
 */
final class MatbaoStatusSync {

	/** Action Scheduler hook of the recurring sync. */
	public const ACTION = 'einvoice_matbao_status_sync';

	/** Seconds between two syncs. */
	public const SYNC_INTERVAL_SECONDS = HOUR_IN_SECONDS;

	/** Invoices dated within this many days are synced. */
	public const SYNC_WINDOW_DAYS = 30;

	/** Maximum invoices read per run. */
	public const SYNC_LIMIT = 200;

	/** Maximum references per lookup request. */
	public const LOOKUP_CHUNK = 20;

	/** `component` tag of the issues this sync reports. */
	private const MONITORING_COMPONENT = 'status_sync';

	/**
	 * Hooks the handler and the schedule.
	 */
	public static function register(): void {
		add_action( self::ACTION, array( self::class, 'run' ) );
		add_action( 'action_scheduler_init', array( self::class, 'schedule' ) );
	}

	/**
	 * Queues the recurring sync unless it is already queued.
	 */
	public static function schedule(): void {
		if ( ! as_has_scheduled_action( self::ACTION, array(), DraftInvoiceWorkflow::GROUP ) ) {
			as_schedule_recurring_action( time() + self::SYNC_INTERVAL_SECONDS, self::SYNC_INTERVAL_SECONDS, self::ACTION, array(), DraftInvoiceWorkflow::GROUP );
		}
	}

	/**
	 * Runs one sync. Hooked to the recurring action.
	 */
	public static function run(): void {
		( new self() )->sync_batch();
	}

	/**
	 * Creates the service.
	 *
	 * @param InvoiceRepository $repository Invoice storage.
	 * @param MatbaoClient|null $client     Mat Bao client; built from the saved credentials when null.
	 */
	public function __construct(
		private readonly InvoiceRepository $repository = new InvoiceRepository(),
		private readonly ?MatbaoClient $client = null,
	) {
	}

	/**
	 * Looks up the next batch of due invoices, one request per template chunk. A failing lookup
	 * keeps the stored statuses, is retried at the next run and does not stop the other chunks.
	 */
	public function sync_batch(): void {
		$client = $this->resolve_client();
		if ( null === $client ) {
			return;
		}

		$due    = $this->repository->due_for_status_sync( self::SYNC_LIMIT, self::window_start() );
		$groups = array();
		foreach ( $due as $invoice ) {
			$groups[ $invoice->template_form . '|' . $invoice->template_series ][] = $invoice;
		}

		foreach ( $groups as $group ) {
			foreach ( array_chunk( $group, self::LOOKUP_CHUNK ) as $chunk ) {
				$this->sync_chunk( $client, $chunk );
			}
		}
	}

	/**
	 * Looks up and records one invoice.
	 *
	 * @param InvoiceRecord $invoice Invoice as read.
	 */
	public function sync_one( InvoiceRecord $invoice ): SyncOutcome {
		$client = $this->resolve_client();
		if ( null === $client ) {
			return SyncOutcome::FAILED;
		}

		$lookup = $this->lookup( $client, array( $invoice ) );

		return null === $lookup ? SyncOutcome::FAILED : $this->apply( $invoice, $lookup );
	}

	/**
	 * Looks up one chunk of invoices of one template and records each.
	 *
	 * @param MatbaoClient    $client   Client.
	 * @param InvoiceRecord[] $invoices Invoices of one template.
	 */
	private function sync_chunk( MatbaoClient $client, array $invoices ): void {
		$lookup = $this->lookup( $client, $invoices );
		if ( null === $lookup ) {
			return;
		}
		foreach ( $invoices as $invoice ) {
			$this->apply( $invoice, $lookup );
		}
	}

	/**
	 * One lookup request. A Mat Bao error is logged (no event: the next run retries) and the
	 * invoices are marked as checked so others are tried first.
	 *
	 * @param MatbaoClient    $client   Client.
	 * @param InvoiceRecord[] $invoices Invoices of one template.
	 * @return MatbaoLookup|null Null when the lookup failed.
	 */
	private function lookup( MatbaoClient $client, array $invoices ): ?MatbaoLookup {
		try {
			return $client->find_invoices_by_references(
				array_map( static fn( InvoiceRecord $invoice ): string => $invoice->reference, $invoices ),
				$invoices[0]->template_form,
				$invoices[0]->template_series
			);
		} catch ( MatbaoError $error ) {
			$this->repository->touch_matbao_checked( array_map( static fn( InvoiceRecord $invoice ): int => $invoice->id, $invoices ) );
			Monitor::log(
				'warn',
				'Mat Bao status lookup failed',
				array(
					'error_kind' => $error->kind,
					'api_code'   => $error->api_code,
					'count'      => count( $invoices ),
				)
			);

			return null;
		}
	}

	/**
	 * Records what a lookup says about one invoice.
	 *
	 * @param InvoiceRecord $read   Invoice as read before the lookup.
	 * @param MatbaoLookup  $lookup Answer of the batch.
	 */
	private function apply( InvoiceRecord $read, MatbaoLookup $lookup ): SyncOutcome {
		$snapshot = $this->snapshot_for( $read, $lookup );
		if ( null === $snapshot ) {
			$this->repository->touch_matbao_checked( array( $read->id ) );
			Monitor::log( 'warn', 'Mat Bao status lookup did not confirm the invoice', array( 'order_id' => $read->order_id ) );

			return SyncOutcome::UNCONFIRMED;
		}

		$new_status = null === $snapshot->status ? null : MatbaoStatus::tryFrom( $snapshot->status );
		$result     = $this->repository->record_matbao_status( $read, $snapshot, $new_status?->label() ?? '' );

		if ( RecordResult::FAILED === $result ) {
			Monitor::log( 'warn', 'Mat Bao status could not be stored', array( 'order_id' => $read->order_id ) );

			return SyncOutcome::FAILED;
		}
		if ( RecordResult::STALE === $result ) {
			return SyncOutcome::UNCHANGED;
		}

		$code = $new_status?->alert_code();
		if ( null !== $code && $snapshot->status !== $read->matbao_status ) {
			$this->notify( $read->id, $code );
		}

		return SyncOutcome::UPDATED;
	}

	/**
	 * The snapshot to store, or null when the answer does not settle the invoice: the reference
	 * is missing from both lists, unreadable (no valid status), or carries a different Mat Bao invoice.
	 *
	 * @param InvoiceRecord $read   Invoice.
	 * @param MatbaoLookup  $lookup Answer.
	 */
	private function snapshot_for( InvoiceRecord $read, MatbaoLookup $lookup ): ?MatbaoSnapshot {
		foreach ( $lookup->invoices as $row ) {
			if ( $row->reference === $read->reference && $row->invoice_id === $read->matbao_invoice_id ) {
				// Deleted (0) is only ever proven by errorCode 301 / `notFound`, never by a row; an
				// unreadable or out-of-range status settles nothing. Unknown codes (e.g. 12) are kept.
				return null !== $row->status && $row->status >= 1 && $row->status <= 255 ? MatbaoSnapshot::from_invoice( $row ) : null;
			}
		}

		return in_array( $read->reference, $lookup->not_found, true ) ? MatbaoSnapshot::deleted() : null;
	}

	/**
	 * Fires the needs-attention hook; a failing listener must not stop the sync.
	 *
	 * @param int              $invoice_id Invoice row ID.
	 * @param InvoiceErrorCode $code       The warning.
	 */
	private function notify( int $invoice_id, InvoiceErrorCode $code ): void {
		$updated = $this->repository->find( $invoice_id );
		if ( null === $updated ) {
			return;
		}
		try {
			do_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION, $updated, $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- constant with the plugin prefix.
		} catch ( \Throwable $listener_error ) {
			error_log( 'einvoice-matbao: needs_attention listener failed (' . get_class( $listener_error ) . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			CaughtFailure::report( $listener_error, self::MONITORING_COMPONENT, 'needs_attention listener failed', $updated->order_id );
		}
	}

	/**
	 * The injected client, or one built from the saved credentials.
	 */
	private function resolve_client(): ?MatbaoClient {
		if ( null !== $this->client ) {
			return $this->client;
		}
		$credentials = ( new CredentialStore() )->matbao();

		return null === $credentials ? null : new MatbaoClient( $credentials );
	}

	/**
	 * First date (`Y-m-d`, Vietnam time like `issued_on`) still inside the sync window.
	 */
	private static function window_start(): string {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Ho_Chi_Minh' ) ) )->modify( '-' . self::SYNC_WINDOW_DAYS . ' days' )->format( 'Y-m-d' );
	}
}
