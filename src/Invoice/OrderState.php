<?php
/**
 * What an order looked like when its invoice data was sent.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

use LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore;
use WC_Order;

/**
 * Three short keyed digests (amounts, buyer, refunded total) stored in the `order_state` column.
 * They are compared later to tell a significant change from a save that changed nothing that
 * matters. Digests are HMACs keyed with the site salt, so the column holds no readable personal
 * data and a phone number or email cannot be guessed back from it.
 */
final class OrderState {

	/**
	 * Encoded state of the order as it is now: `a:<digest>|b:<digest>|r:<refunded total>`.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function encode( WC_Order $order ): string {
		$lines = array();
		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product && ! $item instanceof \WC_Order_Item_Fee && ! $item instanceof \WC_Order_Item_Shipping ) {
				continue;
			}
			$lines[] = implode(
				'~',
				array(
					$item->get_type(),
					self::text( $item->get_name() ),
					(string) $item->get_quantity(),
					self::money( $item->get_total() ),
					self::money( $item->get_total_tax() ),
				)
			);
		}
		// Line order is not part of the order's content, so reordering lines is not a change.
		sort( $lines );
		$amounts = implode(
			'|',
			array(
				$order->get_currency(),
				self::money( $order->get_total() ),
				self::money( $order->get_total_tax() ),
				self::money( $order->get_discount_total() ),
				implode( ';', $lines ),
			)
		);
		$buyer   = implode(
			'|',
			array(
				self::text( $order->get_billing_first_name() ),
				self::text( $order->get_billing_last_name() ),
				self::text( $order->get_billing_company() ),
				self::text( $order->get_billing_email() ),
				self::text( $order->get_billing_phone() ),
				(string) $order->get_meta( BuyerRequestStore::META_KEY ),
			)
		);

		return 'a:' . self::digest( $amounts ) . '|b:' . self::digest( $buyer ) . '|r:' . self::money( $order->get_total_refunded() );
	}

	/**
	 * `$current` with the buyer part taken from `$basis`: the buyer the existing snapshot was built
	 * from, so re-basing the other parts never hides a buyer edit the snapshot did not pick up.
	 *
	 * @param string $current State of the order now.
	 * @param string $basis   State whose buyer part is kept; empty keeps the current one.
	 */
	public static function with_buyer_of( string $current, string $basis ): string {
		$buyer = self::parts( $basis )['b'];
		if ( '' === $buyer ) {
			return $current;
		}
		$parts = self::parts( $current );

		return 'a:' . $parts['a'] . '|b:' . $buyer . '|r:' . $parts['r'];
	}

	/**
	 * Which parts differ between two encoded states.
	 *
	 * @param string $sent    State stored on the invoice.
	 * @param string $current State of the order now.
	 * @return array{amounts: bool, buyer: bool, refunded: bool}
	 */
	public static function differences( string $sent, string $current ): array {
		$old = self::parts( $sent );
		$new = self::parts( $current );

		return array(
			'amounts'  => $old['a'] !== $new['a'],
			'buyer'    => $old['b'] !== $new['b'],
			'refunded' => $old['r'] !== $new['r'],
		);
	}

	/**
	 * Splits an encoded state into its keyed parts.
	 *
	 * @param string $state Encoded state.
	 * @return array{a: string, b: string, r: string}
	 */
	private static function parts( string $state ): array {
		$parts = array(
			'a' => '',
			'b' => '',
			'r' => '',
		);
		foreach ( explode( '|', $state ) as $part ) {
			$key = substr( $part, 0, 1 );
			if ( isset( $parts[ $key ] ) && ':' === substr( $part, 1, 1 ) ) {
				$parts[ $key ] = substr( $part, 2 );
			}
		}

		return $parts;
	}

	/**
	 * Keyed digest of a value.
	 *
	 * @param string $value Value.
	 */
	private static function digest( string $value ): string {
		return substr( hash_hmac( 'sha256', $value, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Text with trimmed, single-spaced whitespace, so re-saving a name with stray spaces is not a change.
	 *
	 * @param string $value Text.
	 */
	private static function text( string $value ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * An amount with a fixed format, so 100000 and "100000.00" compare equal.
	 *
	 * @param mixed $amount Amount.
	 */
	private static function money( mixed $amount ): string {
		return number_format( is_numeric( $amount ) ? (float) $amount : 0.0, 2, '.', '' );
	}
}
