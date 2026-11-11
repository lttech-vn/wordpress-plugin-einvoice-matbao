<?php
/**
 * Contract between the Monitor facade and the error-monitoring SDK adapter.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

/**
 * What Monitor needs from a monitoring backend. Implementations may throw; Monitor swallows
 * every failure that is not the caller's own exception.
 */
interface Backend {

	/**
	 * Reports an exception as an issue.
	 *
	 * @param \Throwable           $e     Exception to report.
	 * @param array<string, mixed> $tags  Event tags (filtered by the allowlist).
	 * @param array<int, string>   $known Known personal values to mask in this event.
	 * @return string|null Event ID, or null when nothing was sent.
	 */
	public function capture_exception( \Throwable $e, array $tags, array $known ): ?string;

	/**
	 * Reports a message as an issue.
	 *
	 * @param string               $msg         Message.
	 * @param string               $level       Sentry severity (`debug`, `info`, `warning`, `error`, `fatal`).
	 * @param array<string, mixed> $tags        Event tags (filtered by the allowlist).
	 * @param array<int, string>   $fingerprint Grouping fingerprint; empty for the default grouping.
	 * @param array<int, string>   $known       Known personal values to mask in this event.
	 * @return string|null Event ID, or null when nothing was sent.
	 */
	public function capture_message( string $msg, string $level, array $tags, array $fingerprint, array $known ): ?string;

	/**
	 * Opens a transaction; an open one is closed first.
	 *
	 * @param string $name Transaction name.
	 * @param string $op   Operation, for example `queue.task`.
	 */
	public function start_transaction( string $name, string $op ): void;

	/**
	 * Closes the open transaction, if any, and sends it when sampled.
	 *
	 * @param string $status Span status, for example `ok` or `internal_error`.
	 */
	public function finish_transaction( string $status ): void;

	/**
	 * Runs $callback inside a child span of the open transaction and returns its result. An exception
	 * of $callback closes the span with `internal_error` and is rethrown unchanged.
	 *
	 * @param string             $op             Span operation, for example `http.client`.
	 * @param string             $description    Span description.
	 * @param callable           $callback       Code to measure; called exactly once.
	 * @param callable           $data_of_result Maps the result of $callback to span data.
	 * @param array<int, string> $known          Known personal values to mask in the description and data.
	 * @return mixed Result of $callback.
	 */
	public function span( string $op, string $description, callable $callback, callable $data_of_result, array $known ): mixed;

	/**
	 * Buffers a structured log; flush() sends the buffer.
	 *
	 * @param string               $level Log level (`trace`, `debug`, `info`, `warn`, `error`, `fatal`).
	 * @param string               $msg   Log body.
	 * @param array<string, mixed> $attrs Log attributes.
	 * @param array<int, string>   $known Known personal values to mask in this log.
	 */
	public function log( string $level, string $msg, array $attrs, array $known ): void;

	/**
	 * Sends a cron monitor check-in.
	 *
	 * The schedule array has the keys `interval` (int), `unit` (`minute`, `hour`, `day`, `week`,
	 * `month` or `year`), `checkin_margin`, `max_runtime`, `failure_issue_threshold`,
	 * `recovery_threshold` (int, minutes or counts) and `timezone` (string).
	 *
	 * @param string               $slug     Monitor slug.
	 * @param string               $status   `in_progress`, `ok` or `error`.
	 * @param string|null          $id       Check-in ID of the matching `in_progress` check-in.
	 * @param float|null           $duration Run time in seconds.
	 * @param array<string, mixed> $schedule Monitor configuration, see above.
	 * @return string|null Check-in ID.
	 */
	public function check_in( string $slug, string $status, ?string $id, ?float $duration, array $schedule ): ?string;

	/**
	 * Sends buffered logs.
	 */
	public function flush(): void;
}
