<?php
/**
 * Keeps buyer meta out of WooCommerce REST order responses.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Buyer;

use WC_Meta_Data;
use WP_REST_Response;

/**
 * WooCommerce REST returns every order meta row in `meta_data`, underscore-prefixed keys included.
 * The buyer request and snapshot hold the encrypted CCCD, its mask and the invoice address, so
 * they are removed from every order response (wc/v2 and wc/v3 share one hook; the experimental
 * wc/v4 has its own). wc/v1 does not return order meta.
 */
final class OrderRestPrivacy {

	/** Order meta keys never returned by the REST API. */
	public const HIDDEN_META_KEYS = array( BuyerRequestStore::META_KEY, BuyerSnapshotter::META_KEY );

	/**
	 * Hooks the order response filters.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( self::class, 'filter_response' ) );
		add_filter( 'woocommerce_rest_api_v4_orders_item_response', array( self::class, 'filter_response' ) );
	}

	/**
	 * Removes the buyer meta from one order response.
	 *
	 * @param mixed $response Response the controller prepared.
	 * @return mixed The same response without the hidden meta.
	 */
	public static function filter_response( mixed $response ): mixed {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || ! is_array( $data['meta_data'] ?? null ) ) {
			return $response;
		}

		$data['meta_data'] = array_values( array_filter( $data['meta_data'], array( self::class, 'is_visible' ) ) );
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Whether a `meta_data` entry may be returned.
	 *
	 * @param mixed $meta WC_Meta_Data (v2/v3) or `{id, key, value}` array (v4).
	 */
	private static function is_visible( mixed $meta ): bool {
		$key = null;
		if ( $meta instanceof WC_Meta_Data ) {
			$key = $meta->key;
		} elseif ( is_array( $meta ) ) {
			$key = $meta['key'] ?? null;
		}

		return ! in_array( $key, self::HIDDEN_META_KEYS, true );
	}
}
