<?php
/**
 * Failure talking to the Mat Bao API.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Matbao;

use RuntimeException;

/**
 * A failed Mat Bao call. The message never contains credentials or tokens.
 */
final class MatbaoError extends RuntimeException {

	/** The request did not complete (DNS, TLS, timeout...). The outcome is unknown. */
	public const KIND_TRANSPORT = 'transport';

	/** Non-2xx HTTP status. */
	public const KIND_HTTP = 'http';

	/** HTTP 2xx but `success` false, or an unreadable body. */
	public const KIND_API = 'api';

	/**
	 * Creates the error.
	 *
	 * @param string   $kind        One of the KIND_* constants.
	 * @param string   $message     Secret-free message (Mat Bao `message` or a fixed text).
	 * @param int|null $api_code    Mat Bao `errorCode`, when present.
	 * @param int|null $http_status HTTP status, when a response was received.
	 */
	public function __construct(
		public readonly string $kind,
		string $message,
		public readonly ?int $api_code = null,
		public readonly ?int $http_status = null,
	) {
		parent::__construct( $message );
	}

	/**
	 * Whether Mat Bao rejected the credentials or token.
	 */
	public function is_unauthorized(): bool {
		return 401 === $this->http_status || 401 === $this->api_code;
	}
}
