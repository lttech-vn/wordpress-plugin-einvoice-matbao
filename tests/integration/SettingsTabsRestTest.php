<?php
/**
 * Tab fragment route of the admin REST seam.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * Administrators fetch tab fragments for htmx swaps; unknown tabs get an HTML notice; nobody else gets in.
 */
final class SettingsTabsRestTest extends SettingsRestTestCase {

	/**
	 * The five tab keys.
	 *
	 * @return array<string, array{string}>
	 */
	public static function tab_keys(): array {
		return array(
			'invoices'      => array( 'invoices' ),
			'connection'    => array( 'connection' ),
			'invoicing'     => array( 'invoicing' ),
			'notifications' => array( 'notifications' ),
			'advanced'      => array( 'advanced' ),
		);
	}

	/**
	 * Legacy tab keys that are aliases of the notifications tab.
	 *
	 * @return array<string, array{string}>
	 */
	public static function legacy_keys(): array {
		return array(
			'lark'       => array( 'lark' ),
			'monitoring' => array( 'monitoring' ),
		);
	}

	/**
	 * Legacy keys return the notifications tab with that tab marked as current.
	 *
	 * @dataProvider legacy_keys
	 *
	 * @param string $key Legacy tab key.
	 */
	public function test_legacy_tab_keys_return_the_notifications_tab( string $key ): void {
		$this->login_as( 'administrator' );

		$response = $this->htmx( 'GET', '/tabs/' . $key );
		$html     = $this->html( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-tab="notifications"', $html );
		$this->assertMatchesRegularExpression( '/<a [^>]*data-einv-tab-link="notifications"[^>]*aria-current="page"/', $html );
	}

	/**
	 * Keys that are not tabs.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unknown_keys(): array {
		return array(
			'unknown'    => array( 'foo' ),
			'wrong case' => array( 'Lark1' ),
			'upper'      => array( 'LARK' ),
		);
	}

	/**
	 * Returns the tab bar and the requested panel.
	 *
	 * @dataProvider tab_keys
	 *
	 * @param string $key Tab key.
	 */
	public function test_each_tab_returns_its_panel( string $key ): void {
		$this->login_as( 'administrator' );

		$response = $this->htmx( 'GET', '/tabs/' . $key );
		$html     = $this->html( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-tab="' . $key . '"', $html );
		$this->assertMatchesRegularExpression( '/<a [^>]*data-einv-tab-link="' . $key . '"[^>]*aria-current="page"|<a [^>]*aria-current="page"[^>]*data-einv-tab-link="' . $key . '"/', $html );
	}

	/**
	 * Answers with an HTML error notice rather than core JSON.
	 *
	 * @dataProvider unknown_keys
	 *
	 * @param string $key Malformed or unknown key.
	 */
	public function test_unknown_tab_is_not_found( string $key ): void {
		$this->login_as( 'administrator' );

		$response = $this->htmx( 'GET', '/tabs/' . $key );
		$html     = $this->html( $response );

		$this->assertSame( 404, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringNotContainsString( '{', substr( $html, 0, 1 ) );
	}

	public function test_anonymous_request_is_rejected(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->htmx( 'GET', '/tabs/invoices' )->get_status() );
	}

	public function test_shop_manager_is_rejected(): void {
		$this->login_as( 'shop_manager' );

		$response = $this->htmx( 'GET', '/tabs/connection' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertStringNotContainsString( 'einv-mst', (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_invalid_nonce_is_rejected(): void {
		$this->login_as( 'administrator' );

		$this->assertSame( 403, $this->htmx( 'GET', '/tabs/invoices', array(), 'bad' )->get_status() );
	}
}
