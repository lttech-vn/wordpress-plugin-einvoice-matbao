<?php
/**
 * Access control on the settings REST routes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * Only Administrators with a valid `wp_rest` nonce may use the settings routes.
 */
final class SettingsRestAccessTest extends SettingsRestTestCase {

	/**
	 * Every settings route, as [method, route].
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function settings_routes(): array {
		return array(
			'templates list'  => array( 'GET', '/settings/templates' ),
			'save matbao'     => array( 'POST', '/settings/matbao' ),
			'test matbao'     => array( 'POST', '/settings/matbao/test' ),
			'save lark'       => array( 'POST', '/settings/lark' ),
			'test lark'       => array( 'POST', '/settings/lark/test' ),
			'save template'   => array( 'POST', '/settings/template' ),
			'save invoicing'  => array( 'POST', '/settings/invoicing' ),
		);
	}

	/**
	 * Routes are registered under the plugin's own namespace.
	 *
	 * @dataProvider settings_routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 */
	public function test_route_is_registered( string $method, string $route ): void {
		$routes = rest_get_server()->get_routes( 'einvoice-matbao/v1' );

		$this->assertArrayHasKey( self::NAMESPACE . $route, $routes );
		$methods = array_merge( ...array_map( static fn( array $handler ): array => array_keys( $handler['methods'] ), $routes[ self::NAMESPACE . $route ] ) );
		$this->assertContains( $method, $methods );
	}

	/**
	 * @dataProvider settings_routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 */
	public function test_anonymous_request_is_rejected( string $method, string $route ): void {
		wp_set_current_user( 0 );

		$response = $this->htmx( $method, $route );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( array(), $this->http->requests );
	}

	/**
	 * @dataProvider settings_routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 */
	public function test_shop_manager_is_rejected( string $method, string $route ): void {
		$this->login_as( 'shop_manager' );

		$response = $this->htmx( $method, $route );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_editor_is_rejected(): void {
		$this->login_as( 'editor' );

		$this->assertSame( 403, $this->htmx( 'POST', '/settings/invoicing', array( 'reference_prefix' => 'X' ) )->get_status() );
	}

	/**
	 * @dataProvider settings_routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 */
	public function test_administrator_without_nonce_is_rejected( string $method, string $route ): void {
		$this->login_as( 'administrator' );

		$response = $this->htmx( $method, $route, array(), '' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_administrator_with_wrong_nonce_is_rejected(): void {
		$this->login_as( 'administrator' );

		$response = $this->htmx( 'POST', '/settings/invoicing', array( 'reference_prefix' => 'X' ), wp_create_nonce( 'some_other_action' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertFalse( get_option( 'einvoice_matbao_settings' ) );
	}

	public function test_administrator_with_nonce_gets_an_html_fragment(): void {
		$this->login_as( 'administrator' );

		$response = $this->htmx( 'POST', '/settings/invoicing', array( 'reference_prefix' => 'SHOP', 'walk_in_buyer_name' => 'Khách lẻ' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( 'text/html', $response->get_headers()['Content-Type'] ?? '' );
		$this->assertStringContainsString( 'data-einv-status="success"', $this->html( $response ) );
	}

	public function test_rejection_is_served_as_html_fragment_for_htmx(): void {
		$this->login_as( 'shop_manager' );
		$response = $this->htmx( 'POST', '/settings/invoicing' );

		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $response, new \WP_REST_Request( 'POST', self::NAMESPACE . '/settings/invoicing' ), rest_get_server() );
		$output = (string) ob_get_clean();

		$this->assertTrue( $served );
		$this->assertStringContainsString( 'data-einv-status="error"', $output );
		$this->assertStringNotContainsString( '{"code"', $output );
	}

	public function test_core_routes_are_still_served_as_json(): void {
		$this->login_as( 'administrator' );
		$request  = new \WP_REST_Request( 'GET', '/wp/v2/users/me' );
		$response = rest_do_request( $request );

		$served = apply_filters( 'rest_pre_serve_request', false, $response, $request, rest_get_server() );

		$this->assertFalse( $served );
	}
}
