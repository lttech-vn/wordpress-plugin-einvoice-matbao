<?php
/**
 * One resolution hint on every surface an administrator or the monitoring server reads.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use ActionScheduler;
use LTTech\EinvoiceMatbao\Admin\OrderInvoiceMetaBox;
use LTTech\EinvoiceMatbao\Alert\AdminAlertNotice;
use LTTech\EinvoiceMatbao\Alert\InvoiceAlerts;
use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Lark\LarkWebhook;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use WC_Order;
use WC_Product_Simple;
use WC_Tax;

/**
 * A real order stops with `line_tax_rate_count`; the hint must match on Lark, the admin notice,
 * the order meta box, the invoice detail and the monitoring event.
 */
final class SameHintEverywhereTest extends InvoiceWorkflowTestCase {

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

	/**
	 * Path route of the fake Lark webhook.
	 *
	 * @var string
	 */
	private string $lark_route = '';

	public function set_up(): void {
		parent::set_up();
		InvoiceAlerts::register();
		$this->sink               = SentrySink::install( $this->http );
		$this->previous_error_log = (string) ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps expected failure lines out of the test output.
		$this->lark_route         = 'POST ' . (string) wp_parse_url( self::LARK_WEBHOOK, PHP_URL_PATH );
		$this->configure_matbao();
		$this->enable_monitoring();
	}

	public function tear_down(): void {
		ini_set( 'error_log', $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring.
		$this->reset_monitoring();
		parent::tear_down();
	}

	public function test_same_hint_everywhere(): void {
		$this->configure_lark();
		$order = $this->order_with_two_tax_rates();
		$this->assertSame( 1, $this->run_alert_actions() );

		$hint    = InvoiceErrorCode::LINE_TAX_RATE_COUNT->hint();
		$invoice = $this->invoice_of( $order );
		$this->assertNotNull( $invoice );
		$this->assertTrue( $invoice->needs_admin() );
		$this->assertSame( InvoiceErrorCode::LINE_TAX_RATE_COUNT->value, $invoice->error_code );

		// 1. Lark text, sent as plain text (no escaping).
		$sent = $this->http->requests_to( $this->lark_route );
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( $hint, $sent[0]['body']['content']['text'] );

		// 2. Admin notice (escaped for HTML).
		$this->assertStringContainsString( '<span class="einv-hint">' . esc_html( $hint ) . '</span>', $this->notice_html() );

		// 3. Order meta box: text and attribute.
		$meta = $this->meta_box_html( $order );
		$this->assertStringContainsString( 'data-einv-hint="' . esc_attr( $hint ) . '"', $meta );
		$this->assertStringContainsString( esc_html( $hint ), $meta );

		// 4. Invoice detail fragment, through the admin REST route.
		$this->login_as( 'administrator' );
		$detail = $this->html( $this->htmx( 'GET', '/invoices/' . $invoice->id ) );
		$this->assertStringContainsString( 'data-einv-hint="' . esc_attr( $hint ) . '"', $detail );
		$this->assertStringContainsString( esc_html( $hint ), $detail );

		// 5. Monitoring event context, unescaped.
		Monitor::flush();
		$events = array_values( array_filter( $this->sink->events(), static fn( array $event ): bool => 'warning' === ( $event['level'] ?? 'error' ) ) );
		$this->assertCount( 1, $events );
		$this->assertSame( $hint, $events[0]['contexts']['invoice']['hint'] );
	}

	/**
	 * Saves Lark and fakes its webhook.
	 */
	private function configure_lark(): void {
		$this->with_encryption_key();
		( new CredentialStore() )->save_lark( new LarkWebhook( self::LARK_WEBHOOK, self::LARK_SECRET ) );
		$this->http->respond_json(
			$this->lark_route,
			200,
			array(
				'code' => 0,
				'msg'  => 'success',
			)
		);
	}

	/**
	 * Completes an order whose only product has two tax rates, so the workflow stops.
	 */
	private function order_with_two_tax_rates(): WC_Order {
		$product = $this->double_rate_product( 'Áo thun', 'AO-01' );
		$order   = $this->create_order( array( array( $product, 1 ) ) );
		$this->complete_and_run( $order );

		return $order;
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
	 * Runs the queued Lark alert action.
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

	/**
	 * The admin notice rendered for an administrator.
	 */
	private function notice_html(): string {
		$this->login_as( 'administrator' );
		ob_start();
		AdminAlertNotice::render();

		return (string) ob_get_clean();
	}

	/**
	 * The order meta box HTML.
	 *
	 * @param WC_Order $order Order.
	 */
	private function meta_box_html( WC_Order $order ): string {
		ob_start();
		OrderInvoiceMetaBox::render( $order );

		return (string) ob_get_clean();
	}
}
