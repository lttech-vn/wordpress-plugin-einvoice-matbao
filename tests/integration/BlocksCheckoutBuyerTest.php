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

	private const ID_NUMBER     = '000000001234';
	private const TAX_CODE      = '0000000000-001';
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
		$response = $this->place_order(
			array(
				'request_invoice' => true,
				'address'         => 'Số 1',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'cá nhân hoặc doanh nghiệp', (string) $response->get_data()['message'] );
	}

	public function test_individual_with_a_bad_billing_phone_or_too_long_name_is_refused(): void {
		$this->with_encryption_key();

		$response = $this->place_order(
			$this->individual(),
			array(
				'first_name' => str_repeat( 'Ư', 60 ),
				'last_name'  => str_repeat( 'ơ', 60 ),
				'phone'      => '123',
			)
		);
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

	public function test_a_patch_with_a_pending_order_in_session_neither_refuses_nor_stores_the_request(): void {
		$this->with_encryption_key();
		$order = $this->pending_order_in_session();

		// A half-typed request: ticked, no type yet.
		$response = $this->send( 'PATCH', array( 'extensions' => array( self::NAMESPACE_KEY => array( 'request_invoice' => true ) ) ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertNull( ( new BuyerRequestStore() )->load( wc_get_order( $order->get_id() ) ) );

		// A complete request on PATCH is not stored either: only the real place-order POST stores.
		$response = $this->send( 'PATCH', array( 'extensions' => array( self::NAMESPACE_KEY => $this->individual() ) ) );
		$this->assertSame( 200, $response->get_status() );
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertNull( ( new BuyerRequestStore() )->load( $reloaded ) );
		$this->assertStringNotContainsString( self::ID_NUMBER, (string) wp_json_encode( $reloaded->get_meta_data() ) );
		$this->assertStringNotContainsString( 'v1.', (string) wp_json_encode( $reloaded->get_meta_data() ) );
	}

	public function test_a_failed_payment_retry_with_a_failed_order_in_session_can_still_place_the_order(): void {
		$this->with_encryption_key();
		update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes' ) );
		WC()->payment_gateways()->init();
		$order = $this->pending_order_in_session();
		$order->update_status( 'failed' );
		$this->assertSame( $order->get_id(), (int) WC()->session->get( 'store_api_draft_order' ) );

		$response = $this->place_order( $this->individual(), array(), array( 'payment_method' => 'bacs' ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$placed = $this->order_of( $response );
		$this->assertSame( $order->get_id(), $placed->get_id() );
		$request = ( new BuyerRequestStore() )->load( $placed );
		$this->assertNotNull( $request );
		$this->assertSame( '********1234', $request['id_mask'] );
	}

	public function test_a_draft_order_holds_no_citizen_id_after_the_checkout_page_and_a_patch(): void {
		$this->with_encryption_key();

		rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/checkout' ) );
		$this->send( 'PATCH', array( 'extensions' => array( self::NAMESPACE_KEY => $this->individual() ) ) );

		$drafts = wc_get_orders(
			array(
				'status' => 'checkout-draft',
				'limit'  => -1,
			)
		);
		foreach ( $drafts as $draft ) {
			$meta = (string) wp_json_encode( $draft->get_meta_data() );
			$this->assertStringNotContainsString( self::ID_NUMBER, $meta );
			$this->assertStringNotContainsString( 'v1.', $meta );
			$this->assertNull( ( new BuyerRequestStore() )->load( $draft ) );
		}
		$this->assertStringNotContainsString( self::ID_NUMBER, (string) wp_json_encode( WC()->session->get_session_data() ) );
	}

	public function test_the_schema_refuses_overlong_values_without_echoing_them(): void {
		$this->with_encryption_key();
		$cases = array(
			'id_number'     => array( $this->individual(), 13 ),
			'tax_code'      => array( $this->business(), 15 ),
			'address'       => array( $this->business(), 401 ),
			'business_name' => array( $this->business(), 401 ),
		);
		foreach ( $cases as $key => list( $base, $length ) ) {
			$value    = str_repeat( '7', $length );
			$response = $this->place_order( array( $key => $value ) + $base );

			$this->assertSame( 400, $response->get_status(), $key );
			$this->assertStringNotContainsString( $value, (string) wp_json_encode( $response->get_data() ), $key );
		}
	}

	public function test_an_unknown_buyer_type_is_refused_without_echoing_it(): void {
		$response = $this->place_order( array( 'buyer_type' => 'zzcompany' ) + $this->business() );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringNotContainsString( 'zzcompany', (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_request_invoice_follows_the_rest_boolean_rules_and_anything_else_is_refused(): void {
		$this->with_encryption_key();

		// The REST layer turns these into booleans, so a ticked intent stays ticked and "false" stays walk-in.
		foreach ( array( 'true', 1 ) as $value ) {
			$order = $this->order_of( $this->place_order( array( 'request_invoice' => $value ) + $this->individual() ) );
			$this->assertNotNull( ( new BuyerRequestStore() )->load( $order ), 'value ' . wp_json_encode( $value ) );
		}
		foreach ( array( 'false', 0 ) as $value ) {
			$order = $this->order_of( $this->place_order( array( 'request_invoice' => $value ) + $this->individual() ) );
			$this->assertNull( ( new BuyerRequestStore() )->load( $order ), 'value ' . wp_json_encode( $value ) );
		}
		// Anything else is an explicit 400, never a silent walk-in.
		foreach ( array( 'yes', 'zz', array( 'x' ), 2 ) as $value ) {
			$response = $this->place_order( array( 'request_invoice' => $value ) + $this->individual() );
			$this->assertSame( 400, $response->get_status(), 'value ' . wp_json_encode( $value ) );
		}
	}

	public function test_business_request_needs_no_contact_name(): void {
		$order = new \WC_Order();
		$order->set_billing_phone( '0900000001' );
		$order->set_billing_email( 'buyer@example.invalid' );
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'extensions', array( self::NAMESPACE_KEY => $this->business() ) );

		\LTTech\EinvoiceMatbao\Checkout\BlocksCheckout::save_to_order( $order, $request );

		$stored = ( new BuyerRequestStore() )->load( $order );
		$this->assertNotNull( $stored );
		$this->assertSame( 'business', $stored['type'] );
	}

	public function test_posting_twice_keeps_one_request_per_order_and_no_leak(): void {
		$this->with_encryption_key();

		$first  = $this->place_order( $this->individual() );
		$second = $this->place_order( $this->individual() );

		$this->assertSame( 200, $first->get_status() );
		$this->assertContains( $second->get_status(), array( 200, 400, 409 ) );
		foreach ( array( $first, $second ) as $response ) {
			$this->assertStringNotContainsString( self::ID_NUMBER, (string) wp_json_encode( $response->get_data() ) );
		}
		$order = $this->order_of( $first );
		$this->assertNotNull( ( new BuyerRequestStore() )->load( $order ) );
	}

	public function test_the_id_number_does_not_leak_to_the_order_received_page_email_rest_transients_or_logs(): void {
		$this->with_encryption_key();
		$mails = array();
		add_filter(
			'wp_mail',
			static function ( array $args ) use ( &$mails ): array {
				$mails[] = $args;
				return $args;
			}
		);

		$order   = $this->order_of( $this->place_order( $this->individual() ) );
		$request = ( new BuyerRequestStore() )->load( $order );
		$this->assertNotNull( $request );

		ob_start();
		WC()->mailer()->emails['WC_Email_Customer_Invoice']->trigger( $order->get_id() );
		wc_get_template( 'checkout/thankyou.php', array( 'order' => $order ) );
		$rendered = (string) ob_get_clean() . (string) wp_json_encode( $mails );

		$rest = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() ) );
		wp_set_current_user( 1 );
		$rest = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() ) );
		wp_set_current_user( 0 );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test reads raw options.
		$options = (string) $wpdb->get_var( $wpdb->prepare( "SELECT GROUP_CONCAT(option_value) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '%transient%', '%einvoice%log%' ) );
		$logs    = '';
		foreach ( (array) glob( WC_LOG_DIR . '*.log' ) as $file ) {
			$logs .= (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Local log file.
		}

		foreach ( array( $rendered, (string) wp_json_encode( $rest->get_data() ), $options, $logs ) as $output ) {
			$this->assertStringNotContainsString( self::ID_NUMBER, $output );
			$this->assertStringNotContainsString( $request['id_encrypted'], $output );
		}
		$this->assertSame( 200, $rest->get_status() );
	}

	public function test_the_checkout_script_is_registered_for_the_blocks_checkout(): void {
		do_action( 'woocommerce_blocks_checkout_block_registration', new \Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.

		$this->assertTrue( wp_script_is( 'einvoice-matbao-checkout-blocks', 'registered' ) );
	}

	/**
	 * A pending, payable order tied to the current cart and the session, as a failed-payment retry sees it.
	 */
	private function pending_order_in_session(): WC_Order {
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->create_product( 'Paid item', '100000' )->get_id() );
		$order = wc_create_order( array( 'status' => 'pending' ) );
		$order->add_product( wc_get_product( array_values( WC()->cart->get_cart() )[0]['product_id'] ), 1 );
		$order->set_cart_hash( WC()->cart->get_cart_hash() );
		$order->calculate_totals();
		$order->save();
		WC()->session->set( 'store_api_draft_order', $order->get_id() );

		return $order;
	}

	/**
	 * Sends a Store API checkout request with the default billing address.
	 *
	 * @param string               $method PATCH or POST.
	 * @param array<string, mixed> $body   Extra body keys.
	 */
	private function send( string $method, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wc/store/v1/checkout' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
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
	 * @param array<string, mixed>      $extra     Extra body keys.
	 */
	private function place_order( ?array $extension, array $billing = array(), array $extra = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_header( 'Content-Type', 'application/json' );
		$body = $extra + array(
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
