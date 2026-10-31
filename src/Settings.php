<?php
/**
 * Non-secret invoicing settings.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao;

use LTTech\EinvoiceMatbao\Matbao\TemplateCache;

/**
 * Default template, `MTChieu` prefix and walk-in buyer name, stored in one option.
 * Secrets never go here; see CredentialStore.
 */
final class Settings {

	public const OPTION = 'einvoice_matbao_settings';

	public const DEFAULT_WALK_IN_BUYER_NAME = 'Khách hàng không lấy hóa đơn';

	/** `MTChieu` is at most 20 characters (Mat Bao create-invoice definition). */
	public const MAX_REFERENCE_LENGTH = 20;

	/** Literal between the prefix and the order ID in `MTChieu` (prefix + "WC" + order ID). */
	public const REFERENCE_MARKER = 'WC';

	/** Order IDs up to this many digits must always fit, so the prefix gets the rest. */
	public const RESERVED_ORDER_ID_DIGITS = 10;

	public const MAX_REFERENCE_PREFIX_LENGTH = self::MAX_REFERENCE_LENGTH - 2 - self::RESERVED_ORDER_ID_DIGITS;

	/** `NMua_Ten` maximum length (Mat Bao create-invoice definition). */
	public const MAX_BUYER_NAME_LENGTH = 400;

	/** `DVTinh` when no store default is configured. */
	public const DEFAULT_UNIT = 'Gói';

	/** `DVTinh` maximum length (Mat Bao create-invoice definition). */
	public const MAX_UNIT_LENGTH = 50;

	/**
	 * Whether a prefix is allowed: ASCII letters and digits only, short enough for 10-digit order IDs.
	 *
	 * @param string $prefix Candidate prefix.
	 */
	public static function is_valid_reference_prefix( string $prefix ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9]{0,' . self::MAX_REFERENCE_PREFIX_LENGTH . '}$/', $prefix );
	}

	/**
	 * Configured `MTChieu` prefix; empty by default.
	 */
	public function reference_prefix(): string {
		return $this->string_value( 'reference_prefix' );
	}

	/**
	 * Configured `NMua_Ten` for walk-in buyers, or the default.
	 */
	public function walk_in_buyer_name(): string {
		$name = $this->string_value( 'walk_in_buyer_name' );

		return '' === $name ? self::DEFAULT_WALK_IN_BUYER_NAME : $name;
	}

	/**
	 * Store-wide default `DVTinh`, or "Gói" when none is configured.
	 */
	public function default_unit(): string {
		$unit = $this->string_value( 'default_unit' );

		return '' === $unit ? self::DEFAULT_UNIT : $unit;
	}

	/**
	 * Default VAT template chosen from Mat Bao, or null when none is chosen.
	 *
	 * @return array{form: string, series: string}|null `form` is `KHMSHDon`, `series` is `KHHDon`.
	 */
	public function default_template(): ?array {
		$form   = $this->string_value( 'template_form' );
		$series = $this->string_value( 'template_series' );

		if ( '' === $form || '' === $series ) {
			return null;
		}

		return array(
			'form'   => $form,
			'series' => $series,
		);
	}

	/**
	 * Saves the prefix and walk-in buyer name. Callers validate first.
	 *
	 * @param string $reference_prefix   Valid prefix.
	 * @param string $walk_in_buyer_name Name, or empty for the default.
	 */
	public function save_invoicing( string $reference_prefix, string $walk_in_buyer_name ): void {
		$this->update(
			array(
				'reference_prefix'   => $reference_prefix,
				'walk_in_buyer_name' => $walk_in_buyer_name,
			)
		);
	}

	/**
	 * Saves the store-wide default unit. Callers validate the length first.
	 *
	 * @param string $unit Unit, or empty for the built-in default.
	 */
	public function save_default_unit( string $unit ): void {
		$this->update( array( 'default_unit' => $unit ) );
	}

	/**
	 * Saves the default template. Callers verify it with Mat Bao first.
	 *
	 * @param string $form   `KHMSHDon`.
	 * @param string $series `KHHDon`.
	 */
	public function save_default_template( string $form, string $series ): void {
		$this->update(
			array(
				'template_form'   => $form,
				'template_series' => $series,
			)
		);
		TemplateCache::forget();
	}

	/**
	 * Merges values into the option.
	 *
	 * @param array<string, string> $values Values to set.
	 */
	private function update( array $values ): void {
		update_option( self::OPTION, array_merge( $this->all(), $values ) );
	}

	/**
	 * A stored string setting, or empty string.
	 *
	 * @param string $key Setting name.
	 */
	private function string_value( string $key ): string {
		$value = $this->all()[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * All stored settings.
	 *
	 * @return array<string, mixed>
	 */
	private function all(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}
}
