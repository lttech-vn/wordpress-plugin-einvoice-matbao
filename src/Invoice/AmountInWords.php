<?php
/**
 * Vietnamese reading of VND amounts.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

/**
 * Builds `TgTTTBChu` ("Tổng tiền thanh toán bằng chữ") from a whole VND amount.
 */
final class AmountInWords {

	private const DIGITS = array( 'không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín' );

	private const BILLION = 1000000000;

	/**
	 * The amount in words with a capitalised first letter, ending in "đồng".
	 *
	 * @param int $amount Whole VND amount, not negative.
	 */
	public static function vnd( int $amount ): string {
		$words = 0 === $amount ? 'không' : self::read( $amount, true );

		return mb_strtoupper( mb_substr( $words, 0, 1 ) ) . mb_substr( $words, 1 ) . ' đồng';
	}

	/**
	 * Reads a positive number; above a billion the part in "tỷ" is read recursively.
	 *
	 * @param int  $number  Positive number.
	 * @param bool $leading Whether nothing is read before it (no "không trăm" for the first group).
	 */
	private static function read( int $number, bool $leading ): string {
		$parts    = array();
		$billions = intdiv( $number, self::BILLION );
		if ( $billions > 0 ) {
			$parts[] = self::read( $billions, $leading ) . ' tỷ';
		}

		$groups = array(
			'triệu' => intdiv( $number, 1000000 ) % 1000,
			'nghìn' => intdiv( $number, 1000 ) % 1000,
			''      => $number % 1000,
		);
		foreach ( $groups as $scale => $group ) {
			if ( 0 === $group ) {
				continue;
			}
			$parts[] = trim( self::read_group( $group, $leading && array() === $parts ) . ' ' . $scale );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Reads one group of three digits.
	 *
	 * @param int  $group   1 to 999.
	 * @param bool $leading Whether this is the first group read (skip leading zeros).
	 */
	private static function read_group( int $group, bool $leading ): string {
		$hundreds = intdiv( $group, 100 );
		$tens     = intdiv( $group, 10 ) % 10;
		$units    = $group % 10;
		$words    = array();

		if ( $hundreds > 0 || ! $leading ) {
			$words[] = self::digit( $hundreds ) . ' trăm';
		}

		if ( 0 === $tens ) {
			if ( $units > 0 ) {
				$words[] = array() === $words ? self::digit( $units ) : 'linh ' . self::digit( $units );
			}
			return implode( ' ', $words );
		}

		$words[] = 1 === $tens ? 'mười' : self::digit( $tens ) . ' mươi';
		if ( 5 === $units ) {
			$words[] = 'lăm';
		} elseif ( 1 === $units && $tens > 1 ) {
			$words[] = 'mốt';
		} elseif ( $units > 0 ) {
			$words[] = self::digit( $units );
		}

		return implode( ' ', $words );
	}

	/**
	 * Word of one digit.
	 *
	 * @param int $digit 0 to 9.
	 */
	private static function digit( int $digit ): string {
		return self::DIGITS[ $digit ] ?? '';
	}
}
