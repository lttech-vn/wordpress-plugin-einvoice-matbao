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
	 * Runs $callback and returns what it wrote through error_log().
	 *
	 * @param callable $callback Code under test.
	 */
	protected static function error_log_of( callable $callback ): string {
		$log      = (string) wp_tempnam( 'einv-monitor-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- capturing error_log output.

		try {
			$callback();
		} finally {
			ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring.
		}

		$written = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
		wp_delete_file( $log );

		return $written;
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
