<?php
/**
 * The draft carries the buyer captured when the order completed.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshotter;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;

/**
 * The workflow seam: a request stored by checkout becomes a snapshot on completion, and every
 * attempt sends from it. All identity numbers are obviously fake test values.
 */
final class DraftInvoiceBuyerSnapshotTest extends InvoiceWorkflowTestCase {

	private const ID_NUMBER = '000000001234';

	public function tear_down(): void {
		$_POST = array();

		parent::tear_down();
	}

	public function test_individual_draft_carries_the_snapshot_fields(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->order_with_request( $this->individual_post() );

		$this->complete_and_run( $order );

		$invoice = $this->sent_invoices()[0];
		$this->assertSame( 'Nguyễn Văn A', $invoice['NMua_Ten'] );
		$this->assertSame( 'Nguyễn Văn A', $invoice['NMua_HVTNMHang'] );
		$this->assertSame( 'Số 1 Đường Thử, Quận Thử', $invoice['NMua_DChi'] );
		$this->assertSame( self::ID_NUMBER, $invoice['NMua_CCCDan'] );
		$this->assertSame( '0900000001', $invoice['NMua_SDThoai'] );
		$this->assertSame( 'buyer@example.invalid', $invoice['NMua_DCTDTu'] );
		$this->assertArrayNotHasKey( 'NMua_MST', $invoice );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
		$this->assertSame( 'Nguyễn Văn A', $this->invoice_of( $order )?->buyer_name );
	}

	public function test_business_draft_carries_the_snapshot_fields_without_a_contact_name(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->order_with_request( $this->business_post() );

		$this->complete_and_run( $order );

		$invoice = $this->sent_invoices()[0];
		$this->assertSame( 'Doanh nghiệp Thử', $invoice['NMua_Ten'] );
		$this->assertSame( '0000000000-001', $invoice['NMua_MST'] );
		$this->assertSame( 'Số 2 Đường Thử, Quận Thử', $invoice['NMua_DChi'] );
		$this->assertSame( '0900000001', $invoice['NMua_SDThoai'] );
		$this->assertSame( 'buyer@example.invalid', $invoice['NMua_DCTDTu'] );
		$this->assertArrayNotHasKey( 'NMua_CCCDan', $invoice );
		$this->assertArrayNotHasKey( 'NMua_HVTNMHang', $invoice );
		$this->assertSame( 'Doanh nghiệp Thử', $this->invoice_of( $order )?->buyer_name );
	}

	public function test_order_with_billing_but_no_request_is_a_walk_in_buyer(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_billing_phone( '0900000001' );
		$order->save();

		$this->complete_and_run( $order );

		$invoice = $this->sent_invoices()[0];
		$this->assertSame( 'Khách hàng không lấy hóa đơn', $invoice['NMua_Ten'] );
		$this->assertSame( array( 'NMua_Ten' ), array_values( array_filter( array_keys( $invoice ), static fn( string $key ): bool => str_starts_with( $key, 'NMua_' ) ) ) );
	}

	public function test_every_attempt_sends_the_snapshot_not_the_current_order(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->order_with_request( $this->individual_post() );
		$order->update_status( 'completed' );

		$order->set_billing_phone( '0900000002' );
		$order->set_billing_first_name( 'Khác' );
		$order->save();
		$this->run_queued_draft_actions();

		$invoice = $this->sent_invoices()[0];
		$this->assertSame( '0900000001', $invoice['NMua_SDThoai'] );
		$this->assertSame( 'Nguyễn Văn A', $invoice['NMua_Ten'] );
	}

	public function test_the_snapshot_can_be_captured_again_before_a_draft_exists(): void {
		$this->with_encryption_key();
		$order       = $this->order_with_request( $this->individual_post() );
		$snapshotter = new BuyerSnapshotter();
		$snapshotter->capture( $order, 'Khách hàng không lấy hóa đơn' );

		$order->set_billing_phone( '0900000002' );
		$order->save();
		$snapshotter->capture( $order, 'Khách hàng không lấy hóa đơn' );

		$this->assertSame( '0900000002', $snapshotter->load( $order )?->payload_fields()['NMua_SDThoai'] );
	}

	public function test_id_number_never_leaks_outside_the_encrypted_order_meta(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->order_with_request( $this->individual_post() );

		$this->complete_and_run( $order );

		$record = $this->invoice_of( $order );
		$this->assertInstanceOf( InvoiceRecord::class, $record );
		$notes = array_map( static fn( $note ): string => $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertStringContainsString( '********1234', implode( "\n", $notes ) );

		$exposed = wp_json_encode(
			array(
				$notes,
				$record,
				$this->attempts_of( $record ),
				array_map( static fn( $meta ): array => $meta->get_data(), $order->get_meta_data() ),
			)
		);
		$this->assertStringNotContainsString( self::ID_NUMBER, (string) $exposed );
	}

	public function test_undecryptable_id_number_fails_the_draft_without_sending_or_leaking(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->order_with_request( $this->individual_post() );
		$order->update_status( 'completed' );

		$snapshot                 = json_decode( (string) $order->get_meta( BuyerSnapshotter::META_KEY ), true );
		$snapshot['id_encrypted'] = 'v1.not-a-valid-ciphertext';
		$order->update_meta_data( BuyerSnapshotter::META_KEY, wp_json_encode( $snapshot ) );
		$order->save();
		$this->run_queued_draft_actions();

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::FAILED, $record?->status );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertStringNotContainsString( self::ID_NUMBER, (string) $record?->status_reason );
	}

	/**
	 * Posted fields.
	 *
	 * @return array<string, string>
	 */
	private function individual_post(): array {
		return array(
			'einv_request_invoice' => '1',
			'einv_buyer_type'      => 'individual',
			'einv_address'         => 'Số 1 Đường Thử, Quận Thử',
			'einv_id_number'       => self::ID_NUMBER,
		);
	}

	/**
	 * Posted fields.
	 *
	 * @return array<string, string>
	 */
	private function business_post(): array {
		return array(
			'einv_request_invoice' => '1',
			'einv_buyer_type'      => 'business',
			'einv_business_name'   => 'Doanh nghiệp Thử',
			'einv_tax_code'        => '0000000000-001',
			'einv_address'         => 'Số 2 Đường Thử, Quận Thử',
		);
	}

	/**
	 * Creates an order whose checkout request was stored by the order-creation hook.
	 *
	 * @param array<string, string> $post Submitted `einv_*` fields.
	 */
	private function order_with_request( array $post ): WC_Order {
		$_POST = $post;
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_billing_phone( '0900000001' );
		$billing = array(
			'billing_first_name' => $order->get_billing_first_name(),
			'billing_last_name'  => $order->get_billing_last_name(),
			'billing_phone'      => $order->get_billing_phone(),
			'billing_email'      => $order->get_billing_email(),
		);
		do_action( 'woocommerce_checkout_create_order', $order, $billing ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fires a WooCommerce hook.
		$order->save();

		return $order;
	}
}
