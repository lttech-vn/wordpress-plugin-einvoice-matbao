<?php
/**
 * Converts log attributes to their final, redacted wire form.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

use JsonSerializable;
use Stringable;

/**
 * The SDK turns objects into strings (Stringable) or JSON when it stores a log attribute, after
 * any redaction would run. This class does that conversion first, so the redactor sees exactly
 * the text that will be sent: scalars stay scalars, strings and Stringable objects are masked,
 * arrays and other objects become masked JSON, and anything that cannot be represented is
 * replaced by the filtered marker (fail closed). Keys are masked too.
 */
final class LogAttributes {

	/**
	 * Nesting deeper than this is replaced by the filtered marker (also stops reference cycles).
	 */
	private const MAX_DEPTH = 5;

	/**
	 * Redacted attributes ready for `Log::setAttribute()`.
	 *
	 * @param array<array-key, mixed> $attrs    Raw attributes.
	 * @param Redactor                $redactor Redactor holding the current known values.
	 * @return array<string, bool|int|float|string>
	 */
	public static function normalize( array $attrs, Redactor $redactor ): array {
		$normalized = array();
		foreach ( $attrs as $key => $value ) {
			$normalized[ $redactor->text( (string) $key ) ] = self::scalar( $value, $redactor );
		}

		return $normalized;
	}

	/**
	 * Final scalar form of one attribute value.
	 *
	 * @param mixed    $value    Raw value.
	 * @param Redactor $redactor Redactor.
	 */
	private static function scalar( mixed $value, Redactor $redactor ): bool|int|float|string {
		if ( null === $value ) {
			return 'null';
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) || ( $value instanceof Stringable && ! $value instanceof JsonSerializable ) ) {
			return $redactor->text( (string) $value );
		}

		$json = wp_json_encode( self::plain( $value, $redactor, 0 ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $json ? Scrubber::FILTERED : $redactor->text( $json );
	}

	/**
	 * Plain-data copy of a value with every key and string masked.
	 *
	 * @param mixed    $value    Raw value.
	 * @param Redactor $redactor Redactor.
	 * @param int      $depth    Current nesting depth.
	 */
	private static function plain( mixed $value, Redactor $redactor, int $depth ): mixed {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return $redactor->text( $value );
		}
		if ( $depth >= self::MAX_DEPTH ) {
			return Scrubber::FILTERED;
		}
		if ( $value instanceof JsonSerializable ) {
			return self::plain( $value->jsonSerialize(), $redactor, $depth + 1 );
		}
		if ( $value instanceof Stringable ) {
			return $redactor->text( (string) $value );
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return Scrubber::FILTERED;
		}

		$plain = array();
		foreach ( $value as $key => $item ) {
			$plain[ is_int( $key ) ? $key : $redactor->text( $key ) ] = self::plain( $item, $redactor, $depth + 1 );
		}

		return $plain;
	}
}
