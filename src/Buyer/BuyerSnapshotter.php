<?php
/**
 * Captures and reads the buyer snapshot of an order.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Buyer;

use LTTech\EinvoiceMatbao\Encryption;
use WC_Order;

/**
 * Builds the snapshot from the checkout request and the billing details present at capture time
 * (name, phone, email are reused from billing). Capturing again replaces the snapshot, which an
 * admin edit before a successful draft relies on (ticket #14). Sending reads the snapshot only.
 */
final class BuyerSnapshotter {

	public const META_KEY = '_einvoice_buyer_snapshot';

	/**
	 * Creates the snapshotter.
	 *
	 * @param BuyerRequestStore $requests Stored checkout requests.
	 */
	public function __construct( private readonly BuyerRequestStore $requests = new BuyerRequestStore() ) {
	}

	/**
	 * Captures the snapshot now and stores it on the order.
	 *
	 * @param WC_Order $order          Order.
	 * @param string   $walk_in_name   `NMua_Ten` for a buyer who did not ask for an invoice.
	 */
	public function capture( WC_Order $order, string $walk_in_name ): BuyerSnapshot {
		return $this->store( $order, $this->build( $order, $walk_in_name ) );
	}

	/**
	 * Stores a snapshot built by build() on the order.
	 *
	 * @param WC_Order      $order    Order.
	 * @param BuyerSnapshot $snapshot Snapshot.
	 */
	public function store( WC_Order $order, BuyerSnapshot $snapshot ): BuyerSnapshot {
		$order->update_meta_data( self::META_KEY, (string) wp_json_encode( $snapshot->to_array() ) );
		$order->save_meta_data();

		return $snapshot;
	}

	/**
	 * The stored snapshot, or null when none was captured.
	 *
	 * @param WC_Order $order Order.
	 */
	public function load( WC_Order $order ): ?BuyerSnapshot {
		$data = json_decode( (string) $order->get_meta( self::META_KEY ), true );

		return is_array( $data ) ? BuyerSnapshot::from_array( $data ) : null;
	}

	/**
	 * Checks the request and the current billing details against BuyerRules again, since an admin
	 * may have edited billing after checkout. A walk-in buyer has nothing to check.
	 *
	 * @param WC_Order   $order      Order.
	 * @param Encryption $encryption Encryption service, to read the stored citizen ID.
	 * @return array<string, string> Error message by field key; empty when valid. Messages never contain the data.
	 */
	public function validate( WC_Order $order, Encryption $encryption = new Encryption() ): array {
		$request = $this->requests->load( $order );
		if ( null === $request || ( BuyerType::INDIVIDUAL !== $request['type'] && BuyerType::BUSINESS !== $request['type'] ) ) {
			return array();
		}

		$id_number = '' === $request['id_encrypted'] ? '' : (string) $encryption->decrypt( $request['id_encrypted'] );
		$input     = new BuyerInput(
			$request['type'],
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			$request['business_name'],
			$request['tax_code'],
			$request['address'],
			$id_number,
			$order->get_billing_phone(),
			$order->get_billing_email(),
		);

		return BuyerRules::validate( $input, $encryption->is_available() );
	}

	/**
	 * Builds a snapshot without storing it.
	 *
	 * @param WC_Order $order        Order.
	 * @param string   $walk_in_name `NMua_Ten` for a walk-in buyer.
	 */
	public function build( WC_Order $order, string $walk_in_name ): BuyerSnapshot {
		$request = $this->requests->load( $order );
		$type    = $request['type'] ?? '';

		if ( null === $request || ( BuyerType::INDIVIDUAL !== $type && BuyerType::BUSINESS !== $type ) ) {
			return new BuyerSnapshot( BuyerType::WALK_IN, $walk_in_name, array( 'NMua_Ten' => $walk_in_name ) );
		}

		$full_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$name      = BuyerType::INDIVIDUAL === $type ? $full_name : $request['business_name'];
		$email     = trim( $order->get_billing_email() );

		$fields = array( 'NMua_Ten' => $name );
		if ( BuyerType::INDIVIDUAL === $type ) {
			$fields['NMua_HVTNMHang'] = $name;
		} else {
			$fields['NMua_MST'] = $request['tax_code'];
		}
		$fields['NMua_DChi']    = $request['address'];
		$fields['NMua_SDThoai'] = BuyerRules::normalize_phone( $order->get_billing_phone() );
		if ( '' !== $email && mb_strlen( $email ) <= BuyerRules::MAX_EMAIL && is_email( $email ) ) {
			$fields['NMua_DCTDTu'] = $email;
		}

		return new BuyerSnapshot( $type, $name, $fields, $request['id_encrypted'], $request['id_mask'] );
	}
}
