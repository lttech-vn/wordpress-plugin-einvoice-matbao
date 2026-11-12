<?php
/**
 * Boundary policy for failures of the monitoring code itself.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

/**
 * Monitoring failures are never sent to the monitoring server and never reach business code;
 * only the exception class is logged, because messages may contain personal data.
 */
final class InternalFailure {

	/**
	 * Logs a monitoring failure by class name only.
	 *
	 * @param \Throwable $failure Failure.
	 */
	public static function report( \Throwable $failure ): void {
		error_log( 'einvoice-matbao: monitoring failed (' . get_class( $failure ) . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
