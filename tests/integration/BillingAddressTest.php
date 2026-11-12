<?php
/**
 * The one-line billing address sent as the buyer address.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Buyer\BillingAddress;
use WC_Order;
use WP_UnitTestCase;

/**
 * BillingAddress joins the non-empty billing parts and resolves the province name from its code.
 * All addresses are obviously fake test values.
 */
final class BillingAddressTest extends WP_UnitTestCase {

	public function test_from_array_joins_all_four_parts_with_the_province_name(): void {
		$address = BillingAddress::from_array(
			array(
				'billing_address_1' => '12 Đường Thử',
				'billing_address_2' => 'Tầng 3',
				'billing_city'      => 'Quận Thử',
				'billing_state'     => 'HANOI',
				'billing_country'   => 'VN',
			)
		);

		$this->assertSame( '12 Đường Thử, Tầng 3, Quận Thử, ' . $this->province_name( 'VN', 'HANOI' ), $address );
	}

	public function test_from_array_skips_empty_parts(): void {
		$address = BillingAddress::from_array(
			array(
				'billing_address_1' => ' 12 Đường Thử ',
				'billing_address_2' => '',
				'billing_city'      => '  ',
				'billing_state'     => '',
				'billing_country'   => 'VN',
			)
		);

		$this->assertSame( '12 Đường Thử', $address );
	}

	public function test_from_array_keeps_an_unknown_state_code(): void {
		$address = BillingAddress::from_array(
			array(
				'billing_address_1' => '12 Đường Thử',
				'billing_state'     => 'ZZ-UNKNOWN',
				'billing_country'   => 'VN',
			)
		);

		$this->assertSame( '12 Đường Thử, ZZ-UNKNOWN', $address );
	}

	public function test_from_array_honours_a_custom_prefix(): void {
		$address = BillingAddress::from_array( array( 'shipping_address_1' => '5 Đường Thử' ), 'shipping_' );

		$this->assertSame( '5 Đường Thử', $address );
	}

	public function test_from_array_returns_empty_string_when_everything_is_empty(): void {
		$this->assertSame( '', BillingAddress::from_array( array() ) );
		$this->assertSame(
			'',
			BillingAddress::from_array(
				array(
					'billing_address_1' => ' ',
					'billing_state'     => '',
				)
			)
		);
	}

	public function test_from_order_joins_all_parts_with_the_province_name(): void {
		$order = new WC_Order();
		$order->set_billing_address_1( '12 Đường Thử' );
		$order->set_billing_address_2( 'Tầng 3' );
		$order->set_billing_city( 'Quận Thử' );
		$order->set_billing_state( 'HANOI' );
		$order->set_billing_country( 'VN' );

		$this->assertSame( '12 Đường Thử, Tầng 3, Quận Thử, ' . $this->province_name( 'VN', 'HANOI' ), BillingAddress::from_order( $order ) );
	}

	public function test_from_order_returns_empty_string_when_billing_is_empty(): void {
		$this->assertSame( '', BillingAddress::from_order( new WC_Order() ) );
	}

	/**
	 * Province name WooCommerce knows for a code.
	 *
	 * @param string $country Country code.
	 * @param string $state   State code.
	 */
	private function province_name( string $country, string $state ): string {
		$states = WC()->countries->get_states( $country );

		return is_array( $states ) && isset( $states[ $state ] ) ? (string) $states[ $state ] : $state;
	}
}
