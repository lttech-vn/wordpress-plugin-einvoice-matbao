<?php
/**
 * WooCommerce zero tax rates that can be assigned to KCT or KKKNT.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use WC_Tax;

/**
 * Reads the 0% rates (Standard plus every additional tax class) from WooCommerce.
 */
final class ExemptTaxRateOptions {

	/**
	 * Every rate whose percentage is 0, in tax class order.
	 *
	 * @return list<array{id: int, name: string, class: string, country: string}>
	 */
	public static function zero_rates(): array {
		$classes = array( '' => __( 'Tiêu chuẩn', 'einvoice-matbao' ) );
		foreach ( array_combine( WC_Tax::get_tax_class_slugs(), WC_Tax::get_tax_classes() ) as $slug => $name ) {
			$classes[ (string) $slug ] = (string) $name;
		}

		$rates = array();
		foreach ( $classes as $slug => $class_name ) {
			foreach ( (array) WC_Tax::get_rates_for_tax_class( (string) $slug ) as $rate ) {
				if ( 0.0 !== (float) $rate->tax_rate ) {
					continue;
				}

				$rates[] = array(
					'id'      => (int) $rate->tax_rate_id,
					'name'    => (string) $rate->tax_rate_name,
					'class'   => $class_name,
					'country' => '' === (string) $rate->tax_rate_country ? '*' : (string) $rate->tax_rate_country,
				);
			}
		}

		return $rates;
	}

	/**
	 * IDs of every 0% rate.
	 *
	 * @return list<int>
	 */
	public static function zero_rate_ids(): array {
		return array_column( self::zero_rates(), 'id' );
	}
}
