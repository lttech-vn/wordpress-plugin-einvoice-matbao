<?php
/**
 * Content of the needs-attention event: readable title and invoice context.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use LTTech\EinvoiceMatbao\Alert\AlertStore;
use LTTech\EinvoiceMatbao\Alert\InvoiceAlerts;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Monitoring\InvoiceContext;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use RuntimeException;
use WC_Order;
use WC_Tax;

/**
 * Real orders stop in the workflow and the Sentry endpoint is faked at the HTTP boundary.
 */
final class NeedsAttentionEventTest extends InvoiceWorkflowTestCase {

	use MonitoringFixture;

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
		$this->reset_monitoring();
		parent::tear_down();
	}

	public function test_readable_title(): void {
		$order = $this->order_with_two_tax_rates();

		$event = $this->only_warning();

		$this->assertSame(
			'Hóa đơn cần xử lý: Dòng không có đúng một thuế suất – Đơn #' . $order->get_id(),
			$event['message'] ?? $event['logentry']['formatted'] ?? null
		);
	}

	public function test_invoice_context_present(): void {
		$order = $this->order_with_two_tax_rates();

		$invoice = $this->only_warning()['contexts']['invoice'];

		$this->assertSame( $order->get_id(), $invoice['order_id'] );
		$this->assertSame( InvoiceErrorCode::LINE_TAX_RATE_COUNT->hint(), $invoice['hint'] );
		$this->assertSame( 'line_tax_rate_count', $invoice['error_code'] );
		$this->assertStringStartsWith( '/', $invoice['admin_path'] );
		$this->assertStringNotContainsString( '://', $invoice['admin_path'] );
	}

	public function test_admin_path_without_sensitive_parameters(): void {
		$this->assertSame(
			'/wp-admin/admin.php?page=wc-orders&action=edit&id=42',
			InvoiceContext::admin_path( 'https://shop.vn/wp-admin/admin.php?page=wc-orders&action=edit&id=42&_wpnonce=abc#x' )
		);
		$this->assertSame(
			'/wp-admin/post.php?post=42&action=edit',
			InvoiceContext::admin_path( 'https://shop.vn/wp-admin/post.php?post=42&action=edit&_wpnonce=abc' )
		);
	}

	public function test_alert_without_failure_detail(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		add_filter( 'pre_as_enqueue_async_action', static fn(): int => 0 );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->update_status( 'completed' );
		$record = $this->invoice_of( $order );
		$this->assertNotNull( $record );

		$this->age_invoice( $record, HOUR_IN_SECONDS );
		( new DraftInvoiceWorkflow() )->recover_stuck();
		( new DraftInvoiceWorkflow() )->recover_stuck();

		$event = $this->only_warning();
		$this->assertSame( 'queue_failed', $event['tags']['error_code'] );
		$this->assertArrayHasKey( 'invoice', $event['contexts'] );
		$this->assertArrayNotHasKey( 'line', $event['contexts'] );
		$this->assertArrayNotHasKey( 'matbao', $event['contexts'] );
	}

	public function test_invoice_context_without_order_has_no_admin_path(): void {
		$invoice = new InvoiceRecord( 1, 99999999, 'WC99999999', InvoiceStatus::FAILED, 'x', 'unexpected', 'system', '', '', '', null, null );

		$context = InvoiceContext::for_invoice( $invoice );

		$this->assertSame( '', $context['admin_path'] );
		$this->assertSame( 99999999, $context['order_id'] );
	}

	/**
	 * Ways an invoice stops for good.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function stop_cases(): array {
		return array(
			'final'             => array( 'final' ),
			'retries_exhausted' => array( 'retries_exhausted' ),
		);
	}

	/**
	 * A failing context builder changes nothing about the invoice flow.
	 *
	 * @dataProvider stop_cases
	 *
	 * @param string $stop `final` or `retries_exhausted`.
	 */
	public function test_context_building_fails_keeps_invoice_outcome( string $stop ): void {
		$this->configure_matbao();
		$this->http->respond_json( self::CREATE_ROUTE, 'final' === $stop ? 400 : 503, array( 'message' => 'x' ) );

		$baseline = $this->outcome_of( $this->stop_order() );

		$this->enable_monitoring();
		$calls = 0;
		add_filter(
			'woocommerce_get_edit_order_url',
			static function () use ( &$calls ): never {
				++$calls;
				throw new RuntimeException( 'order url unavailable' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test fixture, never rendered.
			}
		);
		$monitored = $this->outcome_of( $this->stop_order() );

		$this->assertGreaterThan( 0, $calls, 'The context really failed to build.' );
		$this->assertSame( $baseline, $monitored );
		$this->assertContains( $monitored['status'], array( InvoiceStatus::FAILED, InvoiceStatus::NEEDS_ATTENTION ) );
	}

	/**
	 * Completes an order and runs its draft actions until no retry is left.
	 */
	private function stop_order(): WC_Order {
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->update_status( 'completed' );
		$ran = $this->run_queued_draft_actions();
		for ( $run = 0; $run < 10 && $ran > 0; $run++ ) {
			$ran = $this->run_queued_draft_actions();
		}

		return $order;
	}

	/**
	 * What the invoice flow left behind for an order, without order or invoice IDs.
	 *
	 * @param WC_Order $order Order.
	 * @return array{status: string|null, error_code: string|null, pending: int, alert: bool}
	 */
	private function outcome_of( WC_Order $order ): array {
		$record  = $this->invoice_of( $order );
		$pending = 0;
		foreach ( $this->queued_draft_action_ids() as $id ) {
			$args     = \ActionScheduler::store()->fetch_action( (string) $id )->get_args();
			$pending += in_array( $order->get_id(), array_map( 'intval', $args ), true ) ? 1 : 0;
		}

		return array(
			'status'     => $record?->status,
			'error_code' => $record?->error_code,
			'pending'    => $pending,
			'alert'      => array() !== AlertStore::keys_of_order( $order->get_id() ),
		);
	}

	/**
	 * Stops an order with an enabled monitor because a line has two tax rates.
	 */
	private function order_with_two_tax_rates(): WC_Order {
		$this->create_tax_rate( 'einv-double', 5 );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => '5',
				'tax_rate_name'     => 'Second 5',
				'tax_rate_priority' => 2,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => 'einv-double',
			)
		);
		$this->configure_matbao();
		$this->enable_monitoring();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'Hai thuế', '100000', 'einv-double' ), 1 ) ) );
		$this->complete_and_run( $order );

		return $order;
	}

	/**
	 * The only warning event sent.
	 *
	 * @return array<string, mixed>
	 */
	private function only_warning(): array {
		Monitor::flush();
		$warnings = array_values( array_filter( $this->sink->events(), static fn( array $event ): bool => 'warning' === ( $event['level'] ?? 'error' ) ) );
		$this->assertCount( 1, $warnings );

		return $warnings[0];
	}
}
