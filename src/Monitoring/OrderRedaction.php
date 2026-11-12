<?php
/**
 * Personal values of an order that must never leave the site.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

use LTTech\EinvoiceMatbao\Buyer\BuyerRequestStore;
use LTTech\EinvoiceMatbao\Buyer\BuyerSnapshotter;
use LTTech\EinvoiceMatbao\Encryption;
use WC_Order;

/**
 * Collects the buyer values of an order for the known-values layer of the Scrubber: billing and
 * shipping names, company, email, phone and address lines, the stored invoice request and buyer
 * snapshot, the tax code and the decrypted citizen ID. Names and addresses cannot be caught by
 * patterns, so this list is what keeps them out of monitoring payloads.
 */
final class OrderRedaction {

	/**
	 * Personal values of the order; empty when the order does not exist.
	 *
	 * @param int $order_id Order ID.
	 * @return list<string>
	 */
	public static function values_for( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$values = array_merge( self::address_values( $order ), self::stored_buyer_values( $order ) );

		return array_values( array_unique( array_filter( array_map( 'trim', $values ), static fn( string $value ): bool => '' !== $value ) ) );
	}

	/**
	 * Billing and shipping contact values.
	 *
	 * @param WC_Order $order Order.
	 * @return list<string>
	 */
	private static function address_values( WC_Order $order ): array {
		$values = array( $order->get_billing_email(), $order->get_billing_phone(), $order->get_shipping_phone() );
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$first = (string) $order->{"get_{$type}_first_name"}();
			$last  = (string) $order->{"get_{$type}_last_name"}();
			// Full name first; each part too, since messages may carry only one of them.
			array_push(
				$values,
				$first . ' ' . $last,
				$first,
				$last,
				(string) $order->{"get_{$type}_company"}(),
				(string) $order->{"get_{$type}_address_1"}(),
				(string) $order->{"get_{$type}_address_2"}()
			);
		}

		return $values;
	}

	/**
	 * Values of the stored invoice request and buyer snapshot, with citizen IDs decrypted.
	 *
	 * @param WC_Order $order Order.
	 * @return list<string>
	 */
	private static function stored_buyer_values( WC_Order $order ): array {
		$encryption = new Encryption();
		$values     = array();

		$request = ( new BuyerRequestStore() )->load( $order );
		if ( null !== $request ) {
			array_push( $values, $request['business_name'], $request['tax_code'], $request['address'] );
			$values[] = self::decrypt( $encryption, $request['id_encrypted'] );
		}

		$snapshot = ( new BuyerSnapshotter() )->load( $order );
		if ( null !== $snapshot ) {
			array_push( $values, $snapshot->buyer_name, ...array_values( $snapshot->fields ) );
			$values[] = self::decrypt( $encryption, $snapshot->id_encrypted );
		}

		return $values;
	}

	/**
	 * Decrypted citizen ID, or '' when absent or unreadable.
	 *
	 * @param Encryption $encryption Encryption service.
	 * @param string     $ciphertext Stored ciphertext.
	 */
	private static function decrypt( Encryption $encryption, string $ciphertext ): string {
		return '' === $ciphertext ? '' : (string) $encryption->decrypt( $ciphertext );
	}
}
