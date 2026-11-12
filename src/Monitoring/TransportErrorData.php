<?php
/**
 * Span data of an HTTP call that failed before any response.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

use WP_Error;

/**
 * `error.type` is the WP_Error code, or `timeout` when the transport gave up waiting. The message
 * is only inspected, never copied, so no URL or host reaches the span.
 */
final class TransportErrorData {

	public const TIMEOUT = 'timeout';

	/**
	 * Span data for a failed wp_remote_*() call.
	 *
	 * @param WP_Error $error Transport failure.
	 * @return array{'error.type': string}
	 */
	public static function of( WP_Error $error ): array {
		$message = strtolower( $error->get_error_message() );
		$timeout = str_contains( $message, 'curl error 28' ) || str_contains( $message, 'timed out' );

		return array( 'error.type' => $timeout ? self::TIMEOUT : (string) $error->get_error_code() );
	}
}
