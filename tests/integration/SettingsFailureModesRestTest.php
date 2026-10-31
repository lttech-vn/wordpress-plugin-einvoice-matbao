<?php
/**
 * Failure modes of the Mat Bao and Lark HTTP boundaries, seen through the settings routes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * Server errors, malformed bodies, repeated 401s, hostile messages and expired tokens.
 */
final class SettingsFailureModesRestTest extends SettingsRestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->login_as( 'administrator' );
		$this->with_encryption_key();
		$this->save_matbao_credentials();
	}

	public function test_http_5xx_from_matbao_is_reported_as_an_error(): void {
		$this->http->respond_json( 'POST /api/auth/login', 503, array( 'message' => 'Dịch vụ bảo trì' ) );

		$html = $this->html( $this->htmx( 'POST', '/settings/matbao/test' ) );

		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringContainsString( '503', $html );
	}

	public function test_non_json_body_from_matbao_is_reported_as_an_error(): void {
		$this->http->respond_raw( 'POST /api/auth/login', 200, '<html>Bad gateway</html>' );

		$html = $this->html( $this->htmx( 'POST', '/settings/matbao/test' ) );

		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringNotContainsString( 'data-einv-status="success"', $html );
	}

	public function test_login_success_without_access_token_is_an_error(): void {
		$this->http->respond_json(
			'POST /api/auth/login',
			200,
			array(
				'errorCode' => 200,
				'message'   => 'Thành công',
				'data'      => array( 'expiredDate' => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ) ),
				'success'   => true,
			)
		);

		$html = $this->html( $this->htmx( 'POST', '/settings/matbao/test' ) );

		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertSame( '', $this->stored_transients() );
	}

	public function test_a_second_401_after_relogin_is_not_retried_again(): void {
		$this->fake_matbao_login_ok();
		$this->http->respond_json( 'GET /api/invoice/templates', 401, array( 'message' => 'Unauthorized' ) );

		$html = $this->html( $this->htmx( 'GET', '/settings/templates' ) );

		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertCount( 2, $this->http->requests_to( 'POST /api/auth/login' ) );
		$this->assertCount( 2, $this->http->requests_to( 'GET /api/invoice/templates' ) );
	}

	public function test_matbao_error_message_is_escaped(): void {
		$this->http->respond_json(
			'POST /api/auth/login',
			200,
			array(
				'errorCode' => 400,
				'message'   => '<script>alert(1)</script>',
				'data'      => null,
				'success'   => false,
			)
		);

		$html = $this->html( $this->htmx( 'POST', '/settings/matbao/test' ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_lark_error_message_is_escaped(): void {
		$this->htmx( 'POST', '/settings/lark', array( 'webhook_url' => self::LARK_WEBHOOK ) );
		$this->http->respond_json(
			'POST ' . (string) wp_parse_url( self::LARK_WEBHOOK, PHP_URL_PATH ),
			200,
			array(
				'code' => 9499,
				'msg'  => '<script>alert(1)</script>',
			)
		);

		$html = $this->html( $this->htmx( 'POST', '/settings/lark/test' ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_token_already_expired_is_not_cached(): void {
		$this->http->respond_json(
			'POST /api/auth/login',
			200,
			array(
				'errorCode' => 200,
				'message'   => 'Thành công',
				'data'      => array(
					'accessToken' => 'fake-token-expired',
					'expiredDate' => '2000-01-01T00:00:00',
				),
				'success'   => true,
			)
		);
		$this->fake_matbao_templates();

		$this->htmx( 'GET', '/settings/templates' );
		$this->assertSame( '', $this->stored_transients() );

		$this->htmx( 'GET', '/settings/templates' );
		$this->assertCount( 2, $this->http->requests_to( 'POST /api/auth/login' ) );
	}

	public function test_token_near_expiry_is_cached_for_less_than_the_margin_window(): void {
		$this->http->respond_json(
			'POST /api/auth/login',
			200,
			array(
				'errorCode' => 200,
				'message'   => 'Thành công',
				'data'      => array(
					'accessToken' => 'fake-token-soon',
					// Inside the 5 minute refresh margin: not worth caching.
					'expiredDate' => ( new \DateTimeImmutable( '+2 minutes', new \DateTimeZone( 'Asia/Ho_Chi_Minh' ) ) )->format( 'Y-m-d\TH:i:s' ),
				),
				'success'   => true,
			)
		);

		$this->htmx( 'POST', '/settings/matbao/test' );

		$this->assertSame( '', $this->stored_transients() );
	}
}
