<?php
/**
 * Issues, status logs and HTTP spans reported from the invoice workflow.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use ActionScheduler;
use LTTech\EinvoiceMatbao\Alert\InvoiceAlerts;
use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\OrderChangeWatcher;
use LTTech\EinvoiceMatbao\Lark\LarkWebhook;
use LTTech\EinvoiceMatbao\Matbao\MatbaoClient;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use RuntimeException;
use WC_Order;

/**
 * Real orders go through the workflow with Mat Bao, Lark and the Sentry endpoint faked at the
 * HTTP boundary.
 */
final class WorkflowMonitoringTest extends InvoiceWorkflowTestCase {

	use MonitoringFixture;

	private const ADDRESS = '12 Nguyen Trai Street';

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
		InvoiceAlerts::register();
		$this->sink               = SentrySink::install( $this->http );
		$this->previous_error_log = (string) ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps expected failure lines out of the test output.
	}

	public function tear_down(): void {
		ini_set( 'error_log', $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring.
		OrderChangeWatcher::reset();
		$this->reset_monitoring();
		parent::tear_down();
	}

	public function test_unexpected_error_reported(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$token = str_repeat( 'aB3', 12 );
		$this->http->respond_with(
			self::CREATE_ROUTE,
			static function () use ( $token ): never {
				throw new RuntimeException( 'token ' . $token . ' at https://internal.example/path' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test fixture, never rendered.
			}
		);
		$order = $this->new_order();

		$this->complete_and_run( $order );

		$errors = $this->events_of_level( 'error' );
		$this->assertCount( 1, $errors );
		$event = $errors[0];
		$this->assertNotEmpty( $event['exception']['values'][0]['stacktrace']['frames'] ?? array() );
		$this->assertSame( (string) $order->get_id(), $event['tags']['order_id'] );
		$this->assertSame( 'draft_workflow', $event['tags']['component'] );
		$value = (string) $event['exception']['values'][0]['value'];
		$this->assertStringNotContainsString( $token, $value );
		$this->assertStringNotContainsString( 'internal.example', $value );
		$this->assertCount( 1, $this->events_of_level( 'warning' ), 'The needs-attention event is separate.' );
		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::FAILED, $record?->status );
		$this->assertSame( 'unexpected', $record->error_code );
	}

	public function test_needs_attention_once_per_alert(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$this->fake_create_invoice_timeout();
		$this->fake_lookup_not_found();
		$order = $this->new_order();
		$order->update_status( 'completed' );

		for ( $run = 0; $run < 6; $run++ ) {
			$this->run_queued_draft_actions();
		}

		$this->assertSame( InvoiceStatus::NEEDS_ATTENTION, $this->invoice_of( $order )?->status );
		$warnings = $this->events_of_level( 'warning' );
		$this->assertCount( 1, $warnings, 'Both hooks fire, the alert store dedupes.' );
		$this->assertSame( array( 'needs-attention', 'matbao_transport' ), $warnings[0]['fingerprint'] );
		$this->assertSame( 'matbao_transport', $warnings[0]['tags']['error_code'] );
		$this->assertSame( 'matbao', $warnings[0]['tags']['error_kind'] );
		$this->assertSame( InvoiceStatus::NEEDS_ATTENTION, $warnings[0]['tags']['invoice.status'] );
		$this->assertSame( (string) $order->get_id(), $warnings[0]['tags']['order_id'] );
	}

	public function test_same_code_two_orders_same_fingerprint(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$this->http->respond_json( self::CREATE_ROUTE, 400, array( 'message' => 'Sai dữ liệu' ) );

		$first  = $this->new_order();
		$second = $this->new_order();
		$this->complete_and_run( $first );
		$this->complete_and_run( $second );

		$warnings = $this->events_of_level( 'warning' );
		$this->assertCount( 2, $warnings );
		$this->assertSame( array( 'needs-attention', 'matbao_http' ), $warnings[0]['fingerprint'] );
		$this->assertSame( $warnings[0]['fingerprint'], $warnings[1]['fingerprint'] );
		$this->assertNotSame( $warnings[0]['tags']['order_id'], $warnings[1]['tags']['order_id'] );
	}

	public function test_status_changes_logged(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$this->fake_create_invoice_ok();
		$order = $this->new_order();

		$this->complete_and_run( $order );
		Monitor::flush();

		$invoice = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $invoice?->status );
		$changes = array_values(
			array_map(
				static fn( array $log ): string => $log['attributes']['from']['value'] . '→' . $log['attributes']['to']['value'],
				array_filter( $this->sink->logs(), static fn( array $log ): bool => 'invoice status changed' === $log['body'] )
			)
		);
		$this->assertSame( array( 'pending→sending', 'sending→draft_created' ), $changes );
		$logs = array_values( array_filter( $this->sink->logs(), static fn( array $log ): bool => 'invoice status changed' === $log['body'] ) );
		$this->assertSame( 'info', $logs[0]['level'] );
		$this->assertSame( $invoice->id, $logs[0]['attributes']['invoice.id']['value'] );
		$this->assertArrayHasKey( 'error_code', $logs[1]['attributes'] );
	}

	public function test_matbao_spans(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$this->fake_create_invoice_ok();
		$credentials = ( new CredentialStore() )->matbao();
		$this->assertNotNull( $credentials );
		( new MatbaoClient( $credentials ) )->forget_token();
		$order = $this->new_order();

		Monitor::start_transaction( 't', 'queue.task' );
		$this->complete_and_run( $order );
		Monitor::finish_transaction();

		$transactions = $this->sink->transactions();
		$this->assertCount( 1, $transactions );
		$spans = array();
		foreach ( $transactions[0]['spans'] as $span ) {
			$spans[ $span['description'] ] = $span;
		}
		$this->assertArrayHasKey( 'POST /api/auth/login', $spans );
		$this->assertArrayHasKey( 'POST /api/invoice/create-invoice', $spans );
		$this->assertSame( 'http.client', $spans['POST /api/invoice/create-invoice']['op'] );
		$this->assertSame( 200, $spans['POST /api/auth/login']['data']['http.response.status_code'] );
		$this->assertSame( 200, $spans['POST /api/invoice/create-invoice']['data']['http.response.status_code'] );
		$encoded = (string) wp_json_encode( $transactions[0]['spans'] );
		foreach ( array( 'Authorization', 'Bearer', 'fake-token-1', self::PASSWORD, self::USERNAME, 'MTChieu', 'KHMSHDon' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $encoded, $forbidden );
		}
	}

	public function test_lark_failure_has_no_webhook(): void {
		$this->enable_monitoring();
		$this->with_encryption_key();
		( new CredentialStore() )->save_lark( new LarkWebhook( self::LARK_WEBHOOK, self::LARK_SECRET ) );
		$lark_route = 'POST ' . (string) wp_parse_url( self::LARK_WEBHOOK, PHP_URL_PATH );
		$this->http->respond_json( $lark_route, 500, array( 'msg' => 'boom ' . self::LARK_WEBHOOK ) );
		$this->configure_matbao();
		$this->http->respond_json( self::CREATE_ROUTE, 400, array( 'message' => 'Sai dữ liệu' ) );
		$this->complete_and_run( $this->new_order() );

		Monitor::start_transaction( 'alerts', 'queue.task' );
		$runs = 0;
		while ( $runs < 6 && $this->run_alert_actions() > 0 ) {
			++$runs;
		}
		Monitor::finish_transaction();

		$lark_errors = array_values( array_filter( $this->events_of_level( 'error' ), static fn( array $event ): bool => 'invoice_alerts' === ( $event['tags']['component'] ?? '' ) ) );
		$this->assertCount( 1, $lark_errors, 'The final Lark failure is reported once.' );
		$spans = $this->sink->transactions()[0]['spans'];
		$this->assertNotEmpty( $spans );
		foreach ( $spans as $span ) {
			$this->assertSame( 'POST lark-webhook', $span['description'] );
			$this->assertSame( 500, $span['data']['http.response.status_code'] );
		}
		$path = (string) wp_parse_url( self::LARK_WEBHOOK, PHP_URL_PATH );
		foreach ( $this->sink->raw_bodies() as $body ) {
			$this->assertStringNotContainsString( 'open.larksuite.com', $body );
			$this->assertStringNotContainsString( $path, $body );
			$this->assertStringNotContainsString( '00000000-fake-hook', $body );
			$this->assertStringNotContainsString( self::LARK_SECRET, $body );
		}
	}

	public function test_address_echoed_by_matbao_is_redacted(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$this->http->respond_json( self::CREATE_ROUTE, 400, array( 'message' => 'Địa chỉ ' . self::ADDRESS . ' không hợp lệ' ) );
		$rejected = $this->new_order( self::ADDRESS );
		$this->complete_and_run( $rejected );

		$this->http->respond_with(
			self::CREATE_ROUTE,
			static function (): never {
				throw new RuntimeException( 'Mat Bao echoed ' . self::ADDRESS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test fixture, never rendered.
			}
		);
		$crashed = $this->new_order( self::ADDRESS );
		$this->complete_and_run( $crashed );
		Monitor::flush();

		$attempts = $this->attempts_of( $this->invoice_of( $rejected ) ?? $this->fail( 'No invoice.' ) );
		$this->assertStringContainsString( self::ADDRESS, (string) $attempts[0]['message'], 'Mat Bao did echo the address.' );
		$this->assertCount( 1, $this->events_of_level( 'error' ) );
		$this->assertStringContainsString( '[filtered]', (string) $this->events_of_level( 'error' )[0]['exception']['values'][0]['value'] );
		$this->assertNotEmpty( $this->sink->logs() );
		foreach ( $this->sink->raw_bodies() as $body ) {
			$this->assertStringNotContainsString( self::ADDRESS, $body );
		}
	}

	public function test_order_watcher_failure_reported(): void {
		$this->enable_monitoring();
		$order = $this->new_order();
		OrderChangeWatcher::queue( $order->get_id() );
		$this->fail_invoice_reads();

		$written = self::error_log_of( array( OrderChangeWatcher::class, 'flush' ) );
		Monitor::flush();

		$this->assertStringContainsString( 'einvoice-matbao: order change check failed (RuntimeException).', $written );
		$errors = $this->events_of_level( 'error' );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'order_watcher', $errors[0]['tags']['component'] );
		$this->assertSame( (string) $order->get_id(), $errors[0]['tags']['order_id'] );
		$error_logs = array_values( array_filter( $this->sink->logs(), static fn( array $log ): bool => 'error' === $log['level'] ) );
		$this->assertCount( 1, $error_logs );
		$this->assertSame( 'RuntimeException', $error_logs[0]['attributes']['exception.type']['value'] );
	}

	public function test_disabled_behaviour_unchanged(): void {
		Monitor::reset_for_tests();
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$this->complete_and_run( $this->new_order() );

		$order = $this->new_order();
		OrderChangeWatcher::queue( $order->get_id() );
		$this->fail_invoice_reads();
		$written = self::error_log_of( array( OrderChangeWatcher::class, 'flush' ) );
		Monitor::flush();

		$this->assertStringContainsString( 'einvoice-matbao: order change check failed (RuntimeException).', $written );
		$this->assertSame( 0, $this->sink->request_count() );
	}

	public function test_server_down_keeps_invoice_flow(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$this->sink->fail_transport();
		$this->http->respond_json( self::CREATE_ROUTE, 503, array( 'message' => 'busy' ) );
		$order = $this->new_order();

		$this->complete_and_run( $order );

		$invoice = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::PENDING, $invoice?->status );
		$this->assertSame( 'matbao_http_temporary', $invoice->error_code );
		$this->assertEqualsWithDelta( 60, (int) $this->seconds_until_next_draft_action(), 5 );
		$this->assertLessThanOrEqual( 1, $this->sink->request_count() );
	}

	public function test_slow_sink_success_keeps_invoice_flow(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$sink = $this->sink;
		$this->http->respond_with(
			'POST /api/3/envelope/',
			static function () use ( $sink ) {
				usleep( 1_500_000 );

				return $sink->respond();
			}
		);
		$this->fake_create_invoice_ok();
		$order = $this->new_order();

		Monitor::start_transaction( 't', 'queue.task' );
		$this->complete_and_run( $order );
		Monitor::finish_transaction();
		Monitor::flush();

		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
		$this->assertSame( array( 't' ), array_column( $this->sink->transactions(), 'transaction' ) );
		foreach ( $this->http->raw_requests() as $request ) {
			if ( 'sentry.test' === wp_parse_url( $request['url'], PHP_URL_HOST ) ) {
				$this->assertSame( 3.0, $request['args']['timeout'] );
			}
		}
	}

	/**
	 * A pending order with one line, optionally with a billing address.
	 *
	 * @param string $address Billing address line 1.
	 */
	private function new_order( string $address = '' ): WC_Order {
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		if ( '' !== $address ) {
			$order->set_billing_address_1( $address );
			$order->save();
		}

		return $order;
	}

	/**
	 * Events of one level. The SDK leaves `level` out of exception events, which the Sentry
	 * protocol reads as `error`.
	 *
	 * @param string $level Sentry level.
	 * @return list<array<string, mixed>>
	 */
	private function events_of_level( string $level ): array {
		return array_values( array_filter( $this->sink->events(), static fn( array $event ): bool => ( $event['level'] ?? 'error' ) === $level ) );
	}

	/**
	 * Makes every read of the invoice table throw, so the order change check fails.
	 */
	private function fail_invoice_reads(): void {
		add_filter(
			'query',
			static function ( string $query ): string {
				if ( str_starts_with( ltrim( $query ), 'SELECT' ) && str_contains( $query, 'einvoice_matbao_invoice' ) ) {
					throw new RuntimeException( 'invoice table unavailable' );
				}

				return $query;
			}
		);
	}

	/**
	 * Runs every pending Lark alert action.
	 *
	 * @return int Number of actions run.
	 */
	private function run_alert_actions(): int {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => InvoiceAlerts::SEND_ACTION,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		foreach ( $ids as $id ) {
			ActionScheduler::runner()->process_action( (int) $id, 'einv-test' );
		}

		return count( $ids );
	}
}
