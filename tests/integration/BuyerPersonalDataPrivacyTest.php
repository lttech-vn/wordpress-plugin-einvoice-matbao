<?php
/**
 * WordPress privacy exporter and eraser for the buyer data the plugin keeps.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore;
use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshotter;
use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Privacy\BuyerPersonalData;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;
use WC_Privacy_Erasers;

/**
 * Real orders with a checkout request and a captured snapshot, exported and erased through the
 * callbacks WordPress core calls for a privacy request. All identity numbers are fake.
 */
final class BuyerPersonalDataPrivacyTest extends InvoiceWorkflowTestCase {

	private const ID_NUMBER = '000000005678';

	private const EMAIL       = 'buyer@example.invalid';
	private const OTHER_EMAIL = 'other@example.invalid';

	/**
	 * Whether create-invoice was faked to fail before the orders are completed.
	 *
	 * @var bool
	 */
	private bool $create_fails = false;

	public function tear_down(): void {
		$_POST = array();

		parent::tear_down();
	}

	public function test_exporter_and_eraser_are_registered_before_woocommerce_order_eraser(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

		$this->assertArrayHasKey( BuyerPersonalData::ID, $exporters );
		$this->assertArrayHasKey( BuyerPersonalData::ID, $erasers );
		$keys = array_keys( $erasers );
		$this->assertLessThan(
			array_search( 'woocommerce-customer-orders', $keys, true ),
			array_search( BuyerPersonalData::ID, $keys, true ),
			'The plugin eraser runs while the order still carries the billing email.'
		);
	}

	public function test_export_lists_the_buyer_data_of_a_guest_order_with_a_masked_id_number(): void {
		$order = $this->completed_individual_order( self::EMAIL );
		$this->completed_individual_order( self::OTHER_EMAIL, 'Số 9 Đường Khác' );

		$export = BuyerPersonalData::export( self::EMAIL, 1 );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$item = $export['data'][0];
		$this->assertSame( BuyerPersonalData::ID, $item['group_id'] );
		$this->assertSame( 'einvoice-matbao-order-' . $order->get_id(), $item['item_id'] );
		$values = array_column( $item['data'], 'value', 'name' );
		$this->assertSame( '********5678', $values['CCCD'] );
		$this->assertSame( 'Nguyễn Văn A', $values['Tên người mua'] );
		$this->assertSame( 'Số 1 Đường Thử, Quận Thử', $values['Địa chỉ'] );
		$this->assertSame( '0900000001', $values['Số điện thoại'] );
		$this->assertSame( self::EMAIL, $values['Email'] );
		$this->assertSame( 'WC' . $order->get_id(), $values['Mã tham chiếu (MTChieu)'] );

		$raw = (string) wp_json_encode( $export );
		$this->assertStringNotContainsString( self::ID_NUMBER, $raw );
		$this->assertStringNotContainsString( 'v1.', $raw, 'No ciphertext is exported.' );
		$this->assertStringNotContainsString( 'Số 9 Đường Khác', $raw, 'Another person is not exported.' );
	}

	public function test_export_includes_the_business_tax_code(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->order_with_request( $this->business_post(), self::EMAIL );
		$this->complete_and_run( $order );

		$values = array_column( BuyerPersonalData::export( self::EMAIL, 1 )['data'][0]['data'], 'value', 'name' );

		$this->assertSame( '0000000000-001', $values['Mã số thuế'] );
		$this->assertSame( 'Doanh nghiệp Thử', $values['Tên người mua'] );
		$this->assertArrayNotHasKey( 'CCCD', $values );
	}

	public function test_export_skips_orders_without_plugin_data(): void {
		$this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->assertSame( array(), BuyerPersonalData::export( self::EMAIL, 1 )['data'] );
	}

	public function test_eraser_removes_the_buyer_meta_and_anonymizes_the_invoice_row_of_that_email_only(): void {
		$this->fake_create_invoice_error_with_buyer_echo();
		$order = $this->completed_individual_order( self::EMAIL );
		$other = $this->completed_individual_order( self::OTHER_EMAIL );
		$this->assertNotSame( '', $this->stored_attempt_messages( $order ) );

		$result = BuyerPersonalData::erase( self::EMAIL, 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$messages = implode( "\n", $result['messages'] );
		$this->assertStringContainsString( 'nghĩa vụ kế toán', $messages );
		$this->assertStringContainsString( 'Mắt Bão', $messages );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( '', (string) $reloaded->get_meta( BuyerRequestStore::META_KEY ) );
		$this->assertSame( '', (string) $reloaded->get_meta( BuyerSnapshotter::META_KEY ) );
		$this->assertSame( self::EMAIL, $reloaded->get_billing_email(), 'The WooCommerce order itself is untouched.' );
		$this->assertSame( 'completed', $reloaded->get_status() );

		$invoice = $this->invoice_of( $reloaded );
		$this->assertNotNull( $invoice );
		$this->assertSame( 'WC' . $order->get_id(), $invoice->reference, 'The reconciliation reference is kept.' );
		$this->assertSame( InvoiceStatus::FAILED, $invoice->status );
		$this->assertNotSame( 'Nguyễn Văn A', $invoice->buyer_name );
		$this->assertStringNotContainsString( 'Nguyễn Văn A', (string) $invoice->status_reason );
		$this->assertStringNotContainsString( 'Nguyễn Văn A', $this->stored_attempt_messages( $order ) );
		$this->assertCount( 1, $this->attempts_of( $invoice ), 'Attempt rows are kept.' );

		$untouched = wc_get_order( $other->get_id() );
		$this->assertInstanceOf( WC_Order::class, $untouched );
		$this->assertNotSame( '', (string) $untouched->get_meta( BuyerRequestStore::META_KEY ) );
		$this->assertNotSame( '', (string) $untouched->get_meta( BuyerSnapshotter::META_KEY ) );
		$this->assertSame( 'Nguyễn Văn A', $this->invoice_of( $untouched )?->buyer_name );
		$this->assertStringContainsString( 'Nguyễn Văn A', $this->stored_attempt_messages( $untouched ) );
	}

	public function test_eraser_keeps_the_created_draft_and_says_mat_bao_is_out_of_scope(): void {
		$order = $this->completed_individual_order( self::EMAIL );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $this->invoice_of( $order )?->status );

		$result = BuyerPersonalData::erase( self::EMAIL, 1 );

		$invoice = $this->invoice_of( $order );
		$this->assertSame( InvoiceStatus::DRAFT_CREATED, $invoice?->status );
		$this->assertSame( 'ZmFrZS1pbnZvaWNlLWlk', $invoice->matbao_invoice_id );
		$this->assertStringContainsString( 'trên Mắt Bão', implode( "\n", $result['messages'] ) );
	}

	public function test_second_erase_removes_nothing_more(): void {
		$this->completed_individual_order( self::EMAIL );
		BuyerPersonalData::erase( self::EMAIL, 1 );

		$result = BuyerPersonalData::erase( self::EMAIL, 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
	}

	public function test_eraser_for_an_email_without_orders_does_nothing(): void {
		$this->completed_individual_order( self::OTHER_EMAIL );

		$result = BuyerPersonalData::erase( 'nobody@example.invalid', 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( array(), $result['messages'] );
	}

	public function test_woocommerce_order_anonymization_also_erases_the_plugin_data(): void {
		$order = $this->completed_individual_order( self::EMAIL );

		WC_Privacy_Erasers::remove_order_personal_data( $order );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( '', (string) $reloaded->get_meta( BuyerRequestStore::META_KEY ) );
		$this->assertSame( '', (string) $reloaded->get_meta( BuyerSnapshotter::META_KEY ) );
		$this->assertNotSame( 'Nguyễn Văn A', $this->invoice_of( $reloaded )?->buyer_name );
		$this->assertSame( 'WC' . $order->get_id(), $this->invoice_of( $reloaded )?->reference );
	}

	public function test_woocommerce_order_anonymization_also_scrubs_the_buyer_echo_in_status_reason_and_attempts(): void {
		$this->fake_create_invoice_error_with_buyer_echo();
		$order = $this->completed_individual_order( self::EMAIL );
		$this->assertStringContainsString( 'Nguyễn Văn A', $this->stored_attempt_messages( $order ) );

		WC_Privacy_Erasers::remove_order_personal_data( $order );

		$this->assertStringNotContainsString( 'Nguyễn Văn A', $this->stored_attempt_messages( $order ) );
		$this->assertNotSame( 'Nguyễn Văn A', $this->invoice_of( $order )?->buyer_name );
	}

	public function test_eraser_wins_over_a_pending_invoice_and_says_so(): void {
		$this->configure_matbao();
		$order = $this->order_with_request( $this->individual_post(), self::EMAIL );
		$order->update_status( 'completed' );
		$invoice = $this->invoice_of( $order ) ?? ( new InvoiceRepository() )->reserve( $order->get_id(), 'WC' . $order->get_id(), 'Nguyễn Văn A', InvoiceStatus::PENDING );
		$this->assertNotNull( $invoice );

		$result = BuyerPersonalData::erase( self::EMAIL, 1 );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( '', (string) $reloaded->get_meta( BuyerRequestStore::META_KEY ), 'The right to erasure wins over the queued send.' );
		$this->assertTrue( $result['items_retained'] );
		$this->assertStringContainsString( 'đang chờ gửi', implode( "\n", $result['messages'] ) );
	}

	public function test_paged_export_and_erase_cover_more_orders_than_one_page_and_leave_other_emails_alone(): void {
		$this->configure_matbao();
		for ( $i = 0; $i < 12; $i++ ) {
			$this->order_with_request( $this->individual_post(), self::EMAIL );
		}
		$other = $this->order_with_request( $this->individual_post(), self::OTHER_EMAIL );

		$first = BuyerPersonalData::erase( self::EMAIL, 1 );
		$this->assertFalse( $first['done'] );
		$this->assertTrue( $first['items_removed'] );
		$second = BuyerPersonalData::erase( self::EMAIL, 2 );
		$this->assertTrue( $second['done'] );
		$this->assertTrue( $second['items_removed'] );
		$this->assertFalse( BuyerPersonalData::erase( self::EMAIL, 3 )['items_removed'] );

		$this->assertSame( array(), $this->orders_with_request_meta( self::EMAIL ) );
		$untouched = wc_get_order( $other->get_id() );
		$this->assertInstanceOf( WC_Order::class, $untouched );
		$this->assertNotSame( '', (string) $untouched->get_meta( BuyerRequestStore::META_KEY ) );
	}

	public function test_trashed_orders_are_still_erased(): void {
		$this->configure_matbao();
		$order = $this->order_with_request( $this->individual_post(), self::EMAIL );
		$order->set_status( 'trash' );
		$order->save();
		$this->assertSame( 'trash', wc_get_order( $order->get_id() )->get_status() );

		$this->assertTrue( BuyerPersonalData::erase( self::EMAIL, 1 )['items_removed'], 'A trashed order is still erased.' );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( BuyerRequestStore::META_KEY ) );
		$this->assertSame( array(), $this->orders_with_request_meta( self::EMAIL ) );
	}

	public function test_export_includes_the_stored_status_reason_and_invoice_buyer_name(): void {
		$this->fake_create_invoice_error_with_buyer_echo();
		$order = $this->completed_individual_order( self::EMAIL );

		$values = array_column( BuyerPersonalData::export( self::EMAIL, 1 )['data'][0]['data'], 'value', 'name' );

		$this->assertSame( 'Nguyễn Văn A', $values['Tên người mua trên hóa đơn'] );
		$this->assertArrayHasKey( 'Lý do trạng thái', $values );
		$this->assertStringNotContainsString( 'v1.', $values['Lý do trạng thái'] );
		$this->assertNotNull( $order );
	}

	public function test_full_core_erasure_with_woocommerce_order_removal_finds_and_erases_the_order(): void {
		update_option( 'woocommerce_erasure_request_removes_order_data', 'yes' );
		$order   = $this->completed_individual_order( self::EMAIL );
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

		foreach ( $erasers as $eraser ) {
			call_user_func( $eraser['callback'], self::EMAIL, 1 );
		}

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( '', (string) $reloaded->get_meta( BuyerSnapshotter::META_KEY ) );
		$this->assertNotSame( 'Nguyễn Văn A', $this->invoice_of( $reloaded )?->buyer_name );
	}

	/**
	 * Makes create-invoice fail with a message that echoes the buyer name, as Mat Bao may do.
	 */
	private function fake_create_invoice_error_with_buyer_echo(): void {
		$this->create_fails = true;
		$this->http->respond_json(
			self::CREATE_ROUTE,
			200,
			array(
				'errorCode' => 400,
				'message'   => 'Người mua Nguyễn Văn A không hợp lệ',
				'data'      => null,
				'success'   => false,
			)
		);
	}

	/**
	 * Stored attempt messages and status reason of an order's invoice, raw from the tables.
	 *
	 * @param WC_Order $order Order.
	 */
	private function stored_attempt_messages( WC_Order $order ): string {
		$invoice = ( new InvoiceRepository() )->find_by_order( $order->get_id() );
		$this->assertNotNull( $invoice );

		return implode( "\n", array_map( static fn( array $attempt ): string => (string) $attempt['message'], $this->attempts_of( $invoice ) ) ) . "\n" . $invoice->status_reason;
	}

	/**
	 * A completed individual order whose draft was sent (the fake answers unless set otherwise).
	 *
	 * @param string $email   Billing email.
	 * @param string $address Buyer address.
	 */
	private function completed_individual_order( string $email, string $address = 'Số 1 Đường Thử, Quận Thử' ): WC_Order {
		$this->configure_matbao();
		if ( ! $this->create_fails ) {
			$this->fake_create_invoice_ok();
		}
		$order = $this->order_with_request(
			array(
				'einv_request_invoice' => '1',
				'einv_buyer_type'      => 'individual',
				'einv_address'         => $address,
				'einv_id_number'       => self::ID_NUMBER,
			),
			$email
		);
		$this->complete_and_run( $order );

		return $order;
	}

	/**
	 * Posted fields of an individual buyer.
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
	 * IDs of the orders of an email that still hold the stored checkout request.
	 *
	 * @param string $email Billing email.
	 * @return list<int>
	 */
	private function orders_with_request_meta( string $email ): array {
		$ids = array();
		foreach ( wc_get_orders( array( 'billing_email' => $email, 'limit' => -1, 'status' => 'any', 'return' => 'ids' ) ) as $id ) {
			if ( '' !== (string) wc_get_order( $id )->get_meta( BuyerRequestStore::META_KEY ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Posted fields of a business buyer.
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
	 * A guest order whose checkout request was stored by the order-creation hook.
	 *
	 * @param array<string, string> $post  Submitted `einv_*` fields.
	 * @param string                $email Billing email.
	 */
	private function order_with_request( array $post, string $email ): WC_Order {
		$_POST = $post;
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_billing_email( $email );
		$order->set_billing_phone( '0900000001' );
		$order->set_customer_id( 0 );
		$billing = array(
			'billing_first_name' => $order->get_billing_first_name(),
			'billing_last_name'  => $order->get_billing_last_name(),
			'billing_phone'      => $order->get_billing_phone(),
			'billing_email'      => $email,
		);
		do_action( 'woocommerce_checkout_create_order', $order, $billing ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fires a WooCommerce hook.
		$order->save();

		return $order;
	}
}
