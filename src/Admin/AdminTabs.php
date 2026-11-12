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

	/** Former tab keys that now open the notifications tab, so old bookmarks keep working. */
	public const ALIASES = array(
		'lark'       => 'notifications',
		'monitoring' => 'notifications',
	);

	/**
	 * Tab labels keyed by tab key, in display order.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'invoices'      => __( 'Hóa đơn', 'einvoice-matbao' ),
			'connection'    => __( 'Kết nối Mắt Bão', 'einvoice-matbao' ),
			'invoicing'     => __( 'Xuất hóa đơn', 'einvoice-matbao' ),
			'notifications' => __( 'Thông báo', 'einvoice-matbao' ),
			'advanced'      => __( 'Nâng cao', 'einvoice-matbao' ),
		);
	}

	/**
	 * Canonical tab key of a raw value: an exact, case-sensitive tab key or alias, otherwise null.
	 *
	 * @param mixed $tab Raw, untrusted tab value.
	 */
	public static function canonical( mixed $tab ): ?string {
		if ( ! is_string( $tab ) ) {
			return null;
		}
		if ( array_key_exists( $tab, self::labels() ) ) {
			return $tab;
		}

		return self::ALIASES[ $tab ] ?? null;
	}

	/**
	 * Returns the canonical key when the value is a known tab or alias, otherwise the default.
	 *
	 * @param mixed $tab Raw, untrusted tab value.
	 */
	public static function resolve( mixed $tab ): string {
		return self::canonical( $tab ) ?? self::DEFAULT;
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
