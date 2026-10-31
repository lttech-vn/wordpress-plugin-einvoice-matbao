<?php
/**
 * Admin and Lark alerts for invoices that need an admin, at the workflow seam.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use ActionScheduler;
use LTTech\EinvoiceMatbao\Alert\AdminAlertNotice;
use LTTech\EinvoiceMatbao\Alert\AlertStore;
use LTTech\EinvoiceMatbao\Alert\InvoiceAlerts;
use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Lark\LarkWebhook;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp;
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

	/**
	 * Adds alerts of orders that do not need anything any more, as if they were left behind.
	 *
	 * @param int  $count     How many.
	 * @param bool $dismissed Whether the admin hid them.
	 */
	private function add_leftover_alerts( int $count, bool $dismissed ): void {
		for ( $n = 0; $n < $count; $n++ ) {
			$order_id = 900000 + $n + ( $dismissed ? 0 : 5000 );
			$key      = AlertStore::key( $order_id, 'matbao_http', 1 );
			AlertStore::create( $key, $order_id, 'matbao_http', 1 );
			if ( $dismissed ) {
				AlertStore::set( $key, 'dismissed', true );
			}
		}
	}

	public function test_notice_cost_is_bounded_and_an_old_real_alert_is_not_pushed_out_by_dismissed_ones(): void {
		global $wpdb;
		$order = $this->failing_order();
		$this->add_leftover_alerts( 60, true );

		$before = $wpdb->num_queries;
		$html   = $this->notice_html();
		$used   = $wpdb->num_queries - $before;

		$this->assertStringContainsString( '#' . $order->get_id(), $html );
		$this->assertLessThanOrEqual( 15, $used, 'Queries spent by the notice on one admin page.' );
	}

	public function test_old_real_alert_is_still_listed_after_the_sweep_removed_newer_expired_alerts(): void {
		$order = $this->failing_order();
		$this->add_leftover_alerts( 60, false );

		do_action( DraftInvoiceWorkflow::RECOVERY_ACTION );

		$this->assertStringContainsString( '#' . $order->get_id(), $this->notice_html() );
	}

	public function test_sweep_removes_expired_alerts_and_old_dismissed_ones_but_keeps_current_ones(): void {
		global $wpdb;
		$order   = $this->failing_order();
		$invoice = $this->invoice_of( $order );
		$this->assertNotNull( $invoice );
		$current = AlertStore::key( $order->get_id(), $invoice->error_code, $invoice->alert_cycle );
		$this->add_leftover_alerts( 3, false );

		do_action( DraftInvoiceWorkflow::RECOVERY_ACTION );

		$this->assertNotNull( AlertStore::get( $current ), 'A current alert stays.' );
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'einvoice_matbao_alert_' ) . '%' ) );
		$this->assertSame( array( $current ), $names, 'Expired leftovers are gone.' );

		AlertStore::set( $current, 'dismissed', time() - 31 * DAY_IN_SECONDS );
		do_action( DraftInvoiceWorkflow::RECOVERY_ACTION );
		$this->assertNull( AlertStore::get( $current ), 'Dismissed more than 30 days ago: gone.' );
	}

	public function test_recently_dismissed_alert_is_kept_for_its_cycle_and_the_alert_of_a_paid_invoice_is_removed(): void {
		$order = $this->failing_order();
		AdminAlertNotice::dismiss( $order->get_id() );
		do_action( DraftInvoiceWorkflow::RECOVERY_ACTION );
		$this->assertCount( 1, AlertStore::keys_of_order( $order->get_id() ), 'Dismissed a moment ago: kept so the same cycle does not alert again.' );

		$this->fake_create_invoice_ok();
		$this->fake_lookup_not_found();
		$this->assertTrue( DraftInvoiceWorkflow::restart( $order->get_id(), InvoiceStatus::FAILED ) );
		$this->run_queued_draft_actions();
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );

		do_action( DraftInvoiceWorkflow::RECOVERY_ACTION );
		$this->assertSame( array(), AlertStore::keys_of_order( $order->get_id() ) );
	}

	public function test_dismiss_reaches_alerts_beyond_the_notice_list(): void {
		$order = $this->failing_order();
		$this->add_leftover_alerts( 80, false );
		$keys = AlertStore::keys_of_order( $order->get_id() );
		$this->assertCount( 1, $keys );

		AdminAlertNotice::dismiss( $order->get_id() );

		$this->assertTrue( AlertStore::get( $keys[0] )['dismissed'] ?? false );
	}

	public function test_lark_server_error_is_retried_with_backoff_until_it_succeeds(): void {
		$this->configure_lark();
		$calls = 0;
		$this->http->respond_with(
			$this->lark_route,
			static function () use ( &$calls ): array {
				++$calls;
				return $calls < 3 ? FakeHttp::json_response( 503, array( 'msg' => 'busy' ) ) : FakeHttp::json_response(
					200,
					array(
						'code' => 0,
						'msg'  => 'success',
					)
				);
			}
		);
		$this->failing_order();

		$this->assertSame( 1, $this->run_alert_actions() );
		$first = as_next_scheduled_action( InvoiceAlerts::SEND_ACTION );
		$this->assertIsInt( $first );
		$this->assertEqualsWithDelta( time() + 60, $first, 5, 'First retry after 1 minute.' );
		$this->assertSame( 1, $this->run_alert_actions() );
		$second = as_next_scheduled_action( InvoiceAlerts::SEND_ACTION );
		$this->assertEqualsWithDelta( time() + 300, (int) $second, 5, 'Second retry after 5 minutes.' );
		$this->assertSame( 1, $this->run_alert_actions() );

		$this->assertSame( 3, $calls );
		$this->assertSame( 0, $this->run_alert_actions(), 'Delivered: nothing left to run.' );
		$this->assertStringNotContainsString( 'Không gửi được tin Lark', $this->notice_html() );
	}

	public function test_lark_server_error_stops_after_three_retries_and_leaves_the_invoice_alone(): void {
		$this->configure_lark();
		$this->http->respond_json( $this->lark_route, 502, array( 'msg' => 'bad gateway' ) );
		$order = $this->failing_order();

		$runs = 0;
		while ( $this->run_alert_actions() > 0 && $runs < 10 ) {
			++$runs;
		}

		$this->assertSame( 4, $runs, 'One try and three retries.' );
		$this->assertCount( 4, $this->http->requests_to( $this->lark_route ) );
		$this->assertStringContainsString( 'Không gửi được tin Lark', $this->notice_html() );
		$this->assertSame( InvoiceStatus::FAILED, $this->invoice_of( $order )?->status );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
	}

	public function test_lark_network_error_and_429_are_retried_but_a_4xx_is_not(): void {
		$this->configure_lark();
		$this->http->respond_with( $this->lark_route, static fn(): WP_Error => new WP_Error( 'http_request_failed', 'cURL error 28' ) );
		$this->failing_order();
		$this->assertSame( 1, $this->run_alert_actions() );
		$this->assertIsInt( as_next_scheduled_action( InvoiceAlerts::SEND_ACTION ), 'A network error is retried.' );
		$this->http->respond_json( $this->lark_route, 429, array( 'msg' => 'slow down' ) );
		$this->assertSame( 1, $this->run_alert_actions() );
		$this->assertIsInt( as_next_scheduled_action( InvoiceAlerts::SEND_ACTION ), '429 is retried.' );

		$this->http->respond_json( $this->lark_route, 400, array( 'msg' => 'bad webhook' ) );
		$this->assertSame( 1, $this->run_alert_actions() );
		$this->assertFalse( as_next_scheduled_action( InvoiceAlerts::SEND_ACTION ), '4xx is final.' );
	}

	public function test_lark_client_error_is_not_retried(): void {
		$this->configure_lark();
		$this->http->respond_json( $this->lark_route, 400, array( 'msg' => 'bad webhook' ) );
		$this->failing_order();

		$this->assertSame( 1, $this->run_alert_actions() );

		$this->assertFalse( as_next_scheduled_action( InvoiceAlerts::SEND_ACTION ) );
		$this->assertSame( 0, $this->run_alert_actions() );
		$this->assertCount( 1, $this->http->requests_to( $this->lark_route ) );
		$this->assertStringContainsString( 'Không gửi được tin Lark', $this->notice_html() );
	}

	public function test_missing_lark_configuration_is_not_retried(): void {
		$this->failing_order();

		$this->assertSame( 1, $this->run_alert_actions() );

		$this->assertFalse( as_next_scheduled_action( InvoiceAlerts::SEND_ACTION ) );
	}

	public function test_queue_exception_marks_the_alert_queue_failed(): void {
		add_filter(
			'pre_as_enqueue_async_action',
			static function ( $pre, string $hook ) {
				if ( InvoiceAlerts::SEND_ACTION === $hook ) {
					throw new \RuntimeException( 'queue down' );
				}
				return $pre;
			},
			10,
			2
		);
		$order = $this->failing_order();

		$keys = AlertStore::keys_of_order( $order->get_id() );
		$this->assertCount( 1, $keys );
		$this->assertSame( 'queue_failed', AlertStore::get( $keys[0] )['lark'] ?? '' );
	}

	public function test_dismiss_link_needs_the_capability_and_a_valid_nonce(): void {
		$order = $this->failing_order();
		$keys  = AlertStore::keys_of_order( $order->get_id() );
		$nonce = wp_create_nonce( AdminAlertNotice::DISMISS_ACTION . '_' . $order->get_id() );
		$_GET  = array(
			'order_id' => (string) $order->get_id(),
			'_wpnonce' => $nonce,
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		try {
			AdminAlertNotice::handle_dismiss();
			$this->fail( 'A user without manage_options must be stopped.' );
		} catch ( \WPDieException $stopped ) {
			$this->assertSame( 403, $stopped->getCode() );
		}
		$this->assertFalse( AlertStore::get( $keys[0] )['dismissed'] ?? true );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['_wpnonce'] = 'wrong';
		try {
			AdminAlertNotice::handle_dismiss();
			$this->fail( 'A wrong nonce must be stopped.' );
		} catch ( \WPDieException $stopped ) {
			$this->assertFalse( AlertStore::get( $keys[0] )['dismissed'] ?? true );
		} finally {
			$_GET = array();
		}
	}
}
