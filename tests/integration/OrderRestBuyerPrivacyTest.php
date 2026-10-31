<?php
/**
 * Buyer request and snapshot meta stay out of the WooCommerce REST order responses.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore;
use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshotter;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;
use WP_REST_Request;

/**
 * A REST client allowed to read orders (an API key with read access acts as a user who can read
 * shop orders) must not receive the encrypted CCCD, its mask or the captured buyer snapshot.
 * All identity numbers are obviously fake test values.
 */
final class OrderRestBuyerPrivacyTest extends InvoiceWorkflowTestCase {

	private const ID_NUMBER = '000000001234';

	public function tear_down(): void {
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Every REST order route that returns `meta_data`.
	 *
	 * @return array<string, array{string}>
	 */
	public static function order_routes(): array {
		return array(
			'v3' => array( '/wc/v3/orders' ),
			'v2' => array( '/wc/v2/orders' ),
			'v1' => array( '/wc/v1/orders' ),
		);
	}

	/**
	 * Single order response.
	 *
	 * @dataProvider order_routes
	 *
	 * @param string $route Collection route.
	 */
	public function test_single_order_response_hides_the_buyer_request_and_snapshot( string $route ): void {
		$order = $this->completed_individual_order();
		$this->login_as( 'shop_manager' );

		$response = rest_do_request( new WP_REST_Request( 'GET', $route . '/' . $order->get_id() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assert_no_buyer_secrets( $order, (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Order list response, including an explicit request for the meta keys.
	 *
	 * @dataProvider order_routes
	 *
	 * @param string $route Collection route.
	 */
	public function test_order_list_response_hides_the_buyer_request_and_snapshot( string $route ): void {
		$order = $this->completed_individual_order();
		$this->login_as( 'shop_manager' );

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'include_meta', array( BuyerRequestStore::META_KEY, BuyerSnapshotter::META_KEY ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assert_no_buyer_secrets( $order, (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_experimental_v4_order_response_hides_the_buyer_request_and_snapshot(): void {
		add_filter( 'woocommerce_admin_features', static fn( array $features ): array => array_merge( $features, array( 'rest-api-v4' ) ) );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- rebuild routes with wc/v4 enabled.
		rest_get_server();
		$order = $this->completed_individual_order();
		$this->login_as( 'administrator' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/v4/orders/' . $order->get_id() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'meta_data', (array) $response->get_data() );
		$this->assert_no_buyer_secrets( $order, (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_other_order_meta_is_still_returned(): void {
		$order = $this->completed_individual_order();
		$order->update_meta_data( 'einv_test_visible', 'visible-value' );
		$order->save();
		$this->login_as( 'shop_manager' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() ) );

		$this->assertStringContainsString( 'visible-value', (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Asserts a response carries none of the buyer meta.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $json  Encoded response data.
	 */
	private function assert_no_buyer_secrets( WC_Order $order, string $json ): void {
		$request = ( new BuyerRequestStore() )->load( $order );
		$this->assertNotNull( $request );
		$this->assertNotSame( '', $request['id_encrypted'] );

		$this->assertStringNotContainsString( self::ID_NUMBER, $json );
		$this->assertStringNotContainsString( $request['id_encrypted'], $json );
		$this->assertStringNotContainsString( str_replace( '/', '\\/', $request['id_encrypted'] ), $json );
		$this->assertStringNotContainsString( '********1234', $json );
		$this->assertStringNotContainsString( 'Số 1 Đường Thử', $json );
		$this->assertStringNotContainsString( BuyerRequestStore::META_KEY, $json );
		$this->assertStringNotContainsString( BuyerSnapshotter::META_KEY, $json );
	}

	/**
	 * A completed order whose individual request (with an encrypted CCCD) and snapshot are stored.
	 */
	private function completed_individual_order(): WC_Order {
		$this->with_encryption_key();
		$_POST = array(
			'einv_request_invoice' => '1',
			'einv_buyer_type'      => 'individual',
			'einv_address'         => 'Số 1 Đường Thử, Quận Thử',
			'einv_id_number'       => self::ID_NUMBER,
		);
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
		$order->update_status( 'completed' );
		$this->assertNotSame( '', (string) $order->get_meta( BuyerSnapshotter::META_KEY ) );

		return wc_get_order( $order->get_id() );
	}
}
