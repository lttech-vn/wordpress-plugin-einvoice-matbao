<?php
/**
 * Order data that cannot be turned into a correct invoice.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

use RuntimeException;

/**
 * Raised by the payload builder instead of sending a wrong draft. The message is a Vietnamese,
 * admin-facing reason without personal data; the code classifies it (always an
 * InvoiceErrorKind::DATA code).
 */
final class InvoiceDataError extends RuntimeException {

	/**
	 * Creates the error.
	 *
	 * @param InvoiceErrorCode $error_code Stable code.
	 * @param string           $message    Vietnamese reason.
	 */
	public function __construct(
		public readonly InvoiceErrorCode $error_code,
		string $message,
	) {
		parent::__construct( $message );
	}
}
