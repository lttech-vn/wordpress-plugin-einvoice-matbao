<?php
/**
 * Line details carried by data errors of the draft payload builder.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Invoice\DraftPayloadBuilder;
use LTTech\EinvoiceMatbao\Invoice\InvoiceDataError;
use LTTech\EinvoiceMatbao\Invoice\InvoiceErrorCode;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\InvoiceWorkflowTestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Tax;

/**
 * Calls the builder directly and inspects the line on the raised InvoiceDataError.
 */
final class DraftPayloadLineFailureTest extends InvoiceWorkflowTestCase {

	public function test_two_tax_rates_on_product_line(): void {
		$product  = $this->create_product( 'Áo thun', '100000', '', 'taxable', 'AO-01' );
		$order    = $this->create_order( array( array( $product, 1 ) ) );
		$rate_ids = array_map( static fn( $rate ): int => (int) $rate->tax_rate_id, array_values( WC_Tax::get_rates_for_tax_class( self::CLASS_FIVE ) ) );
		$rate_ids = array_merge( $rate_ids, array_map( static fn( $rate ): int => (int) $rate->tax_rate_id, array_values( WC_Tax::get_rates_for_tax_class( '' ) ) ) );
		$this->assertCount( 2, $rate_ids );

		foreach ( $order->get_items() as $item ) {
			$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
			$item->set_taxes(
				array(
					'total'    => array(
						$rate_ids[0] => '5000',
						$rate_ids[1] => '10000',
					),
					'subtotal' => array(
						$rate_ids[0] => '5000',
						$rate_ids[1] => '10000',
					),
				)
			);
			$item->save();
		}

		$error = $this->build_error( $order );

		$this->assertSame( InvoiceErrorCode::LINE_TAX_RATE_COUNT, $error->error_code );
		$this->assertNotNull( $error->line_failure );
		$this->assertSame( 'line_item', $error->line_failure->item_type );
		$this->assertSame( $product->get_id(), $error->line_failure->product_id );
		$this->assertSame( 'AO-01', $error->line_failure->sku );
		$this->assertEqualsCanonicalizing( $rate_ids, $error->line_failure->tax_rate_ids );
		$this->assertSame( 'Dòng "Áo thun" cần đúng một thuế suất GTGT.', $error->getMessage() );
	}

	public function test_shipping_line_with_fractional_amount(): void {
		$order    = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Giao hàng' );
		$shipping->set_total( '30000.5' );
		$order->add_item( $shipping );
		$order->save();

		$error = $this->build_error( $order );

		$this->assertSame( InvoiceErrorCode::AMOUNT_NOT_WHOLE_VND, $error->error_code );
		$this->assertNotNull( $error->line_failure );
		$this->assertSame( 'shipping', $error->line_failure->item_type );
		$this->assertSame( 0, $error->line_failure->product_id );
		$this->assertSame( '', $error->line_failure->sku );
	}

	public function test_fractional_amount_keeps_tax_rate_ids(): void {
		$order = $this->create_order( array( array( $this->create_product( 'Lẻ', '100.5', self::CLASS_ZERO ), 1 ) ) );
		$rates = array_keys( $order->get_taxes() );
		$this->assertNotEmpty( $rates );
		$rate_id = (int) current( $order->get_taxes() )->get_rate_id();

		$error = $this->build_error( $order );

		$this->assertSame( InvoiceErrorCode::AMOUNT_NOT_WHOLE_VND, $error->error_code );
		$this->assertNotNull( $error->line_failure );
		$this->assertContains( $rate_id, $error->line_failure->tax_rate_ids );
	}

	public function test_totals_mismatch_has_no_line(): void {
		$order = $this->create_order( array( array( $this->create_product( 'A', '100000' ), 1 ) ) );
		$order->set_total( '120000' );
		$order->save();

		$error = $this->build_error( $order );

		$this->assertSame( InvoiceErrorCode::TOTALS_MISMATCH, $error->error_code );
		$this->assertNull( $error->line_failure );
	}

	private function build_error( WC_Order $order ): InvoiceDataError {
		try {
			( new DraftPayloadBuilder() )->build(
				$order,
				'REF-1',
				array(),
				array(
					'form'   => self::TEMPLATE_FORM,
					'series' => self::TEMPLATE_SERIES,
				),
				new \DateTimeImmutable( '2026-10-14' )
			);
		} catch ( InvoiceDataError $error ) {
			return $error;
		}

		$this->fail( 'Expected InvoiceDataError.' );
	}
}
