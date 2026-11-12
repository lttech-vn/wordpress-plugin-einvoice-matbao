<?php
/**
 * Static facade through which every instrumentation point reports to monitoring.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;

/**
 * The only monitoring entry point for business code. The backend is created lazily on first use
 * and only when monitoring is active, so a disabled site never loads the Sentry SDK.
 *
 * Instrumentation failures are swallowed and logged by class name only. span() and
 * with_order_context() run the caller's callable exactly once, return its value and rethrow its
 * exception unchanged; if the instrumentation around it fails, the callable runs unmeasured.
 */
final class Monitor {

	public const TEST_EVENT_MESSAGE = 'einvoice-matbao test event';

	/**
	 * Recovery cron monitor schedule; see design D4.
	 */
	private const CHECK_IN_SCHEDULE = array(
		'interval'                => 15,
		'unit'                    => 'minute',
		'checkin_margin'          => 15,
		'max_runtime'             => 10,
		'timezone'                => 'UTC',
		'failure_issue_threshold' => 2,
		'recovery_threshold'      => 1,
	);

	/**
	 * Backend of this request; null until resolved.
	 *
	 * @var Backend|null
	 */
	private static ?Backend $backend = null;

	/**
	 * Whether the backend was resolved (it stays null when monitoring is off).
	 *
	 * @var bool
	 */
	private static bool $resolved = false;

	/**
	 * Whether monitoring is active, read from the configuration only; null until read.
	 *
	 * @var bool|null
	 */
	private static ?bool $active = null;

	/**
	 * Number of start_transaction() calls not yet finished; only the outermost pair reaches the
	 * backend, so nested runs (an action run inside a REST request) report into one transaction.
	 *
	 * @var int
	 */
	private static int $transaction_depth = 0;

	/**
	 * Monitor slug of this site.
	 *
	 * @var string
	 */
	private static string $slug = '';

	/**
	 * Known personal values of the enclosing with_order_context() calls, innermost last; null
	 * marks a context whose values could not be collected (nothing is captured inside it).
	 *
	 * @var list<list<string>|null>
	 */
	private static array $order_contexts = array();

	/**
	 * Failure details of the enclosing with_failure_detail() calls, innermost last.
	 *
	 * @var list<FailureDetail>
	 */
	private static array $failure_details = array();

	/**
	 * Reports an exception as an issue.
	 *
	 * @param \Throwable           $e    Exception.
	 * @param array<string, mixed> $tags Tags; only allow-listed ones are sent.
	 * @param \Closure|null        $contexts Builds the context blocks (`Closure(): array<string, array<string, mixed>>`);
	 *                                       it runs inside the guard, so a failure drops the event instead of escaping.
	 * @return string|null Event ID, or null when nothing was sent.
	 */
	public static function capture_exception( \Throwable $e, array $tags = array(), ?\Closure $contexts = null ): ?string {
		return self::capture(
			static fn( Backend $backend ): ?string => $backend->capture_exception(
				$e,
				$tags,
				self::known_values(),
				null === $contexts ? array() : $contexts()
			)
		);
	}

	/**
	 * Reports an invoice that needs an administrator, grouped by error code.
	 *
	 * @param InvoiceRecord    $i Invoice.
	 * @param InvoiceErrorCode $c Error that needs attention.
	 */
	public static function capture_needs_attention( InvoiceRecord $i, InvoiceErrorCode $c ): void {
		self::capture(
			static function ( Backend $backend ) use ( $i, $c ): ?string {
				$contexts = array( 'invoice' => InvoiceContext::for_invoice( $i ) );
				$tags     = array(
					'order_id'       => (string) $i->order_id,
					'error_code'     => $c->value,
					'error_kind'     => $c->kind()->value,
					'invoice.status' => $i->status,
				);
				$detail   = self::failure_detail_for( $i->order_id, $c );
				if ( null !== $detail ) {
					$contexts += $detail->contexts;
					$tags     += $detail->tags;
				}

				return $backend->capture_message(
					sprintf( 'Hóa đơn cần xử lý: %s – Đơn #%d', $c->label(), $i->order_id ),
					'warning',
					$tags,
					array( 'needs-attention', $c->value ),
					self::known_values(),
					$contexts
				);
			}
		);
	}

	/**
	 * Buffers a structured log; it is sent at shutdown or when a transaction ends.
	 *
	 * @param string               $level `trace`, `debug`, `info`, `warn`, `error` or `fatal`.
	 * @param string               $msg   Message.
	 * @param array<string, mixed> $attrs Attributes.
	 */
	public static function log( string $level, string $msg, array $attrs = array() ): void {
		self::capture(
			static function ( Backend $backend ) use ( $level, $msg, $attrs ): void {
				$backend->log( $level, $msg, $attrs, self::known_values() );
			}
		);
	}

	/**
	 * Whether monitoring is active for this request. Reads the configuration only (cached for the
	 * request), never the Sentry SDK, so hooks can skip their own work cheaply when it is off.
	 */
	public static function is_active(): bool {
		if ( self::$resolved ) {
			return null !== self::$backend;
		}

		if ( null === self::$active ) {
			try {
				self::$active = MonitoringConfig::current()->active();
			} catch ( \Throwable $failure ) {
				InternalFailure::report( $failure );
				self::$active = false;
			}
		}

		return self::$active;
	}

	/**
	 * Opens a transaction. Inside an open transaction this only nests: the outer one stays the
	 * reported transaction and the matching finish_transaction() leaves it open.
	 *
	 * @param string $name Transaction name.
	 * @param string $op   Operation, for example `queue.task`.
	 */
	public static function start_transaction( string $name, string $op ): void {
		++self::$transaction_depth;
		if ( self::$transaction_depth > 1 ) {
			return;
		}

		self::capture(
			static function ( Backend $backend ) use ( $name, $op ): void {
				$backend->start_transaction( $name, $op, self::known_values() );
			}
		);
	}

	/**
	 * Closes the open transaction. A nested finish only unwinds one level (its status is not
	 * reported); a finish without an open transaction does nothing.
	 *
	 * @param string $status `ok`, `internal_error`, ...
	 */
	public static function finish_transaction( string $status = 'ok' ): void {
		if ( 0 === self::$transaction_depth ) {
			return;
		}

		--self::$transaction_depth;
		if ( self::$transaction_depth > 0 ) {
			return;
		}

		// Inside an unreadable order context the matching start was dropped, so this is too.
		self::capture(
			static function ( Backend $backend ) use ( $status ): void {
				$backend->finish_transaction( $status );
			}
		);
	}

	/**
	 * Closes the open transaction like finish_transaction(), with the status of an HTTP response
	 * code. The mapping lives in the backend so that only it touches the Sentry SDK.
	 *
	 * @param int $code HTTP response code of the traced request.
	 */
	public static function finish_transaction_for_http_status( int $code ): void {
		if ( 0 === self::$transaction_depth ) {
			return;
		}

		--self::$transaction_depth;
		if ( self::$transaction_depth > 0 ) {
			return;
		}

		self::capture(
			static function ( Backend $backend ) use ( $code ): void {
				$backend->finish_transaction_for_http_status( $code );
			}
		);
	}

	/**
	 * Measures $callback as a child span of the open transaction.
	 *
	 * @param string        $op             Operation, for example `http.client`.
	 * @param string        $desc           Description, for example `POST /api/x`.
	 * @param callable      $callback       Code to measure; called exactly once.
	 * @param callable|null $data_of_result Maps the result of $callback to span data.
	 * @return mixed Result of $callback.
	 * @throws \Throwable The exception of $callback, unchanged.
	 */
	public static function span( string $op, string $desc, callable $callback, ?callable $data_of_result = null ): mixed {
		$backend = self::capturing_backend();
		if ( null === $backend ) {
			return $callback();
		}

		$outcome  = new CallOutcome();
		$measured = static fn(): mixed => $outcome->run( $callback );

		try {
			return $backend->span( $op, $desc, $measured, $data_of_result ?? static fn(): array => array(), self::known_values() );
		} catch ( \Throwable $failure ) {
			if ( $outcome->is_failure( $failure ) ) {
				throw $failure;
			}
			InternalFailure::report( $failure );

			// The instrumentation failed: replay what $callback did, or run it now if it never ran.
			return $outcome->has_run() ? $outcome->replay() : $callback();
		}
	}

	/**
	 * Sends a check-in for the recovery cron monitor of this site.
	 *
	 * @param string      $status   `in_progress`, `ok` or `error`.
	 * @param string|null $id       ID returned by the `in_progress` check-in.
	 * @param float|null  $duration Run time in seconds.
	 * @return string|null Check-in ID.
	 */
	public static function check_in( string $status, ?string $id = null, ?float $duration = null ): ?string {
		return self::capture( static fn( Backend $backend ): ?string => $backend->check_in( self::$slug, $status, $id, $duration, self::CHECK_IN_SCHEDULE ) );
	}

	/**
	 * Runs $callback with the buyer values of the order masked in every payload captured meanwhile.
	 *
	 * @param int      $order_id Order ID.
	 * @param callable $callback Code to run; called exactly once.
	 * @return mixed Result of $callback.
	 * @throws \Throwable The exception of $callback, unchanged.
	 */
	public static function with_order_context( int $order_id, callable $callback ): mixed {
		if ( null === self::backend_or_null() ) {
			return $callback();
		}

		try {
			$values = OrderRedaction::values_for( $order_id );
		} catch ( \Throwable $failure ) {
			// Fail closed: without the buyer values nothing inside this context can be masked.
			InternalFailure::report( $failure );
			$values = null;
		}

		self::$order_contexts[] = $values;
		$depth                  = count( self::$order_contexts );
		try {
			return $callback();
		} finally {
			self::$order_contexts = array_slice( self::$order_contexts, 0, $depth - 1 );
		}
	}

	/**
	 * Runs $callback with $detail available to the needs-attention event of the same order and code.
	 *
	 * @param FailureDetail $detail   Detail to expose while $callback runs.
	 * @param callable      $callback Code to run; called exactly once.
	 * @return mixed Result of $callback.
	 * @throws \Throwable The exception of $callback, unchanged.
	 */
	public static function with_failure_detail( FailureDetail $detail, callable $callback ): mixed {
		if ( null === self::backend_or_null() ) {
			return $callback();
		}

		self::$failure_details[] = $detail;
		$depth                   = count( self::$failure_details );
		try {
			return $callback();
		} finally {
			self::$failure_details = array_slice( self::$failure_details, 0, $depth - 1 );
		}
	}

	/**
	 * The innermost open failure detail of the order and error code.
	 *
	 * @internal Used by the needs-attention capture.
	 *
	 * @param int              $order_id Order ID.
	 * @param InvoiceErrorCode $code     Error code.
	 */
	public static function failure_detail_for( int $order_id, InvoiceErrorCode $code ): ?FailureDetail {
		foreach ( array_reverse( self::$failure_details ) as $detail ) {
			if ( $detail->order_id === $order_id && $detail->code === $code ) {
				return $detail;
			}
		}

		return null;
	}

	/**
	 * Sends the settings-page test event.
	 *
	 * @return string|null Event ID when the server accepted it.
	 */
	public static function send_test_event(): ?string {
		return self::capture( static fn( Backend $backend ): ?string => $backend->capture_message( self::TEST_EVENT_MESSAGE, 'info', array(), array(), array(), array() ) );
	}

	/**
	 * Sends buffered logs.
	 */
	public static function flush(): void {
		if ( ! self::$resolved ) {
			return;
		}

		self::guard(
			static function ( Backend $backend ): void {
				$backend->flush();
			}
		);
	}

	/**
	 * Drops the backend of this request without sending what it buffered (logs, the open
	 * transaction), so nothing built for the old configuration reaches the old server. The next
	 * call builds a backend from the new configuration. Open transaction depth and order contexts
	 * are kept, so the matching finish of an enclosing request is a no-op on the new backend.
	 */
	public static function reconfigure(): void {
		self::$backend  = null;
		self::$resolved = false;
		self::$active   = null;
		self::$slug     = '';
	}

	/**
	 * Forgets the backend so the next call reads the configuration again, or injects one.
	 *
	 * @internal Test seam; production code never calls it.
	 *
	 * @param Backend|null $b Backend to use, or null to resolve it from the configuration.
	 */
	public static function reset_for_tests( ?Backend $b = null ): void {
		self::$backend           = $b;
		self::$resolved          = null !== $b;
		self::$active            = null;
		self::$transaction_depth = 0;
		self::$order_contexts    = array();
		self::$failure_details   = array();
		self::$slug              = null === $b ? '' : MonitorSlug::for_host( MonitoringConfig::current()->site );
	}

	/**
	 * Like guard(), but drops the call inside an order context whose values are unreadable.
	 *
	 * @template T
	 * @param callable(Backend): T $call Backend call that sends or buffers data.
	 * @return T|null
	 */
	private static function capture( callable $call ): mixed {
		return self::is_suppressed() ? null : self::guard( $call );
	}

	/**
	 * The backend for a new capture, or null when monitoring is off or captures are suppressed.
	 */
	private static function capturing_backend(): ?Backend {
		return self::is_suppressed() ? null : self::backend_or_null();
	}

	/**
	 * Whether an enclosing order context could not collect its buyer values.
	 */
	private static function is_suppressed(): bool {
		return in_array( null, self::$order_contexts, true );
	}

	/**
	 * Runs $call against the backend; a disabled or failing backend yields null.
	 *
	 * @template T
	 * @param callable(Backend): T $call Backend call.
	 * @return T|null
	 */
	private static function guard( callable $call ): mixed {
		try {
			$backend = self::backend_or_null();

			return null === $backend ? null : $call( $backend );
		} catch ( \Throwable $failure ) {
			InternalFailure::report( $failure );

			return null;
		}
	}

	/**
	 * The backend of this request, created on first use; null when monitoring is off or broken.
	 */
	private static function backend_or_null(): ?Backend {
		if ( self::$resolved ) {
			return self::$backend;
		}

		self::$resolved = true;
		try {
			$config = MonitoringConfig::current();
			if ( ! $config->active() ) {
				return null;
			}

			self::$slug    = MonitorSlug::for_host( $config->site );
			self::$backend = new SentryBackend( $config, new Scrubber() );
			add_action( 'shutdown', array( self::class, 'flush' ), 100 );
		} catch ( \Throwable $failure ) {
			self::$backend = null;
			InternalFailure::report( $failure );
		}

		return self::$backend;
	}

	/**
	 * Known personal values of every enclosing order context.
	 *
	 * @return list<string>
	 */
	private static function known_values(): array {
		return array_values( array_unique( array_merge( array(), ...array_filter( self::$order_contexts ) ) ) );
	}
}
