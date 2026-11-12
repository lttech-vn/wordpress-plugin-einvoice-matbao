<?php
/**
 * Structured contexts and failure-detail scoping of the monitoring facade.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Matbao\MatbaoError;
use LTTech\EinvoiceMatbao\Monitoring\FailureDetail;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use RuntimeException;
use WP_UnitTestCase;

final class EventContextTest extends WP_UnitTestCase {

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
		$this->previous_error_log = (string) ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps expected internal-failure lines out of the test output.
		$this->enable_monitoring( 1.0 );
	}

	public function tear_down(): void {
		ini_set( 'error_log', $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring.
		$this->reset_monitoring();
		$this->http->uninstall();
		parent::tear_down();
	}

	private static function detail( int $order_id, InvoiceErrorCode $code, string $marker = 'a' ): FailureDetail {
		return new FailureDetail( $order_id, $code, array( 'line' => array( 'marker' => $marker ) ), array() );
	}

	public function test_exception_contexts_are_sent_and_masked(): void {
		Monitor::capture_exception(
			new RuntimeException( 'x' ),
			array(),
			static fn(): array => array(
				'probe' => array(
					'email' => 'a@b.vn',
					'n'     => 5,
				),
			)
		);

		$event = $this->sink->events()[0];
		$this->assertSame( 5, $event['contexts']['probe']['n'] );
		$this->assertSame( '[filtered]', $event['contexts']['probe']['email'] );
	}

	public function test_context_closure_failure_never_escapes(): void {
		$id      = null;
		$written = self::error_log_of(
			static function () use ( &$id ): void {
				$id = Monitor::capture_exception(
					new RuntimeException( 'x' ),
					array(),
					static function (): array {
						throw new RuntimeException( 'secret-buyer@example.vn' );
					}
				);
			}
		);

		$this->assertNull( $id );
		$this->assertSame( 0, $this->sink->request_count() );
		$this->assertStringContainsString( 'einvoice-matbao: monitoring failed (RuntimeException).', $written );
		$this->assertStringNotContainsString( 'secret-buyer', $written );
	}

	public function test_new_filter_tags_kept(): void {
		Monitor::capture_exception(
			new RuntimeException( 'x' ),
			array(
				'product_id'      => '55',
				'matbao.api_code' => '306',
			)
		);

		$tags = $this->sink->events()[0]['tags'];
		$this->assertSame( '55', $tags['product_id'] );
		$this->assertSame( '306', $tags['matbao.api_code'] );
	}

	public function test_filter_wins_over_new_tag(): void {
		Monitor::capture_exception( new RuntimeException( 'x' ), array( 'product_id' => '1234567890' ) );

		$this->assertSame( '[filtered]', $this->sink->events()[0]['tags']['product_id'] );
	}

	public function test_for_matbao_builds_context_and_tag(): void {
		$temporary = FailureDetail::for_matbao( 7, InvoiceErrorCode::MATBAO_HTTP_TEMPORARY, new MatbaoError( MatbaoError::KIND_HTTP, 'm', 306, 503, true ) );

		$this->assertSame(
			array(
				'kind'        => 'http',
				'http_status' => 503,
				'api_code'    => 306,
				'target_sent' => true,
				'auto_retry'  => true,
			),
			$temporary->contexts['matbao']
		);
		$this->assertSame( array( 'matbao.api_code' => '306' ), $temporary->tags );

		$rejected = FailureDetail::for_matbao( 7, InvoiceErrorCode::MATBAO_API, new MatbaoError( MatbaoError::KIND_API, 'm', null, 200, false ) );
		$this->assertFalse( $rejected->contexts['matbao']['auto_retry'] );
		$this->assertFalse( $rejected->contexts['matbao']['target_sent'] );
		$this->assertNull( $rejected->contexts['matbao']['api_code'] );
		$this->assertSame( array(), $rejected->tags );
	}

	public function test_failure_detail_scoped_to_callback(): void {
		$detail = self::detail( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT );

		Monitor::with_failure_detail(
			$detail,
			function () use ( $detail ): void {
				$this->assertSame( $detail, Monitor::failure_detail_for( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT ) );
				$this->assertNull( Monitor::failure_detail_for( 10, InvoiceErrorCode::MATBAO_HTTP ) );
				$this->assertNull( Monitor::failure_detail_for( 11, InvoiceErrorCode::LINE_TAX_RATE_COUNT ) );
			}
		);

		$this->assertNull( Monitor::failure_detail_for( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT ) );
	}

	public function test_reentrant_other_order_same_code(): void {
		$ten    = self::detail( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT );
		$eleven = self::detail( 11, InvoiceErrorCode::LINE_TAX_RATE_COUNT );

		Monitor::with_failure_detail(
			$ten,
			function () use ( $ten, $eleven ): void {
				Monitor::with_failure_detail(
					$eleven,
					function () use ( $ten, $eleven ): void {
						$this->assertSame( $eleven, Monitor::failure_detail_for( 11, InvoiceErrorCode::LINE_TAX_RATE_COUNT ) );
						$this->assertSame( $ten, Monitor::failure_detail_for( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT ) );
					}
				);
				$this->assertSame( $ten, Monitor::failure_detail_for( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT ) );
			}
		);
	}

	public function test_nested_failure_detail_innermost_wins_and_unwinds(): void {
		$outer = self::detail( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT, 'outer' );
		$inner = self::detail( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT, 'inner' );

		Monitor::with_failure_detail(
			$outer,
			function () use ( $outer, $inner ): void {
				try {
					Monitor::with_failure_detail(
						$inner,
						function () use ( $inner ): void {
							$this->assertSame( $inner, Monitor::failure_detail_for( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT ) );
							throw new RuntimeException( 'inner failed' );
						}
					);
					$this->fail( 'The exception must propagate.' );
				} catch ( RuntimeException $e ) {
					$this->assertSame( 'inner failed', $e->getMessage() );
				}
				$this->assertSame( $outer, Monitor::failure_detail_for( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT ) );
			}
		);
	}

	public function test_detail_closure_failure_runs_callback_once_without_scope(): void {
		$runs    = 0;
		$result  = null;
		$written = self::error_log_of(
			function () use ( &$runs, &$result ): void {
				$result = Monitor::with_failure_detail(
					static function (): FailureDetail {
						throw new RuntimeException( 'secret-buyer@example.vn' );
					},
					function () use ( &$runs ): string {
						++$runs;
						$this->assertNull( Monitor::failure_detail_for( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT ) );

						return 'done';
					}
				);
			}
		);

		$this->assertSame( 1, $runs );
		$this->assertSame( 'done', $result );
		$this->assertStringContainsString( 'einvoice-matbao: monitoring failed (RuntimeException).', $written );
		$this->assertStringNotContainsString( 'secret-buyer', $written );
		$this->assertSame( 0, $this->sink->request_count() );
	}

	public function test_callback_exception_propagates_with_detail_closure(): void {
		$detail = self::detail( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT );
		$logged = self::error_log_of(
			function () use ( $detail ): void {
				try {
					Monitor::with_failure_detail(
						static fn(): FailureDetail => $detail,
						static function (): never {
							throw new RuntimeException( 'callback failed' );
						}
					);
					$this->fail( 'The exception must propagate.' );
				} catch ( RuntimeException $e ) {
					$this->assertSame( 'callback failed', $e->getMessage() );
				}
			}
		);

		$this->assertSame( '', $logged, 'A callback exception is not a monitoring failure.' );
		$this->assertNull( Monitor::failure_detail_for( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT ) );
	}

	public function test_detail_closure_not_called_when_monitoring_inactive(): void {
		$this->reset_monitoring();
		$built = 0;
		$runs  = 0;

		$result = Monitor::with_failure_detail(
			static function () use ( &$built ): FailureDetail {
				++$built;
				return self::detail( 10, InvoiceErrorCode::LINE_TAX_RATE_COUNT );
			},
			static function () use ( &$runs ): int {
				return ++$runs;
			}
		);

		$this->assertSame( 0, $built );
		$this->assertSame( 1, $result );
	}
}
