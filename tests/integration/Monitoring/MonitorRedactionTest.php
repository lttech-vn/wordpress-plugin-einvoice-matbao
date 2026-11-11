<?php
/**
 * Personal data never reaches the monitoring server, asserted on the raw envelopes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use DomainException;
use JsonSerializable;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use RuntimeException;
use Stringable;
use WC_Order;
use WP_UnitTestCase;

/**
 * Every assertion searches the whole request body (envelope header and all items), so data that
 * the SDK copies into trace contexts or the dynamic sampling context is covered too.
 */
final class MonitorRedactionTest extends WP_UnitTestCase {

	use MonitoringFixture;

	private const EMAIL   = 'buyer.secret@example.vn';
	private const ADDRESS = '12 Nguyen Trai Street';
	private const NAME    = 'Tran Thi Lan';

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
		remove_all_filters( 'woocommerce_order_get_billing_email' );
		$this->reset_monitoring();
		$this->http->uninstall();
		parent::tear_down();
	}

	/**
	 * Asserts that no raw envelope contains any of the values (JSON-escaped forms included).
	 *
	 * @param array<int, string> $values Values that must not leave the site.
	 */
	private function assert_not_sent( array $values ): void {
		$this->assertGreaterThan( 0, $this->sink->request_count(), 'Something must have been sent.' );
		foreach ( $this->sink->raw_bodies() as $body ) {
			foreach ( $values as $value ) {
				$this->assertStringNotContainsStringIgnoringCase( $value, $body );
				$this->assertStringNotContainsStringIgnoringCase( trim( (string) wp_json_encode( $value ), '"' ), $body );
				$this->assertStringNotContainsStringIgnoringCase( rawurlencode( $value ), $body );
			}
		}
	}

	/**
	 * Order with the buyer constants as billing data.
	 */
	private static function order(): WC_Order {
		$order = wc_create_order();
		$order->set_billing_first_name( 'Tran Thi' );
		$order->set_billing_last_name( 'Lan' );
		$order->set_billing_email( self::EMAIL );
		$order->set_billing_address_1( self::ADDRESS );
		$order->set_billing_company( self::NAME );
		$order->save();

		return $order;
	}

	public function test_stringable_log_attribute_is_scrubbed(): void {
		$attribute = new class( self::EMAIL ) implements Stringable {
			public function __construct( private readonly string $email ) {}

			public function __toString(): string {
				return 'contact ' . $this->email;
			}
		};

		Monitor::log( 'info', 'm', array( 'who' => $attribute ) );
		Monitor::flush();

		$this->assert_not_sent( array( self::EMAIL ) );
		$this->assertStringContainsString( '[filtered]', $this->sink->logs()[0]['attributes']['who']['value'] );
	}

	public function test_json_serializable_log_attribute_is_scrubbed(): void {
		$attribute = new class( self::EMAIL ) implements JsonSerializable {
			public function __construct( private readonly string $email ) {}

			public function jsonSerialize(): mixed {
				return array( 'email' => $this->email );
			}
		};

		Monitor::log( 'info', 'm', array( 'who' => $attribute ) );
		Monitor::flush();

		$this->assert_not_sent( array( self::EMAIL ) );
	}

	public function test_nested_object_log_attribute_is_scrubbed(): void {
		$inner        = new \stdClass();
		$inner->email = self::EMAIL;
		$outer        = new \stdClass();
		$outer->inner = $inner;

		Monitor::log( 'info', 'm', array( 'who' => array( 'nested' => $outer ) ) );
		Monitor::flush();

		$this->assert_not_sent( array( self::EMAIL ) );
	}

	public function test_known_value_in_object_attribute_is_scrubbed(): void {
		$order = self::order();
		Monitor::with_order_context(
			$order->get_id(),
			static function (): void {
				$address         = new \stdClass();
				$address->street = self::address();
				Monitor::log( 'info', 'm', array( 'where' => $address ) );
			}
		);
		Monitor::flush();

		$this->assert_not_sent( array( self::ADDRESS ) );
	}

	public function test_log_attribute_key_is_scrubbed(): void {
		Monitor::log( 'info', 'm', array( self::EMAIL => 'v' ) );
		Monitor::flush();

		$this->assert_not_sent( array( self::EMAIL ) );
	}

	public function test_transaction_name_and_op_are_scrubbed_everywhere(): void {
		Monitor::start_transaction( 'sync ' . self::EMAIL, 'queue.task ' . self::EMAIL );
		Monitor::span( 'http.client ' . self::EMAIL, 'POST /api/x', static fn(): int => 1 );
		Monitor::finish_transaction();

		$this->assertCount( 1, $this->sink->transactions() );
		$this->assert_not_sent( array( self::EMAIL ) );
	}

	public function test_error_inside_transaction_does_not_leak_trace_metadata(): void {
		Monitor::start_transaction( 'sync ' . self::EMAIL, 'queue.task ' . self::EMAIL );
		Monitor::capture_exception( new RuntimeException( 'x' ) );
		Monitor::finish_transaction();

		$this->assertCount( 1, $this->sink->events() );
		$this->assert_not_sent( array( self::EMAIL ) );
	}

	public function test_event_in_order_context_masks_trace_header_and_context(): void {
		$order = self::order();
		// Opened before the order context exists, so only the capture-time scrub can mask the name.
		Monitor::start_transaction( 'sync ' . self::NAME, 'queue.task ' . self::NAME );

		Monitor::with_order_context(
			$order->get_id(),
			static fn() => Monitor::capture_exception( new RuntimeException( 'x' ) )
		);

		$bodies = $this->sink->raw_bodies();
		$this->assertCount( 1, $bodies );
		$this->assertStringNotContainsString( self::NAME, $bodies[0] );
		$this->assertStringNotContainsString( rawurlencode( self::NAME ), $bodies[0] );
		$this->assertArrayHasKey( 'trace', $this->sink->envelope_headers()[0] );
		Monitor::finish_transaction();
	}

	public function test_log_in_order_context_masks_open_transaction_in_raw_envelope(): void {
		$order = self::order();
		// Opened outside the order context, so the name is only known to be personal later.
		Monitor::start_transaction( 'sync ' . self::NAME, 'queue.task ' . self::NAME );

		Monitor::with_order_context(
			$order->get_id(),
			static fn() => Monitor::log( 'info', 'inside' )
		);
		Monitor::flush();

		$this->assertCount( 1, $this->sink->logs() );
		$this->assert_not_sent( array( self::NAME ) );
		Monitor::finish_transaction();
		$this->assert_not_sent( array( self::NAME ) );
	}

	public function test_transaction_sent_after_context_masks_values_seen_while_open(): void {
		$order = self::order();
		Monitor::start_transaction( 'sync ' . self::NAME, 'queue.task' );

		// The span inside the context is where the buyer values are seen by the open transaction.
		Monitor::with_order_context(
			$order->get_id(),
			static fn() => Monitor::span( 'http.client', 'POST /api/x', static fn(): int => 1 )
		);
		Monitor::finish_transaction();

		$this->assertCount( 1, $this->sink->transactions() );
		$this->assert_not_sent( array( self::NAME ) );
	}

	public function test_trace_identifiers_survive_scrubbing_and_link_up(): void {
		$order = self::order();
		Monitor::start_transaction( 'sync ' . self::NAME, 'queue.task' );
		Monitor::with_order_context(
			$order->get_id(),
			static function (): void {
				Monitor::capture_exception( new RuntimeException( 'x' ) );
				Monitor::log( 'info', 'inside' );
			}
		);
		Monitor::span( 'http.client', 'POST /api/x', static fn(): int => 1 );
		Monitor::finish_transaction();

		$hex32 = '/^[0-9a-f]{32}$/';
		$hex16 = '/^[0-9a-f]{16}$/';
		$event = $this->sink->events()[0];
		$trace = $this->sink->transactions()[0]['contexts']['trace'];
		$this->assertMatchesRegularExpression( $hex32, $trace['trace_id'] );
		$this->assertMatchesRegularExpression( $hex16, $trace['span_id'] );
		$this->assertSame( $trace['trace_id'], $event['contexts']['trace']['trace_id'] );
		$this->assertMatchesRegularExpression( $hex16, $event['contexts']['trace']['span_id'] );
		$this->assertSame( $trace['trace_id'], $this->sink->logs()[0]['trace_id'] );
		$span = $this->sink->transactions()[0]['spans'][0];
		$this->assertSame( $trace['trace_id'], $span['trace_id'] );
		$this->assertSame( $trace['span_id'], $span['parent_span_id'] );
		$this->assertMatchesRegularExpression( $hex16, $span['span_id'] );

		foreach ( $this->sink->envelope_headers() as $header ) {
			$this->assertSame( $trace['trace_id'], $header['trace']['trace_id'] );
			$this->assertSame( 'k', $header['trace']['public_key'] );
			$this->assertSame( 'einvoice-matbao@' . EINVOICE_MATBAO_VERSION, $header['trace']['release'] );
			$this->assertNotSame( '[filtered]', $header['trace']['sample_rand'] ?? '' );
			$this->assertStringNotContainsString( self::NAME, (string) ( $header['trace']['transaction'] ?? '' ) );
		}
		$this->assert_not_sent( array( self::NAME ) );
	}

	public function test_transaction_name_snapshots_order_context(): void {
		$order = self::order();
		Monitor::with_order_context(
			$order->get_id(),
			static function (): void {
				Monitor::start_transaction( 'invoice for ' . self::name() . ' at ' . self::address(), 'queue.task' );
			}
		);
		Monitor::finish_transaction();

		$this->assertCount( 1, $this->sink->transactions() );
		$this->assert_not_sent( array( self::NAME, self::ADDRESS ) );
	}

	public function test_span_op_and_data_snapshot_order_context(): void {
		$order = self::order();
		Monitor::start_transaction( 't', 'queue.task' );
		Monitor::with_order_context(
			$order->get_id(),
			static fn() => Monitor::span(
				'http.client ' . self::name(),
				'POST ' . self::address(),
				static fn(): int => 1,
				static fn(): array => array( 'buyer' => self::name() )
			)
		);
		Monitor::finish_transaction();

		$this->assert_not_sent( array( self::NAME, self::ADDRESS ) );
	}

	public function test_unreadable_order_context_drops_captures_but_runs_callable(): void {
		$order = self::order();
		add_filter(
			'woocommerce_order_get_billing_email',
			static function (): string {
				throw new RuntimeException( 'order store down' );
			}
		);
		$calls = 0;

		$log = self::error_log_of(
			function () use ( $order, &$calls ): void {
				$result = Monitor::with_order_context(
					$order->get_id(),
					static function () use ( &$calls ): string {
						++$calls;
						Monitor::capture_exception( new RuntimeException( 'inside ' . self::address() ) );
						Monitor::log( 'info', 'inside ' . self::address() );
						Monitor::start_transaction( 'inside ' . self::address(), 'queue.task' );
						Monitor::span( 'http.client', 'POST ' . self::address(), static fn(): int => 1 );
						Monitor::finish_transaction();
						Monitor::check_in( 'ok' );

						return 'value';
					}
				);
				$this->assertSame( 'value', $result );
			}
		);
		Monitor::flush();

		$this->assertSame( 1, $calls );
		$this->assertSame( 0, $this->sink->request_count() );
		$this->assertStringContainsString( 'einvoice-matbao: monitoring failed (RuntimeException).', $log );
		$this->assertStringNotContainsString( 'order store down', $log );
	}

	public function test_unreadable_order_context_rethrows_and_restores(): void {
		$order = self::order();
		add_filter(
			'woocommerce_order_get_billing_email',
			static function (): string {
				throw new RuntimeException( 'order store down' );
			}
		);
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

		remove_all_filters( 'woocommerce_order_get_billing_email' );
		Monitor::capture_exception( new RuntimeException( 'after' ) );

		$this->assertSame( 1, $calls );
		$this->assertCount( 1, $this->sink->events(), 'Captures resume once the unreadable context closes.' );
	}

	public function test_unreadable_context_leaves_outer_transaction_open(): void {
		$order = self::order();
		add_filter(
			'woocommerce_order_get_billing_email',
			static function (): string {
				throw new RuntimeException( 'order store down' );
			}
		);
		Monitor::start_transaction( 'outer', 'queue.task' );

		Monitor::with_order_context(
			$order->get_id(),
			static function (): void {
				Monitor::start_transaction( 'inner', 'queue.task' );
				Monitor::finish_transaction();
			}
		);
		$this->assertSame( 0, $this->sink->request_count(), 'Outer transaction still open.' );

		remove_all_filters( 'woocommerce_order_get_billing_email' );
		Monitor::finish_transaction();

		$this->assertSame( array( 'outer' ), array_column( $this->sink->transactions(), 'transaction' ) );
	}

	public function test_nested_unreadable_context_restores_outer_context(): void {
		$order   = self::order();
		$failing = static function (): string {
			throw new RuntimeException( 'order store down' );
		};

		Monitor::with_order_context(
			$order->get_id(),
			static function () use ( $order, $failing ): void {
				add_filter( 'woocommerce_order_get_billing_email', $failing );
				Monitor::with_order_context(
					$order->get_id(),
					static fn() => Monitor::capture_exception( new RuntimeException( 'dropped' ) )
				);
				remove_filter( 'woocommerce_order_get_billing_email', $failing );
				Monitor::capture_exception( new RuntimeException( 'outer ' . self::address() ) );
			}
		);

		$events = $this->sink->events();
		$this->assertCount( 1, $events );
		$this->assertStringContainsString( 'outer', $events[0]['exception']['values'][0]['value'] );
		$this->assert_not_sent( array( self::ADDRESS, 'dropped' ) );
	}

	/**
	 * Test address.
	 */
	public static function address(): string {
		return self::ADDRESS;
	}

	/**
	 * Test buyer name.
	 */
	public static function name(): string {
		return self::NAME;
	}
}
