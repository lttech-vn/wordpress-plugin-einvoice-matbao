<?php
/**
 * Order and action context on caught exceptions.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use ActionScheduler;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Monitoring\CaughtFailure;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use RuntimeException;
use WC_Order;

/**
 * Real Action Scheduler runs and caught failures with the Sentry endpoint faked at the HTTP boundary.
 */
final class ExceptionContextTest extends InvoiceWorkflowTestCase {

	use MonitoringFixture;

	private const NAME    = 'Tran Thi Lan';
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
		$this->sink               = SentrySink::install( $this->http );
		$this->previous_error_log = (string) ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps expected failure lines out of the test output.
	}

	public function tear_down(): void {
		ini_set( 'error_log', $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring.
		$this->reset_monitoring();
		parent::tear_down();
	}

	public function test_action_scheduler_action_throws(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$order = $this->completed_order();
		$this->throw_from( DraftInvoiceWorkflow::ACTION, 'action broke' );
		$id = as_enqueue_async_action( DraftInvoiceWorkflow::ACTION, array( $order->get_id() ), DraftInvoiceWorkflow::GROUP );

		ActionScheduler::runner()->process_action( $id, 'einv-test' );

		$event = $this->sink->events()[0];
		$this->assertCount( 1, $this->sink->events() );
		$this->assertSame( (string) $order->get_id(), $event['tags']['order_id'] );
		$this->assertSame( 'einvoice_matbao_create_draft', $event['contexts']['action']['hook'] );
		$this->assertSame( (int) $id, $event['contexts']['action']['action_id'] );
		$this->assertSame( $order->get_id(), $event['contexts']['invoice']['order_id'] );
	}

	public function test_action_scheduler_exception_echoes_buyer(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$order = $this->completed_order();
		$this->throw_from( DraftInvoiceWorkflow::ACTION, 'bad buyer ' . self::NAME . ' at ' . self::ADDRESS );
		$id = as_enqueue_async_action( DraftInvoiceWorkflow::ACTION, array( $order->get_id() ), DraftInvoiceWorkflow::GROUP );

		ActionScheduler::runner()->process_action( $id, 'einv-test' );

		$this->assertGreaterThan( 0, $this->sink->request_count() );
		foreach ( $this->sink->raw_bodies() as $body ) {
			$this->assertStringNotContainsStringIgnoringCase( self::NAME, $body );
			$this->assertStringNotContainsStringIgnoringCase( self::ADDRESS, $body );
		}
	}

	public function test_action_without_an_order(): void {
		$this->enable_monitoring();
		$this->throw_from( DraftInvoiceWorkflow::RECOVERY_ACTION, 'sweep broke' );
		$id = as_enqueue_async_action( DraftInvoiceWorkflow::RECOVERY_ACTION, array(), DraftInvoiceWorkflow::GROUP );

		ActionScheduler::runner()->process_action( $id, 'einv-test' );

		$events = $this->sink->events();
		$this->assertCount( 1, $events );
		$this->assertSame( DraftInvoiceWorkflow::RECOVERY_ACTION, $events[0]['contexts']['action']['hook'] );
		$this->assertArrayNotHasKey( 'order_id', $events[0]['tags'] );
		$this->assertArrayNotHasKey( 'invoice', $events[0]['contexts'] );
	}

	public function test_caught_failure_without_order_still_sent(): void {
		$this->enable_monitoring();

		CaughtFailure::report( new RuntimeException( 'x' ), 'probe', 'probe failed' );

		$events = $this->sink->events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'probe', $events[0]['tags']['component'] );
		$this->assertArrayNotHasKey( 'invoice', $events[0]['contexts'] );
	}

	public function test_caught_failure_context_failure_keeps_unexpected(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$this->http->respond_with(
			self::CREATE_ROUTE,
			static function (): never {
				throw new RuntimeException( 'boom' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test fixture, never rendered.
			}
		);
		$order = $this->new_order();
		$order->update_status( 'completed' );
		// Armed after completion: WooCommerce's own emails read the same URL inside an output buffer.
		add_filter(
			'woocommerce_get_edit_order_url',
			static function (): never {
				throw new RuntimeException( 'order url unavailable' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test fixture, never rendered.
			}
		);

		$this->run_queued_draft_actions();

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::FAILED, $record?->status );
		$this->assertSame( 'unexpected', $record->error_code );
	}

	/**
	 * A fresh order with one product.
	 */
	private function new_order(): WC_Order {
		return $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
	}

	/**
	 * Order with buyer data, completed so it has an invoice record.
	 */
	private function completed_order(): WC_Order {
		$order = $this->new_order();
		$order->set_billing_first_name( 'Tran Thi' );
		$order->set_billing_last_name( 'Lan' );
		$order->set_billing_address_1( self::ADDRESS );
		$order->save();
		$order->update_status( 'completed' );

		return $order;
	}

	/**
	 * Makes the hook throw.
	 *
	 * @param string $hook    Hook name.
	 * @param string $message Exception message.
	 */
	private function throw_from( string $hook, string $message ): void {
		add_action(
			$hook,
			static function () use ( $message ): never {
				throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test fixture, never rendered.
			},
			5
		);
	}
}
