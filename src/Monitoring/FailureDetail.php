<?php
/**
 * Extra monitoring context of one invoice failure.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Matbao\MatbaoError;

/**
 * Context blocks and tags that a failure site hands to the needs-attention event of the same
 * order and error code, through Monitor::with_failure_detail().
 */
final class FailureDetail {

	/**
	 * Creates the detail.
	 *
	 * @param int                                 $order_id Order ID.
	 * @param InvoiceErrorCode                    $code     Error code the detail belongs to.
	 * @param array<string, array<string, mixed>> $contexts Context blocks by name.
	 * @param array<string, string>               $tags     Extra tags.
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly InvoiceErrorCode $code,
		public readonly array $contexts,
		public readonly array $tags,
	) {}

	/**
	 * Detail of a failed Mat Bao call.
	 *
	 * @param int              $order_id Order ID.
	 * @param InvoiceErrorCode $code     Error code the failure was classified as.
	 * @param MatbaoError      $error    Mat Bao error.
	 */
	public static function for_matbao( int $order_id, InvoiceErrorCode $code, MatbaoError $error ): self {
		$tags = array();
		if ( null !== $error->api_code ) {
			$tags['matbao.api_code'] = (string) $error->api_code;
		}

		return new self(
			$order_id,
			$code,
			array(
				'matbao' => array(
					'kind'        => $error->kind,
					'http_status' => $error->http_status,
					'api_code'    => $error->api_code,
					'target_sent' => $error->target_sent,
					'auto_retry'  => $code->is_auto_retryable(),
				),
			),
			$tags
		);
	}
}
