<?php
/**
 * Monitoring settings routes of the admin REST seam.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Monitoring\MonitoringConfig;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * Administrators configure error monitoring; the DSN is stored encrypted and never rendered back.
 */
final class SettingsMonitoringRestTest extends SettingsRestTestCase {

	use MonitoringFixture;

	private const DSN = 'https://secretpubkey@rustrak.ltt.onl/3';

	/**
	 * Sink of the DSN saved through the form.
	 *
	 * @var SentrySink
	 */
	private SentrySink $sink;

	public function set_up(): void {
		parent::set_up();
		$this->login_as( 'administrator' );
		$this->sink = SentrySink::install( $this->http, 'rustrak.ltt.onl', 3 );
	}

	public function tear_down(): void {
		$this->reset_monitoring();
		parent::tear_down();
	}

	/**
	 * Posts the monitoring form.
	 *
	 * @param array<string, string> $params Form fields.
	 */
	private function save( array $params ): string {
		return $this->html( $this->htmx( 'POST', '/settings/monitoring', $params ) );
	}

	private function tab_html(): string {
		return $this->html( $this->htmx( 'GET', '/tabs/monitoring' ) );
	}

	public function test_save_valid_settings(): void {
		$this->with_encryption_key();

		$html = $this->save(
			array(
				'enabled'            => '1',
				'dsn'                => self::DSN,
				'traces_sample_rate' => '0.5',
			)
		);

		$this->assertStringContainsString( 'data-einv-status="success"', $html );
		$config = MonitoringConfig::current();
		$this->assertTrue( $config->active() );
		$this->assertSame( 0.5, $config->traces_sample_rate );
		$this->assertStringNotContainsString( 'secretpubkey', $this->stored_plugin_options() );
	}

	public function test_form_never_renders_dsn(): void {
		$this->with_encryption_key();
		$html = $this->save(
			array(
				'enabled'            => '1',
				'dsn'                => self::DSN,
				'traces_sample_rate' => '1',
			)
		);

		$tab = $this->tab_html();

		$this->assertStringContainsString( 'rustrak.ltt.onl · project 3', $tab );
		$this->assertStringNotContainsString( 'secretpubkey', $tab . $html );
	}

	public function test_invalid_dsn_rejected(): void {
		$this->with_encryption_key();
		( new CredentialStore() )->save_sentry_dsn( self::DSN );

		$html = $this->save(
			array(
				'enabled'            => '1',
				'dsn'                => 'not-a-dsn',
				'traces_sample_rate' => '1',
			)
		);

		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringContainsString( 'DSN không hợp lệ', $html );
		$this->assertSame( self::DSN, ( new CredentialStore() )->sentry_dsn() );
		$this->assertFalse( ( new Settings() )->monitoring_enabled() );
	}

	/**
	 * Rates outside 0..1 or not numeric.
	 *
	 * @return array<string, array{string}>
	 */
	public static function bad_rates(): array {
		return array(
			'too high' => array( '1.5' ),
			'negative' => array( '-0.1' ),
			'text'     => array( 'abc' ),
			'blank'    => array( '' ),
		);
	}

	/**
	 * Keeps the previous configuration when the rate is invalid.
	 *
	 * @dataProvider bad_rates
	 *
	 * @param string $rate Posted rate.
	 */
	public function test_rate_out_of_range_rejected( string $rate ): void {
		$this->with_encryption_key();
		( new Settings() )->save_monitoring( false, 0.25 );

		$html = $this->save(
			array(
				'enabled'            => '1',
				'dsn'                => self::DSN,
				'traces_sample_rate' => $rate,
			)
		);

		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringContainsString( 'Tỉ lệ lấy mẫu phải là số từ 0 đến 1', $html );
		$this->assertFalse( ( new Settings() )->monitoring_enabled() );
		$this->assertSame( 0.25, ( new Settings() )->traces_sample_rate() );
		$this->assertFalse( ( new CredentialStore() )->has_sentry_dsn() );
	}

	public function test_blank_dsn_keeps_stored(): void {
		$this->with_encryption_key();
		( new CredentialStore() )->save_sentry_dsn( self::DSN );

		$html = $this->save(
			array(
				'enabled'            => '1',
				'dsn'                => '',
				'traces_sample_rate' => '0.1',
			)
		);

		$this->assertStringContainsString( 'data-einv-status="success"', $html );
		$this->assertSame( self::DSN, ( new CredentialStore() )->sentry_dsn() );
		$this->assertTrue( MonitoringConfig::current()->active() );
	}

	public function test_dsn_is_trimmed(): void {
		$this->with_encryption_key();

		$this->save(
			array(
				'enabled'            => '1',
				'dsn'                => '  ' . self::DSN . "\n",
				'traces_sample_rate' => '1',
			)
		);

		$this->assertSame( self::DSN, ( new CredentialStore() )->sentry_dsn() );
	}

	public function test_missing_key_rejects_save(): void {
		$html = $this->save(
			array(
				'enabled'            => '1',
				'dsn'                => self::DSN,
				'traces_sample_rate' => '1',
			)
		);

		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringContainsString( 'EINVOICE_MATBAO_ENCRYPTION_KEY', $html );
		$this->assertFalse( ( new CredentialStore() )->has_sentry_dsn() );
		$this->assertFalse( ( new Settings() )->monitoring_enabled() );
	}

	public function test_undecryptable_dsn_warns(): void {
		$this->with_encryption_key();
		( new CredentialStore() )->save_sentry_dsn( self::DSN );
		remove_all_filters( 'einvoice_matbao_encryption_key' );
		add_filter( 'einvoice_matbao_encryption_key', static fn(): string => 'another-key-material-0123456789abcdef' );

		$tab = $this->tab_html();

		$this->assertStringContainsString( 'DSN đã lưu không đọc được', $tab );
		$this->assertStringNotContainsString( 'secretpubkey', $tab );
	}

	public function test_constant_fields_readonly_and_ignored(): void {
		$this->with_encryption_key();
		( new CredentialStore() )->save_sentry_dsn( self::DSN );
		$this->monitoring_constants(
			array(
				'EINVOICE_MATBAO_SENTRY_DSN'     => 'https://constkey@const.test/9',
				'EINVOICE_MATBAO_SENTRY_ENABLED' => false,
			)
		);

		$tab = $this->tab_html();
		$this->assertStringContainsString( 'Đặt trong wp-config', $tab );
		$this->assertSame( 2, substr_count( $tab, 'Đặt trong wp-config' ) );
		$this->assertMatchesRegularExpression( '/<input[^>]*name="dsn"[^>]*readonly/', $tab );
		$this->assertMatchesRegularExpression( '/<input[^>]*name="enabled"[^>]*disabled/', $tab );
		$this->assertStringNotContainsString( 'constkey', $tab );

		$html = $this->save(
			array(
				'enabled'            => '1',
				'dsn'                => 'https://other@elsewhere.test/5',
				'traces_sample_rate' => '1',
			)
		);

		$this->assertStringContainsString( 'data-einv-status="success"', $html );
		$this->assertSame( self::DSN, ( new CredentialStore() )->sentry_dsn() );
		$this->assertFalse( ( new Settings() )->monitoring_enabled() );
		$this->assertSame( 'constant', MonitoringConfig::current()->dsn_source );
	}

	public function test_invalid_dsn_constant_warning(): void {
		$this->monitoring_constants( array( 'EINVOICE_MATBAO_SENTRY_DSN' => 'not-a-dsn' ) );

		$tab = $this->tab_html();

		$this->assertStringContainsString( 'Hằng EINVOICE_MATBAO_SENTRY_DSN không hợp lệ', $tab );
	}

	public function test_test_event_success(): void {
		$this->enable_monitoring();
		$sink = SentrySink::install( $this->http, 'sentry.test', 3 );

		$html = $this->html( $this->htmx( 'POST', '/settings/monitoring/test' ) );

		$this->assertStringContainsString( 'data-einv-status="success"', $html );
		$this->assertMatchesRegularExpression( '/ID: [0-9a-f]{32}/', $html );
		$this->assertStringNotContainsString( 'sentry.test/3', $html );
		$this->assertCount( 1, $sink->events() );
	}

	public function test_test_event_failure_reports_error(): void {
		$this->enable_monitoring();
		$sink = SentrySink::install( $this->http, 'sentry.test', 3 );
		$sink->respond_with( 401 );

		$response = $this->htmx( 'POST', '/settings/monitoring/test' );
		$html     = $this->html( $response );

		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringNotContainsString( 'k@sentry.test', $html );
	}

	public function test_test_event_when_disabled(): void {
		$html = $this->html( $this->htmx( 'POST', '/settings/monitoring/test' ) );

		$this->assertStringContainsString( 'data-einv-status="error"', $html );
		$this->assertStringContainsString( 'Cần bật giám sát và nhập DSN hợp lệ', $html );
		$this->assertSame( 0, $this->sink->request_count() );
		$this->assertCount( 0, $this->http->raw_requests() );
	}

	/**
	 * Both routes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function routes(): array {
		return array(
			'save' => array( '/settings/monitoring' ),
			'test' => array( '/settings/monitoring/test' ),
		);
	}

	/**
	 * Anonymous users are rejected and nothing is written.
	 *
	 * @dataProvider routes
	 *
	 * @param string $route Route.
	 */
	public function test_anonymous_rejected( string $route ): void {
		$this->with_encryption_key();
		wp_set_current_user( 0 );

		$response = $this->htmx(
			'POST',
			$route,
			array(
				'enabled'            => '1',
				'dsn'                => self::DSN,
				'traces_sample_rate' => '1',
			)
		);

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( ( new CredentialStore() )->has_sentry_dsn() );
	}

	/**
	 * Shop managers are rejected and nothing is written.
	 *
	 * @dataProvider routes
	 *
	 * @param string $route Route.
	 */
	public function test_shop_manager_rejected( string $route ): void {
		$this->with_encryption_key();
		$this->login_as( 'shop_manager' );

		$response = $this->htmx(
			'POST',
			$route,
			array(
				'enabled'            => '1',
				'dsn'                => self::DSN,
				'traces_sample_rate' => '1',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertFalse( ( new CredentialStore() )->has_sentry_dsn() );
		$this->assertFalse( ( new Settings() )->monitoring_enabled() );
	}

	/**
	 * A wrong nonce is rejected and nothing is written.
	 *
	 * @dataProvider routes
	 *
	 * @param string $route Route.
	 */
	public function test_invalid_nonce_rejected( string $route ): void {
		$this->with_encryption_key();

		$response = $this->htmx(
			'POST',
			$route,
			array(
				'enabled'            => '1',
				'dsn'                => self::DSN,
				'traces_sample_rate' => '1',
			),
			'bad'
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertFalse( ( new CredentialStore() )->has_sentry_dsn() );
	}
}
