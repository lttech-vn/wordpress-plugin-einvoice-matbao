<?php
/**
 * Kind, status and auto-retry decision of every invoice error code.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Matbao\MatbaoError;
use WP_UnitTestCase;

/**
 * Expectations are literal stored values, so a change to the classification shows up here.
 */
final class InvoiceErrorCodeTest extends WP_UnitTestCase {

	/**
	 * Every code with its stored kind, the invoice status it sets and whether a background
	 * retry may resend the draft.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
	 */
	public static function codes(): array {
		return array(
			'currency_not_vnd'           => array( 'currency_not_vnd', 'data', 'failed', false ),
			'no_lines'                   => array( 'no_lines', 'data', 'failed', false ),
			'line_type_unsupported'      => array( 'line_type_unsupported', 'data', 'failed', false ),
			'line_amount_invalid'        => array( 'line_amount_invalid', 'data', 'failed', false ),
			'amount_not_whole_vnd'       => array( 'amount_not_whole_vnd', 'data', 'failed', false ),
			'line_tax_rate_count'        => array( 'line_tax_rate_count', 'data', 'failed', false ),
			'tax_rate_not_stored'        => array( 'tax_rate_not_stored', 'data', 'failed', false ),
			'tax_rate_unsupported'       => array( 'tax_rate_unsupported', 'data', 'failed', false ),
			'untaxed_line_with_tax'      => array( 'untaxed_line_with_tax', 'data', 'failed', false ),
			'totals_mismatch'            => array( 'totals_mismatch', 'data', 'failed', false ),
			'reference_too_long'         => array( 'reference_too_long', 'data', 'failed', false ),
			'credentials_missing'        => array( 'credentials_missing', 'config', 'not_configured', false ),
			'template_missing'           => array( 'template_missing', 'config', 'not_configured', false ),
			'template_unavailable'       => array( 'template_unavailable', 'config', 'not_configured', false ),
			'template_rejected'          => array( 'template_rejected', 'config', 'not_configured', false ),
			'order_not_completed'        => array( 'order_not_completed', 'order', 'stopped', false ),
			'order_refunded'             => array( 'order_refunded', 'order', 'stopped', false ),
			'matbao_transport'           => array( 'matbao_transport', 'matbao', 'failed', true ),
			'matbao_http_temporary'      => array( 'matbao_http_temporary', 'matbao', 'failed', true ),
			'matbao_response_unreadable' => array( 'matbao_response_unreadable', 'matbao', 'failed', true ),
			'matbao_http'                => array( 'matbao_http', 'matbao', 'failed', false ),
			'matbao_api'                 => array( 'matbao_api', 'matbao', 'failed', false ),
			'matbao_duplicate_reference' => array( 'matbao_duplicate_reference', 'matbao', 'failed', true ),
			'queue_failed'               => array( 'queue_failed', 'system', 'failed', false ),
			'unexpected'                 => array( 'unexpected', 'system', 'failed', false ),
		);
	}

	/**
	 * Failed Mat Bao calls with the code they are recorded as.
	 *
	 * @return array<string, array{0: MatbaoError, 1: string}>
	 */
	public static function matbao_errors(): array {
		return array(
			'request did not complete'           => array( new MatbaoError( MatbaoError::KIND_TRANSPORT, 'Request did not complete (http_request_failed).' ), 'matbao_transport' ),
			'HTTP 500'                           => array( new MatbaoError( MatbaoError::KIND_HTTP, 'HTTP 500', null, 500 ), 'matbao_http_temporary' ),
			'HTTP 503'                           => array( new MatbaoError( MatbaoError::KIND_HTTP, 'HTTP 503', null, 503 ), 'matbao_http_temporary' ),
			'HTTP 408'                           => array( new MatbaoError( MatbaoError::KIND_HTTP, 'HTTP 408', null, 408 ), 'matbao_http_temporary' ),
			'HTTP 429'                           => array( new MatbaoError( MatbaoError::KIND_HTTP, 'HTTP 429', null, 429 ), 'matbao_http_temporary' ),
			'HTTP 400'                           => array( new MatbaoError( MatbaoError::KIND_HTTP, 'HTTP 400', 400, 400 ), 'matbao_http' ),
			'HTTP 401'                           => array( new MatbaoError( MatbaoError::KIND_HTTP, 'Unauthorized', null, 401 ), 'matbao_http' ),
			'HTTP 404'                           => array( new MatbaoError( MatbaoError::KIND_HTTP, 'HTTP 404', null, 404 ), 'matbao_http' ),
			'unreadable body'                    => array( new MatbaoError( MatbaoError::KIND_API, 'Unexpected response.', null, 200 ), 'matbao_response_unreadable' ),
			'create response without an ID'      => array( new MatbaoError( MatbaoError::KIND_API, 'Create-invoice response has no invoice ID.', 200 ), 'matbao_response_unreadable' ),
			'invoice rejected by Mat Bao'        => array( new MatbaoError( MatbaoError::KIND_API, 'Sai thông tin', 400 ), 'matbao_api' ),
			'login rejected inside the envelope' => array( new MatbaoError( MatbaoError::KIND_API, 'Sai mật khẩu', 401, 200 ), 'matbao_api' ),
			'template not found (308)'           => array( new MatbaoError( MatbaoError::KIND_API, 'Không tìm thấy mẫu số kí hiệu', 308 ), 'template_rejected' ),
			'template out of numbers (327)'      => array( new MatbaoError( MatbaoError::KIND_API, 'Mẫu hóa đơn đã hết số', 327 ), 'template_rejected' ),
			'template code on an HTTP error'     => array( new MatbaoError( MatbaoError::KIND_HTTP, 'Không tìm thấy mẫu số kí hiệu', 308, 400 ), 'template_rejected' ),
			'duplicate reference (305)'          => array( new MatbaoError( MatbaoError::KIND_API, 'Trùng mã tham chiếu', 305 ), 'matbao_duplicate_reference' ),
		);
	}

	public function test_the_table_lists_every_code(): void {
		$listed = array_column( self::codes(), 0 );
		$cases  = array_map( static fn( InvoiceErrorCode $code ): string => $code->value, InvoiceErrorCode::cases() );

		sort( $listed );
		sort( $cases );
		$this->assertSame( $listed, $cases );
		$this->assertCount( 25, $cases );
	}

	/**
	 * Kind, status and retry decision of one code.
	 *
	 * @dataProvider codes
	 *
	 * @param string $value     Stored code.
	 * @param string $kind      Stored kind.
	 * @param string $status    Invoice status.
	 * @param bool   $retryable Whether a background retry may resend.
	 */
	public function test_code_has_its_kind_status_and_retry_decision( string $value, string $kind, string $status, bool $retryable ): void {
		$code = InvoiceErrorCode::from( $value );

		$this->assertSame( $kind, $code->kind()->value );
		$this->assertSame( $status, $code->kind()->status() );
		$this->assertSame( $retryable, $code->is_auto_retryable() );
		$this->assertNotSame( '', $code->label() );
	}

	/**
	 * Code of one failed Mat Bao call.
	 *
	 * @dataProvider matbao_errors
	 *
	 * @param MatbaoError $error    Failed call.
	 * @param string      $expected Stored code.
	 */
	public function test_matbao_error_maps_to_its_code( MatbaoError $error, string $expected ): void {
		$code = InvoiceErrorCode::from_matbao( $error );

		$this->assertSame( $expected, $code->value );
		if ( 'template_rejected' !== $expected ) {
			$this->assertSame( $error->is_retryable(), $code->is_auto_retryable(), 'MatbaoError and InvoiceErrorCode disagree on retry' );
		}
	}

	/**
	 * A duplicate reference has its own code and is retried, because the retry first looks the
	 * invoice up by `MTChieu` and adopts the existing draft (DraftInvoiceRetryTest).
	 */
	public function test_duplicate_reference_305_has_its_own_code_and_is_retried_through_the_lookup(): void {
		$error = new MatbaoError( MatbaoError::KIND_API, 'Trùng mã tham chiếu', 305 );
		$code  = InvoiceErrorCode::from_matbao( $error );

		$this->assertSame( 'matbao_duplicate_reference', $code->value );
		$this->assertTrue( $code->is_auto_retryable() );
		$this->assertTrue( $error->is_retryable() );
	}
}
