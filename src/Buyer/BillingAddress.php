<?php
/**
 * The one-line billing address sent to the invoice as the buyer address.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Buyer;

use WC_Order;

/**
 * Joins address line 1, line 2, city and province/state name into one string. Empty parts are
 * skipped; the result is never truncated (the length limit belongs to the caller).
 */
final class BillingAddress {

	/**
	 * Address from checkout-style form data.
	 *
	 * @param array<string, mixed> $data   Values keyed `{prefix}address_1`, `address_2`, `city`, `state`, `country`.
	 * @param string               $prefix Key prefix.
	 * @return string Joined address, or '' when every part is empty.
	 */
	public static function from_array( array $data, string $prefix = 'billing_' ): string {
		$text = static fn( string $key ): string => is_scalar( $data[ $prefix . $key ] ?? null ) ? trim( (string) $data[ $prefix . $key ] ) : '';

		return self::join(
			$text( 'address_1' ),
			$text( 'address_2' ),
			$text( 'city' ),
			$text( 'state' ),
			$text( 'country' )
		);
	}

	/**
	 * Address from the order billing fields.
	 *
	 * @param WC_Order $order Order.
	 * @return string Joined address, or '' when every part is empty.
	 */
	public static function from_order( WC_Order $order ): string {
		return self::join(
			trim( $order->get_billing_address_1() ),
			trim( $order->get_billing_address_2() ),
			trim( $order->get_billing_city() ),
			trim( $order->get_billing_state() ),
			trim( $order->get_billing_country() )
		);
	}

	/**
	 * Joins the non-empty parts, resolving the state code to its name.
	 *
	 * @param string $line_1  Address line 1.
	 * @param string $line_2  Address line 2.
	 * @param string $city    City.
	 * @param string $state   State code (or free text).
	 * @param string $country Country code.
	 */
	private static function join( string $line_1, string $line_2, string $city, string $state, string $country ): string {
		$parts = array( $line_1, $line_2, $city, self::state_name( $state, $country ) );

		return implode( ', ', array_filter( $parts, static fn( string $part ): bool => '' !== $part ) );
	}

	/**
	 * Province/state name for a code; an unknown code is kept as entered.
	 *
	 * @param string $state   State code.
	 * @param string $country Country code.
	 */
	private static function state_name( string $state, string $country ): string {
		if ( '' === $state || '' === $country ) {
			return $state;
		}

		$states = WC()->countries->get_states( $country );

		return is_array( $states ) && isset( $states[ $state ] ) && is_string( $states[ $state ] ) ? $states[ $state ] : $state;
	}
}
