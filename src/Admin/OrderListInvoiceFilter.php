<?php
/**
 * Invoice status filter on the WooCommerce order list.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use LTTech\EinvoiceMatbao\Invoice\Schema;
use WP_Query;

/**
 * Adds an "invoice" dropdown (errors first) above the order list and narrows the list to the
 * matching orders, on the HPOS list table and on the legacy posts list. Administrators only.
 */
final class OrderListInvoiceFilter {

	public const PARAM = 'einv_invoice';

	/** Order query argument carrying the selected InvoiceStatus values from the request to the SQL clause. */
	public const QUERY_VAR = 'einv_invoice_statuses';

	/**
	 * Hooks both order screens.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( self::class, 'render_dropdown' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( self::class, 'filter_hpos_query_args' ) );
		add_action( 'restrict_manage_posts', array( self::class, 'render_legacy_dropdown' ) );
		add_filter( 'woocommerce_orders_table_query_clauses', array( self::class, 'filter_hpos_clauses' ), 10, 3 );
		add_action( 'pre_get_posts', array( self::class, 'filter_legacy_query' ) );
		add_filter( 'posts_where', array( self::class, 'filter_legacy_where' ), 10, 2 );
	}

	/**
	 * Renders the dropdown on the legacy list only for the order post type.
	 *
	 * @param string $post_type Post type being listed.
	 */
	public static function render_legacy_dropdown( string $post_type ): void {
		if ( 'shop_order' === $post_type ) {
			self::render_dropdown();
		}
	}

	/**
	 * Prints the dropdown.
	 */
	public static function render_dropdown(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		$selected = self::selected();
		echo '<select name="' . esc_attr( self::PARAM ) . '"><option value="">' . esc_html__( 'Hóa đơn: tất cả', 'einvoice-matbao' ) . '</option>';
		foreach ( InvoiceStatus::filter_labels() as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Passes the selected invoice statuses to the HPOS order query; filter_hpos_clauses() applies them.
	 *
	 * @param array<string, mixed> $args Order query arguments.
	 * @return array<string, mixed>
	 */
	public static function filter_hpos_query_args( array $args ): array {
		$statuses = self::selected_statuses();
		if ( null !== $statuses ) {
			$args[ self::QUERY_VAR ] = $statuses;
		}

		return $args;
	}

	/**
	 * Keeps only orders whose invoice is in the statuses carried by QUERY_VAR, as an `EXISTS` on the
	 * invoice table. It narrows the WHERE clause, so it combines with every other argument
	 * (`post__in` included) and the count query, and has no cap on how many orders match.
	 *
	 * @param array<string, string> $clauses Query clauses.
	 * @param mixed                 $query   OrdersTableQuery (unused).
	 * @param array<string, mixed>  $args    Query arguments.
	 * @return array<string, string>
	 */
	public static function filter_hpos_clauses( array $clauses, mixed $query, array $args ): array {
		$statuses = $args[ self::QUERY_VAR ] ?? null;
		if ( ! is_array( $statuses ) || array() === $statuses ) {
			return $clauses;
		}

		global $wpdb;

		// The HPOS orders table, as OrdersTableDataStore::get_orders_table_name() names it.
		$clauses['where'] = '(' . ( $clauses['where'] ?? '1=1' ) . ') AND ' . self::exists_clause( $wpdb->prefix . 'wc_orders.id', $statuses );

		return $clauses;
	}

	/**
	 * Passes the selected invoice statuses to the legacy orders list query; filter_legacy_where() applies them.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_legacy_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}

		$statuses = self::selected_statuses();
		if ( null !== $statuses ) {
			$query->set( self::QUERY_VAR, $statuses );
		}
	}

	/**
	 * Same `EXISTS` as filter_hpos_clauses() for a posts query that carries QUERY_VAR.
	 *
	 * @param string   $where WHERE clause.
	 * @param WP_Query $query Query.
	 */
	public static function filter_legacy_where( string $where, WP_Query $query ): string {
		$statuses = $query->get( self::QUERY_VAR );
		if ( ! is_array( $statuses ) || array() === $statuses ) {
			return $where;
		}

		global $wpdb;

		return $where . ' AND ' . self::exists_clause( $wpdb->posts . '.ID', $statuses );
	}

	/**
	 * `EXISTS` subquery matching an invoice of the order column in one of the statuses.
	 *
	 * @param string   $order_column Fully qualified order ID column (fixed table and column names only).
	 * @param string[] $statuses     InvoiceStatus values.
	 */
	private static function exists_clause( string $order_column, array $statuses ): string {
		global $wpdb;

		$statuses = array_values( array_intersect( $statuses, array_keys( InvoiceStatus::filter_labels() ) ) );
		if ( array() === $statuses ) {
			return '1=0';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $order_column is a fixed table column; the IN list is generated placeholders only.
		return $wpdb->prepare( 'EXISTS (SELECT 1 FROM %i einv WHERE einv.order_id = ' . $order_column . ' AND einv.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . '))', array_merge( array( Schema::invoice_table() ), $statuses ) );
	}

	/**
	 * The requested filter value, or an empty string.
	 */
	private static function selected(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter, no state change.
		$value = isset( $_GET[ self::PARAM ] ) && is_string( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) : '';

		return array() === InvoiceStatus::statuses_for_filter( $value ) ? '' : $value;
	}

	/**
	 * InvoiceStatus values of the selected filter, or null when no filter applies or the user may not use it.
	 *
	 * @return list<string>|null
	 */
	private static function selected_statuses(): ?array {
		$selected = self::selected();
		if ( '' === $selected || ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return null;
		}

		return InvoiceStatus::statuses_for_filter( $selected );
	}
}
