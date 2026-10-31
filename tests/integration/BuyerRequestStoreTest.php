<?php
/**
 * The stored invoice request built from validated buyer input, shared by Classic and Blocks checkout.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Buyer\BuyerInput;
use LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore;
use LTTech\EinvoiceMatbao\Encryption;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * BuyerRequestStore::from_input keeps only the fields of the chosen type and never returns the
 * citizen ID in clear text. All identity numbers are obviously fake test values.
 */
final class BuyerRequestStoreTest extends SettingsRestTestCase {

	private const ID_NUMBER = '000000001234';

	public function test_individual_request_encrypts_the_id_number_and_drops_business_fields(): void {
		$this->with_encryption_key();
		$encryption = new Encryption();

		$request = BuyerRequestStore::from_input(
			new BuyerInput( 'individual', 'Nguyễn Văn A', 'Doanh nghiệp Thử', '0000000000', '  Số 1 Đường Thử  ', ' ' . self::ID_NUMBER . ' ', '0900000001', 'buyer@example.invalid' ),
			$encryption
		);

		$this->assertSame( 'individual', $request['type'] );
		$this->assertSame( '', $request['business_name'] );
		$this->assertSame( '', $request['tax_code'] );
		$this->assertSame( 'Số 1 Đường Thử', $request['address'] );
		$this->assertSame( '********1234', $request['id_mask'] );
		$this->assertSame( self::ID_NUMBER, $encryption->decrypt( $request['id_encrypted'] ) );
		$this->assertStringNotContainsString( self::ID_NUMBER, (string) wp_json_encode( $request ) );
	}

	public function test_business_request_keeps_business_fields_and_never_stores_an_id_number(): void {
		$this->with_encryption_key();

		$request = BuyerRequestStore::from_input(
			new BuyerInput( 'business', 'Nguyễn Văn A', ' Doanh nghiệp Thử ', ' 0000000000-001 ', 'Số 2 Đường Thử', self::ID_NUMBER, '0900000001', 'buyer@example.invalid' ),
			new Encryption()
		);

		$this->assertSame(
			array(
				'type'          => 'business',
				'business_name' => 'Doanh nghiệp Thử',
				'tax_code'      => '0000000000-001',
				'address'       => 'Số 2 Đường Thử',
				'id_encrypted'  => '',
				'id_mask'       => '',
			),
			$request
		);
	}
}
