<?php
/**
 * Failure sending a Lark message.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Lark;

use RuntimeException;

/**
 * Lark rejected a message or could not be reached. The message never contains the webhook URL.
 */
final class LarkError extends RuntimeException {

	/**
	 * Creates the error.
	 *
	 * @param string   $message   Secret-free message.
	 * @param int|null $lark_code Lark `code`, when present.
	 */
	public function __construct( string $message, public readonly ?int $lark_code = null ) {
		parent::__construct( $message );
	}
}
