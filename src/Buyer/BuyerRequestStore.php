<?php
/**
 * The invoice request a customer made at checkout, kept in order meta.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Buyer;

use LTTech\EinvoiceMatbao\Encryption;
use LTTech\EinvoiceMatbao\MissingEncryptionKey;
use WC_Order;

/**
 * Stores only what checkout adds on top of billing. The citizen ID is kept encrypted with a
 * 4-digit mask beside it; the full number never reaches order meta in clear text.
 *
 * @phpstan-type Request array{type: string, business_name: string, tax_code: string, address: string, id_encrypted: string, id_mask: string}
 */
final class BuyerRequestStore {

	public const META_KEY = '_einvoice_buyer_request';

	/**
	 * The request to store for input that already passed BuyerRules. Keeps only the fields of the
	 * chosen type and encrypts the citizen ID, so every checkout flavour stores the same shape.
	 *
	 * @param BuyerInput $input      Validated input.
	 * @param Encryption $encryption Encryption service (must be available for an individual).
	 * @return Request
	 * @throws MissingEncryptionKey When an individual request arrives without a usable key.
	 */
	public static function from_input( BuyerInput $input, Encryption $encryption ): array {
		$is_individual = BuyerType::INDIVIDUAL === $input->type;
		$id_number     = $is_individual ? trim( $input->id_number ) : '';

		return array(
			'type'          => $input->type,
			'business_name' => $is_individual ? '' : trim( $input->business_name ),
			'tax_code'      => $is_individual ? '' : trim( $input->tax_code ),
			'address'       => trim( $input->address ),
			'id_encrypted'  => '' === $id_number ? '' : $encryption->encrypt( $id_number ),
			'id_mask'       => '' === $id_number ? '' : BuyerRules::mask_id_number( $id_number ),
		);
	}

	/**
	 * Saves a request on the order (the caller saves the order).
	 *
	 * @param WC_Order              $order   Order.
	 * @param array<string, string> $request Request to store (see the class type).
	 */
	public function save( WC_Order $order, array $request ): void {
		$order->update_meta_data( self::META_KEY, (string) wp_json_encode( $request ) );
	}

	/**
	 * Removes any stored request (the caller saves the order).
	 *
	 * @param WC_Order $order Order.
	 */
	public function clear( WC_Order $order ): void {
		$order->delete_meta_data( self::META_KEY );
	}

	/**
	 * The stored request, or null when the customer did not ask for an invoice.
	 *
	 * @param WC_Order $order Order.
	 * @return Request|null
	 */
	public function load( WC_Order $order ): ?array {
		$data = json_decode( (string) $order->get_meta( self::META_KEY ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$text = static fn( string $key ): string => is_string( $data[ $key ] ?? null ) ? $data[ $key ] : '';

		return array(
			'type'          => $text( 'type' ),
			'business_name' => $text( 'business_name' ),
			'tax_code'      => $text( 'tax_code' ),
			'address'       => $text( 'address' ),
			'id_encrypted'  => $text( 'id_encrypted' ),
			'id_mask'       => $text( 'id_mask' ),
		);
	}
}
