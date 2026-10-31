<?php
/**
 * The draft carries the buyer captured when the order completed.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshotter;
use LTTech\EinvoiceMatbao\Invoice\DraftInvoiceWorkflow;
use LTTech\EinvoiceMatbao\Invoice\InvoiceDataError;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRecord;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\Schema;
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
		$needs_attention = $this->collect_needs_attention();
		$this->run_queued_draft_actions();

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::FAILED, $record?->status );
		$this->assertSame( 'buyer_id_unreadable', $record->error_code );
		$this->assertSame( array( 'buyer_id_unreadable' ), $needs_attention->codes );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )?->get_status() );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( array(), $this->attempts_of( $record ) );
		$this->assertStringNotContainsString( self::ID_NUMBER, (string) $record->status_reason );
	}

	public function test_completing_an_individual_order_without_the_encryption_key_is_a_config_error(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->order_with_request( $this->individual_post() );
		remove_all_filters( 'einvoice_matbao_encryption_key' );
		$needs_attention = $this->collect_needs_attention();

		$this->complete_and_run( $order );

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::NOT_CONFIGURED, $record?->status );
		$this->assertSame( 'buyer_id_key_missing', $record->error_code );
		$this->assertSame( array( 'buyer_id_key_missing' ), $needs_attention->codes, 'The hook fires exactly once.' );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )?->get_status() );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
		$this->assertStringNotContainsString( self::ID_NUMBER, (string) $record->status_reason );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
	}

	public function test_completing_an_individual_order_with_a_changed_encryption_key_is_unreadable(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->order_with_request( $this->individual_post() );
		remove_all_filters( 'einvoice_matbao_encryption_key' );
		add_filter( 'einvoice_matbao_encryption_key', static fn(): string => 'another-test-key-another-test-key-0123456789' );
		$needs_attention = $this->collect_needs_attention();

		$this->complete_and_run( $order );

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::FAILED, $record?->status );
		$this->assertSame( 'buyer_id_unreadable', $record->error_code );
		$this->assertSame( array( 'buyer_id_unreadable' ), $needs_attention->codes );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )?->get_status() );
		$this->assertStringNotContainsString( self::ID_NUMBER, (string) $record->status_reason );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
	}

	public function test_snapshot_with_an_id_number_but_no_encryption_key_is_a_config_error(): void {
		$this->with_encryption_key();
		$order    = $this->order_with_request( $this->individual_post() );
		$snapshot = ( new BuyerSnapshotter() )->capture( $order, 'Khách hàng không lấy hóa đơn' );
		remove_all_filters( 'einvoice_matbao_encryption_key' );

		try {
			$snapshot->payload_fields();
			$this->fail( 'Expected InvoiceDataError.' );
		} catch ( InvoiceDataError $error ) {
			$this->assertSame( InvoiceErrorCode::BUYER_ID_KEY_MISSING, $error->error_code );
			$this->assertSame( 'config', $error->error_code->kind()->value );
			$this->assertStringNotContainsString( self::ID_NUMBER, $error->getMessage() );
		}
	}

	public function test_missing_snapshot_is_a_classified_error_without_sending(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->order_with_request( $this->individual_post() );
		$order->update_status( 'completed' );
		$order->delete_meta_data( BuyerSnapshotter::META_KEY );
		$order->save();
		$needs_attention = $this->collect_needs_attention();

		$this->run_queued_draft_actions();

		$record = $this->invoice_of( $order );
		$this->assertSame( 'buyer_snapshot_invalid', $record?->error_code );
		$this->assertSame( InvoiceStatus::FAILED, $record->status );
		$this->assertSame( array( 'buyer_snapshot_invalid' ), $needs_attention->codes );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
	}

	public function test_empty_buyer_name_in_the_snapshot_is_a_classified_error(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->order_with_request( $this->individual_post() );
		$order->update_status( 'completed' );
		$snapshot               = json_decode( (string) $order->get_meta( BuyerSnapshotter::META_KEY ), true );
		$snapshot['buyer_name'] = '';
		$order->update_meta_data( BuyerSnapshotter::META_KEY, wp_json_encode( $snapshot ) );
		$order->save();

		$this->run_queued_draft_actions();

		$this->assertSame( 'buyer_snapshot_invalid', $this->invoice_of( $order )?->error_code );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
	}

	public function test_restart_of_a_stopped_order_sends_the_stored_snapshot(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->order_with_request( $this->individual_post() );
		$order->update_status( 'completed' );
		$order->update_status( 'cancelled' );
		$this->run_queued_draft_actions();
		$this->assertSame( InvoiceStatus::STOPPED, $this->invoice_of( $order )?->status );

		$order->set_billing_phone( '0900000002' );
		$order->save();
		$order->update_status( 'completed' );
		$this->run_queued_draft_actions();

		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );
		$this->assertCount( 1, $this->http->requests_to( self::CREATE_ROUTE ) );
		$invoice = $this->sent_invoices()[0];
		$this->assertSame( '0900000001', $invoice['NMua_SDThoai'], 'The snapshot taken at the first completion is sent.' );
		$this->assertSame( self::ID_NUMBER, $invoice['NMua_CCCDan'] );
	}

	public function test_restart_of_a_stopped_order_after_a_possible_create_looks_up_before_creating(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_timeout();
		$order = $this->order_with_request( $this->individual_post() );
		$order->update_status( 'completed' );
		$this->run_queued_draft_actions();
		$order->update_status( 'cancelled' );
		$this->run_queued_draft_actions();
		$this->assertSame( InvoiceStatus::STOPPED, $this->invoice_of( $order )?->status );

		$this->fake_lookup_found( 'c3RvcHBlZA==' );
		$creates_before = count( $this->http->requests_to( self::CREATE_ROUTE ) );
		$order->update_status( 'completed' );
		$this->run_queued_draft_actions();

		$this->assertCount( 1, $this->http->requests_to( self::LOOKUP_ROUTE ) );
		$this->assertCount( $creates_before, $this->http->requests_to( self::CREATE_ROUTE ), 'The adopted draft is not created again.' );
		$this->assertSame( 'c3RvcHBlZA==', $this->invoice_of( $order )?->matbao_invoice_id );
	}

	public function test_restart_looks_up_before_it_creates_again(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_timeout();
		$this->fake_lookup_not_found();
		$order = $this->order_with_request( $this->individual_post() );
		$order->update_status( 'completed' );
		$this->run_queued_draft_actions();
		$first_run_requests = count( $this->http->requests );

		$this->assertTrue( DraftInvoiceWorkflow::restart( $order->get_id(), InvoiceStatus::PENDING ) );
		$this->run_queued_draft_actions();

		$after = array_slice( $this->http->requests, $first_run_requests );
		$paths = array_values(
			array_filter(
				array_map(
					static fn( array $request ): string => str_contains( $request['url'], '/api/invoice/invoice-detail-batch' ) ? 'lookup' : ( str_contains( $request['url'], '/api/invoice/create-invoice' ) ? 'create' : '' ),
					$after
				)
			)
		);
		$this->assertSame( array( 'lookup', 'create' ), array_slice( $paths, 0, 2 ) );
	}

	/**
	 * Billing edited by an admin after checkout so it no longer passes BuyerRules.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function invalid_billing_edits(): array {
		return array(
			'individual malformed phone' => array( 'individual', 'phone', '12345', '12345' ),
			'individual phone over 20'   => array( 'individual', 'phone', '090000000109000000010', '090000000109000000010' ),
			'individual name over 100'   => array( 'individual', 'first_name', str_repeat( 'Ư', 101 ), str_repeat( 'Ư', 101 ) ),
			'business email over 50'     => array( 'business', 'email', str_repeat( 'a', 45 ) . '@e.invalid', str_repeat( 'a', 45 ) ),
			'business email removed'     => array( 'business', 'email', '', 'Doanh nghiệp Thử' ),
			'business phone over 20'     => array( 'business', 'phone', '090000000109000000010', '090000000109000000010' ),
		);
	}

	/**
	 * Fails the draft instead of sending data Mat Bao would refuse.
	 *
	 * @dataProvider invalid_billing_edits
	 *
	 * @param string $type      Buyer type posted at checkout.
	 * @param string $field     Billing field the admin edited.
	 * @param string $value     Edited value.
	 * @param string $never_say Text the stored reason must not contain.
	 */
	public function test_billing_edited_to_invalid_data_fails_the_draft_without_sending( string $type, string $field, string $value, string $never_say ): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->order_with_request( 'individual' === $type ? $this->individual_post() : $this->business_post() );
		$order->{'set_billing_' . $field}( $value );
		$order->save();
		$needs_attention = $this->collect_needs_attention();

		$this->complete_and_run( $order );

		$record = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::FAILED, $record?->status );
		$this->assertSame( 'buyer_invalid', $record->error_code );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )?->get_status() );
		$this->assertSame( array( 'buyer_invalid' ), $needs_attention->codes, 'The hook fires exactly once.' );
		$this->assertSame( array(), $this->queued_draft_action_ids(), 'Not retried.' );
		$this->assertStringContainsString( 'thông tin người mua', (string) $record?->status_reason );
		$this->assertStringNotContainsString( $never_say, (string) $record?->status_reason );
		$this->assertStringNotContainsString( self::ID_NUMBER, (string) $record?->status_reason );
		$this->assertCount( 0, $this->http->requests_to( self::CREATE_ROUTE ) );
		$this->assertSame( '', (string) $order->get_meta( BuyerSnapshotter::META_KEY ) );
	}

	public function test_only_the_hook_that_reserves_the_invoice_captures_the_snapshot_and_adds_the_note(): void {
		$this->configure_matbao();
		$order = $this->order_with_request( $this->individual_post() );
		$this->lose_the_reservation_race( $order );

		DraftInvoiceWorkflow::on_order_completed( $order->get_id(), $order );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( '', (string) $order->get_meta( BuyerSnapshotter::META_KEY ) );
		$this->assertSame( array(), $this->id_notes( $order ) );
		$this->assertSame( array(), $this->queued_draft_action_ids() );
	}

	public function test_two_completions_capture_and_note_once(): void {
		$this->configure_matbao();
		$order = $this->order_with_request( $this->individual_post() );

		DraftInvoiceWorkflow::on_order_completed( $order->get_id(), $order );
		DraftInvoiceWorkflow::on_order_completed( $order->get_id(), $order );

		$this->assertCount( 1, $this->id_notes( $order ) );
		$this->assertCount( 1, $this->queued_draft_action_ids() );
	}

	public function test_skipped_zero_total_order_does_not_capture_the_id_number(): void {
		$this->configure_matbao();
		$_POST = $this->individual_post();
		$order = $this->create_order( array( array( $this->create_product( 'Free', '0' ), 1 ) ) );
		do_action( 'woocommerce_checkout_create_order', $order, array( 'billing_phone' => '0900000001' ) + $this->billing_of( $order ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fires a WooCommerce hook.
		$order->set_billing_phone( '0900000001' );
		$order->save();

		$order->update_status( 'completed' );

		$this->assertSame( InvoiceStatus::SKIPPED, $this->invoice_of( $order )?->status );
		$this->assertSame( '', (string) $order->get_meta( BuyerSnapshotter::META_KEY ) );
		$this->assertSame( array(), $this->id_notes( $order ) );
	}

	public function test_mat_bao_error_message_is_stored_without_a_twelve_digit_number(): void {
		$this->configure_matbao();
		$this->http->respond_json(
			self::CREATE_ROUTE,
			400,
			array(
				'errorCode' => 400,
				'message'   => 'NMua_CCCDan ' . self::ID_NUMBER . ' không hợp lệ',
				'success'   => false,
			)
		);
		$order = $this->order_with_request( $this->individual_post() );

		$this->complete_and_run( $order );

		$record = $this->invoice_of( $order );
		$this->assertInstanceOf( InvoiceRecord::class, $record );
		$this->assertSame( InvoiceStatus::FAILED, $record->status );
		$stored = (string) wp_json_encode( array( $record->status_reason, $this->attempts_of( $record ) ) );
		$this->assertStringNotContainsString( self::ID_NUMBER, $stored );
		$this->assertStringContainsString( '********1234', $record->status_reason );
	}

	/**
	 * Collects the codes of every needs-attention hook from now on.
	 */
	private function collect_needs_attention(): object {
		$collected        = new \stdClass();
		$collected->codes = array();
		add_action(
			DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION,
			static function ( InvoiceRecord $invoice, InvoiceErrorCode $code ) use ( $collected ): void {
				$collected->codes[] = $code->value;
			},
			10,
			2
		);

		return $collected;
	}

	/**
	 * Makes the next reservation of an order fail as if a concurrent hook inserted the row first,
	 * after this hook already found no row.
	 *
	 * @param WC_Order $order Order.
	 */
	private function lose_the_reservation_race( WC_Order $order ): void {
		$filter = static function ( string $query ) use ( $order, &$filter ): string {
			if ( ! str_starts_with( $query, 'INSERT IGNORE INTO' ) || ! str_contains( $query, Schema::invoice_table() ) ) {
				return $query;
			}
			remove_filter( 'query', $filter );
			( new InvoiceRepository() )->reserve( $order->get_id(), 'OTHER-' . $order->get_id(), 'Other hook', InvoiceStatus::PENDING );

			return $query;
		};
		add_filter( 'query', $filter );
	}

	/**
	 * Order notes that mention the masked citizen ID.
	 *
	 * @param WC_Order $order Order.
	 * @return list<string>
	 */
	private function id_notes( WC_Order $order ): array {
		$notes = array_map( static fn( $note ): string => $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		return array_values( array_filter( $notes, static fn( string $note ): bool => str_contains( $note, '********1234' ) ) );
	}

	/**
	 * Billing fields of an order as checkout posts them.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	private function billing_of( WC_Order $order ): array {
		return array(
			'billing_first_name' => $order->get_billing_first_name(),
			'billing_last_name'  => $order->get_billing_last_name(),
			'billing_phone'      => $order->get_billing_phone(),
			'billing_email'      => $order->get_billing_email(),
		);
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
		do_action( 'woocommerce_checkout_create_order', $order, $this->billing_of( $order ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fires a WooCommerce hook.
		$order->save();

		return $order;
	}
}
