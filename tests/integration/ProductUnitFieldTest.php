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
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * The override is written with the product's CRUD meta by users who may edit the product, behind a nonce.
 */
final class ProductUnitFieldTest extends InvoiceWorkflowTestCase {

	public function set_up(): void {
		parent::set_up();
		// Admin-only helpers that the product edit screen has loaded.
		require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
	}

	public function tear_down(): void {
		unset( $_POST[ ProductUnitField::FIELD ], $_POST[ ProductUnitField::NONCE_FIELD ] );
		unset( $GLOBALS['post'] );
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

	public function test_save_without_the_field_keeps_the_existing_override(): void {
		$this->login_as( 'shop_manager' );
		$product = $this->create_product( 'A', '100000' );
		$product->update_meta_data( ProductUnitField::META_KEY, 'Hộp' );
		$product->save();
		unset( $_POST[ ProductUnitField::FIELD ] );
		$_POST[ ProductUnitField::NONCE_FIELD ] = wp_create_nonce( ProductUnitField::NONCE_ACTION );

		ProductUnitField::save( $product );
		$product->save();

		$this->assertSame( 'Hộp', ( new WC_Product_Simple( $product->get_id() ) )->get_meta( ProductUnitField::META_KEY ) );
	}

	public function test_multibyte_unit_over_50_characters_is_refused_and_the_old_value_stays(): void {
		$this->login_as( 'shop_manager' );
		$product = $this->create_product( 'A', '100000' );
		$product->update_meta_data( ProductUnitField::META_KEY, 'Hộp' );
		$product->save();
		$this->submit( str_repeat( 'á', 51 ), true );

		ProductUnitField::save( $product );
		$product->save();

		$this->assertSame( 'Hộp', ( new WC_Product_Simple( $product->get_id() ) )->get_meta( ProductUnitField::META_KEY ) );
	}

	public function test_each_variation_loop_saves_its_own_value(): void {
		$this->login_as( 'shop_manager' );
		$variations = $this->create_variations( 3 );
		$nonce      = wp_create_nonce( ProductUnitField::NONCE_ACTION );
		$_POST[ ProductUnitField::FIELD ]       = array( 'Hộp', 'Chai', 'Cái' );
		$_POST[ ProductUnitField::NONCE_FIELD ] = array( $nonce, $nonce, $nonce );

		foreach ( $variations as $loop => $variation ) {
			ProductUnitField::save_variation( $variation, $loop );
			$variation->save();
		}

		$this->assertSame( array( 'Hộp', 'Chai', 'Cái' ), $this->saved_units( $variations ) );
	}

	public function test_variation_without_its_nonce_is_not_saved(): void {
		$this->login_as( 'shop_manager' );
		$variations = $this->create_variations( 3 );
		$nonce      = wp_create_nonce( ProductUnitField::NONCE_ACTION );
		$_POST[ ProductUnitField::FIELD ]       = array( 'Hộp', 'Chai', 'Cái' );
		$_POST[ ProductUnitField::NONCE_FIELD ] = array(
			0 => $nonce,
			2 => $nonce,
		);

		foreach ( $variations as $loop => $variation ) {
			ProductUnitField::save_variation( $variation, $loop );
			$variation->save();
		}

		$this->assertSame( array( 'Hộp', '', 'Cái' ), $this->saved_units( $variations ) );
	}

	public function test_variation_missing_from_the_submitted_field_keeps_its_override(): void {
		$this->login_as( 'shop_manager' );
		$variations = $this->create_variations( 2 );
		$variations[1]->update_meta_data( ProductUnitField::META_KEY, 'Chai' );
		$variations[1]->save();
		$nonce                                  = wp_create_nonce( ProductUnitField::NONCE_ACTION );
		$_POST[ ProductUnitField::FIELD ]       = array( 0 => 'Hộp' );
		$_POST[ ProductUnitField::NONCE_FIELD ] = array( $nonce, $nonce );

		foreach ( $variations as $loop => $variation ) {
			ProductUnitField::save_variation( $variation, $loop );
			$variation->save();
		}

		$this->assertSame( array( 'Hộp', 'Chai' ), $this->saved_units( $variations ) );
	}

	public function test_variation_unit_over_50_multibyte_characters_is_refused_and_the_old_value_stays(): void {
		$this->login_as( 'shop_manager' );
		$variations = $this->create_variations( 1 );
		$variations[0]->update_meta_data( ProductUnitField::META_KEY, 'Hộp' );
		$variations[0]->save();
		$_POST[ ProductUnitField::FIELD ]       = array( str_repeat( 'ế', 51 ) );
		$_POST[ ProductUnitField::NONCE_FIELD ] = array( wp_create_nonce( ProductUnitField::NONCE_ACTION ) );

		ProductUnitField::save_variation( $variations[0], 0 );
		$variations[0]->save();

		$this->assertSame( array( 'Hộp' ), $this->saved_units( $variations ) );
	}

	public function test_registered_hooks_save_the_product_and_its_variations(): void {
		$this->login_as( 'shop_manager' );
		ProductUnitField::register();
		$product    = $this->create_product( 'A', '100000' );
		$variations = $this->create_variations( 2 );
		$nonce      = wp_create_nonce( ProductUnitField::NONCE_ACTION );

		$this->submit( 'Hộp', true );
		do_action( 'woocommerce_admin_process_product_object', $product );
		$product->save();

		$_POST[ ProductUnitField::FIELD ]       = array( 'Chai', 'Cái' );
		$_POST[ ProductUnitField::NONCE_FIELD ] = array( $nonce, $nonce );
		foreach ( $variations as $loop => $variation ) {
			do_action( 'woocommerce_admin_process_variation_object', $variation, $loop );
			$variation->save();
		}

		$this->assertSame( 'Hộp', ( new WC_Product_Simple( $product->get_id() ) )->get_meta( ProductUnitField::META_KEY ) );
		$this->assertSame( array( 'Chai', 'Cái' ), $this->saved_units( $variations ) );
	}

	public function test_registered_hooks_render_the_fields(): void {
		$this->login_as( 'shop_manager' );
		ProductUnitField::register();
		$variations = $this->create_variations( 1 );

		ob_start();
		do_action( 'woocommerce_product_options_general_product_data' );
		$general = (string) ob_get_clean();
		ob_start();
		do_action( 'woocommerce_variation_options_pricing', 0, array(), get_post( $variations[0]->get_id() ) );
		$variation = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . ProductUnitField::NONCE_FIELD . '"', $general );
		$this->assertStringContainsString( 'name="' . ProductUnitField::NONCE_FIELD . '[0]"', $variation );
	}

	public function test_render_prefills_the_current_override_escaped(): void {
		$this->login_as( 'shop_manager' );
		$product = $this->create_product( 'A', '100000' );
		$product->update_meta_data( ProductUnitField::META_KEY, 'Hộp "x" <b>' );
		$product->save();
		$GLOBALS['post'] = get_post( $product->get_id() );

		ob_start();
		ProductUnitField::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="Hộp &quot;x&quot; &lt;b&gt;"', $html );
		$this->assertStringContainsString( 'name="' . ProductUnitField::NONCE_FIELD . '"', $html );
		$this->assertStringContainsString( 'maxlength="50"', $html );
	}

	public function test_render_variation_has_a_positional_nonce_and_the_escaped_override(): void {
		$this->login_as( 'shop_manager' );
		$variations = $this->create_variations( 2 );
		$variations[1]->update_meta_data( ProductUnitField::META_KEY, 'Chai "y" <i>' );
		$variations[1]->save();

		ob_start();
		ProductUnitField::render_variation( 1, array(), get_post( $variations[1]->get_id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . ProductUnitField::NONCE_FIELD . '[1]"', $html );
		$this->assertStringContainsString( 'name="' . ProductUnitField::FIELD . '[1]"', $html );
		$this->assertStringContainsString( 'value="Chai &quot;y&quot; &lt;i&gt;"', $html );
	}

	/**
	 * Variations of one new variable product, in loop order.
	 *
	 * @param int $count How many variations.
	 * @return array<int, WC_Product_Variation>
	 */
	private function create_variations( int $count ): array {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Variable' );
		$parent->save();

		$variations = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_regular_price( '100000' );
			$variation->save();
			$variations[] = $variation;
		}

		return $variations;
	}

	/**
	 * Override stored for each variation, re-read from the database.
	 *
	 * @param array<int, WC_Product_Variation> $variations Variations to read.
	 * @return array<int, string>
	 */
	private function saved_units( array $variations ): array {
		return array_map(
			static fn( WC_Product_Variation $variation ): string => (string) ( new WC_Product_Variation( $variation->get_id() ) )->get_meta( ProductUnitField::META_KEY ),
			$variations
		);
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
