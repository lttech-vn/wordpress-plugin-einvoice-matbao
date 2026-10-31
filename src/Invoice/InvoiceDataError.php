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
 * admin-facing reason without personal data.
 */
final class InvoiceDataError extends RuntimeException {
}
