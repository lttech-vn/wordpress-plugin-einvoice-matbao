<?php
/**
 * Counts behind the dashboard.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table (ADR 0002); counts must never be served from a cache.

/**
 * Error, waiting and created counts from the invoice table in one grouped query over the `status` index.
 */
final class DashboardStats {

	/**
	 * Counts per metric. "Created today" counts drafts whose row last changed (the moment the draft
	 * was recorded) inside the current day of the shop timezone.
	 *
	 * @return array{error: int, waiting: int, created: int, created_today: int}
	 */
	public static function counts(): array {
		global $wpdb;

		list( $start, $end ) = self::today_bounds( time() );
		$statuses            = array_merge( InvoiceStatus::error_statuses(), InvoiceStatus::waiting_statuses(), array( InvoiceStatus::DRAFT_CREATED ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the IN list only holds %s placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS total, COALESCE(SUM(updated_at >= %s AND updated_at < %s), 0) AS today FROM %i WHERE status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ') GROUP BY status',
				array_merge( array( gmdate( 'Y-m-d H:i:s', $start ), gmdate( 'Y-m-d H:i:s', $end ), Schema::invoice_table() ), $statuses )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$by_status = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$by_status[ (string) $row['status'] ] = array( (int) $row['total'], (int) $row['today'] );
		}
		$sum = static function ( array $wanted ) use ( $by_status ): int {
			return array_sum( array_map( static fn( string $status ): int => $by_status[ $status ][0] ?? 0, $wanted ) );
		};

		return array(
			'error'         => $sum( InvoiceStatus::error_statuses() ),
			'waiting'       => $sum( InvoiceStatus::waiting_statuses() ),
			'created'       => $sum( array( InvoiceStatus::DRAFT_CREATED ) ),
			'created_today' => $by_status[ InvoiceStatus::DRAFT_CREATED ][1] ?? 0,
		);
	}

	/**
	 * Start (inclusive) and end (exclusive) of the shop-timezone day containing a moment.
	 *
	 * @param int $now Unix timestamp.
	 * @return array{int, int}
	 */
	public static function today_bounds( int $now ): array {
		$start = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( 0, 0, 0 );

		return array( $start->getTimestamp(), $start->modify( '+1 day' )->getTimestamp() );
	}
}
