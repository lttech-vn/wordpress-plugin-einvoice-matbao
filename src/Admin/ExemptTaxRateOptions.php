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
		$rates = array();
		foreach ( self::class_names() as $slug => $class_name ) {
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
	 * Assigned rates that still exist but are no longer 0%, so they cannot be picked on the form.
	 *
	 * @param array<int, int> $assigned `tax_rate_id => TSuat` as stored.
	 * @return list<array{id: int, name: string, class: string, percent: string, code: int}>
	 */
	public static function stale_assignments( array $assigned ): array {
		$classes = self::class_names();
		$stale   = array();
		foreach ( $assigned as $id => $code ) {
			$rate = WC_Tax::_get_tax_rate( $id );
			if ( ! is_array( $rate ) || 0.0 === (float) $rate['tax_rate'] ) {
				continue;
			}

			$slug    = (string) $rate['tax_rate_class'];
			$stale[] = array(
				'id'      => (int) $rate['tax_rate_id'],
				'name'    => (string) $rate['tax_rate_name'],
				'class'   => $classes[ $slug ] ?? $slug,
				'percent' => (string) (float) $rate['tax_rate'],
				'code'    => (int) $code,
			);
		}

		return $stale;
	}

	/**
	 * Display name of every tax class, keyed by slug; the standard class has slug ''.
	 *
	 * @return array<string, string>
	 */
	private static function class_names(): array {
		$classes = array( '' => __( 'Tiêu chuẩn', 'einvoice-matbao' ) );
		foreach ( array_combine( WC_Tax::get_tax_class_slugs(), WC_Tax::get_tax_classes() ) as $slug => $name ) {
			$classes[ (string) $slug ] = (string) $name;
		}

		return $classes;
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
