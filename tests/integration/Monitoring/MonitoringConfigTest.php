<?php
/**
 * Effective monitoring configuration.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Monitoring\MonitoringConfig;
use LTTech\EinvoiceMatbao\Settings;
use WP_UnitTestCase;

/**
 * Constants win over the UI, a DSN alone never enables monitoring, and the stored DSN is encrypted.
 */
final class MonitoringConfigTest extends WP_UnitTestCase {

	private const KEY = 'test-only-key-material-0123456789abcdef';
	private const DSN = 'https://abc123@sentry.test/3';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'einvoice_matbao_encryption_key', static fn(): string => self::KEY );
	}

	public function tear_down(): void {
		remove_all_filters( 'einvoice_matbao_encryption_key' );
		remove_all_filters( 'einvoice_matbao_monitoring_constants' );
		remove_all_filters( 'einvoice_matbao_monitoring_mbstring' );
		parent::tear_down();
	}

	/**
	 * Simulates wp-config constants.
	 *
	 * @param array<string, mixed> $constants Simulated wp-config constants.
	 */
	private function constants( array $constants ): void {
		add_filter( 'einvoice_matbao_monitoring_constants', static fn(): array => $constants );
	}

	public function test_defaults_off(): void {
		$config = MonitoringConfig::current();

		$this->assertFalse( $config->enabled );
		$this->assertFalse( $config->active() );
		$this->assertSame( 1.0, $config->traces_sample_rate );
		$this->assertSame( 'none', $config->dsn_source );
	}

	public function test_enabled_without_dsn_is_inactive(): void {
		( new Settings() )->save_monitoring( true, 1.0 );

		$config = MonitoringConfig::current();

		$this->assertTrue( $config->enabled );
		$this->assertFalse( $config->active() );
	}

	public function test_ui_dsn_used(): void {
		( new Settings() )->save_monitoring( true, 0.5 );
		( new CredentialStore() )->save_sentry_dsn( self::DSN );

		$config = MonitoringConfig::current();

		$this->assertTrue( $config->active() );
		$this->assertSame( 'ui', $config->dsn_source );
		$this->assertSame( 0.5, $config->traces_sample_rate );
	}

	public function test_dsn_constant_wins(): void {
		( new Settings() )->save_monitoring( true, 1.0 );
		( new CredentialStore() )->save_sentry_dsn( self::DSN );
		$this->constants( array( 'EINVOICE_MATBAO_SENTRY_DSN' => 'https://other@const.test/9' ) );

		$config = MonitoringConfig::current();

		$this->assertSame( 'constant', $config->dsn_source );
		$this->assertSame( 9, $config->dsn?->project_id );
		$this->assertFalse( $config->dsn_constant_invalid );
	}

	public function test_invalid_dsn_constant_falls_back(): void {
		( new Settings() )->save_monitoring( true, 1.0 );
		( new CredentialStore() )->save_sentry_dsn( self::DSN );
		$this->constants( array( 'EINVOICE_MATBAO_SENTRY_DSN' => 'not a dsn' ) );

		$config = MonitoringConfig::current();

		$this->assertTrue( $config->dsn_constant_invalid );
		$this->assertSame( 'ui', $config->dsn_source );
		$this->assertTrue( $config->active() );
	}

	public function test_enabled_constant_false_wins(): void {
		( new Settings() )->save_monitoring( true, 1.0 );
		( new CredentialStore() )->save_sentry_dsn( self::DSN );
		$this->constants( array( 'EINVOICE_MATBAO_SENTRY_ENABLED' => false ) );

		$config = MonitoringConfig::current();

		$this->assertFalse( $config->enabled );
		$this->assertTrue( $config->enabled_from_constant );
		$this->assertFalse( $config->active() );
	}

	public function test_dsn_constant_alone_does_not_enable(): void {
		$this->constants( array( 'EINVOICE_MATBAO_SENTRY_DSN' => self::DSN ) );

		$config = MonitoringConfig::current();

		$this->assertFalse( $config->enabled );
		$this->assertFalse( $config->active() );
		$this->assertNotNull( $config->dsn );
	}

	public function test_undecryptable_stored_dsn(): void {
		( new Settings() )->save_monitoring( true, 1.0 );
		( new CredentialStore() )->save_sentry_dsn( self::DSN );
		remove_all_filters( 'einvoice_matbao_encryption_key' );
		add_filter( 'einvoice_matbao_encryption_key', static fn(): string => 'a-different-key-material-0123456789abcdef' );

		$config = MonitoringConfig::current();

		$this->assertNull( $config->dsn );
		$this->assertTrue( $config->stored_dsn_unreadable );
		$this->assertFalse( $config->active() );
	}

	public function test_missing_mbstring_is_inactive(): void {
		( new Settings() )->save_monitoring( true, 1.0 );
		( new CredentialStore() )->save_sentry_dsn( self::DSN );
		add_filter( 'einvoice_matbao_monitoring_mbstring', '__return_false' );

		$this->assertFalse( MonitoringConfig::current()->active() );
	}

	public function test_site_and_release(): void {
		$config = MonitoringConfig::current();

		$this->assertSame( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), $config->site );
		$this->assertSame( 'einvoice-matbao@' . EINVOICE_MATBAO_VERSION, $config->release );
	}

	public function test_sample_rate_is_clamped_and_non_numeric_defaults(): void {
		$settings = new Settings();
		$settings->save_monitoring( false, 7.0 );
		$this->assertSame( 1.0, $settings->traces_sample_rate() );
		$settings->save_monitoring( false, -2.0 );
		$this->assertSame( 0.0, $settings->traces_sample_rate() );
		update_option( Settings::OPTION, array( 'traces_sample_rate' => 'abc' ) );
		$this->assertSame( 1.0, $settings->traces_sample_rate() );
	}

	public function test_stored_ciphertext(): void {
		( new CredentialStore() )->save_sentry_dsn( self::DSN );

		$raw = (string) wp_json_encode( get_option( CredentialStore::OPTION ) );

		$this->assertStringNotContainsString( 'abc123', $raw );
		$this->assertStringNotContainsString( 'sentry.test', $raw );
	}
}
