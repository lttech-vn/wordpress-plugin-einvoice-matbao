<?php
/**
 * Invoice status filter on the WooCommerce order list.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

use LTTech\EinvoiceMatbao\Invoice\InvoiceRepository;
use LTTech\EinvoiceMatbao\Invoice\InvoiceStatus;
use WP_Query;

/**
 * Adds an "invoice" dropdown (errors first) above the order list and narrows the list to the
 * matching orders, on the HPOS list table and on the legacy posts list. Administrators only.
 */
final class OrderListInvoiceFilter {

	public const PARAM = 'einv_invoice';

	/**
	 * Hooks both order screens.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( self::class, 'render_dropdown' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( self::class, 'filter_hpos_query_args' ) );
		add_action( 'restrict_manage_posts', array( self::class, 'render_legacy_dropdown' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter_legacy_query' ) );
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
	 * Limits the HPOS order query to orders whose invoice matches the filter.
	 *
	 * @param array<string, mixed> $args Order query arguments.
	 * @return array<string, mixed>
	 */
	public static function filter_hpos_query_args( array $args ): array {
		$ids = self::matching_order_ids();
		if ( null !== $ids ) {
			$args['post__in'] = array() === $ids ? array( 0 ) : $ids;
		}

		return $args;
	}

	/**
	 * Limits the legacy orders list query to orders whose invoice matches the filter.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_legacy_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}

		$ids = self::matching_order_ids();
		if ( null !== $ids ) {
			$query->set( 'post__in', array() === $ids ? array( 0 ) : $ids );
		}
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
	 * Order IDs matching the filter, or null when no filter applies or the user may not use it.
	 *
	 * @return list<int>|null
	 */
	private static function matching_order_ids(): ?array {
		$selected = self::selected();
		if ( '' === $selected || ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return null;
		}

		return ( new InvoiceRepository() )->order_ids_with_status( InvoiceStatus::statuses_for_filter( $selected ) );
	}
}
