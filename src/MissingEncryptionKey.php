<?php
/**
 * Raised when a secret must be stored but no encryption key is configured.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao;

use RuntimeException;

/**
 * The `EINVOICE_MATBAO_ENCRYPTION_KEY` constant is missing or too short.
 */
final class MissingEncryptionKey extends RuntimeException {

	/**
	 * Creates the exception with a fixed, secret-free message.
	 */
	public function __construct() {
		parent::__construct( 'Encryption key constant ' . Encryption::KEY_CONSTANT . ' is missing or too short.' );
	}
}
