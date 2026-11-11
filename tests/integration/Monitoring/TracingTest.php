<?php
/**
 * Transactions for the plugin's Action Scheduler actions and REST routes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use ActionScheduler;
use LTTech\EinvoiceMatbao\Admin\SettingsController;
use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Matbao\MatbaoClient;
use LTTech\EinvoiceMatbao\Monitoring\Monitor;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\MonitoringFixture;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SentrySink;
use RuntimeException;
use WC_Order;
use WP_REST_Request;

/**
 * Real Action Scheduler runs and REST dispatches with the Sentry endpoint faked at the HTTP boundary;
 * the tracing hooks are the ones Plugin::boot() registered.
 */
final class TracingTest extends InvoiceWorkflowTestCase {

	use MonitoringFixture;

	private const OTHER_HOOK = 'einv_test_other_plugin_action';

	private const NESTED_ROUTE = '/run-draft';

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

	public function test_draft_action_creates_transaction(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$this->fake_create_invoice_ok();
		$this->forget_matbao_token();

		$this->complete_and_run( $this->new_order() );

		$transactions = $this->sink->transactions();
		$this->assertCount( 1, $transactions );
		$transaction = $transactions[0];
		$this->assertSame( DraftInvoiceWorkflow::ACTION, $transaction['transaction'] );
		$this->assertSame( 'queue.task', $transaction['contexts']['trace']['op'] );
		$this->assertSame( 'ok', $transaction['contexts']['trace']['status'] );
		$descriptions = array_column( $transaction['spans'], 'description' );
		$this->assertContains( 'POST /api/auth/login', $descriptions );
		$this->assertContains( 'POST /api/invoice/create-invoice', $descriptions );
	}

	public function test_failed_action_marks_internal_error(): void {
		$this->enable_monitoring();
		add_action(
			DraftInvoiceWorkflow::ACTION,
			static function (): never {
				throw new RuntimeException( 'action broke' );
			},
			5
		);
		$id = as_enqueue_async_action( DraftInvoiceWorkflow::ACTION, array( 999999 ), DraftInvoiceWorkflow::GROUP );

		ActionScheduler::runner()->process_action( $id, 'einv-test' );

		$transactions = $this->sink->transactions();
		$this->assertCount( 1, $transactions );
		$this->assertSame( 'internal_error', $transactions[0]['contexts']['trace']['status'] );
		$errors = $this->sink->events();
		$this->assertCount( 1, $errors );
		$this->assertSame( 'RuntimeException', $errors[0]['exception']['values'][0]['type'] );
		$this->assertSame( 'action_scheduler', $errors[0]['tags']['component'] );
		$this->assertSame( \ActionScheduler_Store::STATUS_FAILED, ActionScheduler::store()->get_status( (string) $id ) );
		$this->assert_transaction_depth_is_zero();
	}

	public function test_other_group_ignored(): void {
		$this->enable_monitoring();
		$ran = 0;
		add_action(
			self::OTHER_HOOK,
			static function () use ( &$ran ): void {
				++$ran;
			}
		);
		$id = as_enqueue_async_action( self::OTHER_HOOK, array(), 'woocommerce' );

		ActionScheduler::runner()->process_action( $id, 'einv-test' );

		$this->assertSame( 1, $ran );
		$this->assertSame( array(), $this->sink->transactions() );
		$this->assertSame( 0, $this->sink->request_count() );
	}

	public function test_rest_route_transaction(): void {
		$this->enable_monitoring();
		$this->login_as( 'administrator' );

		$response = $this->htmx( 'GET', '/tabs/lark', array( 'secret' => 'query-value' ) );
		$this->assertSame( 200, $response->get_status() );
		rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/types' ) );

		$transactions = $this->sink->transactions();
		$this->assertCount( 1, $transactions, 'Only the plugin route is traced.' );
		$this->assertSame( '/' . SettingsController::NAMESPACE . '/tabs/(?P<tab>[^/]+)', $transactions[0]['transaction'] );
		$this->assertSame( 'http.server', $transactions[0]['contexts']['trace']['op'] );
		$this->assertSame( 'ok', $transactions[0]['contexts']['trace']['status'] );
		foreach ( $this->sink->raw_bodies() as $body ) {
			$this->assertStringNotContainsString( 'query-value', $body );
			$this->assertStringNotContainsString( 'tabs/lark', $body );
		}
	}

	public function test_rest_transaction_is_named_after_readable_route_pattern(): void {
		$this->enable_monitoring();
		$this->login_as( 'administrator' );

		$this->htmx( 'GET', '/tabs/lark' );
		$this->htmx( 'POST', '/settings/monitoring/test' );

		$names = array_column( $this->sink->transactions(), 'transaction' );
		$this->assertSame(
			array(
				'/einvoice-matbao/v1/tabs/(?P<tab>[^/]+)',
				'/einvoice-matbao/v1/settings/monitoring/test',
			),
			$names
		);
	}

	public function test_denied_rest_request_is_traced_with_response_status(): void {
		$this->enable_monitoring();
		wp_set_current_user( 0 );

		$response = $this->htmx( 'GET', '/tabs/lark' );
		$this->assertSame( 401, $response->get_status() );
		$this->login_as( 'shop_manager' );
		$response = $this->htmx( 'GET', '/tabs/lark' );
		$this->assertSame( 403, $response->get_status() );

		$transactions = $this->sink->transactions();
		$this->assertCount( 2, $transactions );
		$this->assertSame( 'unauthenticated', $transactions[0]['contexts']['trace']['status'] );
		$this->assertSame( 'permission_denied', $transactions[1]['contexts']['trace']['status'] );
		$this->assertSame( '/einvoice-matbao/v1/tabs/(?P<tab>[^/]+)', $transactions[0]['transaction'] );
	}

	public function test_rest_response_returned_unchanged(): void {
		$this->login_as( 'administrator' );
		$disabled = $this->html( $this->htmx( 'GET', '/tabs/lark' ) );

		$this->enable_monitoring();
		$enabled = $this->html( $this->htmx( 'GET', '/tabs/lark' ) );

		$this->assertSame( $this->without_nonces( $disabled ), $this->without_nonces( $enabled ) );
	}

	public function test_action_inside_rest_request_reports_one_transaction(): void {
		$this->configure_matbao();
		$this->enable_monitoring();
		$this->fake_create_invoice_ok();
		$order = $this->new_order();
		$order->update_status( 'completed' );
		$this->register_route_running_draft_actions();

		$response = $this->htmx( 'POST', self::NESTED_ROUTE );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotNull( $this->invoice_of( $order )?->matbao_invoice_id, 'The draft action ran inside the request.' );

		$transactions = $this->sink->transactions();
		$this->assertCount( 1, $transactions );
		$this->assertSame( '/' . SettingsController::NAMESPACE . self::NESTED_ROUTE, $transactions[0]['transaction'] );
		$this->assertSame( 'http.server', $transactions[0]['contexts']['trace']['op'] );
		$this->assertContains( 'POST /api/invoice/create-invoice', array_column( $transactions[0]['spans'], 'description' ) );
		$this->assert_transaction_depth_is_zero();
	}

	public function test_route_error_without_status_is_internal_error(): void {
		$this->enable_monitoring();
		register_rest_route(
			SettingsController::NAMESPACE,
			'/broken',
			array(
				'methods'             => 'GET',
				'callback'            => static fn(): \WP_Error => new \WP_Error( 'einv_broken', 'broken' ),
				'permission_callback' => '__return_true',
			)
		);

		$response = $this->htmx( 'GET', '/broken' );

		$this->assertSame( 500, $response->get_status() );
		$transactions = $this->sink->transactions();
		$this->assertCount( 1, $transactions );
		$this->assertSame( 'internal_error', $transactions[0]['contexts']['trace']['status'] );
	}

	public function test_sampling_zero_still_reports_errors(): void {
		$this->configure_matbao();
		$this->enable_monitoring( 0.0 );
		$this->http->respond_with(
			self::CREATE_ROUTE,
			static function (): never {
				throw new RuntimeException( 'unexpected' );
			}
		);

		$this->complete_and_run( $this->new_order() );

		$this->assertSame( array(), $this->sink->transactions() );
		$this->assertNotEmpty( array_filter( $this->sink->events(), static fn( array $event ): bool => 'error' === ( $event['level'] ?? 'error' ) ) );
	}

	public function test_disabled_tracing_sends_nothing(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		Monitor::reset_for_tests();

		$this->complete_and_run( $this->new_order() );
		$this->htmx( 'GET', '/tabs/lark' );
		Monitor::flush();

		$this->assertSame( 0, $this->sink->request_count() );
	}

	/**
	 * A transaction opened now is the outermost one, so it is sent as soon as it finishes.
	 */
	private function assert_transaction_depth_is_zero(): void {
		$before = count( $this->sink->transactions() );
		Monitor::start_transaction( 'probe', 'test' );
		Monitor::finish_transaction();

		$this->assertCount( $before + 1, $this->sink->transactions(), 'No transaction is left open.' );
	}

	/**
	 * Registers a plugin-namespace route whose callback runs the queued draft actions synchronously.
	 */
	private function register_route_running_draft_actions(): void {
		register_rest_route(
			SettingsController::NAMESPACE,
			self::NESTED_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => function (): array {
					return array( 'ran' => $this->run_queued_draft_actions() );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Makes the next Mat Bao call log in first.
	 */
	private function forget_matbao_token(): void {
		$credentials = ( new CredentialStore() )->matbao();
		$this->assertNotNull( $credentials );
		( new MatbaoClient( $credentials ) )->forget_token();
	}

	/**
	 * A fresh order with one product.
	 */
	private function new_order(): WC_Order {
		return $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
	}

	/**
	 * Rendered HTML with nonce values blanked, so two renders compare equal.
	 *
	 * @param string $html Fragment.
	 */
	private function without_nonces( string $html ): string {
		return (string) preg_replace( '/[0-9a-f]{10}/', 'NONCE', $html );
	}
}
