<?php
/**
 * Admin and Lark alerts for invoices that need an admin, at the workflow seam.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use ActionScheduler;
use LTTech\EinvoiceMatbao\Alert\AdminAlertNotice;
use LTTech\EinvoiceMatbao\Alert\InvoiceAlerts;
use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Lark\LarkWebhook;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;
use WP_Error;

/**
 * Real orders go through the workflow with Mat Bao and Lark faked at the HTTP boundary.
 */
final class InvoiceAlertTest extends InvoiceWorkflowTestCase {

	/**
	 * Path route of the fake Lark webhook.
	 *
	 * @var string
	 */
	private string $lark_route = '';

	public function set_up(): void {
		parent::set_up();
		InvoiceAlerts::register();
		$this->lark_route = 'POST ' . (string) wp_parse_url( self::LARK_WEBHOOK, PHP_URL_PATH );
	}

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

	private function failing_order(): WC_Order {
		$this->configure_matbao();
		$this->http->respond_json( self::CREATE_ROUTE, 400, array( 'message' => 'Sai dữ liệu' ) );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$this->complete_and_run( $order );

		return $order;
	}

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

	private function notice_html(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		AdminAlertNotice::render();

		return (string) ob_get_clean();
	}

	private function exhaust_retries( WC_Order $order ): void {
		for ( $run = 0; $run < 6; $run++ ) {
			$this->run_queued_draft_actions();
		}
		$this->assertSame( InvoiceStatus::NEEDS_ATTENTION, $this->invoice_of( $order )?->status );
	}

	public function test_final_error_shows_an_admin_notice_with_a_link_to_the_order(): void {
		$order = $this->failing_order();

		$html = $this->notice_html();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( '#' . $order->get_id(), $html );
		$this->assertStringContainsString( 'Mắt Bão trả lỗi HTTP', $html );
		$this->assertStringContainsString( esc_url( $order->get_edit_order_url() ), $html );
	}

	public function test_final_error_sends_one_lark_message_with_only_order_alert_type_and_link(): void {
		$this->configure_lark();
		$order = $this->failing_order();

		$this->assertSame( 1, $this->run_alert_actions() );

		$sent = $this->http->requests_to( $this->lark_route );
		$this->assertCount( 1, $sent );
		$text = $sent[0]['body']['content']['text'];
		$this->assertStringContainsString( '#' . $order->get_id(), $text );
		$this->assertStringContainsString( 'matbao_http', $text );
		$this->assertStringContainsString( $order->get_edit_order_url(), $text );
	}

	public function test_lark_payload_and_notice_contain_no_personal_data_or_secrets(): void {
		$this->configure_lark();
		$order = $this->failing_order();
		$order->set_billing_address_1( '12 Đường Lê Lợi' );
		$order->set_billing_company( 'Công ty Người Mua' );
		$order->save();
		$this->run_alert_actions();

		$payload = (string) wp_json_encode( $this->http->requests_to( $this->lark_route )[0]['body'] ) . $this->notice_html();

		foreach ( array( 'Nguyễn', 'Văn A', 'buyer@example.invalid', 'Lê Lợi', 'Công ty', 'Sai dữ liệu', 'fake-hook', self::LARK_SECRET, 'fake-token' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $payload, $forbidden );
		}
	}

	public function test_exhausted_retries_alert_once_although_both_hooks_fire(): void {
		$this->configure_lark();
		$this->configure_matbao();
		$this->fake_create_invoice_timeout();
		$this->fake_lookup_not_found();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->update_status( 'completed' );

		$this->exhaust_retries( $order );
		$this->run_alert_actions();

		$this->assertCount( 1, $this->http->requests_to( $this->lark_route ), 'Retries before the last one are silent; the end alerts once.' );
		$this->assertStringContainsString( 'matbao_transport', $this->http->requests_to( $this->lark_route )[0]['body']['content']['text'] );
	}

	public function test_hooks_firing_again_for_the_same_order_and_error_do_not_alert_again(): void {
		$this->configure_lark();
		$order = $this->failing_order();
		$this->run_alert_actions();
		$invoice = $this->invoice_of( $order );
		$this->assertNotNull( $invoice );

		do_action( 'einvoice_matbao_invoice_needs_attention', $invoice, $invoice->error() );
		do_action( 'einvoice_matbao_retries_exhausted', $order->get_id(), $invoice->reference );

		$this->assertSame( 0, $this->run_alert_actions() );
		$this->assertCount( 1, $this->http->requests_to( $this->lark_route ) );
		$this->assertSame( 1, substr_count( $this->notice_html(), 'data-einv-alert=' ) );
	}

	public function test_dedupe_survives_a_restart_of_the_request_but_a_resend_cycle_alerts_again(): void {
		$this->configure_lark();
		$order = $this->failing_order();
		$this->run_alert_actions();

		// A new PHP request: nothing but the database remains.
		wp_cache_flush();
		$invoice = $this->invoice_of( $order );
		do_action( 'einvoice_matbao_invoice_needs_attention', $invoice, $invoice?->error() );
		$this->assertSame( 0, $this->run_alert_actions() );

		// The admin resends and it fails the same way: a new cycle.
		$this->fake_lookup_not_found();
		$this->assertTrue( DraftInvoiceWorkflow::restart( $order->get_id(), InvoiceStatus::FAILED ) );
		$this->run_queued_draft_actions();
		$this->assertSame( 1, $this->run_alert_actions() );
		$this->assertCount( 2, $this->http->requests_to( $this->lark_route ) );
	}

	public function test_different_error_on_the_same_order_alerts_separately(): void {
		$this->configure_lark();
		$order = $this->failing_order();
		$this->run_alert_actions();
		$invoice = $this->invoice_of( $order );

		do_action( 'einvoice_matbao_invoice_needs_attention', $invoice, \LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode::UNEXPECTED );

		$this->assertSame( 1, $this->run_alert_actions() );
	}

	public function test_lark_http_failure_only_leaves_a_notice_and_does_not_touch_the_invoice(): void {
		$this->configure_lark();
		$this->http->respond_json( $this->lark_route, 500, array( 'msg' => 'boom' ) );
		$order  = $this->failing_order();
		$before = $this->invoice_of( $order );

		$this->run_alert_actions();
		$this->run_queued_draft_actions();

		$after = $this->invoice_of( $order );
		$this->assertSame( $before?->status, $after?->status );
		$this->assertSame( $before?->error_code, $after?->error_code );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ), 'No invoice is created or resent.' );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
		$this->assertStringContainsString( 'Lark', $this->notice_html() );
		$this->assertStringNotContainsString( 'fake-hook', $this->notice_html() );
	}

	public function test_lark_network_error_and_missing_configuration_are_harmless(): void {
		$this->configure_lark();
		$this->http->respond_with( $this->lark_route, static fn(): WP_Error => new WP_Error( 'http_request_failed', 'cURL error 28 for fake-hook' ) );
		$order = $this->failing_order();
		$this->run_alert_actions();
		$this->assertSame( InvoiceStatus::FAILED, $this->invoice_of( $order )?->status );
		$this->assertStringNotContainsString( 'fake-hook', $this->notice_html() );

		delete_option( CredentialStore::OPTION );
		$second = $this->create_order( array( array( $this->create_product( 'B', '200000' ), 1 ) ) );
		$this->complete_and_run( $second );
		$before = count( $this->http->requests_to( $this->lark_route ) );
		$this->run_alert_actions();

		$this->assertSame( $before, count( $this->http->requests_to( $this->lark_route ) ), 'Without a webhook nothing is sent.' );
		$this->assertStringContainsString( '#' . $second->get_id(), $this->notice_html() );
	}

	public function test_a_listener_never_sends_lark_inline(): void {
		$this->configure_lark();
		$this->failing_order();

		$this->assertCount( 0, $this->http->requests_to( $this->lark_route ), 'Lark is only called from the queued action.' );
	}

	public function test_order_moved_out_of_completed_by_an_admin_raises_no_alert(): void {
		$this->configure_lark();
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->update_status( 'completed' );
		$order->update_status( 'processing' );
		$this->run_queued_draft_actions();

		$this->assertSame( 0, $this->run_alert_actions() );
		$this->assertStringNotContainsString( 'data-einv-alert=', $this->notice_html() );
	}

	public function test_notice_is_only_for_users_who_manage_options_and_can_be_dismissed(): void {
		$order = $this->failing_order();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		AdminAlertNotice::render();
		$this->assertSame( '', (string) ob_get_clean() );

		$html = $this->notice_html();
		$this->assertStringContainsString( 'data-einv-alert=', $html );
		$this->assertStringContainsString( 'einvoice_matbao_dismiss_alert', $html );

		AdminAlertNotice::dismiss( $order->get_id() );
		$this->assertStringNotContainsString( 'data-einv-alert=', $this->notice_html() );
	}

	public function test_notice_disappears_when_the_invoice_no_longer_needs_the_admin(): void {
		$order = $this->failing_order();
		$this->assertStringContainsString( 'data-einv-alert=', $this->notice_html() );

		$this->assertTrue( DraftInvoiceWorkflow::restart( $order->get_id(), InvoiceStatus::FAILED ) );

		$this->assertStringNotContainsString( 'data-einv-alert=', $this->notice_html() );
	}

	public function test_every_resend_that_stops_before_an_attempt_alerts_again_and_shows_again_after_dismissal(): void {
		$this->configure_lark();
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_currency( 'USD' );
		$order->save();
		$this->complete_and_run( $order );
		$this->assertSame( 1, $this->run_alert_actions() );
		AdminAlertNotice::dismiss( $order->get_id() );
		$this->assertStringNotContainsString( 'data-einv-alert=', $this->notice_html() );

		// The data error stops before any attempt, so the attempt table cannot tell the resends apart.
		$this->assertTrue( DraftInvoiceWorkflow::restart( $order->get_id(), InvoiceStatus::FAILED ) );
		$this->run_queued_draft_actions();
		$this->assertSame( 1, $this->run_alert_actions(), 'Second resend alerts Lark again.' );
		$this->assertStringContainsString( 'data-einv-alert=', $this->notice_html(), 'Second resend shows again after Ẩn.' );

		AdminAlertNotice::dismiss( $order->get_id() );
		$this->assertTrue( DraftInvoiceWorkflow::restart( $order->get_id(), InvoiceStatus::FAILED ) );
		$this->run_queued_draft_actions();
		$this->assertSame( 1, $this->run_alert_actions(), 'Third resend alerts Lark again.' );
		$this->assertCount( 3, $this->http->requests_to( $this->lark_route ) );
		$this->assertStringContainsString( 'data-einv-alert=', $this->notice_html() );
	}
}
