<?php
/**
 * Cron monitor check-ins around the recovery sweep.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use ActionScheduler;
use ActionScheduler_Store;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Monitoring\MonitorSlug;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use RuntimeException;
use WP_UnitTestCase;

/**
 * The real recurring sweep action runs through the Action Scheduler runner with the tracing hooks
 * Plugin::boot() registered and the Sentry endpoint faked at the HTTP boundary.
 */
final class RecoveryMonitorTest extends WP_UnitTestCase {

	use MonitoringFixture;

	/**
	 * HTTP fake.
	 *
	 * @var FakeHttp
	 */
	private FakeHttp $http;

	/**
	 * Fake Sentry endpoint.
	 *
	 * @var SentrySink
	 */
	private SentrySink $sink;

	/**
	 * The error_log destination before the test.
	 *
	 * @var string
	 */
	private string $previous_error_log = '';

	public function set_up(): void {
		parent::set_up();
		$this->http               = FakeHttp::install();
		$this->sink               = SentrySink::install( $this->http );
		$this->previous_error_log = (string) ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps expected failure lines out of the test output.
	}

	public function tear_down(): void {
		ini_set( 'error_log', $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring.
		$this->reset_monitoring();
		$this->http->uninstall();
		parent::tear_down();
	}

	public function test_sweep_reports_in_progress_then_ok(): void {
		$this->enable_monitoring();
		$id = $this->sweep_action_id();

		ActionScheduler::runner()->process_action( $id, 'einv-test' );

		$check_ins = $this->sink->check_ins();
		$this->assertSame( array( 'in_progress', 'ok' ), array_column( $check_ins, 'status' ) );
		$this->assertSame( $check_ins[0]['check_in_id'], $check_ins[1]['check_in_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $check_ins[0]['check_in_id'] );
		$this->assertSame( MonitorSlug::for_host( 'example.org' ), $check_ins[0]['monitor_slug'] );
		$this->assertSame( MonitorSlug::for_host( 'example.org' ), $check_ins[1]['monitor_slug'] );
		$this->assertArrayHasKey( 'duration', $check_ins[1] );
		$this->assertGreaterThanOrEqual( 0.0, (float) $check_ins[1]['duration'] );
		$this->assertSame( 15, $check_ins[1]['monitor_config']['schedule']['value'] );
		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( (string) $id ) );
		$transactions = $this->sink->transactions();
		$this->assertCount( 1, $transactions );
		$this->assertSame( DraftInvoiceWorkflow::RECOVERY_ACTION, $transactions[0]['transaction'] );
		$this->assertSame( 'queue.task', $transactions[0]['contexts']['trace']['op'] );
	}

	public function test_failed_sweep_reports_error_and_rethrows(): void {
		Monitor::reset_for_tests();
		$disabled_status = $this->status_after_failing_sweep();
		$this->assertSame( 0, $this->sink->request_count() );

		$this->enable_monitoring();
		$enabled_status = $this->status_after_failing_sweep();

		$check_ins = $this->sink->check_ins();
		$this->assertSame( array( 'in_progress', 'error' ), array_column( $check_ins, 'status' ) );
		$this->assertSame( $check_ins[0]['check_in_id'], $check_ins[1]['check_in_id'] );
		$this->assertArrayHasKey( 'duration', $check_ins[1] );
		$this->assertSame( ActionScheduler_Store::STATUS_FAILED, $disabled_status );
		$this->assertSame( $disabled_status, $enabled_status, 'Action Scheduler records the failure as when monitoring is off.' );
		$this->assertSame( 'internal_error', $this->sink->transactions()[0]['contexts']['trace']['status'] );
	}

	public function test_disabled_sweep_sends_nothing(): void {
		Monitor::reset_for_tests();
		$id = $this->sweep_action_id();

		ActionScheduler::runner()->process_action( $id, 'einv-test' );
		Monitor::flush();

		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( (string) $id ) );
		$this->assertSame( 0, $this->sink->request_count() );
	}

	/**
	 * Runs a sweep whose hook throws and returns the action status Action Scheduler stored.
	 */
	private function status_after_failing_sweep(): string {
		$throwing = static function (): never {
			throw new RuntimeException( 'x' );
		};
		add_action( DraftInvoiceWorkflow::RECOVERY_ACTION, $throwing, 5 );
		$id = $this->sweep_action_id();

		try {
			ActionScheduler::runner()->process_action( $id, 'einv-test' );
		} finally {
			remove_action( DraftInvoiceWorkflow::RECOVERY_ACTION, $throwing, 5 );
		}

		return ActionScheduler::store()->get_status( (string) $id );
	}

	/**
	 * A fresh pending sweep action in the plugin group, replacing any queued one.
	 */
	private function sweep_action_id(): int {
		as_unschedule_all_actions( DraftInvoiceWorkflow::RECOVERY_ACTION, array(), DraftInvoiceWorkflow::GROUP );

		return as_schedule_recurring_action( time() - 1, 15 * MINUTE_IN_SECONDS, DraftInvoiceWorkflow::RECOVERY_ACTION, array(), DraftInvoiceWorkflow::GROUP );
	}
}
