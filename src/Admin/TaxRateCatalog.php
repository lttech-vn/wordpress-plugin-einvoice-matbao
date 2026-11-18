<?php
/**
 * WooCommerce tax rates available for the Mat Bao tax mapping table.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use WC_Tax;

/**
 * Reads every tax rate (Standard plus each additional tax class) from WooCommerce.
 */
final class TaxRateCatalog {

	/**
	 * Every rate in tax class order.
	 *
	 * @return list<array{id: int, name: string, class: string, country: string, percent: string}>
	 */
	public static function all_rates(): array {
		$rates = array();
		foreach ( self::class_names() as $slug => $class_name ) {
			foreach ( (array) WC_Tax::get_rates_for_tax_class( (string) $slug ) as $rate ) {
				$rates[] = array(
					'id'      => (int) $rate->tax_rate_id,
					'name'    => (string) $rate->tax_rate_name,
					'class'   => $class_name,
					'country' => self::country( (string) $rate->tax_rate_country ),
					'percent' => self::percent( (string) $rate->tax_rate ),
				);
			}
		}

		return $rates;
	}

	/**
	 * One rate by ID, or null when it does not exist.
	 *
	 * @param int $id WooCommerce tax rate ID.
	 * @return array{id: int, name: string, class: string, country: string, percent: string}|null
	 */
	public static function find( int $id ): ?array {
		$rate = WC_Tax::_get_tax_rate( $id );
		if ( ! is_array( $rate ) ) {
			return null;
		}

		$slug = (string) $rate['tax_rate_class'];

		return array(
			'id'      => (int) $rate['tax_rate_id'],
			'name'    => (string) $rate['tax_rate_name'],
			'class'   => self::class_names()[ $slug ] ?? $slug,
			'country' => self::country( (string) $rate['tax_rate_country'] ),
			'percent' => self::percent( (string) $rate['tax_rate'] ),
		);
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
	 * Country code, or `*` when the rate applies everywhere.
	 *
	 * @param string $country Stored country.
	 */
	private static function country( string $country ): string {
		return '' === $country ? '*' : $country;
	}

	/**
	 * Percent for display: no trailing zeros, shop decimal separator.
	 *
	 * @param string $stored Stored percent such as `10.0000`.
	 */
	private static function percent( string $stored ): string {
		return wc_format_localized_decimal( (string) (float) $stored );
	}
}
