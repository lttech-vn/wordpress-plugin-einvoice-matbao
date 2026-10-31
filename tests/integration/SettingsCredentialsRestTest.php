<?php
/**
 * Saving Mat Bao and Lark credentials through the settings REST routes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * Credentials are stored encrypted, refused without a key and never echoed back.
 */
final class SettingsCredentialsRestTest extends SettingsRestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->login_as( 'administrator' );
	}

	public function test_saving_matbao_credentials_without_encryption_key_is_refused_and_explained(): void {
		$response = $this->htmx(
			'POST',
			'/settings/matbao',
			array(
				'environment' => 'demo',
				'mst'         => self::MST,
				'username'    => self::USERNAME,
				'password'    => self::PASSWORD,
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$html = $this->html( $response );
		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringContainsString( 'EINVOICE_MATBAO_ENCRYPTION_KEY', $html );
		$this->assertStringContainsString( 'wp-config.php', $html );
		$this->assertStringNotContainsString( self::PASSWORD, $this->stored_plugin_options() );
		$this->assertFalse( get_option( CredentialStore::OPTION ) );
	}

	public function test_saving_lark_webhook_without_encryption_key_is_refused(): void {
		$response = $this->htmx(
			'POST',
			'/settings/lark',
			array(
				'webhook_url' => self::LARK_WEBHOOK,
				'secret'      => self::LARK_SECRET,
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertStringContainsString( 'EINVOICE_MATBAO_ENCRYPTION_KEY', $this->html( $response ) );
		$this->assertStringNotContainsString( 'fake-hook', $this->stored_plugin_options() );
	}

	public function test_matbao_credentials_are_stored_encrypted_and_never_echoed(): void {
		$this->with_encryption_key();

		$response = $this->htmx(
			'POST',
			'/settings/matbao',
			array(
				'environment' => 'demo',
				'mst'         => self::MST,
				'username'    => self::USERNAME,
				'password'    => self::PASSWORD,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$html = $this->html( $response );
		$this->assertStringContainsString( 'data-einv-status="success"', $html );
		$this->assertStringNotContainsString( self::PASSWORD, $html );

		$stored = $this->stored_plugin_options();
		$this->assertNotSame( '', $stored );
		$this->assertStringNotContainsString( self::PASSWORD, $stored );
		$this->assertStringNotContainsString( self::USERNAME, $stored );

		$credentials = ( new CredentialStore() )->matbao();
		$this->assertNotNull( $credentials );
		$this->assertSame( self::PASSWORD, $credentials->password );
		$this->assertSame( self::USERNAME, $credentials->username );
		$this->assertSame( self::MST, $credentials->mst );
		$this->assertSame( 'demo', $credentials->environment );
	}

	public function test_settings_page_shows_password_as_configured_but_never_its_value(): void {
		$this->with_encryption_key();
		$this->save_matbao_credentials();

		$page = $this->render_settings_page();

		$this->assertStringNotContainsString( self::PASSWORD, $page );
		$this->assertStringContainsString( 'Đã lưu', $page );
	}

	public function test_blank_password_keeps_the_stored_password(): void {
		$this->with_encryption_key();
		$this->save_matbao_credentials();

		$response = $this->htmx(
			'POST',
			'/settings/matbao',
			array(
				'environment' => 'demo',
				'mst'         => self::MST,
				'username'    => 'renamed-user',
				'password'    => '',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$credentials = ( new CredentialStore() )->matbao();
		$this->assertNotNull( $credentials );
		$this->assertSame( self::PASSWORD, $credentials->password );
		$this->assertSame( 'renamed-user', $credentials->username );
	}

	public function test_first_save_requires_all_matbao_fields(): void {
		$this->with_encryption_key();

		$response = $this->htmx(
			'POST',
			'/settings/matbao',
			array(
				'environment' => 'demo',
				'mst'         => self::MST,
				'username'    => self::USERNAME,
				'password'    => '',
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-status="error"', $this->html( $response ) );
		$this->assertNull( ( new CredentialStore() )->matbao() );
	}

	public function test_unknown_environment_is_refused(): void {
		$this->with_encryption_key();

		$response = $this->htmx(
			'POST',
			'/settings/matbao',
			array(
				'environment' => 'https://attacker.example',
				'mst'         => self::MST,
				'username'    => self::USERNAME,
				'password'    => self::PASSWORD,
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertNull( ( new CredentialStore() )->matbao() );
	}

	public function test_lark_webhook_is_stored_encrypted_and_never_echoed(): void {
		$this->with_encryption_key();

		$response = $this->htmx(
			'POST',
			'/settings/lark',
			array(
				'webhook_url' => self::LARK_WEBHOOK,
				'secret'      => self::LARK_SECRET,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$html = $this->html( $response );
		$this->assertStringNotContainsString( 'fake-hook', $html );
		$this->assertStringNotContainsString( self::LARK_SECRET, $html );
		$this->assertStringNotContainsString( 'fake-hook', $this->stored_plugin_options() );
		$this->assertStringNotContainsString( self::LARK_SECRET, $this->stored_plugin_options() );
		$this->assertStringNotContainsString( 'fake-hook', $this->render_settings_page() );

		$lark = ( new CredentialStore() )->lark();
		$this->assertNotNull( $lark );
		$this->assertSame( self::LARK_WEBHOOK, $lark->webhook_url );
		$this->assertSame( self::LARK_SECRET, $lark->secret );
	}

	public function test_lark_webhook_must_be_an_https_lark_bot_url(): void {
		$this->with_encryption_key();

		$rejected = array(
			'http://open.larksuite.com/open-apis/bot/v2/hook/abc',
			'https://example.com/open-apis/bot/v2/hook/abc',
			'https://open.larksuite.com:8443/open-apis/bot/v2/hook/abc',
			'https://user@open.larksuite.com/open-apis/bot/v2/hook/abc',
			'https://open.larksuite.com/open-apis/bot/v2/hook/',
			'https://open.larksuite.com.evil.com/open-apis/bot/v2/hook/abc',
			'https://evil.com/open.larksuite.com/open-apis/bot/v2/hook/abc',
			'https://OPEN.LARKSUITE.COM/open-apis/bot/v2/hook/abc',
		);

		foreach ( $rejected as $url ) {
			$response = $this->htmx( 'POST', '/settings/lark', array( 'webhook_url' => $url ) );

			$this->assertSame( 422, $response->get_status(), $url );
			$this->assertNull( ( new CredentialStore() )->lark(), $url );
		}
	}

	public function test_lark_blank_secret_is_kept_when_the_webhook_is_unchanged(): void {
		$this->with_encryption_key();
		$this->htmx( 'POST', '/settings/lark', array( 'webhook_url' => self::LARK_WEBHOOK, 'secret' => self::LARK_SECRET ) );

		$this->htmx( 'POST', '/settings/lark', array( 'webhook_url' => self::LARK_WEBHOOK, 'secret' => '' ) );
		$this->assertSame( self::LARK_SECRET, ( new CredentialStore() )->lark()?->secret );

		$this->htmx( 'POST', '/settings/lark', array( 'webhook_url' => '', 'secret' => '' ) );
		$lark = ( new CredentialStore() )->lark();
		$this->assertSame( self::LARK_WEBHOOK, $lark?->webhook_url );
		$this->assertSame( self::LARK_SECRET, $lark?->secret );
	}

	public function test_lark_blank_secret_is_dropped_when_the_webhook_changes(): void {
		$this->with_encryption_key();
		$this->htmx( 'POST', '/settings/lark', array( 'webhook_url' => self::LARK_WEBHOOK, 'secret' => self::LARK_SECRET ) );
		$other = 'https://open.larksuite.com/open-apis/bot/v2/hook/11111111-other-hook';

		$response = $this->htmx( 'POST', '/settings/lark', array( 'webhook_url' => $other, 'secret' => '' ) );

		$this->assertSame( 200, $response->get_status() );
		$lark = ( new CredentialStore() )->lark();
		$this->assertSame( $other, $lark?->webhook_url );
		$this->assertSame( '', $lark?->secret );
	}

	public function test_lark_secret_can_be_cleared_explicitly(): void {
		$this->with_encryption_key();
		$this->htmx( 'POST', '/settings/lark', array( 'webhook_url' => self::LARK_WEBHOOK, 'secret' => self::LARK_SECRET ) );

		$this->htmx( 'POST', '/settings/lark', array( 'webhook_url' => '', 'secret' => '', 'clear_secret' => '1' ) );

		$lark = ( new CredentialStore() )->lark();
		$this->assertSame( self::LARK_WEBHOOK, $lark?->webhook_url );
		$this->assertSame( '', $lark?->secret );
	}

	public function test_stored_credentials_are_unreadable_with_a_different_key(): void {
		$this->with_encryption_key();
		$this->save_matbao_credentials();

		remove_all_filters( 'einvoice_matbao_encryption_key' );
		add_filter( 'einvoice_matbao_encryption_key', static fn(): string => 'another-test-only-key-material-xyz' );

		$this->assertNull( ( new CredentialStore() )->matbao() );
	}

	/**
	 * Renders the admin settings page body as an Administrator would see it.
	 */
	private function render_settings_page(): string {
		return get_echo( array( \LTTech\EinvoiceMatbao\Admin\SettingsPage::class, 'render' ) );
	}
}
