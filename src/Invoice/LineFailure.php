<?php
/**
 * Order line that made the order data unusable for an invoice.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

use WC_Order_Item;
use WC_Order_Item_Product;

/**
 * Identifies the offending order line without personal data, so an admin can find it.
 */
final class LineFailure {

	/**
	 * Creates the value.
	 *
	 * @param int        $item_id      Order item ID.
	 * @param string     $item_type    WooCommerce item type (`line_item`, `shipping`, `fee`, ...).
	 * @param int        $product_id   Product ID, 0 when not a product line.
	 * @param int        $variation_id Variation ID, 0 when none.
	 * @param string     $sku          Product SKU, empty when none.
	 * @param string     $name         Line name.
	 * @param array<int> $tax_rate_ids Tax rate IDs stored on the line (a list).
	 * @phpstan-param list<int> $tax_rate_ids
	 */
	public function __construct(
		public readonly int $item_id,
		public readonly string $item_type,
		public readonly int $product_id,
		public readonly int $variation_id,
		public readonly string $sku,
		public readonly string $name,
		public readonly array $tax_rate_ids,
	) {}

	/**
	 * Describes an order item; must not fail whatever the item looks like.
	 *
	 * @param WC_Order_Item $item Order item.
	 */
	public static function from_item( WC_Order_Item $item ): self {
		$product_id   = 0;
		$variation_id = 0;
		$sku          = '';
		if ( $item instanceof WC_Order_Item_Product ) {
			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$product      = $item->get_product();
			$sku          = $product instanceof \WC_Product ? (string) $product->get_sku() : '';
		}

		return new self( (int) $item->get_id(), $item->get_type(), $product_id, $variation_id, $sku, $item->get_name(), self::tax_rate_ids( $item ) );
	}

	/**
	 * Context for monitoring.
	 *
	 * @return array{item_id: int, item_type: string, product_id: int, variation_id: int, sku: string, name: string, tax_rate_ids: list<int>}
	 */
	public function to_context(): array {
		return array(
			'item_id'      => $this->item_id,
			'item_type'    => $this->item_type,
			'product_id'   => $this->product_id,
			'variation_id' => $this->variation_id,
			'sku'          => $this->sku,
			'name'         => $this->name,
			'tax_rate_ids' => $this->tax_rate_ids,
		);
	}

	/**
	 * Rate IDs with a stored tax amount; items without taxes (unsupported types) have none.
	 *
	 * @param WC_Order_Item $item Order item.
	 * @return list<int>
	 */
	private static function tax_rate_ids( WC_Order_Item $item ): array {
		if ( ! method_exists( $item, 'get_taxes' ) ) {
			return array();
		}

		$taxes = $item->get_taxes();
		$total = is_array( $taxes ) && is_array( $taxes['total'] ?? null ) ? $taxes['total'] : array();
		$ids   = array();
		foreach ( $total as $rate_id => $amount ) {
			if ( '' !== $amount && null !== $amount ) {
				$ids[] = (int) $rate_id;
			}
		}

		return $ids;
	}
}
