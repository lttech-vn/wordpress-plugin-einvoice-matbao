<?php
/**
 * Order edits and refunds after the draft exists (alert only), and buyer edits before it exists
 * (snapshot captured again), at the workflow seam.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshotter;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\OrderChangeWatcher;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;

/**
 * Real orders with Mat Bao faked at the HTTP boundary. Watching is deferred to the end of the
 * request, so tests call OrderChangeWatcher::flush() where PHP would reach `shutdown`.
 */
final class PostDraftOrderChangeTest extends InvoiceWorkflowTestCase {

	private \stdClass $attention;

	public function set_up(): void {
		parent::set_up();
		OrderChangeWatcher::register();
		$this->attention        = new \stdClass();
		$this->attention->codes = array();
		add_action(
			DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION,
			function ( InvoiceRecord $invoice, InvoiceErrorCode $code ): void {
				$this->attention->codes[] = $code->value;
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		OrderChangeWatcher::reset();
		parent::tear_down();
	}

	private function drafted_order(): WC_Order {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_billing_phone( '0900000001' );
		$order->save();
		$this->complete_and_run( $order );
		OrderChangeWatcher::flush();
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
		$this->attention->codes = array();

		return $order;
	}

	private function fresh( WC_Order $order ): WC_Order {
		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh );

		return $fresh;
	}

	public function test_editing_amounts_after_the_draft_alerts_once_and_changes_nothing(): void {
		$order    = $this->drafted_order();
		$before   = $this->invoice_of( $order );
		$requests = count( $this->http->requests );

		$order = $this->fresh( $order );
		$order->add_product( $this->create_product( 'B', '50000' ), 1 );
		$order->calculate_totals();
		$order->save();
		$order->save();
		OrderChangeWatcher::flush();
		OrderChangeWatcher::flush();

		$this->assertSame( array( 'order_amounts_changed' ), $this->attention->codes );
		$after = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $after?->status );
		$this->assertSame( $before?->matbao_invoice_id, $after->matbao_invoice_id );
		$this->assertSame( 'order_amounts_changed', $after->error_code );
		$this->assertSame( 'order', $after->error_kind );
		$this->assertCount( $requests, $this->http->requests, 'No update, cancel or create call.' );
		$notes = implode( ' ', array_map( static fn( $note ): string => $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) );
		$this->assertStringContainsString( 'không tự cập nhật', $notes );
		$this->assertStringNotContainsString( 'buyer@example.invalid', $notes );
	}

	public function test_saving_without_a_significant_change_does_not_alert(): void {
		$order = $this->drafted_order();

		$order = $this->fresh( $order );
		$order->set_customer_note( 'Giao giờ hành chính' );
		$order->save();
		$order->save();
		OrderChangeWatcher::flush();

		$this->assertSame( array(), $this->attention->codes );
		$this->assertSame( '', $this->invoice_of( $order )?->error_code );
	}

	public function test_a_second_change_alerts_again_and_the_same_change_does_not(): void {
		$order = $this->drafted_order();

		$order = $this->fresh( $order );
		$order->set_billing_phone( '0900000002' );
		$order->save();
		OrderChangeWatcher::flush();
		$order->save();
		OrderChangeWatcher::flush();
		$order->set_billing_phone( '0900000003' );
		$order->save();
		OrderChangeWatcher::flush();

		$this->assertSame( array( 'order_buyer_changed', 'order_buyer_changed' ), $this->attention->codes );
	}

	public function test_editing_the_buyer_after_the_draft_alerts_and_keeps_the_snapshot(): void {
		$order = $this->drafted_order();

		$order = $this->fresh( $order );
		$order->set_billing_phone( '0900000002' );
		$order->save();
		OrderChangeWatcher::flush();

		$this->assertSame( array( 'order_buyer_changed' ), $this->attention->codes );
		$this->assertSame( '0900000001', ( new BuyerSnapshotter() )->load( $this->fresh( $order ) )?->fields['NMua_SDThoai'] );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
	}

	public function test_a_partial_refund_after_the_draft_alerts_once(): void {
		$order    = $this->drafted_order();
		$requests = count( $this->http->requests );

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '10000',
			)
		);
		OrderChangeWatcher::flush();

		$this->assertSame( array( 'order_refunded_after_draft' ), $this->attention->codes );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
		$this->assertCount( $requests, $this->http->requests );
	}

	public function test_a_full_refund_after_the_draft_alerts_once(): void {
		$order = $this->drafted_order();

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => (string) $this->fresh( $order )->get_total(),
			)
		);
		OrderChangeWatcher::flush();

		$this->assertSame( array( 'order_refunded_after_draft' ), $this->attention->codes );
	}

	public function test_a_refund_before_the_draft_exists_only_stops_the_send(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->update_status( 'completed' );

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '10000',
			)
		);
		OrderChangeWatcher::flush();
		$this->run_queued_draft_actions();

		$this->assertSame( array( 'order_refunded' ), $this->attention->codes );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
	}

	public function test_a_refund_while_the_draft_is_being_created_alerts_after_it_exists(): void {
		$this->configure_matbao();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$this->http->respond_with(
			self::CREATE_ROUTE,
			function ( array $args ) use ( $order ): array {
				wc_create_refund(
					array(
						'order_id' => $order->get_id(),
						'amount'   => '10000',
					)
				);
				$sent = json_decode( (string) $args['body'], true );

				return \LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp::json_response(
					200,
					array(
						'errorCode' => 200,
						'success'   => true,
						'data'      => array(
							array(
								'errorCode' => 200,
								'success'   => true,
								'data'      => array(
									'mtChieu'  => $sent[0]['MTChieu'],
									'maSoHDon' => 'aWQ=',
									'nLap'     => $sent[0]['NLap'],
								),
							),
						),
					)
				);
			}
		);

		$this->complete_and_run( $order );
		OrderChangeWatcher::flush();

		$this->assertSame( array( 'order_refunded_after_draft' ), $this->attention->codes );
	}

	public function test_admin_editing_the_buyer_before_the_draft_succeeds_makes_the_next_attempt_use_it(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_billing_phone( '0900000001' );
		$order->save();
		$order->update_status( 'completed' );

		$order = $this->fresh( $order );
		$order->set_billing_phone( '0900000002' );
		$order->set_billing_first_name( 'Trần' );
		$order->save();
		OrderChangeWatcher::flush();
		$this->run_queued_draft_actions();
		OrderChangeWatcher::flush();

		$sent = $this->sent_invoices();
		$this->assertCount( 1, $sent );
		$this->assertSame( '0900000002', $sent[0]['NMua_SDThoai'] );
		$this->assertSame( 'Trần Văn A', $sent[0]['NMua_Ten'] );
		$this->assertSame( array(), $this->attention->codes, 'The draft carries the new buyer, so there is nothing to warn about.' );
	}

	public function test_a_buyer_edit_that_no_longer_validates_blocks_the_send_and_alerts_once(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_billing_phone( '0900000001' );
		$order->save();
		$order->update_status( 'completed' );
		$_POST = array(
			'einv_request_invoice' => '1',
			'einv_buyer_type'      => 'business',
			'einv_business_name'   => 'Doanh nghiệp Thử',
			'einv_tax_code'        => '0000000000-001',
			'einv_address'         => 'Số 2 Đường Thử, Quận Thử',
		);
		do_action( 'woocommerce_checkout_create_order', $order, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fires a WooCommerce hook.
		$_POST = array();
		$order->save();
		( new BuyerSnapshotter() )->capture( $order, 'Khách lẻ' );

		$order = $this->fresh( $order );
		$order->set_billing_phone( '12345' );
		$order->save();
		OrderChangeWatcher::flush();
		OrderChangeWatcher::flush();
		$this->run_queued_draft_actions();

		$this->assertSame( array( 'buyer_invalid' ), $this->attention->codes );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( '0900000001', ( new BuyerSnapshotter() )->load( $this->fresh( $order ) )?->fields['NMua_SDThoai'] ?? '0900000001' );
	}

	public function test_buyer_edit_after_a_create_that_may_have_reached_mat_bao_keeps_the_snapshot_then_warns_on_adopt(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_timeout();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_billing_phone( '0900000001' );
		$order->save();
		$order->update_status( 'completed' );
		$this->run_queued_draft_actions();
		$this->assertSame( InvoiceStatus::PENDING, $this->invoice_of( $order )?->status );

		$order = $this->fresh( $order );
		$order->set_billing_phone( '0900000002' );
		$order->save();
		OrderChangeWatcher::flush();
		$this->assertSame( '0900000001', ( new BuyerSnapshotter() )->load( $this->fresh( $order ) )?->fields['NMua_SDThoai'] );

		$this->fake_lookup_found();
		$this->http->respond_json( self::CREATE_ROUTE, 500, array() );
		$this->age_invoice( $this->invoice_of( $order ), 0 );
		$this->run_queued_draft_actions();
		OrderChangeWatcher::flush();

		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ), 'Adopted, not created again.' );
		$this->assertSame( array( 'order_buyer_changed' ), $this->attention->codes );
		$this->assertSame( '0900000001', ( new BuyerSnapshotter() )->load( $this->fresh( $order ) )?->fields['NMua_SDThoai'] );
	}

	public function test_only_an_administrator_edit_captures_the_snapshot_again(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_billing_phone( '0900000001' );
		$order->save();
		$order->update_status( 'completed' );

		$this->login_as( 'shop_manager' );
		$order = $this->fresh( $order );
		$order->set_billing_phone( '0900000002' );
		$order->save();
		OrderChangeWatcher::flush();
		$this->run_queued_draft_actions();

		$this->assertSame( '0900000001', $this->sent_invoices()[0]['NMua_SDThoai'] );
	}

	/**
	 * Lookup rows whose buyer differs from the snapshot, with the field names Mat Bao may use.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function buyer_mismatches(): array {
		return array(
			'another buyer name' => array( array( 'tenNMua' => 'Người Khác' ) ),
			'another tax code'   => array( array( 'mstNMua' => '0999999999' ) ),
		);
	}

	/**
	 * @dataProvider buyer_mismatches
	 *
	 * @param array<string, mixed> $row Lookup row override.
	 */
	public function test_adopt_refuses_a_draft_with_another_buyer(array $row): void {
		$this->configure_matbao();
		$this->fake_create_invoice_timeout();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->update_status( 'completed' );
		$this->run_queued_draft_actions();

		$this->fake_lookup_rows( array( $row + array( 'tenNMua' => 'Nguyễn Văn A' ) ) );
		$this->run_queued_draft_actions();

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::NEEDS_ATTENTION, $record?->status );
		$this->assertSame( 'matbao_lookup_mismatch', $record->error_code );
		$this->assertNull( $record->matbao_invoice_id );
		$this->assertStringNotContainsString( 'Người Khác', $record->status_reason );
	}

	public function test_adopt_accepts_a_draft_with_the_same_buyer_or_no_buyer_fields(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_timeout();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->update_status( 'completed' );
		$this->run_queued_draft_actions();

		$this->fake_lookup_rows( array( array( 'tenNMua' => 'nguyễn văn a' ) ) );
		$this->run_queued_draft_actions();

		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
	}
}
