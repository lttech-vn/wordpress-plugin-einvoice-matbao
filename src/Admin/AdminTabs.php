<?php
/**
 * Tab registry of the plugin admin screen.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Admin;

/**
 * Defines the tab keys, their labels, the default tab and tab URLs.
 */
final class AdminTabs {

	public const DEFAULT = 'invoices';

	/**
	 * Tab labels keyed by tab key, in display order.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'invoices'   => __( 'Hóa đơn', 'einvoice-matbao' ),
			'connection' => __( 'Kết nối Mắt Bão', 'einvoice-matbao' ),
			'invoicing'  => __( 'Xuất hóa đơn', 'einvoice-matbao' ),
			'lark'       => __( 'Cảnh báo Lark', 'einvoice-matbao' ),
			'advanced'   => __( 'Nâng cao', 'einvoice-matbao' ),
		);
	}

	/**
	 * Returns the key when it is a known tab (exact, case-sensitive), otherwise the default.
	 *
	 * @param mixed $tab Raw, untrusted tab value.
	 */
	public static function resolve( mixed $tab ): string {
		if ( is_string( $tab ) && array_key_exists( $tab, self::labels() ) ) {
			return $tab;
		}

		return self::DEFAULT;
	}

	/**
	 * Admin URL of a tab on the plugin screen.
	 *
	 * @param string $tab Tab key.
	 */
	public static function url( string $tab ): string {
		return admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=' . $tab );
	}
}
