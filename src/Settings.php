<?php
/**
 * Non-secret invoicing settings.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao;

use LTTech\EinvoiceMatbao\Matbao\TemplateCache;

/**
 * Default template, `MTChieu` prefix, walk-in buyer name, default unit, the delete-on-uninstall
 * switch, the Lark and monitoring switches, and the KCT and KKKNT tax rate assignment, stored in
 * one option.
 * Secrets never go here; see CredentialStore.
 */
final class Settings {

	public const OPTION = 'einvoice_matbao_settings';

	/** `TSuat` code for tax rates assigned to KCT (not subject to tax). */
	public const TAX_RATE_KCT = -1;

	/** `TSuat` code for tax rates assigned to KKKNT (not declared for tax). */
	public const TAX_RATE_KKKNT = -2;

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
	 * Whether uninstalling the plugin deletes all of its data (off by default; see Uninstaller).
	 */
	public function delete_data_on_uninstall(): bool {
		return '1' === $this->string_value( 'delete_data_on_uninstall' );
	}

	/**
	 * Turns deleting all data on uninstall on or off. Callers require the confirmation first.
	 *
	 * @param bool $enabled Whether to delete everything on uninstall.
	 */
	public function save_delete_data_on_uninstall( bool $enabled ): void {
		$this->update( array( 'delete_data_on_uninstall' => $enabled ? '1' : '' ) );
	}

	/**
	 * Whether Lark alerts are switched on. Until the switch is first saved it follows whether a
	 * webhook is stored, so sites that already receive alerts keep receiving them.
	 *
	 * @param bool $webhook_saved Whether a Lark webhook is stored.
	 */
	public function lark_enabled( bool $webhook_saved ): bool {
		return match ( $this->string_value( 'lark_enabled' ) ) {
			'1'     => true,
			'0'     => false,
			default => $webhook_saved,
		};
	}

	/**
	 * Turns Lark alerts on or off. Stores an explicit value so the choice outlives the webhook default.
	 *
	 * @param bool $enabled Whether Lark alerts are on.
	 */
	public function save_lark_enabled( bool $enabled ): void {
		$this->update( array( 'lark_enabled' => $enabled ? '1' : '0' ) );
	}

	/**
	 * Whether error monitoring is switched on in the UI (off by default).
	 */
	public function monitoring_enabled(): bool {
		return '1' === $this->string_value( 'monitoring_enabled' );
	}

	/**
	 * Transaction sample rate in [0, 1]; 1.0 when unset or not numeric.
	 */
	public function traces_sample_rate(): float {
		$value = $this->all()['traces_sample_rate'] ?? '1';
		if ( ! is_string( $value ) || ! is_numeric( $value ) ) {
			return 1.0;
		}

		return min( 1.0, max( 0.0, (float) $value ) );
	}

	/**
	 * Saves the monitoring switch and sample rate. Callers validate the rate first.
	 *
	 * @param bool  $enabled Whether monitoring is on.
	 * @param float $rate    Sample rate; clamped to [0, 1].
	 */
	public function save_monitoring( bool $enabled, float $rate ): void {
		$this->update(
			array(
				'monitoring_enabled' => $enabled ? '1' : '',
				'traces_sample_rate' => (string) min( 1.0, max( 0.0, $rate ) ),
			)
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
	 * Tax rate IDs assigned to KCT or KKKNT, mapped to their `TSuat` code. A rate in both lists is KKKNT.
	 *
	 * @return array<int, int> `tax_rate_id => TSuat`.
	 */
	public function exempt_tax_rates(): array {
		$rates = array();
		foreach ( $this->positive_ids( 'kct_tax_rate_ids' ) as $id ) {
			$rates[ $id ] = self::TAX_RATE_KCT;
		}
		foreach ( $this->positive_ids( 'kkknt_tax_rate_ids' ) as $id ) {
			$rates[ $id ] = self::TAX_RATE_KKKNT;
		}

		return $rates;
	}

	/**
	 * Replaces the KCT and KKKNT rate ID lists. Callers pass disjoint, valid IDs.
	 *
	 * @param array<int> $kct   Rate IDs assigned to KCT.
	 * @param array<int> $kkknt Rate IDs assigned to KKKNT.
	 */
	public function save_exempt_tax_rates( array $kct, array $kkknt ): void {
		$this->update(
			array(
				'kct_tax_rate_ids'   => $kct,
				'kkknt_tax_rate_ids' => $kkknt,
			)
		);
	}

	/**
	 * Merges values into the option.
	 *
	 * @param array<string, mixed> $values Values to set.
	 */
	private function update( array $values ): void {
		update_option( self::OPTION, array_merge( $this->all(), $values ) );
	}

	/**
	 * Stored tax rate IDs that are positive integers; anything else is dropped.
	 *
	 * @param string $key Option key holding the list.
	 * @return list<int>
	 */
	private function positive_ids( string $key ): array {
		$stored = $this->all()[ $key ] ?? array();
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$ids = array();
		foreach ( $stored as $value ) {
			$id = self::parse_rate_id( $value );
			if ( null !== $id ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * A positive integer tax rate ID from an int or canonical decimal string, or null.
	 *
	 * @param mixed $value Candidate value.
	 */
	public static function parse_rate_id( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $value ) && (string) (int) $value === $value ) {
			return (int) $value;
		}

		return null;
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
