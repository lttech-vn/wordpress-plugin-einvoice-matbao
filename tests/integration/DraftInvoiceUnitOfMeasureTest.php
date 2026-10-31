<?php
/**
 * Unit of measure (`DVTinh`) on invoice lines: store default and per-product override.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\ProductUnitField;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order_Item_Fee;
use WC_Order_Item_Shipping;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * The workflow seam: the payload carries the resolved unit on every line.
 */
final class DraftInvoiceUnitOfMeasureTest extends InvoiceWorkflowTestCase {

	public function test_product_without_override_uses_gói_when_the_store_default_is_not_configured(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assertSame( array( 'Gói' ), array_column( $this->sent_invoices()[0]['DSHHDVu'], 'DVTinh' ) );
	}

	public function test_product_without_override_uses_the_store_default(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		( new Settings() )->save_default_unit( 'Cái' );
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assertSame( array( 'Cái' ), array_column( $this->sent_invoices()[0]['DSHHDVu'], 'DVTinh' ) );
	}

	public function test_product_with_override_uses_its_own_unit(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		( new Settings() )->save_default_unit( 'Cái' );
		$with_override = $this->create_product( 'A', '100000' );
		$with_override->update_meta_data( ProductUnitField::META_KEY, 'Hộp' );
		$with_override->save();
		$order = $this->create_order( array( array( $with_override, 1 ), array( $this->create_product( 'B', '50000' ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assertSame( array( 'Hộp', 'Cái' ), array_column( $this->sent_invoices()[0]['DSHHDVu'], 'DVTinh' ) );
	}

	public function test_variation_inherits_the_parent_override_unless_it_has_its_own(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		( new Settings() )->save_default_unit( 'Cái' );
		$parent = $this->create_variable_product( 'Áo', array( '100000', '100000' ) );
		$parent->update_meta_data( ProductUnitField::META_KEY, 'Chiếc' );
		$parent->save();
		$variations = $parent->get_children();
		$own        = new WC_Product_Variation( $variations[1] );
		$own->update_meta_data( ProductUnitField::META_KEY, 'Bộ' );
		$own->save();
		$order = $this->create_order(
			array(
				array( new WC_Product_Variation( $variations[0] ), 1 ),
				array( new WC_Product_Variation( $variations[1] ), 1 ),
			)
		);

		$this->complete_and_run( $order );

		$this->assertSame( array( 'Chiếc', 'Bộ' ), array_column( $this->sent_invoices()[0]['DSHHDVu'], 'DVTinh' ) );
	}

	public function test_variation_of_a_parent_without_override_uses_the_store_default(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		( new Settings() )->save_default_unit( 'Cái' );
		$parent = $this->create_variable_product( 'Áo', array( '100000' ) );
		$order  = $this->create_order( array( array( new WC_Product_Variation( $parent->get_children()[0] ), 1 ) ) );

		$this->complete_and_run( $order );

		$this->assertSame( array( 'Cái' ), array_column( $this->sent_invoices()[0]['DSHHDVu'], 'DVTinh' ) );
	}

	public function test_fee_and_shipping_lines_use_the_store_default_not_a_product_override(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		( new Settings() )->save_default_unit( 'Lần' );
		$product = $this->create_product( 'A', '100000' );
		$product->update_meta_data( ProductUnitField::META_KEY, 'Hộp' );
		$product->save();
		$order = $this->create_order( array( array( $product, 1 ) ) );
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Phí xử lý' );
		$fee->set_amount( '20000' );
		$fee->set_total( '20000' );
		$fee->set_tax_status( 'taxable' );
		$order->add_item( $fee );
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Giao hàng' );
		$shipping->set_total( '30000' );
		$order->add_item( $shipping );
		$order->calculate_totals();
		$order->save();

		$this->complete_and_run( $order );

		$this->assertSame( array( 'Hộp', 'Lần', 'Lần' ), array_column( $this->sent_invoices()[0]['DSHHDVu'], 'DVTinh' ) );
	}

	public function test_unit_is_snapshotted_when_the_payload_is_built_not_when_the_order_completes(): void {
		$this->configure_matbao();
		$this->fake_create_invoice_ok();
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );

		$order->update_status( 'completed' );
		( new Settings() )->save_default_unit( 'Cái' );
		$this->run_queued_draft_actions();
		( new Settings() )->save_default_unit( 'Hộp' );
		$this->run_queued_draft_actions();

		$sent = $this->sent_invoices();
		$this->assertCount( 1, $sent );
		$this->assertSame( array( 'Cái' ), array_column( $sent[0]['DSHHDVu'], 'DVTinh' ) );
	}

	/**
	 * Creates a variable product with one variation per price.
	 *
	 * @param string       $name   Product name.
	 * @param list<string> $prices Variation regular prices.
	 */
	private function create_variable_product( string $name, array $prices ): WC_Product_Variable {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Cỡ' );
		$attribute->set_options( array_map( static fn( int $i ): string => 'size' . $i, array_keys( $prices ) ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( $name );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();

		foreach ( $prices as $index => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'co' => 'size' . $index ) );
			$variation->set_regular_price( $price );
			$variation->set_virtual( true );
			$variation->save();
		}

		return new WC_Product_Variable( $parent->get_id() );
	}
}
