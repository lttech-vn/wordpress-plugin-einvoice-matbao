<?php
/**
 * Persistent record of invoice alerts.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Alert;

use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;

/**
 * One non-autoloaded option per (order, error code, alert cycle). `add_option()` does nothing when
 * the name exists, so the first writer wins and this survives restarts and cache flushes. This
 * assumes no concurrent writer changes the same option between its own check and insert; the
 * worst case of such a race is one duplicate Lark message. A restart of the invoice bumps
 * `alert_cycle` (also when it stops before any attempt), so the same error alerts again.
 * Stored values hold ids, the error code and delivery flags only: no personal data, no secrets.
 */
final class AlertStore {

	private const PREFIX = 'einvoice_matbao_alert_';

	/** Most alerts listed in the admin notice. */
	private const NOTICE_LIMIT = 10;

	/** How long a hidden alert is kept. */
	private const HIDDEN_KEPT_SECONDS = 30 * DAY_IN_SECONDS;

	/**
	 * Option name of an alert.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $error_code InvoiceErrorCode value.
	 * @param int    $cycle      InvoiceRecord::$alert_cycle.
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
	 * @param int    $cycle      Alert cycle.
	 * @return bool False when this alert already exists.
	 */
	public static function create( string $key, int $order_id, string $error_code, int $cycle ): bool {
		return add_option(
			$key,
			array(
				'order_id'     => $order_id,
				'code'         => $error_code,
				'cycle'        => $cycle,
				'dismissed'    => false,
				'lark'         => 'queued',
				'lark_retries' => 0,
			),
			'',
			false
		);
	}

	/**
	 * Alert by key.
	 *
	 * @param string $key Alert key.
	 * @return array{order_id: int, code: string, cycle: int, dismissed: bool, lark: string, lark_retries: int}|null
	 */
	public static function get( string $key ): ?array {
		return self::normalize( get_option( $key ) );
	}

	/**
	 * Sets a field of an alert.
	 *
	 * @param string $key   Alert key.
	 * @param string $field `lark`, `lark_retries` or `dismissed` (the dismissal time, or false).
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
	 * Alerts not hidden by an admin, newest first, in one query. Hidden ones stay out of the
	 * query so they never take a place in the limit.
	 *
	 * @return array<string, array{order_id: int, code: string, cycle: int, dismissed: bool, lark: string, lark_retries: int}> Keyed by alert key.
	 */
	public static function open(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lists this plugin's own options by prefix; no WordPress API does that.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s AND option_value LIKE %s ORDER BY option_id DESC LIMIT %d',
				$wpdb->options,
				$wpdb->esc_like( self::PREFIX ) . '%',
				'%' . $wpdb->esc_like( '"dismissed";b:0;' ) . '%',
				self::NOTICE_LIMIT
			)
		);

		$alerts = array();
		foreach ( $rows as $row ) {
			$alert = self::normalize( maybe_unserialize( $row->option_value ) );
			if ( null !== $alert ) {
				$alerts[ (string) $row->option_name ] = $alert;
			}
		}

		return $alerts;
	}

	/**
	 * Option names of the alerts of an order, hidden ones included.
	 *
	 * @param int $order_id Order ID.
	 * @return list<string>
	 */
	public static function keys_of_order( int $order_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lists this plugin's own options by prefix; no WordPress API does that.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( self::PREFIX . $order_id . '_' ) . '%'
			)
		);

		return array_values( array_map( 'strval', $names ) );
	}

	/**
	 * Deletes alerts that no longer matter: the invoice is gone, no longer needs an admin or was
	 * restarted since (a newer alert cycle), and alerts an admin hid more than 30 days ago. A
	 * recently hidden alert of the current cycle stays so the same error does not alert again.
	 * Looks at the oldest 200 alerts per run.
	 */
	public static function cleanup(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lists this plugin's own options by prefix; no WordPress API does that.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 200',
				$wpdb->options,
				$wpdb->esc_like( self::PREFIX ) . '%'
			)
		);

		$repository = new InvoiceRepository();
		foreach ( $rows as $row ) {
			$stored = maybe_unserialize( $row->option_value );
			$alert  = self::normalize( $stored );
			if ( null === $alert || ! is_array( $stored ) ) {
				continue;
			}

			$hidden_at = is_int( $stored['dismissed'] ?? null ) ? $stored['dismissed'] : 0;
			$invoice   = $repository->find_by_order( $alert['order_id'] );
			if (
				( $hidden_at > 0 && time() - $hidden_at > self::HIDDEN_KEPT_SECONDS )
				|| null === $invoice
				|| $invoice->alert_cycle !== $alert['cycle']
				|| ! $invoice->needs_admin()
			) {
				delete_option( (string) $row->option_name );
			}
		}
	}

	/**
	 * Alert from a stored value.
	 *
	 * @param mixed $value Option value.
	 * @return array{order_id: int, code: string, cycle: int, dismissed: bool, lark: string, lark_retries: int}|null
	 */
	private static function normalize( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! isset( $value['order_id'], $value['code'], $value['cycle'] ) ) {
			return null;
		}

		return array(
			'order_id'     => (int) $value['order_id'],
			'code'         => (string) $value['code'],
			'cycle'        => (int) $value['cycle'],
			'dismissed'    => ! empty( $value['dismissed'] ),
			'lark'         => is_string( $value['lark'] ?? null ) ? $value['lark'] : 'queued',
			'lark_retries' => is_int( $value['lark_retries'] ?? null ) ? $value['lark_retries'] : 0,
		);
	}
}
