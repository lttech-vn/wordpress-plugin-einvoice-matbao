<?php
/**
 * Checkout Blocks: invoice request through the Store API checkout route.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Blocks seam: a real `POST /wc/store/v1/checkout` with the plugin's extension data, so the
 * server-side rules are exercised exactly as a direct Store API call would reach them.
 * All identity numbers are obviously fake test values.
 */
final class BlocksCheckoutBuyerTest extends InvoiceWorkflowTestCase {

	private const ID_NUMBER = '000000001234';
	private const TAX_CODE  = '0000000000-001';
	private const NAMESPACE_KEY = 'einvoice-matbao';

	public function set_up(): void {
		parent::set_up();

		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		wp_set_current_user( 0 );
		wc_load_cart();
		WC()->cart->empty_cart();
		$product = new \WC_Product_Simple();
		$product->set_name( 'Free item' );
		$product->set_regular_price( '0' );
		$product->set_virtual( true );
		$product->save();
		WC()->cart->add_to_cart( $product->get_id() );
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

		parent::tear_down();
	}

	public function test_individual_request_is_stored_with_the_id_number_encrypted(): void {
		$this->with_encryption_key();

		$response = $this->place_order( $this->individual() );
		$request  = ( new BuyerRequestStore() )->load( $this->order_of( $response ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotNull( $request );
		$this->assertSame( 'individual', $request['type'] );
		$this->assertSame( 'Số 1 Đường Thử, Quận Thử', $request['address'] );
		$this->assertSame( '********1234', $request['id_mask'] );
		$this->assertStringStartsWith( 'v1.', $request['id_encrypted'] );
		$this->assertStringNotContainsString( self::ID_NUMBER, (string) wp_json_encode( $this->order_of( $response )->get_meta_data() ) );
	}

	public function test_business_request_is_stored_without_needing_a_key(): void {
		$response = $this->place_order( $this->business() );
		$request  = ( new BuyerRequestStore() )->load( $this->order_of( $response ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotNull( $request );
		$this->assertSame( 'business', $request['type'] );
		$this->assertSame( 'Doanh nghiệp Thử', $request['business_name'] );
		$this->assertSame( self::TAX_CODE, $request['tax_code'] );
		$this->assertSame( '', $request['id_encrypted'] );
	}

	public function test_without_the_checkbox_the_buyer_is_walk_in_even_with_other_fields_and_billing(): void {
		$this->with_encryption_key();

		$response = $this->place_order( array( 'request_invoice' => false ) + $this->individual() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( ( new BuyerRequestStore() )->load( $this->order_of( $response ) ) );
	}

	public function test_without_any_extension_data_the_buyer_is_walk_in(): void {
		$response = $this->place_order( null );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( ( new BuyerRequestStore() )->load( $this->order_of( $response ) ) );
	}

	public function test_a_ticked_request_with_wrong_data_is_refused_in_vietnamese_and_creates_no_request(): void {
		$this->with_encryption_key();

		$response = $this->place_order( array( 'id_number' => '123' ) + $this->individual() );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'CCCD', (string) $response->get_data()['message'] );
		$this->assertStringNotContainsString( '123', (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_a_ticked_request_without_a_type_is_refused(): void {
		$response = $this->place_order( array( 'request_invoice' => true, 'address' => 'Số 1' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'cá nhân hoặc doanh nghiệp', (string) $response->get_data()['message'] );
	}

	public function test_individual_with_a_bad_billing_phone_or_too_long_name_is_refused(): void {
		$this->with_encryption_key();

		$response = $this->place_order( $this->individual(), array( 'first_name' => str_repeat( 'Ư', 60 ), 'last_name' => str_repeat( 'ơ', 60 ), 'phone' => '123' ) );
		$message  = (string) $response->get_data()['message'];

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'họ tên', $message );
		$this->assertStringContainsString( 'điện thoại', $message );
	}

	public function test_business_with_a_bad_tax_code_or_too_long_billing_email_is_refused(): void {
		$response = $this->place_order( array( 'tax_code' => '12' ) + $this->business(), array( 'email' => str_repeat( 'a', 45 ) . '@example.invalid' ) );
		$message  = (string) $response->get_data()['message'];

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'Mã số thuế', $message );
		$this->assertStringContainsString( 'email', $message );
	}

	public function test_individual_is_refused_when_no_encryption_key_is_configured(): void {
		$response = $this->place_order( $this->individual() );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'khóa mã hóa', (string) $response->get_data()['message'] );
	}

	public function test_extension_data_of_the_wrong_type_is_refused_by_the_schema(): void {
		$response = $this->place_order( array( 'address' => array( 'x' ) ) + $this->business() );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_business_request_ignores_posted_individual_fields(): void {
		$this->with_encryption_key();

		$response = $this->place_order( array( 'id_number' => self::ID_NUMBER ) + $this->business() );
		$request  = ( new BuyerRequestStore() )->load( $this->order_of( $response ) );

		$this->assertNotNull( $request );
		$this->assertSame( '', $request['id_encrypted'] );
		$this->assertSame( '', $request['id_mask'] );
	}

	public function test_the_id_number_never_appears_in_the_store_api_response_notes_or_cart_extensions(): void {
		$this->with_encryption_key();

		$response = $this->place_order( $this->individual() );
		$order    = $this->order_of( $response );
		$request  = ( new BuyerRequestStore() )->load( $order );
		$notes    = (string) wp_json_encode( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$cart     = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) );
		$checkout = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/checkout' ) );

		$this->assertNotNull( $request );
		foreach ( array( (string) wp_json_encode( $response->get_data() ), $notes, (string) wp_json_encode( $cart->get_data() ), (string) wp_json_encode( $checkout->get_data() ) ) as $output ) {
			$this->assertStringNotContainsString( self::ID_NUMBER, $output );
			$this->assertStringNotContainsString( $request['id_encrypted'], $output );
		}
		$this->assertStringNotContainsString( self::ID_NUMBER, (string) wp_json_encode( WC()->session->get_session_data() ) );
	}

	public function test_a_completed_blocks_order_sends_the_buyer_from_the_snapshot(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();

		$order = $this->order_of( $this->place_order( $this->individual() ) );
		// The checkout cart is free so no gateway is needed; give the placed order a priced line.
		$order->add_product( $this->create_product( 'A', '100000' ), 1 );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'completed' );
		$this->run_queued_draft_actions();

		$invoice = $this->sent_invoices()[0];
		$this->assertSame( 'Nguyễn Văn A', $invoice['NMua_HVTNMHang'] );
		$this->assertSame( 'Số 1 Đường Thử, Quận Thử', $invoice['NMua_DChi'] );
		$this->assertSame( self::ID_NUMBER, $invoice['NMua_CCCDan'] );
		$this->assertSame( '0900000001', $invoice['NMua_SDThoai'] );
	}

	public function test_the_checkout_script_is_registered_for_the_blocks_checkout(): void {
		do_action( 'woocommerce_blocks_checkout_block_registration', new \Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.

		$this->assertTrue( wp_script_is( "einvoice-matbao-checkout-blocks", "registered" ) );
	}

	/**
	 * Extension data of a valid individual request.
	 *
	 * @return array<string, mixed>
	 */
	private function individual(): array {
		return array(
			'request_invoice' => true,
			'buyer_type'      => 'individual',
			'address'         => 'Số 1 Đường Thử, Quận Thử',
			'id_number'       => self::ID_NUMBER,
		);
	}

	/**
	 * Extension data of a valid business request.
	 *
	 * @return array<string, mixed>
	 */
	private function business(): array {
		return array(
			'request_invoice' => true,
			'buyer_type'      => 'business',
			'business_name'   => 'Doanh nghiệp Thử',
			'tax_code'        => self::TAX_CODE,
			'address'         => 'Số 2 Đường Thử, Quận Thử',
		);
	}

	/**
	 * POSTs the Store API checkout.
	 *
	 * @param array<string, mixed>|null $extension Plugin extension data, or null to send none.
	 * @param array<string, string>     $billing   Billing overrides.
	 */
	private function place_order( ?array $extension, array $billing = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_header( 'Content-Type', 'application/json' );
		$body = array(
			'billing_address' => $billing + array(
				'first_name' => 'Nguyễn',
				'last_name'  => 'Văn A',
				'address_1'  => '1 Đường Thử',
				'city'       => 'Hà Nội',
				'state'      => 'HANOI',
				'postcode'   => '100000',
				'country'    => 'VN',
				'email'      => 'buyer@example.invalid',
				'phone'      => '0900000001',
			),
		);
		if ( null !== $extension ) {
			$body['extensions'] = array( self::NAMESPACE_KEY => $extension );
		}
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	/**
	 * The order a successful checkout response points at.
	 *
	 * @param WP_REST_Response $response Checkout response.
	 */
	private function order_of( WP_REST_Response $response ): WC_Order {
		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $data ) );
		$order = wc_get_order( (int) $data['order_id'] );
		$this->assertInstanceOf( WC_Order::class, $order );

		return $order;
	}
}
