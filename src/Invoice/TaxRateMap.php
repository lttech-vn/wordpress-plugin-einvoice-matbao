<?php
/**
 * Mat Bao tax mapping table: which WooCommerce tax rate belongs to which `TSuat` group.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

/**
 * Looks up the group of a tax rate and the `TSuat` a group accepts for a stored percent and line tax.
 */
final class TaxRateMap {

	public const GROUP_VAT10 = 'vat10';
	public const GROUP_VAT8  = 'vat8';
	public const GROUP_VAT5  = 'vat5';
	public const GROUP_VAT0  = 'vat0';
	public const GROUP_KCT   = 'kct';
	public const GROUP_KKKNT = 'kkknt';
	public const GROUP_OTHER = 'other';

	/** Percents the fixed groups own; `other` must differ from all of them and from 0. */
	private const FIXED_PERCENTS = array( 5, 8, 10 );

	/** Same tolerance as `DraftPayloadBuilder::same()`. */
	private const TOLERANCE = 0.000001;

	/**
	 * Creates the lookup.
	 *
	 * @param array<string, list<int>> $map Normalized `group => rate IDs`.
	 */
	public function __construct( private array $map ) {}

	/**
	 * Groups in display order.
	 *
	 * @return list<string>
	 */
	public static function groups(): array {
		return array(
			self::GROUP_VAT10,
			self::GROUP_VAT8,
			self::GROUP_VAT5,
			self::GROUP_VAT0,
			self::GROUP_KCT,
			self::GROUP_KKKNT,
			self::GROUP_OTHER,
		);
	}

	/**
	 * Display label of a group.
	 *
	 * @param string $group One of `groups()`.
	 */
	public static function label( string $group ): string {
		return match ( $group ) {
			self::GROUP_VAT10 => '10%',
			self::GROUP_VAT8  => '8%',
			self::GROUP_VAT5  => '5%',
			self::GROUP_VAT0  => '0%',
			self::GROUP_KCT   => 'KCT',
			self::GROUP_KKKNT => 'KKKNT',
			self::GROUP_OTHER => __( 'Khác', 'einvoice-matbao' ),
			default           => __( 'Khác', 'einvoice-matbao' ),
		};
	}

	/**
	 * Group a tax rate is assigned to, or null when it is not in the table.
	 *
	 * @param int $rate_id WooCommerce tax rate ID.
	 */
	public function group_of( int $rate_id ): ?string {
		foreach ( $this->map as $group => $ids ) {
			if ( in_array( $rate_id, $ids, true ) ) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * `TSuat` for a group, or null when the stored percent or line tax does not fit the group.
	 *
	 * @param string      $group          One of `groups()`.
	 * @param string|null $stored_percent Percent stored on the order's tax item.
	 * @param int         $tax            Line tax in whole VND.
	 * @return int|float|null Whole percents are ints; fractional ones are floats.
	 */
	public static function tsuat_for( string $group, ?string $stored_percent, int $tax ): int|float|null {
		return match ( $group ) {
			self::GROUP_VAT10 => self::fixed_percent( $stored_percent, 10 ),
			self::GROUP_VAT8  => self::fixed_percent( $stored_percent, 8 ),
			self::GROUP_VAT5  => self::fixed_percent( $stored_percent, 5 ),
			self::GROUP_VAT0  => 0 === $tax ? 0 : null,
			self::GROUP_KCT   => 0 === $tax ? -1 : null,
			self::GROUP_KKKNT => 0 === $tax ? -2 : null,
			self::GROUP_OTHER => self::other_percent( $stored_percent ),
			default           => null,
		};
	}

	/**
	 * Expected percent when the stored one equals it.
	 *
	 * @param string|null $stored   Stored percent.
	 * @param int         $expected Percent the group stands for.
	 */
	private static function fixed_percent( ?string $stored, int $expected ): ?int {
		$percent = self::parse_percent( $stored );

		return null !== $percent && abs( $percent - $expected ) < self::TOLERANCE ? $expected : null;
	}

	/**
	 * Stored percent when positive and not owned by a fixed group.
	 *
	 * @param string|null $stored Stored percent.
	 */
	private static function other_percent( ?string $stored ): int|float|null {
		$percent = self::parse_percent( $stored );
		if ( null === $percent || $percent < self::TOLERANCE ) {
			return null;
		}

		foreach ( self::FIXED_PERCENTS as $fixed ) {
			if ( abs( $percent - $fixed ) < self::TOLERANCE ) {
				return null;
			}
		}

		$whole = round( $percent );

		return abs( $percent - $whole ) < self::TOLERANCE ? (int) $whole : $percent;
	}

	/**
	 * Numeric value of a stored percent, or null when absent, not numeric or not finite.
	 *
	 * @param string|null $stored Stored percent.
	 */
	private static function parse_percent( ?string $stored ): ?float {
		if ( ! is_string( $stored ) || ! is_numeric( $stored ) ) {
			return null;
		}

		$percent = (float) $stored;

		return is_finite( $percent ) ? $percent : null;
	}
}
