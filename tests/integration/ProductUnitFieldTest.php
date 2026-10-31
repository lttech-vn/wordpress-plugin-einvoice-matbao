<?php
/**
 * Saving the per-product unit of measure override from the product edit screen.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\ProductUnitField;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Product_Simple;

/**
 * The override is written with the product's CRUD meta by users who may edit the product, behind a nonce.
 */
final class ProductUnitFieldTest extends InvoiceWorkflowTestCase {

	public function tear_down(): void {
		unset( $_POST[ ProductUnitField::FIELD ], $_POST[ ProductUnitField::NONCE_FIELD ] );
		parent::tear_down();
	}

	public function test_override_is_saved_for_a_user_who_can_edit_the_product(): void {
		$this->login_as( 'shop_manager' );
		$product = $this->create_product( 'A', '100000' );
		$this->submit( ' Hộp ', true );

		ProductUnitField::save( $product );
		$product->save();

		$this->assertSame( 'Hộp', ( new WC_Product_Simple( $product->get_id() ) )->get_meta( ProductUnitField::META_KEY ) );
	}

	public function test_blank_value_removes_the_override(): void {
		$this->login_as( 'shop_manager' );
		$product = $this->create_product( 'A', '100000' );
		$product->update_meta_data( ProductUnitField::META_KEY, 'Hộp' );
		$product->save();
		$this->submit( '  ', true );

		ProductUnitField::save( $product );
		$product->save();

		$this->assertSame( '', ( new WC_Product_Simple( $product->get_id() ) )->get_meta( ProductUnitField::META_KEY ) );
	}

	public function test_missing_or_invalid_nonce_changes_nothing(): void {
		$this->login_as( 'shop_manager' );
		$product = $this->create_product( 'A', '100000' );

		$this->submit( 'Hộp', false );
		ProductUnitField::save( $product );
		$_POST[ ProductUnitField::NONCE_FIELD ] = 'bad-nonce';
		ProductUnitField::save( $product );
		$product->save();

		$this->assertSame( '', ( new WC_Product_Simple( $product->get_id() ) )->get_meta( ProductUnitField::META_KEY ) );
	}

	public function test_user_who_cannot_edit_the_product_changes_nothing(): void {
		$this->login_as( 'customer' );
		$product = $this->create_product( 'A', '100000' );
		$this->submit( 'Hộp', true );

		ProductUnitField::save( $product );
		$product->save();

		$this->assertSame( '', ( new WC_Product_Simple( $product->get_id() ) )->get_meta( ProductUnitField::META_KEY ) );
	}

	public function test_unit_over_50_characters_is_refused_and_the_old_value_stays(): void {
		$this->login_as( 'shop_manager' );
		$product = $this->create_product( 'A', '100000' );
		$product->update_meta_data( ProductUnitField::META_KEY, 'Hộp' );
		$product->save();
		$this->submit( str_repeat( 'a', 51 ), true );

		ProductUnitField::save( $product );
		$product->save();

		$this->assertSame( 'Hộp', ( new WC_Product_Simple( $product->get_id() ) )->get_meta( ProductUnitField::META_KEY ) );
	}

	public function test_markup_is_stripped_from_the_saved_override(): void {
		$this->login_as( 'shop_manager' );
		$product = $this->create_product( 'A', '100000' );
		$this->submit( '<b>Hộp</b><script>x</script>', true );

		ProductUnitField::save( $product );
		$product->save();

		$this->assertSame( 'Hộp', ( new WC_Product_Simple( $product->get_id() ) )->get_meta( ProductUnitField::META_KEY ) );
	}

	/**
	 * Puts the field in `$_POST` like the product edit form would.
	 *
	 * @param string $value      Submitted unit.
	 * @param bool   $with_nonce Whether to include a valid nonce.
	 */
	private function submit( string $value, bool $with_nonce ): void {
		$_POST[ ProductUnitField::FIELD ] = $value;
		unset( $_POST[ ProductUnitField::NONCE_FIELD ] );
		if ( $with_nonce ) {
			$_POST[ ProductUnitField::NONCE_FIELD ] = wp_create_nonce( ProductUnitField::NONCE_ACTION );
		}
	}
}
