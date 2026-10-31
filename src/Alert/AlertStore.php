<?php
/**
 * Persistent record of invoice alerts.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Alert;

/**
 * One non-autoloaded option per (order, error code, retry cycle). `add_option()` fails when the
 * name exists, which makes "first one wins" atomic and survives restarts and cache flushes. A
 * resend by an admin starts a new retry cycle, so the same error on that order alerts again.
 * Stored values hold ids, the error code and delivery flags only: no personal data, no secrets.
 */
final class AlertStore {

	private const PREFIX = 'einvoice_matbao_alert_';

	/** Most alerts listed in the admin notice. */
	private const NOTICE_LIMIT = 10;

	/**
	 * Option name of an alert.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $error_code InvoiceErrorCode value.
	 * @param int    $cycle      InvoiceRecord::$retry_cycle_start.
	 */
	public static function key( int $order_id, string $error_code, int $cycle ): string {
		return self::PREFIX . $order_id . '_' . $error_code . '_' . $cycle;
	}

	/**
	 * Records a new alert.
	 *
	 * @param string $key        Alert key.
	 * @param int    $order_id   Order ID.
	 * @param string $error_code InvoiceErrorCode value.
	 * @param int    $cycle      Retry cycle.
	 * @return bool False when this alert already exists.
	 */
	public static function create( string $key, int $order_id, string $error_code, int $cycle ): bool {
		return add_option(
			$key,
			array(
				'order_id'  => $order_id,
				'code'      => $error_code,
				'cycle'     => $cycle,
				'dismissed' => false,
				'lark'      => 'queued',
			),
			'',
			false
		);
	}

	/**
	 * Alert by key.
	 *
	 * @param string $key Alert key.
	 * @return array{order_id: int, code: string, cycle: int, dismissed: bool, lark: string}|null
	 */
	public static function get( string $key ): ?array {
		$value = get_option( $key );
		if ( ! is_array( $value ) || ! isset( $value['order_id'], $value['code'], $value['cycle'] ) ) {
			return null;
		}

		return array(
			'order_id'  => (int) $value['order_id'],
			'code'      => (string) $value['code'],
			'cycle'     => (int) $value['cycle'],
			'dismissed' => ! empty( $value['dismissed'] ),
			'lark'      => is_string( $value['lark'] ?? null ) ? $value['lark'] : 'queued',
		);
	}

	/**
	 * Sets a field of an alert.
	 *
	 * @param string $key   Alert key.
	 * @param string $field `lark` or `dismissed`.
	 * @param mixed  $value New value.
	 */
	public static function set( string $key, string $field, mixed $value ): void {
		$stored = get_option( $key );
		if ( ! is_array( $stored ) ) {
			return;
		}
		$stored[ $field ] = $value;
		update_option( $key, $stored, false );
	}

	/**
	 * Newest alerts first.
	 *
	 * @return array<string, array{order_id: int, code: string, cycle: int, dismissed: bool, lark: string}> Keyed by alert key.
	 */
	public static function recent(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lists this plugin's own options by prefix; no WordPress API does that.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d',
				$wpdb->options,
				$wpdb->esc_like( self::PREFIX ) . '%',
				self::NOTICE_LIMIT * 5
			)
		);

		$alerts = array();
		foreach ( $names as $name ) {
			$alert = self::get( (string) $name );
			if ( null !== $alert ) {
				$alerts[ (string) $name ] = $alert;
			}
		}

		return $alerts;
	}
}
