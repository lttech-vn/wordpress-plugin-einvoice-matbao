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
			$this->address( $order, $request['address'] ),
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
			return $this->build_walk_in( $order, $walk_in_name );
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
		$fields['NMua_DChi']    = $this->address( $order, $request['address'] );
		$fields['NMua_SDThoai'] = BuyerRules::normalize_phone( $order->get_billing_phone() );
		if ( '' !== $email && mb_strlen( $email ) <= BuyerRules::MAX_EMAIL && is_email( $email ) ) {
			$fields['NMua_DCTDTu'] = $email;
		}

		return new BuyerSnapshot( $type, $name, $fields, $request['id_encrypted'], $request['id_mask'], true );
	}

	/**
	 * Snapshot of a buyer who did not ask for an invoice: the billing details that are valid, on
	 * top of the configured name. Invalid or empty details are skipped, never an error.
	 *
	 * @param WC_Order $order        Order.
	 * @param string   $walk_in_name Configured `NMua_Ten`.
	 */
	private function build_walk_in( WC_Order $order, string $walk_in_name ): BuyerSnapshot {
		$billing = array_merge(
			$this->walk_in_name_fields( $order ),
			$this->walk_in_address_fields( $order ),
			$this->walk_in_phone_fields( $order ),
			$this->walk_in_email_fields( $order ),
		);
		$fields  = array_merge( array( 'NMua_Ten' => $walk_in_name ), $billing );

		return new BuyerSnapshot( BuyerType::WALK_IN, $fields['NMua_Ten'], $fields, '', '', array() !== $billing );
	}

	/**
	 * `NMua_Ten` and `NMua_HVTNMHang` from the billing name.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	private function walk_in_name_fields( WC_Order $order ): array {
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $name ) {
			return array();
		}

		$fields = array( 'NMua_Ten' => mb_substr( $name, 0, BuyerRules::MAX_BUSINESS_NAME ) );
		if ( mb_strlen( $name ) <= BuyerRules::MAX_FULL_NAME ) {
			$fields['NMua_HVTNMHang'] = $name;
		}

		return $fields;
	}

	/**
	 * `NMua_DChi` from the billing address, cut to the allowed length.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	private function walk_in_address_fields( WC_Order $order ): array {
		$address = BillingAddress::from_order( $order );

		return '' === $address ? array() : array( 'NMua_DChi' => mb_substr( $address, 0, BuyerRules::MAX_ADDRESS ) );
	}

	/**
	 * `NMua_SDThoai` when the billing phone is a valid number.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	private function walk_in_phone_fields( WC_Order $order ): array {
		$phone = BuyerRules::normalize_phone( $order->get_billing_phone() );
		if ( strlen( $phone ) > BuyerRules::MAX_PHONE || 1 !== preg_match( BuyerRules::PHONE_PATTERN, $phone ) ) {
			return array();
		}

		return array( 'NMua_SDThoai' => $phone );
	}

	/**
	 * `NMua_DCTDTu` when the billing email is valid.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	private function walk_in_email_fields( WC_Order $order ): array {
		$email = trim( $order->get_billing_email() );
		if ( '' === $email || mb_strlen( $email ) > BuyerRules::MAX_EMAIL || ! is_email( $email ) ) {
			return array();
		}

		return array( 'NMua_DCTDTu' => $email );
	}

	/**
	 * Buyer address: the billing address, else the address of a legacy checkout request.
	 *
	 * @param WC_Order $order          Order.
	 * @param string   $request_address Address stored by an older checkout request.
	 */
	private function address( WC_Order $order, string $request_address ): string {
		$billing = BillingAddress::from_order( $order );

		return '' !== $billing ? $billing : $request_address;
	}
}
