<?php
/**
 * Buyer data submitted with an invoice request.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Buyer;

/**
 * Raw input for BuyerRules, independent of the checkout flavour (Classic or Blocks).
 */
final class BuyerInput {

	/**
	 * Creates the input.
	 *
	 * @param string $type          BuyerType::INDIVIDUAL, BuyerType::BUSINESS or anything else when none was chosen.
	 * @param string $full_name     Person's full name (individual).
	 * @param string $business_name Business name (business).
	 * @param string $tax_code      Business tax code (business).
	 * @param string $address       Invoice address.
	 * @param string $id_number     Citizen ID number, CCCD (individual).
	 * @param string $phone         Phone number.
	 * @param string $email         Email address.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $full_name = '',
		public readonly string $business_name = '',
		public readonly string $tax_code = '',
		public readonly string $address = '',
		public readonly string $id_number = '',
		public readonly string $phone = '',
		public readonly string $email = '',
	) {
	}
}
