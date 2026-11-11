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
	 * Reports an exception as an issue.
	 *
	 * @param \Throwable           $e    Exception.
	 * @param array<string, mixed> $tags Tags; only allow-listed ones are sent.
	 * @return string|null Event ID, or null when nothing was sent.
	 */
	public static function capture_exception( \Throwable $e, array $tags = array() ): ?string {
		return self::capture( static fn( Backend $backend ): ?string => $backend->capture_exception( $e, $tags, self::known_values() ) );
	}

	/**
	 * Reports an invoice that needs an administrator, grouped by error code.
	 *
	 * @param InvoiceRecord    $i Invoice.
	 * @param InvoiceErrorCode $c Error that needs attention.
	 */
	public static function capture_needs_attention( InvoiceRecord $i, InvoiceErrorCode $c ): void {
		self::capture(
			static fn( Backend $backend ): ?string => $backend->capture_message(
				'Invoice needs attention: ' . $c->value,
				'warning',
				array(
					'order_id'       => (string) $i->order_id,
					'error_code'     => $c->value,
					'error_kind'     => $c->kind()->value,
					'invoice.status' => $i->status,
				),
				array( 'needs-attention', $c->value ),
				self::known_values()
			)
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
	 * Opens a transaction.
	 *
	 * @param string $name Transaction name.
	 * @param string $op   Operation, for example `queue.task`.
	 */
	public static function start_transaction( string $name, string $op ): void {
		self::capture(
			static function ( Backend $backend ) use ( $name, $op ): void {
				$backend->start_transaction( $name, $op, self::known_values() );
			}
		);
	}

	/**
	 * Closes the open transaction.
	 *
	 * @param string $status `ok`, `internal_error`, ...
	 */
	public static function finish_transaction( string $status = 'ok' ): void {
		// Inside an unreadable order context the matching start was dropped, so this is too.
		self::capture(
			static function ( Backend $backend ) use ( $status ): void {
				$backend->finish_transaction( $status );
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
	 * Sends the settings-page test event.
	 *
	 * @return string|null Event ID when the server accepted it.
	 */
	public static function send_test_event(): ?string {
		return self::capture( static fn( Backend $backend ): ?string => $backend->capture_message( self::TEST_EVENT_MESSAGE, 'info', array(), array(), array() ) );
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
	 * Forgets the backend so the next call reads the configuration again, or injects one.
	 *
	 * @param Backend|null $b Backend to use, or null to resolve it from the configuration.
	 */
	public static function reset_for_tests( ?Backend $b = null ): void {
		self::$backend        = $b;
		self::$resolved       = null !== $b;
		self::$order_contexts = array();
		self::$slug           = null === $b ? '' : MonitorSlug::for_host( MonitoringConfig::current()->site );
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
