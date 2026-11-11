<?php
/**
 * Switches plugin monitoring on against the fake Sentry sink.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Support;

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Uninstall\Uninstaller;

/**
 * For WP_UnitTestCase subclasses: stores a UI DSN pointing at `sentry.test` project 3, enables
 * monitoring and resets the Monitor so the next call reads the new configuration. Call
 * reset_monitoring() from tear_down().
 */
trait MonitoringFixture {

	/**
	 * DSN of the fake sink.
	 *
	 * @var string
	 */
	protected static string $sink_dsn = 'https://k@sentry.test/3';

	/**
	 * Enables monitoring with the sink DSN stored through the UI path.
	 *
	 * @param float $traces_sample_rate Transaction sample rate.
	 */
	protected function enable_monitoring( float $traces_sample_rate = 1.0 ): void {
		add_filter( 'einvoice_matbao_encryption_key', static fn(): string => 'test-only-key-material-0123456789abcdef' );
		( new Settings() )->save_monitoring( true, $traces_sample_rate );
		( new CredentialStore() )->save_sentry_dsn( self::$sink_dsn );
		Monitor::reset_for_tests();
	}

	/**
	 * Simulates wp-config monitoring constants.
	 *
	 * @param array<string, mixed> $constants Constant name => value.
	 */
	protected function monitoring_constants( array $constants ): void {
		add_filter( 'einvoice_matbao_monitoring_constants', static fn(): array => $constants );
		Monitor::reset_for_tests();
	}

	/**
	 * Drops the cached backend, the backoff and every monitoring filter.
	 */
	protected function reset_monitoring(): void {
		Monitor::reset_for_tests();
		delete_option( Uninstaller::SENTRY_BACKOFF_OPTION );
		remove_all_filters( 'einvoice_matbao_encryption_key' );
		remove_all_filters( 'einvoice_matbao_monitoring_constants' );
	}
}
