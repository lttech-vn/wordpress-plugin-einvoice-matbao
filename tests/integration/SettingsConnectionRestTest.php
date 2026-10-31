<?php
/**
 * Connection checks for Mat Bao and Lark through the settings REST routes (fake HTTP).
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;
use WP_Error;

/**
 * The "Kiểm tra kết nối" buttons report the outcome without leaking secrets.
 */
final class SettingsConnectionRestTest extends SettingsRestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->login_as( 'administrator' );
		$this->with_encryption_key();
	}

	public function test_matbao_test_without_credentials_asks_to_save_first(): void {
		$response = $this->htmx( 'POST', '/settings/matbao/test' );

		$this->assertSame( 422, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-status="error"', $this->html( $response ) );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_matbao_test_reports_success_and_logs_in_to_the_selected_environment(): void {
		$this->save_matbao_credentials();
		$this->fake_matbao_login_ok();

		$response = $this->htmx( 'POST', '/settings/matbao/test' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-status="success"', $this->html( $response ) );

		$logins = $this->http->requests_to( 'POST /api/auth/login' );
		$this->assertCount( 1, $logins );
		$this->assertStringStartsWith( 'https://demo-api-hddt.matbao.in:11443/', $logins[0]['url'] );
		$this->assertSame(
			array(
				'MST'    => self::MST,
				'TDNhap' => self::USERNAME,
				'MKhau'  => self::PASSWORD,
			),
			$logins[0]['body']
		);
	}

	public function test_matbao_test_reports_rejected_credentials_without_echoing_them(): void {
		$this->save_matbao_credentials();
		$this->http->respond_json(
			'POST /api/auth/login',
			200,
			array(
				'errorCode' => 401,
				'message'   => 'Xác thực tài khoản không hợp lệ',
				'data'      => null,
				'success'   => false,
			)
		);

		$response = $this->htmx( 'POST', '/settings/matbao/test' );

		$this->assertSame( 200, $response->get_status() );
		$html = $this->html( $response );
		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringContainsString( '401', $html );
		$this->assertStringNotContainsString( self::PASSWORD, $html );
	}

	public function test_matbao_test_reports_unreachable_server(): void {
		$this->save_matbao_credentials();
		$this->http->respond_with( 'POST /api/auth/login', static fn(): WP_Error => new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		$html = $this->html( $this->htmx( 'POST', '/settings/matbao/test' ) );

		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringContainsString( 'Không kết nối được', $html );
	}

	public function test_matbao_test_always_logs_in_fresh_and_caches_the_token_encrypted(): void {
		$this->save_matbao_credentials();
		$this->fake_matbao_login_ok( 'fake-token-abc' );

		$this->htmx( 'POST', '/settings/matbao/test' );
		$this->htmx( 'POST', '/settings/matbao/test' );

		$this->assertCount( 2, $this->http->requests_to( 'POST /api/auth/login' ) );
		$this->assertNotSame( '', $this->stored_transients() );
		$this->assertStringNotContainsString( 'fake-token-abc', $this->stored_transients() );
	}

	public function test_lark_test_without_webhook_asks_to_save_first(): void {
		$response = $this->htmx( 'POST', '/settings/lark/test' );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_lark_test_sends_a_signed_message_without_personal_data(): void {
		$this->htmx(
			'POST',
			'/settings/lark',
			array(
				'webhook_url' => self::LARK_WEBHOOK,
				'secret'      => self::LARK_SECRET,
			)
		);
		$path = (string) wp_parse_url( self::LARK_WEBHOOK, PHP_URL_PATH );
		$this->http->respond_json(
			'POST ' . $path,
			200,
			array(
				'code' => 0,
				'msg'  => 'success',
				'data' => array(),
			)
		);

		$response = $this->htmx( 'POST', '/settings/lark/test' );

		$this->assertSame( 200, $response->get_status() );
		$html = $this->html( $response );
		$this->assertStringContainsString( 'data-einv-status="success"', $html );
		$this->assertStringNotContainsString( 'fake-hook', $html );

		$sent = $this->http->requests_to( 'POST ' . $path );
		$this->assertCount( 1, $sent );
		$body = $sent[0]['body'];
		$this->assertSame( 'text', $body['msg_type'] );
		$this->assertIsString( $body['content']['text'] );
		$timestamp = (string) $body['timestamp'];
		$this->assertSame( base64_encode( hash_hmac( 'sha256', '', $timestamp . "\n" . self::LARK_SECRET, true ) ), $body['sign'] );
	}

	public function test_lark_test_reports_rejection_without_echoing_the_webhook(): void {
		$this->htmx( 'POST', '/settings/lark', array( 'webhook_url' => self::LARK_WEBHOOK ) );
		$this->http->respond_json(
			'POST ' . (string) wp_parse_url( self::LARK_WEBHOOK, PHP_URL_PATH ),
			200,
			array(
				'code' => 19021,
				'msg'  => 'sign match fail or timestamp is not within one hour from current time',
			)
		);

		$html = $this->html( $this->htmx( 'POST', '/settings/lark/test' ) );

		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringContainsString( '19021', $html );
		$this->assertStringNotContainsString( 'fake-hook', $html );
	}

	public function test_lark_transport_error_does_not_leak_the_webhook_url(): void {
		$this->htmx( 'POST', '/settings/lark', array( 'webhook_url' => self::LARK_WEBHOOK ) );
		$this->http->respond_with(
			'POST ' . (string) wp_parse_url( self::LARK_WEBHOOK, PHP_URL_PATH ),
			static fn(): WP_Error => new WP_Error( 'http_request_failed', 'Could not resolve ' . self::LARK_WEBHOOK )
		);

		$html = $this->html( $this->htmx( 'POST', '/settings/lark/test' ) );

		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringNotContainsString( 'fake-hook', $html );
	}

	/**
	 * Raw values of the plugin's transients.
	 */
	private function stored_transients(): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test inspects raw storage on purpose.
		$rows = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_einvoice\\_matbao\\_%'" );

		return implode( "\n", $rows );
	}
}
