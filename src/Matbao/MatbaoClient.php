<?php
/**
 * HTTP client for the Mat Bao e-invoice proxy API.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Matbao;

use LTTech\EinvoiceMatbao\Encryption;

/**
 * Talks to Mat Bao through the WordPress HTTP API, which is the fake-able boundary in tests
 * (`pre_http_request`). The access token is cached in a transient, encrypted, and is never
 * logged or included in error messages.
 */
final class MatbaoClient {

	private const TOKEN_TRANSIENT_PREFIX = 'einvoice_matbao_token_';

	/** Upper bound for the cached token lifetime; the token expiry is also honoured when shorter. */
	private const MAX_TOKEN_TTL = HOUR_IN_SECONDS;

	/** Refresh the token this long before Mat Bao says it expires. */
	private const TOKEN_EXPIRY_MARGIN = 5 * MINUTE_IN_SECONDS;

	private const TIMEOUT_SECONDS = 30;

	/**
	 * Creates the client.
	 *
	 * @param MatbaoCredentials $credentials Login and environment.
	 * @param Encryption        $encryption  Encrypts the cached token.
	 */
	public function __construct(
		private readonly MatbaoCredentials $credentials,
		private readonly Encryption $encryption = new Encryption(),
	) {
	}

	/**
	 * Logs in with fresh credentials, ignoring any cached token, and caches the new token.
	 *
	 * @throws MatbaoError When login fails.
	 */
	public function test_connection(): void {
		$this->login();
	}

	/**
	 * Drops the cached token, e.g. before the credentials it was issued for are replaced.
	 */
	public function forget_token(): void {
		delete_transient( $this->token_transient() );
	}

	/**
	 * Invoice templates registered for a year.
	 *
	 * @param int $year Template year; Mat Bao series carry the year (e.g. C26...).
	 * @return list<MatbaoTemplate>
	 * @throws MatbaoError When the call fails.
	 */
	public function templates( int $year ): array {
		$data = $this->authorized( 'GET', '/api/invoice/templates?year=' . $year );

		if ( ! is_array( $data ) ) {
			throw new MatbaoError( MatbaoError::KIND_API, 'Unexpected templates response.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered (Admin\Fragment).
		}

		return array_values( array_map( array( MatbaoTemplate::class, 'from_api' ), array_filter( $data, 'is_array' ) ) );
	}

	/**
	 * Creates one invoice (`/api/invoice/create-invoice` takes a list; this sends a list of one).
	 *
	 * The envelope can succeed while the invoice itself is rejected (per-item `success` false), so
	 * the item result is checked too.
	 *
	 * @param array<string, mixed> $invoice Invoice payload, see Invoice\DraftPayloadBuilder.
	 * @return string `maSoHDon` (encrypted Mat Bao invoice ID) of the created invoice.
	 * @throws MatbaoError When the call fails or Mat Bao rejects the invoice.
	 */
	public function create_invoice( array $invoice ): string {
		$data = $this->authorized( 'POST', '/api/invoice/create-invoice', array( $invoice ) );

		$item = is_array( $data ) && is_array( $data[0] ?? null ) ? array_change_key_case( $data[0], CASE_LOWER ) : null;
		if ( null === $item ) {
			throw new MatbaoError( MatbaoError::KIND_API, 'Unexpected create-invoice response.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered.
		}

		$api_code = is_numeric( $item['errorcode'] ?? null ) ? (int) $item['errorcode'] : null;
		$message  = is_string( $item['message'] ?? null ) && '' !== $item['message'] ? $item['message'] : 'Invoice rejected.';
		if ( true !== ( $item['success'] ?? null ) ) {
			throw new MatbaoError( MatbaoError::KIND_API, $message, $api_code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Mat Bao message, escaped where rendered.
		}

		$created    = is_array( $item['data'] ?? null ) ? $item['data'] : array();
		$invoice_id = is_string( $created['maSoHDon'] ?? null ) ? $created['maSoHDon'] : '';
		if ( '' === $invoice_id ) {
			throw new MatbaoError( MatbaoError::KIND_API, 'Create-invoice response has no invoice ID.', $api_code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered.
		}

		return $invoice_id;
	}

	/**
	 * Looks up the invoices with a reference (`invoice-detail-batch`, `loai` 1 = by `MTChieu`).
	 * Every row Mat Bao returns is passed on; the caller decides whether one is its draft.
	 *
	 * @param string $reference `MTChieu`.
	 * @param string $form      `KHMSHDon` the invoice was sent with.
	 * @param string $series    `KHHDon` the invoice was sent with.
	 * @return list<MatbaoInvoice> Empty when Mat Bao has no such invoice.
	 * @throws MatbaoError When the call fails.
	 */
	public function find_invoices_by_reference( string $reference, string $form, string $series ): array {
		try {
			$data = $this->authorized(
				'POST',
				'/api/invoice/invoice-detail-batch',
				array(
					'loai'     => 1,
					'mauSo'    => $form,
					'kiHieu'   => $series,
					'danhSach' => array( $reference ),
				)
			);
		} catch ( MatbaoError $error ) {
			if ( MatbaoError::API_NOT_FOUND === $error->api_code ) {
				return array();
			}
			throw $error;
		}

		$invoices = is_array( $data ) && is_array( $data['invoices'] ?? null ) ? $data['invoices'] : array();

		return array_values( array_map( array( MatbaoInvoice::class, 'from_api' ), array_filter( $invoices, 'is_array' ) ) );
	}

	/**
	 * Calls an endpoint with the cached token, logging in again once if Mat Bao rejects it.
	 *
	 * @param string            $method HTTP method.
	 * @param string            $path   Path with query string.
	 * @param array<mixed>|null $body   JSON body.
	 * @return mixed Decoded `data` of the response.
	 * @throws MatbaoError When the call fails; errors from logging in are marked as raised before the call was sent.
	 */
	private function authorized( string $method, string $path, ?array $body = null ): mixed {
		$token = $this->cached_token() ?? $this->login_before_call();

		try {
			return $this->request( $method, $path, $body, $token );
		} catch ( MatbaoError $error ) {
			if ( ! $error->is_unauthorized() ) {
				throw $error;
			}

			return $this->request( $method, $path, $body, $this->login_before_call() );
		}
	}

	/**
	 * Logs in for another call, marking a failure as "that call was not sent".
	 *
	 * @throws MatbaoError When login fails.
	 */
	private function login_before_call(): string {
		try {
			return $this->login();
		} catch ( MatbaoError $error ) {
			throw $error->before_target_request();
		}
	}

	/**
	 * Logs in and caches the token.
	 *
	 * @return string Access token.
	 * @throws MatbaoError When login fails.
	 */
	private function login(): string {
		$this->forget_token();

		$data = $this->request(
			'POST',
			'/api/auth/login',
			array(
				'MST'    => $this->credentials->mst,
				'TDNhap' => $this->credentials->username,
				'MKhau'  => $this->credentials->password,
			)
		);

		$token = is_array( $data ) && is_string( $data['accessToken'] ?? null ) ? $data['accessToken'] : '';
		if ( '' === $token ) {
			throw new MatbaoError( MatbaoError::KIND_API, 'Login response has no access token.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered (Admin\Fragment).
		}

		$this->cache_token( $token, is_string( $data['expiredDate'] ?? null ) ? $data['expiredDate'] : '' );

		return $token;
	}

	/**
	 * Sends one request and unwraps the `{success, errorCode, message, data}` envelope.
	 *
	 * @param string            $method HTTP method.
	 * @param string            $path   Path with query string.
	 * @param array<mixed>|null $body   JSON body.
	 * @param string|null       $token  Bearer token.
	 * @return mixed Decoded `data`.
	 * @throws MatbaoError When the call fails.
	 */
	private function request( string $method, string $path, ?array $body, ?string $token = null ): mixed {
		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);
		if ( null !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => self::TIMEOUT_SECONDS,
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['body'] = (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->credentials->base_url() . $path, $args );

		if ( is_wp_error( $response ) ) {
			// The WP_Error message may echo request details; keep only its code.
			throw new MatbaoError( MatbaoError::KIND_TRANSPORT, 'Request did not complete (' . $response->get_error_code() . ').' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered (Admin\Fragment).
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$envelope = json_decode( wp_remote_retrieve_body( $response ), true );
		$envelope = is_array( $envelope ) ? array_change_key_case( $envelope, CASE_LOWER ) : array();
		$api_code = is_numeric( $envelope['errorcode'] ?? null ) ? (int) $envelope['errorcode'] : null;
		$message  = is_string( $envelope['message'] ?? null ) ? $envelope['message'] : '';

		if ( $status < 200 || $status >= 300 ) {
			throw new MatbaoError( MatbaoError::KIND_HTTP, '' !== $message ? $message : 'HTTP ' . $status, $api_code, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered (Admin\Fragment).
		}

		if ( true !== ( $envelope['success'] ?? null ) ) {
			throw new MatbaoError( MatbaoError::KIND_API, '' !== $message ? $message : 'Unexpected response.', $api_code, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, escaped where rendered (Admin\Fragment).
		}

		return $envelope['data'] ?? null;
	}

	/**
	 * Decrypted cached token, or null.
	 */
	private function cached_token(): ?string {
		$cached = get_transient( $this->token_transient() );

		return is_string( $cached ) ? $this->encryption->decrypt( $cached ) : null;
	}

	/**
	 * Caches the token encrypted. Without an encryption key the token is not cached.
	 *
	 * @param string $token        Access token.
	 * @param string $expired_date `expiredDate` from the login response, Vietnam local time.
	 */
	private function cache_token( string $token, string $expired_date ): void {
		if ( ! $this->encryption->is_available() ) {
			return;
		}

		$ttl = self::MAX_TOKEN_TTL;
		$exp = date_create_immutable( $expired_date, new \DateTimeZone( 'Asia/Ho_Chi_Minh' ) );
		if ( '' !== $expired_date && false !== $exp ) {
			$ttl = min( $ttl, $exp->getTimestamp() - time() - self::TOKEN_EXPIRY_MARGIN );
		}

		if ( $ttl > 0 ) {
			set_transient( $this->token_transient(), $this->encryption->encrypt( $token ), $ttl );
		}
	}

	/**
	 * Transient name bound to the environment and login, so changing credentials drops the token.
	 */
	private function token_transient(): string {
		return self::TOKEN_TRANSIENT_PREFIX . md5( $this->credentials->environment . '|' . $this->credentials->mst . '|' . $this->credentials->username );
	}
}
