<?php
/**
 * The one-line billing address sent as the buyer address.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Buyer\BillingAddress;
use WC_Countries;
use WC_Order;
use WP_UnitTestCase;

/**
 * BillingAddress joins the non-empty billing parts and resolves the province name from its code.
 * All addresses are obviously fake test values.
 */
final class BillingAddressTest extends WP_UnitTestCase {

	/**
	 * The WooCommerce countries object before the test replaced it.
	 *
	 * @var WC_Countries
	 */
	private WC_Countries $original_countries;

	public function set_up(): void {
		parent::set_up();

		// A controlled province list whose names differ from their codes.
		add_filter(
			'woocommerce_states',
			static fn(): array => array( 'VN' => array( 'TESTCODE' => 'Tỉnh Thử Nghiệm' ) )
		);
		$this->original_countries = WC()->countries;
		WC()->countries           = new WC_Countries();
	}

	public function tear_down(): void {
		remove_all_filters( 'woocommerce_states' );
		WC()->countries = $this->original_countries;

		parent::tear_down();
	}

	public function test_from_array_joins_all_four_parts_with_the_province_name(): void {
		$address = BillingAddress::from_array(
			array(
				'billing_address_1' => '12 Đường Thử',
				'billing_address_2' => 'Tầng 3',
				'billing_city'      => 'Quận Thử',
				'billing_state'     => 'TESTCODE',
				'billing_country'   => 'VN',
			)
		);

		$this->assertSame( '12 Đường Thử, Tầng 3, Quận Thử, Tỉnh Thử Nghiệm', $address );
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
		$order->set_billing_state( 'TESTCODE' );
		$order->set_billing_country( 'VN' );

		$this->assertSame( '12 Đường Thử, Tầng 3, Quận Thử, Tỉnh Thử Nghiệm', BillingAddress::from_order( $order ) );
	}

	public function test_from_order_skips_missing_middle_parts(): void {
		$order = new WC_Order();
		$order->set_billing_address_1( '12 Đường Thử' );
		$order->set_billing_city( 'Quận Thử' );
		$order->set_billing_state( 'TESTCODE' );
		$order->set_billing_country( 'VN' );

		$this->assertSame( '12 Đường Thử, Quận Thử, Tỉnh Thử Nghiệm', BillingAddress::from_order( $order ) );
	}

	public function test_from_order_keeps_an_unknown_state_code(): void {
		$order = new WC_Order();
		$order->set_billing_address_1( '12 Đường Thử' );
		$order->set_billing_address_2( 'Tầng 3' );
		$order->set_billing_state( 'NOPE' );
		$order->set_billing_country( 'VN' );

		$this->assertSame( '12 Đường Thử, Tầng 3, NOPE', BillingAddress::from_order( $order ) );
	}

	public function test_from_array_does_not_truncate_a_long_vietnamese_address(): void {
		$long = str_repeat( 'ạ', 450 );

		$this->assertSame( $long, BillingAddress::from_array( array( 'billing_address_1' => $long ) ) );
		$this->assertSame( 450, mb_strlen( BillingAddress::from_array( array( 'billing_address_1' => $long ) ) ) );
	}

	public function test_from_order_returns_empty_string_when_billing_is_empty(): void {
		$this->assertSame( '', BillingAddress::from_order( new WC_Order() ) );
	}
}
