<?php
/**
 * Buyer values collected for the known-values redaction layer.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore;
use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshot;
use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshotter;
use LTTech\EinvoiceMatbao\Encryption;
use LTTech\EinvoiceMatbao\Monitoring\OrderRedaction;
use WP_UnitTestCase;

/**
 * Contact, address, stored request and snapshot values, with the citizen ID decrypted.
 */
final class OrderRedactionTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'einvoice_matbao_encryption_key', static fn(): string => 'test-only-key-material-0123456789abcdef' );
	}

	public function tear_down(): void {
		remove_all_filters( 'einvoice_matbao_encryption_key' );
		parent::tear_down();
	}

	public function test_missing_order_has_no_values(): void {
		$this->assertSame( array(), OrderRedaction::values_for( 999999 ) );
	}

	public function test_collects_contact_and_address_values(): void {
		$order = wc_create_order();
		$order->set_billing_first_name( 'Lan' );
		$order->set_billing_last_name( 'Tran Thi' );
		$order->set_billing_company( 'Cong Ty Hoa Mai' );
		$order->set_billing_email( 'lan@example.vn' );
		$order->set_billing_phone( '0901234567' );
		$order->set_billing_address_1( '12 Nguyen Trai' );
		$order->set_billing_address_2( 'Tang 3' );
		$order->set_shipping_first_name( 'Minh' );
		$order->set_shipping_last_name( 'Le' );
		$order->set_shipping_company( 'Kho Binh Duong' );
		$order->set_shipping_phone( '0912345678' );
		$order->set_shipping_address_1( '45 Le Loi' );
		$order->set_shipping_address_2( 'Lo B' );
		$order->save();

		$values = OrderRedaction::values_for( $order->get_id() );

		foreach ( array( 'Lan Tran Thi', 'Lan', 'Tran Thi', 'Cong Ty Hoa Mai', 'lan@example.vn', '0901234567', '12 Nguyen Trai', 'Tang 3', 'Minh Le', 'Kho Binh Duong', '0912345678', '45 Le Loi', 'Lo B' ) as $expected ) {
			$this->assertContains( $expected, $values );
		}
		$this->assertNotContains( '', $values );
		$this->assertSame( $values, array_values( array_unique( $values ) ) );
	}

	public function test_collects_stored_request_and_snapshot_with_decrypted_id(): void {
		$encryption = new Encryption();
		$order      = wc_create_order();
		( new BuyerRequestStore() )->save(
			$order,
			array(
				'type'          => 'company',
				'business_name' => 'Cong Ty TNHH Sao Viet',
				'tax_code'      => '0312345678',
				'address'       => '99 Hai Ba Trung',
				'id_encrypted'  => $encryption->encrypt( '079203001234' ),
				'id_mask'       => '********1234',
			)
		);
		( new BuyerSnapshotter() )->store(
			$order,
			new BuyerSnapshot(
				'individual',
				'Pham Van Nam',
				array(
					'NMua_DChi' => '7 Phan Chu Trinh',
					'NMua_SDT'  => '0987654321',
				),
				$encryption->encrypt( '001099009876' ),
				'********9876'
			)
		);
		$order->save();

		$values = OrderRedaction::values_for( $order->get_id() );

		foreach ( array( 'Cong Ty TNHH Sao Viet', '0312345678', '99 Hai Ba Trung', '079203001234', 'Pham Van Nam', '7 Phan Chu Trinh', '0987654321', '001099009876' ) as $expected ) {
			$this->assertContains( $expected, $values );
		}
	}
}
