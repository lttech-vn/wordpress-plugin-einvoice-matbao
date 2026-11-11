<?php
/**
 * Monitor facade over the isolated Sentry backend.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use DomainException;
use LTTech\EinvoiceMatbao\Monitoring\Backend;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Monitoring\MonitoringConfig;
use LTTech\EinvoiceMatbao\Monitoring\MonitorSlug;
use LTTech\EinvoiceMatbao\Monitoring\Redactor;
use LTTech\EinvoiceMatbao\Monitoring\SentryBackend;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use RuntimeException;
use WP_UnitTestCase;

/**
 * What reaches the monitoring server, and that instrumentation never disturbs the caller.
 */
final class MonitorTest extends WP_UnitTestCase {

	use MonitoringFixture;

	/**
	 * HTTP fake.
	 *
	 * @var FakeHttp
	 */
	private FakeHttp $http;

	/**
	 * Fake Sentry endpoint for project 3.
	 *
	 * @var SentrySink
	 */
	private SentrySink $sink;

	/**
	 * The error_log destination before the test; internal failures are logged to a temp file meanwhile.
	 *
	 * @var string
	 */
	private string $previous_error_log = '';

	public function set_up(): void {
		parent::set_up();
		$this->http               = FakeHttp::install();
		$this->sink               = SentrySink::install( $this->http );
		$this->previous_error_log = (string) ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps expected internal-failure lines out of the test output.
	}

	public function tear_down(): void {
		ini_set( 'error_log', $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring.
		$this->reset_monitoring();
		$this->http->uninstall();
		parent::tear_down();
	}

	/**
	 * Disabled monitoring is a no-op that never loads the SDK.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disabled_sends_nothing_and_loads_no_sdk(): void {
		Monitor::reset_for_tests();

		$this->assertNull( Monitor::capture_exception( new RuntimeException( 'x' ) ) );
		Monitor::log( 'info', 'm' );
		Monitor::start_transaction( 't', 'queue.task' );
		$this->assertSame( 7, Monitor::span( 'http.client', 'POST /x', static fn(): int => 7 ) );
		Monitor::finish_transaction();
		$this->assertNull( Monitor::check_in( 'ok' ) );
		Monitor::flush();

		$this->assertSame( array(), $this->http->raw_requests() );
		$this->assertFalse( class_exists( 'Sentry\\ClientBuilder', false ) );
		$this->assertFalse( class_exists( 'Sentry\\State\\Hub', false ) );
	}

	public function test_capture_exception_scrubbed(): void {
		$this->enable_monitoring();

		$id = Monitor::capture_exception(
			self::exception_from( 'secret-argument', 'Mail a@b.vn MST 0101234567 CCCD 001203004005' ),
			array(
				'order_id' => '42',
				'unknown'  => 'drop me',
			)
		);

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $id );
		$events = $this->sink->events();
		$this->assertCount( 1, $events );
		$event = $events[0];
		$value = $event['exception']['values'][0]['value'];
		$this->assertStringNotContainsString( 'a@b.vn', $value );
		$this->assertStringNotContainsString( '0101234567', $value );
		$this->assertStringNotContainsString( '001203004005', $value );
		$this->assertArrayNotHasKey( 'server_name', $event );
		$this->assertArrayNotHasKey( 'request', $event );
		$this->assertArrayNotHasKey( 'user', $event );
		foreach ( $event['exception']['values'] as $exception ) {
			foreach ( $exception['stacktrace']['frames'] ?? array() as $frame ) {
				$this->assertArrayNotHasKey( 'vars', $frame );
			}
		}
		$this->assertStringNotContainsString( 'secret-argument', (string) wp_json_encode( $event ) );
		$this->assertSame( 'example.org', $event['tags']['site'] );
		$this->assertSame( '42', $event['tags']['order_id'] );
		$this->assertArrayNotHasKey( 'unknown', $event['tags'] );
	}

	public function test_with_order_context_redacts_known_values(): void {
		$this->enable_monitoring();
		$address = '12 Nguyen Trai Street';
		$order   = wc_create_order();
		$order->set_billing_first_name( 'Lan' );
		$order->set_billing_last_name( 'Tran Thi' );
		$order->set_billing_company( 'Cong Ty Hoa Mai' );
		$order->set_billing_address_1( $address );
		$order->save();

		Monitor::with_order_context(
			$order->get_id(),
			static fn() => Monitor::capture_exception( new RuntimeException( "x {$address} for Cong Ty Hoa Mai" ) )
		);
		Monitor::capture_exception( new RuntimeException( "after {$address}" ) );

		$events = $this->sink->events();
		$this->assertCount( 2, $events );
		$inside = $events[0]['exception']['values'][0]['value'];
		$this->assertStringNotContainsString( $address, $inside );
		$this->assertStringNotContainsString( 'Cong Ty Hoa Mai', $inside );
		$this->assertStringContainsString( $address, $events[1]['exception']['values'][0]['value'], 'Context is cleared after the callable.' );
	}

	public function test_log_attribute_scrubbed(): void {
		$this->enable_monitoring();

		Monitor::log( 'info', 'm', array( 'e' => 'a@b.vn' ) );
		$this->assertSame( 0, $this->sink->request_count(), 'Logs are buffered until flush.' );
		Monitor::flush();

		$logs = $this->sink->logs();
		$this->assertCount( 1, $logs );
		$this->assertSame( 'm', $logs[0]['body'] );
		$this->assertSame( 'info', $logs[0]['level'] );
		$this->assertSame( '[filtered]', $logs[0]['attributes']['e']['value'] );
		$this->assertSame( 'example.org', $logs[0]['attributes']['site']['value'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $logs[0]['trace_id'] );
	}

	public function test_transaction_and_span(): void {
		$this->enable_monitoring( 1.0 );

		Monitor::start_transaction( 't', 'queue.task' );
		$result = Monitor::span( 'http.client', 'POST /api/x', static fn(): int => 1, static fn(): array => array( 'http.response.status_code' => 200 ) );
		Monitor::finish_transaction();

		$this->assertSame( 1, $result );
		$transactions = $this->sink->transactions();
		$this->assertCount( 1, $transactions );
		$this->assertSame( 't', $transactions[0]['transaction'] );
		$this->assertSame( 'queue.task', $transactions[0]['contexts']['trace']['op'] );
		$this->assertCount( 1, $transactions[0]['spans'] );
		$span = $transactions[0]['spans'][0];
		$this->assertSame( 'POST /api/x', $span['description'] );
		$this->assertSame( 'http.client', $span['op'] );
		$this->assertSame( 200, $span['data']['http.response.status_code'] );
		$this->assertSame( 'ok', $span['status'] );
	}

	public function test_unsampled_transaction_not_sent(): void {
		$this->enable_monitoring( 0.0 );

		Monitor::start_transaction( 't', 'queue.task' );
		Monitor::span( 'http.client', 'POST /api/x', static fn(): int => 1 );
		Monitor::finish_transaction();

		$this->assertSame( array(), $this->sink->transactions() );
	}

	public function test_check_in(): void {
		$this->enable_monitoring();

		$id = Monitor::check_in( 'ok', null, 1.5 );

		$this->assertNotNull( $id );
		$check_ins = $this->sink->check_ins();
		$this->assertCount( 1, $check_ins );
		$check_in = $check_ins[0];
		$this->assertSame( MonitorSlug::for_host( 'example.org' ), $check_in['monitor_slug'] );
		$this->assertSame( 'ok', $check_in['status'] );
		$this->assertSame( 1.5, (float) $check_in['duration'] );
		$this->assertSame(
			array(
				'type'  => 'interval',
				'value' => 15,
				'unit'  => 'minute',
			),
			$check_in['monitor_config']['schedule']
		);
		$this->assertSame( 15, $check_in['monitor_config']['checkin_margin'] );
		$this->assertSame( 10, $check_in['monitor_config']['max_runtime'] );
		$this->assertSame( 2, $check_in['monitor_config']['failure_issue_threshold'] );
	}

	public function test_check_in_pairs_ids(): void {
		$this->enable_monitoring();

		$id = (string) Monitor::check_in( 'in_progress' );
		Monitor::check_in( 'error', $id, 2.0 );

		$check_ins = $this->sink->check_ins();
		$this->assertSame( array( 'in_progress', 'error' ), array_column( $check_ins, 'status' ) );
		$this->assertSame( $id, $check_ins[1]['check_in_id'] );
	}

	public function test_internal_error_is_swallowed(): void {
		Monitor::reset_for_tests( self::throwing_backend() );

		$this->assertNull( Monitor::capture_exception( new RuntimeException( 'x' ) ) );
		Monitor::log( 'info', 'm' );
		Monitor::start_transaction( 't', 'queue.task' );
		Monitor::finish_transaction();
		$this->assertNull( Monitor::check_in( 'ok' ) );
		$this->assertNull( Monitor::send_test_event() );
		Monitor::flush();
		$this->assertSame( 'v', Monitor::with_order_context( 0, static fn(): string => 'v' ) );
	}

	public function test_redactor_failure_drops_event(): void {
		$this->enable_monitoring();
		$redactor = new class() implements Redactor {
			public function text( string $s ): string {
				throw new RuntimeException( 'redactor down' );
			}

			public function value( mixed $v ): mixed {
				throw new RuntimeException( 'redactor down' );
			}

			public function tags( array $tags ): array {
				throw new RuntimeException( 'redactor down' );
			}

			public function with_known_values( array $values ): static { // phpcs:ignore Universal.CodeAnalysis.StaticInFinalClass.ReturnType -- Redactor declares `static`; `self` is not a valid override.
				return $this;
			}
		};
		Monitor::reset_for_tests( new SentryBackend( MonitoringConfig::current(), $redactor ) );

		$this->assertNull( Monitor::capture_exception( new RuntimeException( 'a@b.vn' ) ) );
		Monitor::log( 'info', 'a@b.vn' );
		Monitor::flush();

		$this->assertSame( 0, $this->sink->request_count() );
	}

	public function test_span_runs_callable_once_and_rethrows(): void {
		$this->enable_monitoring();
		Monitor::start_transaction( 't', 'queue.task' );
		$calls = 0;

		try {
			Monitor::span(
				'http.client',
				'POST /api/x',
				static function () use ( &$calls ): void {
					++$calls;
					throw new DomainException( 'business failure' );
				}
			);
			$this->fail( 'The callable exception must propagate.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'business failure', $e->getMessage() );
		}
		Monitor::finish_transaction( 'internal_error' );

		$this->assertSame( 1, $calls );
		$this->assertSame( 'internal_error', $this->sink->transactions()[0]['spans'][0]['status'] );
	}

	public function test_span_survives_backend_failure(): void {
		Monitor::reset_for_tests( self::throwing_backend() );
		$calls = 0;

		$result = Monitor::span(
			'http.client',
			'POST /api/x',
			static function () use ( &$calls ): string {
				++$calls;
				return 'value';
			}
		);

		$this->assertSame( 'value', $result );
		$this->assertSame( 1, $calls );
	}

	public function test_span_keeps_result_when_backend_fails_after_callable(): void {
		Monitor::reset_for_tests( self::backend_failing_after_span_callable() );
		$calls = 0;

		$result = Monitor::span(
			'http.client',
			'POST /api/x',
			static function () use ( &$calls ): string {
				++$calls;
				return 'value';
			}
		);

		$this->assertSame( 'value', $result );
		$this->assertSame( 1, $calls );
	}

	public function test_span_rethrows_callable_exception_when_backend_fails_after_it(): void {
		Monitor::reset_for_tests( self::backend_failing_after_span_callable() );
		$calls = 0;

		try {
			Monitor::span(
				'http.client',
				'POST /api/x',
				static function () use ( &$calls ): void {
					++$calls;
					throw new DomainException( 'business failure' );
				}
			);
			$this->fail( 'The callable exception must propagate.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'business failure', $e->getMessage() );
		}
		$this->assertSame( 1, $calls );
	}

	public function test_internal_failure_logs_class_name_only(): void {
		Monitor::reset_for_tests( self::throwing_backend() );
		$log      = (string) wp_tempnam( 'einv-monitor-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- capturing error_log output.

		try {
			Monitor::capture_exception( new RuntimeException( 'a@b.vn' ) );
		} finally {
			ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring the /dev/null of set_up().
		}

		$written = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
		wp_delete_file( $log );
		$this->assertStringContainsString( 'einvoice-matbao: monitoring failed (RuntimeException).', $written );
		$this->assertStringNotContainsString( 'a@b.vn', $written );
		$this->assertStringNotContainsString( 'backend down', $written );
	}

	public function test_span_survives_failing_data_callback(): void {
		$this->enable_monitoring();
		Monitor::start_transaction( 't', 'queue.task' );
		$calls = 0;

		$result = Monitor::span(
			'http.client',
			'POST /api/x',
			static function () use ( &$calls ): string {
				++$calls;
				return 'value';
			},
			static function (): array {
				throw new RuntimeException( 'data failure' );
			}
		);
		Monitor::finish_transaction();

		$this->assertSame( 'value', $result );
		$this->assertSame( 1, $calls );
		$this->assertCount( 1, $this->sink->transactions()[0]['spans'] );
	}

	public function test_with_order_context_returns_value_and_rethrows(): void {
		$this->enable_monitoring();
		$order = wc_create_order();

		$this->assertSame( 'v', Monitor::with_order_context( $order->get_id(), static fn(): string => 'v' ) );

		$calls = 0;
		try {
			Monitor::with_order_context(
				$order->get_id(),
				static function () use ( &$calls ): void {
					++$calls;
					throw new DomainException( 'business failure' );
				}
			);
			$this->fail( 'The callable exception must propagate.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'business failure', $e->getMessage() );
		}
		$this->assertSame( 1, $calls );
	}

	public function test_dsn_routing(): void {
		$sink4 = SentrySink::install( $this->http, 'sentry.test', 4 );
		$this->enable_monitoring();
		$this->monitoring_constants( array( 'EINVOICE_MATBAO_SENTRY_DSN' => 'https://k2@sentry.test/4' ) );

		Monitor::capture_exception( new RuntimeException( 'x' ) );

		$this->assertSame( 1, $sink4->request_count() );
		$this->assertSame( 0, $this->sink->request_count() );
		$this->assertStringContainsString( 'sentry_key=k2', $this->http->raw_requests()[0]['args']['headers']['X-Sentry-Auth'] );
	}

	public function test_send_test_event_returns_id(): void {
		$this->enable_monitoring();

		$id = Monitor::send_test_event();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $id );
		$events = $this->sink->events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'info', $events[0]['level'] );
		$this->assertSame( 'einvoice-matbao test event', $events[0]['message'] );
	}

	public function test_send_failure_returns_null(): void {
		$this->enable_monitoring();
		$this->sink->respond_with( 401 );

		$this->assertNull( Monitor::send_test_event() );
	}

	/**
	 * Throws from a frame that has an argument, so the SDK would record frame variables.
	 *
	 * @param string $argument Value that must not leave the site.
	 * @param string $message  Exception message.
	 */
	private static function exception_from( string $argument, string $message ): RuntimeException {
		try {
			self::fail_with( $argument, $message );
		} catch ( RuntimeException $e ) {
			return $e;
		}

		return new RuntimeException( $message );
	}

	/**
	 * Always throws.
	 *
	 * @param string $argument Frame argument.
	 * @param string $message  Exception message.
	 * @throws RuntimeException Always.
	 */
	private static function fail_with( string $argument, string $message ): void {
		unset( $argument ); // Only needed as a recorded frame argument.
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test exception, never rendered.
	}

	/**
	 * Backend whose span() runs the callable, then fails while closing the span.
	 */
	private static function backend_failing_after_span_callable(): Backend {
		return new class() implements Backend {
			public function capture_exception( \Throwable $e, array $tags, array $known ): ?string {
				return null;
			}

			public function capture_message( string $msg, string $level, array $tags, array $fingerprint, array $known ): ?string {
				return null;
			}

			public function start_transaction( string $name, string $op ): void {
			}

			public function finish_transaction( string $status ): void {
			}

			public function span( string $op, string $description, callable $callback, callable $data_of_result, array $known ): mixed {
				try {
					return $callback();
				} finally {
					throw new RuntimeException( 'span close failed' ); // phpcs:ignore Squiz.PHP.NonExecutableCode.ReturnNotRequired -- simulates a failure while closing the span.
				}
			}

			public function log( string $level, string $msg, array $attrs, array $known ): void {
			}

			public function check_in( string $slug, string $status, ?string $id, ?float $duration, array $schedule ): ?string {
				return null;
			}

			public function flush(): void {
			}
		};
	}

	/**
	 * Backend whose every method fails.
	 */
	private static function throwing_backend(): Backend {
		return new class() implements Backend {
			public function capture_exception( \Throwable $e, array $tags, array $known ): ?string {
				throw new RuntimeException( 'backend down' );
			}

			public function capture_message( string $msg, string $level, array $tags, array $fingerprint, array $known ): ?string {
				throw new RuntimeException( 'backend down' );
			}

			public function start_transaction( string $name, string $op ): void {
				throw new RuntimeException( 'backend down' );
			}

			public function finish_transaction( string $status ): void {
				throw new RuntimeException( 'backend down' );
			}

			public function span( string $op, string $description, callable $callback, callable $data_of_result, array $known ): mixed {
				throw new RuntimeException( 'backend down' );
			}

			public function log( string $level, string $msg, array $attrs, array $known ): void {
				throw new RuntimeException( 'backend down' );
			}

			public function check_in( string $slug, string $status, ?string $id, ?float $duration, array $schedule ): ?string {
				throw new RuntimeException( 'backend down' );
			}

			public function flush(): void {
				throw new RuntimeException( 'backend down' );
			}
		};
	}
}
