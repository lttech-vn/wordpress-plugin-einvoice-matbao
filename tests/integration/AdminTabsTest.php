<?php
/**
 * Tab registry of the plugin admin screen.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\AdminTabs;
use WP_UnitTestCase;

/**
 * Tabs are ordered with the invoice list first; unknown keys always fall back to the default.
 */
final class AdminTabsTest extends WP_UnitTestCase {

	public function test_tabs_are_ordered_with_invoices_first(): void {
		$this->assertSame( array( 'invoices', 'connection', 'invoicing', 'lark', 'advanced' ), array_keys( AdminTabs::labels() ) );
		$this->assertSame( 'Hóa đơn', AdminTabs::labels()['invoices'] );
	}

	public function test_resolve_keeps_known_keys(): void {
		$this->assertSame( 'lark', AdminTabs::resolve( 'lark' ) );
	}

	/**
	 * Anything that is not an exact tab key resolves to the default tab.
	 *
	 * @dataProvider unknown_keys
	 *
	 * @param mixed $tab Raw, untrusted tab value.
	 */
	public function test_resolve_falls_back_for_unknown_keys( $tab ): void {
		$this->assertSame( 'invoices', AdminTabs::resolve( $tab ) );
	}

	/**
	 * Untrusted values that must fall back.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function unknown_keys(): array {
		return array(
			'empty'     => array( '' ),
			'unknown'   => array( 'foo' ),
			'uppercase' => array( 'LARK' ),
			'suffix'    => array( 'Lark1' ),
			'null'      => array( null ),
			'array'     => array( array( 'lark' ) ),
			'markup'    => array( '<script>' ),
		);
	}

	public function test_url_points_to_the_settings_page_tab(): void {
		$this->assertSame( admin_url( 'admin.php?page=einvoice-matbao-settings&tab=lark' ), AdminTabs::url( 'lark' ) );
	}
}
