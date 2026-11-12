<?php
/**
 * Reports a failure that business code caught and logged.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

/**
 * One call for every place that catches a Throwable and keeps going: an issue with a
 * stacktrace plus an `error` log, both tagged with the component and, when known, the order.
 * Callers keep their own error_log line, so a site without monitoring behaves as before.
 * With an order, the report runs in that order's context: catch blocks often sit outside the
 * caller's own context, and the message may still echo the buyer.
 */
final class CaughtFailure {

	/**
	 * Reports the failure; never throws.
	 *
	 * @param \Throwable $failure   Caught exception.
	 * @param string     $component Tag naming the code that caught it, for example `draft_workflow`.
	 * @param string     $what      Fixed description of what failed, without data.
	 * @param int|null   $order_id  Order being processed, when known.
	 */
	public static function report( \Throwable $failure, string $component, string $what, ?int $order_id = null ): void {
		$tags = array( 'component' => $component );
		if ( null !== $order_id ) {
			$tags['order_id'] = (string) $order_id;
		}

		$contexts = null !== $order_id ? static fn(): array => InvoiceContext::for_order_or_empty( $order_id ) : null;

		$send = static function () use ( $failure, $what, $tags, $contexts ): void {
			Monitor::capture_exception( $failure, $tags, $contexts );
			Monitor::log( 'error', $what, array_merge( $tags, array( 'exception.type' => get_class( $failure ) ) ) );
		};

		if ( null === $order_id ) {
			$send();
			return;
		}
		Monitor::with_order_context( $order_id, $send );
	}
}
