<?php
/**
 * PDF download of an issued invoice (`download-invoice`).
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API carries the PDF as base64.

use LTTech\EinvoiceMatbao\Matbao\MatbaoClient;
use LTTech\EinvoiceMatbao\Matbao\MatbaoCredentials;
use LTTech\EinvoiceMatbao\Matbao\MatbaoError;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * Mat Bao is faked at the HTTP boundary (`POST /api/invoice/download-invoice`).
 */
final class MatbaoDownloadInvoiceTest extends SettingsRestTestCase {

	private const ROUTE = 'POST /api/invoice/download-invoice';
	private const PDF   = "%PDF-1.4\n%fake\n";

	public function set_up(): void {
		parent::set_up();

		$this->with_encryption_key();
		$this->fake_matbao_login_ok();
	}

	public function test_pdf_returned(): void {
		$this->answer_data( array( 'data_PDF_Base64' => base64_encode( self::PDF ) ) );

		$this->assertSame( self::PDF, $this->client()->download_invoice_pdf( 'abc', 'FM80520C49' ) );

		$requests = $this->http->requests_to( self::ROUTE );
		$this->assertCount( 1, $requests );
		$this->assertSame(
			array(
				'MaTraCuu' => 'FM80520C49',
				'MaSoHDon' => 'abc',
			),
			$requests[0]['body']
		);
	}

	public function test_field_name_casing(): void {
		$this->answer_data( array( 'Data_PDF_Base64' => base64_encode( self::PDF ) ) );

		$this->assertSame( self::PDF, $this->client()->download_invoice_pdf( 'abc', 'FM80520C49' ) );
	}

	public function test_missing_pdf_field(): void {
		$this->answer_data( array() );

		$this->assert_api_error();
	}

	public function test_invalid_base64(): void {
		$this->answer_data( array( 'data_PDF_Base64' => '%%%' ) );

		$this->assert_api_error();
	}

	public function test_not_a_pdf(): void {
		$this->answer_data( array( 'data_PDF_Base64' => base64_encode( 'hello' ) ) );

		$this->assert_api_error();
	}

	public function test_download_rejected(): void {
		$this->http->respond_json(
			self::ROUTE,
			200,
			array(
				'errorCode' => 301,
				'message'   => 'Không tìm thấy hóa đơn',
				'data'      => null,
				'success'   => false,
			)
		);

		$error = $this->assert_api_error();
		$this->assertSame( 301, $error->api_code );
	}

	public function test_download_relogin_on_401(): void {
		$calls = 0;
		$this->http->respond_with(
			self::ROUTE,
			function () use ( &$calls ): array {
				++$calls;
				if ( 1 === $calls ) {
					return FakeHttp::json_response( 401, array( 'message' => 'expired' ) );
				}

				return FakeHttp::json_response( 200, $this->envelope( array( 'data_PDF_Base64' => base64_encode( self::PDF ) ) ) );
			}
		);

		$this->assertSame( self::PDF, $this->client()->download_invoice_pdf( 'abc', 'FM80520C49' ) );
		$this->assertCount( 2, $this->http->requests_to( self::ROUTE ) );
		$this->assertCount( 2, $this->http->requests_to( 'POST /api/auth/login' ) );
	}

	/**
	 * Malformed `data` members of the answer.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformed_data(): array {
		return array(
			'data null'          => array( null ),
			'data scalar'        => array( 'x' ),
			'pdf field is array' => array( array( 'data_PDF_Base64' => array( 'SECRETBODY' ) ) ),
			'pdf field is int'   => array( array( 'data_PDF_Base64' => 12345 ) ),
		);
	}

	/**
	 * Every malformed shape fails as an `api` error without echoing the body.
	 *
	 * @dataProvider malformed_data
	 * @param mixed $data `data` member of the answer.
	 */
	public function test_malformed_shapes( mixed $data ): void {
		$this->answer_data( $data );

		$error = $this->assert_api_error();
		$this->assertStringNotContainsString( 'SECRETBODY', $error->getMessage() );
		$this->assertStringNotContainsString( '12345', $error->getMessage() );
	}

	/**
	 * Runs the download and asserts it fails with an `api` error.
	 */
	private function assert_api_error(): MatbaoError {
		try {
			$this->client()->download_invoice_pdf( 'abc', 'FM80520C49' );
		} catch ( MatbaoError $error ) {
			$this->assertSame( MatbaoError::KIND_API, $error->kind );

			return $error;
		}
		$this->fail( 'Expected a MatbaoError.' );
	}

	/**
	 * Wraps data in the success envelope.
	 *
	 * @param mixed $data `data` member.
	 * @return array<string, mixed>
	 */
	private function envelope( mixed $data ): array {
		return array(
			'errorCode' => 200,
			'message'   => 'Thành công',
			'data'      => $data,
			'success'   => true,
		);
	}

	/**
	 * Answers every download with a success envelope.
	 *
	 * @param mixed $data `data` member.
	 */
	private function answer_data( mixed $data ): void {
		$this->http->respond_json( self::ROUTE, 200, $this->envelope( $data ) );
	}

	private function client(): MatbaoClient {
		return new MatbaoClient( new MatbaoCredentials( 'demo', self::MST, self::USERNAME, self::PASSWORD ) );
	}
}
