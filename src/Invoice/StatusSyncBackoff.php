<?php
/**
 * Pause state of the Mat Bao status sync after temporary failures.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

use LTTech\EinvoiceMatbao\Monitoring\Monitor;

/**
 * Remembers how many temporary Mat Bao failures happened in a row and until when the automatic
 * sync is paused. A single Action Scheduler action retries once the pause is over; the hourly
 * recurring action is left alone. The state lives in one non-autoloaded option and is removed on
 * the first successful lookup.
 */
final class StatusSyncBackoff {

	/** Option holding `array{failures: int, until: int}`. */
	public const OPTION = 'einvoice_matbao_status_sync_backoff';

	/** Action Scheduler hook of the one-off retry run. */
	public const RETRY_ACTION = 'einvoice_matbao_status_sync_retry';

	/** Pause in seconds after the 1st, 2nd, ... failure; the last value repeats. */
	public const PAUSE_SECONDS = array( 900, 1800, 3600, 7200, 14400, 21600 );

	/**
	 * Whether the automatic sync is paused.
	 */
	public function is_paused(): bool {
		return $this->state()['until'] > time();
	}

	/**
	 * Records a temporary failure, extends the pause and queues the single retry.
	 *
	 * @return int Pause in seconds.
	 */
	public function record_failure(): int {
		$failures = $this->state()['failures'] + 1;
		$seconds  = self::PAUSE_SECONDS[ min( $failures, count( self::PAUSE_SECONDS ) ) - 1 ];
		$until    = time() + $seconds;

		update_option(
			self::OPTION,
			array(
				'failures' => $failures,
				'until'    => $until,
			),
			false
		);

		// Not unique on purpose: a unique action would keep the older, earlier run time.
		as_unschedule_all_actions( self::RETRY_ACTION, array(), DraftInvoiceWorkflow::GROUP );
		if ( 0 === as_schedule_single_action( $until, self::RETRY_ACTION, array(), DraftInvoiceWorkflow::GROUP ) ) {
			Monitor::log( 'warn', 'Mat Bao status sync retry not queued' );
		}
		Monitor::log(
			'warn',
			'Mat Bao status sync paused',
			array(
				'failures'      => $failures,
				'pause_seconds' => $seconds,
			)
		);

		return $seconds;
	}

	/**
	 * Ends the backoff after a successful lookup; writes nothing when there is nothing to end.
	 */
	public function clear(): void {
		if ( false !== get_option( self::OPTION ) ) {
			delete_option( self::OPTION );
		}
		if ( as_has_scheduled_action( self::RETRY_ACTION, array(), DraftInvoiceWorkflow::GROUP ) ) {
			as_unschedule_all_actions( self::RETRY_ACTION, array(), DraftInvoiceWorkflow::GROUP );
		}
	}

	/**
	 * The stored state; anything unreadable counts as no backoff.
	 *
	 * @return array{failures: int, until: int}
	 */
	private function state(): array {
		$stored = get_option( self::OPTION );
		if (
			is_array( $stored )
			&& is_int( $stored['failures'] ?? null ) && $stored['failures'] >= 0
			&& is_int( $stored['until'] ?? null ) && $stored['until'] >= 0
		) {
			return array(
				'failures' => $stored['failures'],
				'until'    => $stored['until'],
			);
		}

		return array(
			'failures' => 0,
			'until'    => 0,
		);
	}
}
