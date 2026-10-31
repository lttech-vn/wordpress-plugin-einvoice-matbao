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

	/** Mat Bao `errorCode` for a duplicate reference. */
	public const API_DUPLICATE_REFERENCE = 305;

	/** Mat Bao `errorCode` when a lookup finds none of the references. */
	public const API_NOT_FOUND = 301;

	/**
	 * Creates the error.
	 *
	 * @param string   $kind        One of the KIND_* constants.
	 * @param string   $message     Secret-free message (Mat Bao `message` or a fixed text).
	 * @param int|null $api_code    Mat Bao `errorCode`, when present.
	 * @param int|null $http_status HTTP status, when a response was received.
	 * @param bool     $target_sent Whether the request the caller asked for (e.g. create-invoice) was sent. False
	 *                              when the call failed earlier, e.g. at login, so nothing can have been created.
	 */
	public function __construct(
		public readonly string $kind,
		string $message,
		public readonly ?int $api_code = null,
		public readonly ?int $http_status = null,
		public readonly bool $target_sent = true,
	) {
		parent::__construct( $message );
	}

	/**
	 * The same error, marked as raised before the requested call was sent.
	 */
	public function before_target_request(): self {
		return new self( $this->kind, $this->getMessage(), $this->api_code, $this->http_status, false );
	}

	/**
	 * Whether Mat Bao reported a duplicate reference (`MTChieu`, error 305).
	 */
	public function is_duplicate_reference(): bool {
		return self::KIND_API === $this->kind && self::API_DUPLICATE_REFERENCE === $this->api_code;
	}

	/**
	 * Whether the failure looks temporary, so trying again later can succeed: the request did not
	 * complete, a 5xx/408/429 status, a duplicate reference (settled by a lookup by `MTChieu`), or
	 * a response that could not be understood. Anything else (other 4xx, a rejected invoice, bad
	 * credentials) needs a person.
	 */
	public function is_retryable(): bool {
		if ( self::KIND_TRANSPORT === $this->kind || $this->is_duplicate_reference() ) {
			return true;
		}
		if ( self::KIND_HTTP === $this->kind ) {
			return $this->is_temporary_http_status();
		}

		return $this->is_unreadable_response();
	}

	/**
	 * Whether an HTTP error has a status worth retrying (5xx, 408 timeout, 429 rate limit).
	 */
	public function is_temporary_http_status(): bool {
		return self::KIND_HTTP === $this->kind
			&& null !== $this->http_status
			&& ( $this->http_status >= 500 || in_array( $this->http_status, array( 408, 429 ), true ) );
	}

	/**
	 * Whether a 2xx response could not be understood (no Mat Bao rejection code, or code 200).
	 */
	public function is_unreadable_response(): bool {
		return self::KIND_API === $this->kind && ( null === $this->api_code || 200 === $this->api_code );
	}

	/**
	 * Whether Mat Bao rejected the credentials or token.
	 */
	public function is_unauthorized(): bool {
		return 401 === $this->http_status || 401 === $this->api_code;
	}
}
