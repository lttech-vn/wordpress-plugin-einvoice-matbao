<?php
/**
 * Line and Mat Bao details on the needs-attention event, through the real workflow.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use LTTech\EinvoiceMatbao\Alert\InvoiceAlerts;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\LineFailure;
use LTTech\EinvoiceMatbao\Invoice\OrderChangeWatcher;
use LTTech\EinvoiceMatbao\Monitoring\FailureDetail;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Product_Simple;
use WC_Tax;

/**
 * Real orders stop in the workflow and the Sentry endpoint is faked at the HTTP boundary.
 */
final class WorkflowFailureDetailTest extends InvoiceWorkflowTestCase {

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
		$this->configure_matbao();
		$this->enable_monitoring();
	}

	public function tear_down(): void {
		ini_set( 'error_log', $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring.
		OrderChangeWatcher::reset();
		$this->reset_monitoring();
		parent::tear_down();
	}

	public function test_tax_rate_count_error_on_product_line(): void {
		$this->fake_create_invoice_ok();
		$product = $this->double_rate_product( 'Áo thun', 'AO-01' );

		$this->complete_and_run( $this->create_order( array( array( $product, 1 ) ) ) );

		$event = $this->only_warning();
		$line  = $event['contexts']['line'];
		$this->assertSame( $product->get_id(), $line['product_id'] );
		$this->assertSame( 'AO-01', $line['sku'] );
		$this->assertCount( 2, $line['tax_rate_ids'] );
		$this->assertSame( (string) $product->get_id(), $event['tags']['product_id'] );
	}

	public function test_mat_bao_rejection(): void {
		$this->http->respond_json(
			self::CREATE_ROUTE,
			200,
			array(
				'errorCode' => 200,
				'message'   => 'Thành công',
				'data'      => array(
					array(
						'errorCode' => 306,
						'message'   => 'Trùng mã tham chiếu',
						'data'      => null,
						'success'   => false,
					),
				),
				'success'   => true,
			)
		);

		$this->complete_and_run( $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) ) );

		$event  = $this->only_warning();
		$matbao = $event['contexts']['matbao'];
		$this->assertSame( 'api', $matbao['kind'] );
		$this->assertSame( 306, $matbao['api_code'] );
		$this->assertTrue( $matbao['target_sent'] );
		$this->assertSame( '306', $event['tags']['matbao.api_code'] );
	}

	public function test_transport_error_has_no_api_code_tag(): void {
		$this->fake_create_invoice_timeout();
		$this->fake_lookup_not_found();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->update_status( 'completed' );

		for ( $run = 0; $run < 6; $run++ ) {
			$this->run_queued_draft_actions();
		}

		$event = $this->only_warning();
		$this->assertSame( 'matbao_transport', $event['tags']['error_code'] );
		$this->assertNull( $event['contexts']['matbao']['api_code'] );
		$this->assertArrayNotHasKey( 'matbao.api_code', $event['tags'] );
	}

	public function test_product_name_equals_buyer_name(): void {
		$this->fake_create_invoice_ok();
		$name    = 'Nguyễn Văn A';
		$product = $this->double_rate_product( $name, 'AO-02' );

		$this->complete_and_run( $this->create_order( array( array( $product, 1 ) ) ) );

		$this->assertSame( '[filtered]', $this->only_warning()['contexts']['line']['name'] );
		foreach ( $this->sink->raw_bodies() as $body ) {
			$this->assertStringNotContainsString( $name, $body );
		}
	}

	public function test_fee_line_failure_has_no_product_tag(): void {
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Phí lẻ' );
		$fee->set_total( '10000.5' );
		$fee->set_tax_status( 'none' );
		$order->add_item( $fee );
		$order->save();

		$this->complete_and_run( $order );

		$event = $this->only_warning();
		$this->assertSame( 'amount_not_whole_vnd', $event['tags']['error_code'] );
		$this->assertSame( 'fee', $event['contexts']['line']['item_type'] );
		$this->assertArrayNotHasKey( 'product_id', $event['tags'] );
	}

	public function test_detail_does_not_leak_to_another_order(): void {
		$this->http->respond_json( self::CREATE_ROUTE, 400, array( 'message' => 'Sai dữ liệu' ) );
		$first  = $this->create_order( array( array( $this->double_rate_product( 'Hai thuế', 'AO-03' ), 1 ) ) );
		$second = $this->create_order( array( array( $this->create_product( 'B', '100000' ), 1 ) ) );

		$this->complete_and_run( $first );
		$this->complete_and_run( $second );

		Monitor::flush();
		$by_order = array();
		foreach ( $this->warnings() as $event ) {
			$by_order[ $event['tags']['order_id'] ] = $event;
		}
		$this->assertCount( 2, $by_order );
		$this->assertArrayHasKey( 'line', $by_order[ (string) $first->get_id() ]['contexts'] );
		$this->assertSame( 'matbao_http', $by_order[ (string) $second->get_id() ]['tags']['error_code'] );
		$this->assertArrayNotHasKey( 'line', $by_order[ (string) $second->get_id() ]['contexts'] );
	}

	public function test_post_draft_warning_during_failure(): void {
		OrderChangeWatcher::register();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$this->complete_and_run( $order );
		OrderChangeWatcher::flush();
		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh );
		$fresh->add_product( $this->create_product( 'B', '50000' ), 1 );
		$fresh->calculate_totals();
		$fresh->save();

		$detail = FailureDetail::for_line( $order->get_id(), InvoiceErrorCode::LINE_TAX_RATE_COUNT, new LineFailure( 1, 'line_item', 7, 0, 'X', 'X', array( 1, 2 ) ) );
		Monitor::with_failure_detail( $detail, static fn() => OrderChangeWatcher::flush() );

		$event = $this->only_warning();
		$this->assertSame( 'order_amounts_changed', $event['tags']['error_code'] );
		$this->assertArrayNotHasKey( 'line', $event['contexts'] );
		$this->assertArrayNotHasKey( 'product_id', $event['tags'] );
	}

	/**
	 * A taxable product whose class has two rates, so its line gets two tax rates.
	 *
	 * @param string $name Product name.
	 * @param string $sku  SKU.
	 */
	private function double_rate_product( string $name, string $sku ): WC_Product_Simple {
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

		return $this->create_product( $name, '100000', 'einv-double', 'taxable', $sku );
	}

	/**
	 * Warning events sent.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function warnings(): array {
		return array_values( array_filter( $this->sink->events(), static fn( array $event ): bool => 'warning' === ( $event['level'] ?? 'error' ) ) );
	}

	/**
	 * The only warning event sent.
	 *
	 * @return array<string, mixed>
	 */
	private function only_warning(): array {
		Monitor::flush();
		$warnings = $this->warnings();
		$this->assertCount( 1, $warnings );

		return $warnings[0];
	}
}
