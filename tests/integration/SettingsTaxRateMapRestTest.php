<?php
/**
 * Mat Bao tax mapping card and its add/remove routes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\SettingsPage;
use LTTech\EinvoiceMatbao\Invoice\TaxRateMap;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;
use WC_Tax;
use WP_REST_Request;

/**
 * The "Ánh xạ thuế Mắt Bão" card: one-to-one table, validated before any write, deleted rates removable.
 */
final class SettingsTaxRateMapRestTest extends SettingsRestTestCase {

	private const ADD    = '/settings/tax-rate-map/add';
	private const REMOVE = '/settings/tax-rate-map/remove';

	/**
	 * 10% Standard rate ID.
	 *
	 * @var int
	 */
	private int $ten_id;
	/**
	 * KCT class rate ID.
	 *
	 * @var int
	 */
	private int $kct_id;
	/**
	 * 3.5% rate ID.
	 *
	 * @var int
	 */
	private int $other_id;

	public function set_up(): void {
		parent::set_up();
		$this->login_as( 'administrator' );

		WC_Tax::create_tax_class( 'kct', 'kct' );
		WC_Tax::create_tax_class( 'other', 'other' );
		$this->ten_id   = $this->insert_rate( '', '10', 'VAT 10' );
		$this->kct_id   = $this->insert_rate( 'kct', '0', 'KCT' );
		$this->other_id = $this->insert_rate( 'other', '3.5', 'VAT 3,5' );
	}

	public function test_empty_table(): void {
		$html = SettingsPage::render_tabs( 'invoicing' );

		$this->assertStringContainsString( 'id="einv-tax-rate-map"', $html );
		preg_match_all( '/data-einv-map-group="([a-z0-9]+)"/', $html, $groups );
		$this->assertSame( TaxRateMap::groups(), $groups[1] );
		$this->assertStringNotContainsString( 'data-einv-badge', $html );
		$this->assertSame( 7, substr_count( $html, '<select id="einv-map-' ) );
		$this->assertSame( 7, substr_count( $html, 'VAT 10 · Tiêu chuẩn · * · 10%</option>' ) );
		$this->assertSame( 7, substr_count( $html, '<option value="' . $this->other_id . '">VAT 3,5 · other · * · ' . wc_format_localized_decimal( '3.5' ) . '%</option>' ) );
		foreach ( TaxRateMap::groups() as $group ) {
			$this->assertStringContainsString( '<label class="sr-only" for="einv-map-' . $group . '">', $html );
		}
		foreach ( array( '10%', '8%', '5%', '0%', 'KCT', 'KKKNT', 'Khác' ) as $label ) {
			$this->assertStringContainsString( '>' . $label . '</strong>', $html );
		}
	}

	public function test_badges_per_group(): void {
		$ten_b = $this->insert_rate( 'kct', '10', 'VAT 10 B' );
		( new Settings() )->save_tax_rate_map(
			array(
				'vat10' => array( $this->ten_id, $ten_b ),
				'kct'   => array( $this->kct_id ),
			)
		);

		$html = SettingsPage::render_tabs( 'invoicing' );

		$this->assertSame( 3, substr_count( $html, 'data-einv-badge=' ) );
		$this->assertStringContainsString( 'VAT 10 · Tiêu chuẩn · *', $html );
		$this->assertStringContainsString( 'VAT 10 B · kct · *', $html );
		$this->assertStringContainsString( 'aria-label="Bỏ VAT 10 khỏi 10%"', $html );
		$this->assertStringContainsString( 'aria-label="Bỏ VAT 10 B khỏi 10%"', $html );
		$this->assertStringContainsString( 'aria-label="Bỏ KCT khỏi KCT"', $html );
		$this->assertSame( 1, preg_match( '/data-einv-map-group="kct">.*?data-einv-badge="' . $this->kct_id . '"/s', $html ) );
	}

	public function test_mapped_rate_leaves_the_pickers(): void {
		( new Settings() )->save_tax_rate_map( array( 'vat8' => array( $this->ten_id ) ) );

		$this->assertStringNotContainsString( '<option value="' . $this->ten_id . '"', SettingsPage::render_tabs( 'invoicing' ) );
	}

	public function test_deleted_rate_badge(): void {
		$a = $this->insert_rate( '', '0', 'Gone' );
		( new Settings() )->save_tax_rate_map( array( 'kkknt' => array( $a ) ) );
		WC_Tax::_delete_tax_rate( $a );

		$html = SettingsPage::render_tabs( 'invoicing' );

		$this->assertStringContainsString( '#' . $a . ' (đã xóa)', $html );
		$this->assertStringContainsString( 'aria-label="Bỏ #' . $a . ' khỏi KKKNT"', $html );
	}

	public function test_no_woocommerce_rate(): void {
		foreach ( array( $this->ten_id, $this->kct_id, $this->other_id ) as $id ) {
			WC_Tax::_delete_tax_rate( $id );
		}

		$html = SettingsPage::render_tabs( 'invoicing' );

		$this->assertStringContainsString( 'Chưa có thuế suất nào', $html );
		$this->assertStringNotContainsString( '>Thêm<', $html );
	}

	public function test_add_a_rate(): void {
		$response = $this->htmx(
			'POST',
			self::ADD,
			array(
				'group'   => 'vat10',
				'rate_id' => (string) $this->ten_id,
			)
		);
		$html     = $this->html( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'id="einv-tax-rate-map"', $html );
		$this->assertStringContainsString( 'data-einv-status="success"', $html );
		$this->assertStringContainsString( 'Đã thêm &quot;VAT 10&quot; vào 10%.', $html );
		$this->assertMatchesRegularExpression( '/data-einv-map-group="vat10">.*?data-einv-badge="' . $this->ten_id . '"/s', $html );
		$this->assertStringNotContainsString( '<option value="' . $this->ten_id . '"', $html );
		$this->assertSame( array( 'vat10' => array( $this->ten_id ) ), ( new Settings() )->tax_rate_map() );
	}

	public function test_add_accepts_json_integer_rate_id(): void {
		$request = new WP_REST_Request( 'POST', self::NAMESPACE . self::ADD );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'group'   => 'kct',
					'rate_id' => $this->kct_id,
				)
			)
		);

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( array( 'kct' => array( $this->kct_id ) ), ( new Settings() )->tax_rate_map() );
	}

	/**
	 * Invalid add bodies; `%d` stands for a rate already in another group.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function invalid_adds(): array {
		return array(
			'unknown group'  => array( array( 'group' => 'vat7' ) ),
			'empty group'    => array( array( 'group' => '' ) ),
			'array group'    => array( array( 'group' => array( 'vat10' ) ) ),
			'missing group'  => array( array() ),
			'text rate'      => array(
				array(
					'group'   => 'vat10',
					'rate_id' => 'abc',
				),
			),
			'empty rate'     => array(
				array(
					'group'   => 'vat10',
					'rate_id' => '',
				),
			),
			'array rate'     => array(
				array(
					'group'   => 'vat10',
					'rate_id' => array( '1' ),
				),
			),
			'zero rate'      => array(
				array(
					'group'   => 'vat10',
					'rate_id' => '0',
				),
			),
			'negative rate'  => array(
				array(
					'group'   => 'vat10',
					'rate_id' => '-1',
				),
			),
			'decimal rate'   => array(
				array(
					'group'   => 'vat10',
					'rate_id' => '1.5',
				),
			),
			'missing rate'   => array( array( 'group' => 'vat10' ) ),
			'unknown rate'   => array(
				array(
					'group'   => 'vat10',
					'rate_id' => '999999',
				),
			),
			'already mapped' => array(
				array(
					'group'   => 'vat10',
					'rate_id' => '%d',
				),
			),
		);
	}

	/**
	 * Add is refused and nothing is written.
	 *
	 * @dataProvider invalid_adds
	 * @param array<string, mixed> $params Request body.
	 */
	public function test_add_rejects_invalid_input( array $params ): void {
		$before = array( 'kct' => array( $this->other_id ) );
		( new Settings() )->save_tax_rate_map( $before );
		if ( '%d' === ( $params['rate_id'] ?? '' ) ) {
			$params['rate_id'] = (string) $this->other_id;
		}

		$response = $this->htmx( 'POST', self::ADD, $params );

		$this->assertSame( 422, $response->get_status() );
		$html = $this->html( $response );
		$this->assertStringContainsString( 'id="einv-tax-rate-map"', $html );
		$this->assertStringContainsString( 'Không thêm được thuế suất', $html );
		$this->assertSame( $before, ( new Settings() )->tax_rate_map() );
	}

	public function test_remove_a_rate(): void {
		( new Settings() )->save_tax_rate_map(
			array(
				'vat10' => array( $this->ten_id ),
				'kct'   => array( $this->kct_id ),
			)
		);

		$response = $this->htmx(
			'POST',
			self::REMOVE,
			array(
				'group'   => 'kct',
				'rate_id' => (string) $this->kct_id,
			)
		);
		$html     = $this->html( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Đã bỏ &quot;KCT&quot; khỏi KCT.', $html );
		$this->assertStringNotContainsString( 'data-einv-badge="' . $this->kct_id . '"', $html );
		$this->assertSame( 7, substr_count( $html, '<option value="' . $this->kct_id . '"' ) );
		$this->assertSame( array( 'vat10' => array( $this->ten_id ) ), ( new Settings() )->tax_rate_map() );
	}

	public function test_deleted_rate_badge_can_be_removed(): void {
		$a = $this->insert_rate( '', '0', 'Gone' );
		( new Settings() )->save_tax_rate_map( array( 'kkknt' => array( $a ) ) );
		WC_Tax::_delete_tax_rate( $a );

		$response = $this->htmx(
			'POST',
			self::REMOVE,
			array(
				'group'   => 'kkknt',
				'rate_id' => (string) $a,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), ( new Settings() )->tax_rate_map() );
	}

	/**
	 * Invalid remove bodies; `%d` stands for the mapped rate.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function invalid_removes(): array {
		return array(
			'rate in another group' => array(
				array(
					'group'   => 'vat10',
					'rate_id' => '%d',
				),
			),
			'unknown group'         => array(
				array(
					'group'   => 'vat7',
					'rate_id' => '%d',
				),
			),
			'empty group'           => array(
				array(
					'group'   => '',
					'rate_id' => '%d',
				),
			),
			'array group'           => array(
				array(
					'group'   => array( 'kct' ),
					'rate_id' => '%d',
				),
			),
			'missing group'         => array( array( 'rate_id' => '%d' ) ),
			'text rate'             => array(
				array(
					'group'   => 'kct',
					'rate_id' => 'abc',
				),
			),
			'empty rate'            => array(
				array(
					'group'   => 'kct',
					'rate_id' => '',
				),
			),
			'array rate'            => array(
				array(
					'group'   => 'kct',
					'rate_id' => array( '%d' ),
				),
			),
			'negative rate'         => array(
				array(
					'group'   => 'kct',
					'rate_id' => '-1',
				),
			),
			'missing rate'          => array( array( 'group' => 'kct' ) ),
		);
	}

	/**
	 * Remove is refused and nothing is written.
	 *
	 * @dataProvider invalid_removes
	 * @param array<string, mixed> $params Request body.
	 */
	public function test_remove_rejects_invalid_input( array $params ): void {
		$before = array( 'kct' => array( $this->kct_id ) );
		( new Settings() )->save_tax_rate_map( $before );
		array_walk_recursive(
			$params,
			function ( &$value ): void {
				$value = '%d' === $value ? (string) $this->kct_id : $value;
			}
		);

		$response = $this->htmx( 'POST', self::REMOVE, $params );

		$this->assertSame( 422, $response->get_status() );
		$html = $this->html( $response );
		$this->assertStringContainsString( 'id="einv-tax-rate-map"', $html );
		$this->assertStringContainsString( 'Không bỏ được thuế suất', $html );
		$this->assertSame( $before, ( new Settings() )->tax_rate_map() );
	}

	public function test_access_control(): void {
		$params = array(
			'group'   => 'vat10',
			'rate_id' => (string) $this->ten_id,
		);

		$this->login_as( 'shop_manager' );
		$this->assertSame( 403, $this->htmx( 'POST', self::ADD, $params )->get_status() );
		$this->assertSame( 403, $this->htmx( 'POST', self::REMOVE, $params )->get_status() );

		$this->login_as( 'administrator' );
		$this->assertSame( 403, $this->htmx( 'POST', self::ADD, $params, 'bad-nonce' )->get_status() );
		$this->assertSame( array(), ( new Settings() )->tax_rate_map() );
	}

	public function test_label_escapes_rate_name(): void {
		$this->insert_rate( '', '10', '<b>x</b>' );
		( new Settings() )->save_tax_rate_map( array( 'vat5' => array( $this->insert_rate( '', '5', '<i>y</i>' ) ) ) );

		$html = SettingsPage::render_tabs( 'invoicing' );

		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt; · Tiêu chuẩn', $html );
		$this->assertStringNotContainsString( '<i>y</i>', $html );
		$this->assertStringContainsString( '&lt;i&gt;y&lt;/i&gt;', $html );
	}

	/**
	 * Inserts a WooCommerce tax rate.
	 *
	 * @param string $class_slug Tax class slug; '' is Standard.
	 * @param string $percent    Percent.
	 * @param string $name       Rate name.
	 */
	private function insert_rate( string $class_slug, string $percent, string $name ): int {
		return (int) WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => $percent,
				'tax_rate_name'     => $name,
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => $class_slug,
			)
		);
	}
}
