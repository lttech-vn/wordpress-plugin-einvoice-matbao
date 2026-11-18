<?php
/**
 * KCT/KKKNT tax rate assignment through the settings REST routes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\SettingsPage;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;
use WC_Tax;
use WP_REST_Request;

/**
 * The "Thuế KCT/KKKNT" card: zero rates only, validated before any write, orphans kept.
 */
final class SettingsTaxRatesRestTest extends SettingsRestTestCase {

	/**
	 * KCT rate ID.
	 *
	 * @var int
	 */
	private int $kct_id;
	/**
	 * KKKNT rate ID.
	 *
	 * @var int
	 */
	private int $kkknt_id;
	/**
	 * 10% rate ID.
	 *
	 * @var int
	 */
	private int $ten_id;

	public function set_up(): void {
		parent::set_up();
		$this->login_as( 'administrator' );

		WC_Tax::create_tax_class( 'kct', 'kct' );
		WC_Tax::create_tax_class( 'kkknt', 'kkknt' );
		$this->kct_id   = $this->insert_rate( 'kct', '0', 'Miễn thuế A' );
		$this->kkknt_id = $this->insert_rate( 'kkknt', '0', 'Miễn thuế B' );
		$this->ten_id   = $this->insert_rate( '', '10', 'VAT 10' );
	}

	public function test_not_configured_shows_vat0_for_every_zero_rate(): void {
		$html = SettingsPage::render_tabs( 'invoicing' );

		$this->assertSame( 2, substr_count( $html, '<option value="vat0" selected' ) );
	}

	public function test_saves_kct_and_kkknt(): void {
		$response = $this->save(
			array(
				$this->kct_id   => 'kct',
				$this->kkknt_id => 'kkknt',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-status="success"', $this->html( $response ) );
		$this->assertSame(
			array(
				$this->kct_id   => -1,
				$this->kkknt_id => -2,
			),
			( new Settings() )->exempt_tax_rates()
		);
	}

	public function test_standard_and_additional_zero_rates_in_one_group(): void {
		$standard_zero = $this->insert_rate( '', '0', 'Chuẩn 0' );

		$this->assertSame(
			200,
			$this->save(
				array(
					$standard_zero => 'kct',
					$this->kct_id  => 'kct',
				)
			)->get_status()
		);
		$this->assertSame(
			array(
				$standard_zero => -1,
				$this->kct_id  => -1,
			),
			( new Settings() )->exempt_tax_rates()
		);
	}

	public function test_vat0_clears_an_assignment(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );

		$this->assertSame( 200, $this->save( array( $this->kct_id => 'vat0' ) )->get_status() );
		$this->assertSame( array(), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_missing_field_clears_the_assignment(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );

		$this->assertSame( 200, $this->htmx( 'POST', '/settings/tax-rates' )->get_status() );
		$this->assertSame( array(), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_malformed_field_is_rejected(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );

		$this->assertSame( 422, $this->htmx( 'POST', '/settings/tax-rates', array( 'tax_rate_kind' => 'oops' ) )->get_status() );
		$this->assertSame( array( $this->kct_id => -1 ), ( new Settings() )->exempt_tax_rates() );
		$this->assertSame( 422, $this->htmx( 'POST', '/settings/tax-rates', array( 'tax_rate_kind' => array( $this->kct_id => array( 'kct' ) ) ) )->get_status() );
		$this->assertSame( array( $this->kct_id => -1 ), ( new Settings() )->exempt_tax_rates() );

		$request = new WP_REST_Request( 'POST', self::NAMESPACE . '/settings/tax-rates' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'tax_rate_kind' => null ) ) );
		$this->assertSame( 422, rest_do_request( $request )->get_status() );
		$this->assertSame( array( $this->kct_id => -1 ), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_mixed_valid_and_invalid_entries_write_nothing(): void {
		$this->assertSame(
			422,
			$this->save(
				array(
					$this->kct_id => 'kct',
					$this->ten_id => 'kct',
				)
			)->get_status()
		);
		$this->assertSame( array(), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_non_zero_rate_is_rejected(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );

		$this->assertSame( 422, $this->save( array( $this->ten_id => 'kct' ) )->get_status() );
		$this->assertSame( array( $this->kct_id => -1 ), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_unknown_rate_is_rejected(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );

		$this->assertSame( 422, $this->save( array( 999999 => 'kct' ) )->get_status() );
		$this->assertSame( array( $this->kct_id => -1 ), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_unknown_kind_is_rejected(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );

		$this->assertSame( 422, $this->save( array( $this->kct_id => 'exempt' ) )->get_status() );
		$this->assertSame( array( $this->kct_id => -1 ), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_non_administrator_cannot_save(): void {
		$this->login_as( 'shop_manager' );

		$this->assertSame( 403, $this->save( array( $this->kct_id => 'kct' ) )->get_status() );
		$this->assertSame( array(), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_invalid_nonce_cannot_save(): void {
		$response = $this->htmx( 'POST', '/settings/tax-rates', array( 'tax_rate_kind' => array( $this->kct_id => 'kct' ) ), 'bad-nonce' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_form_lists_only_zero_rates_with_current_choice(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );

		$html = SettingsPage::render_tabs( 'invoicing' );

		$this->assertStringContainsString( sprintf( 'name="tax_rate_kind[%d]"', $this->kct_id ), $html );
		$this->assertMatchesRegularExpression( '/<option value="kct" selected/', $html );
		$this->assertStringContainsString( sprintf( 'name="tax_rate_kind[%d]"', $this->kkknt_id ), $html );
		$this->assertStringNotContainsString( sprintf( 'name="tax_rate_kind[%d]"', $this->ten_id ), $html );
		$this->assertStringContainsString( 'settings/tax-rates', $html );
		$this->assertMatchesRegularExpression( sprintf( '/<label[^>]*for="einv-tax-rate-%1$d"[^>]*>[^<]*Miễn thuế A[^<]*kct/u', $this->kct_id ), $html );
		$this->assertStringContainsString( sprintf( '<select id="einv-tax-rate-%d"', $this->kct_id ), $html );
	}

	public function test_result_goes_to_the_card_result_area(): void {
		$html = SettingsPage::render_tabs( 'invoicing' );

		$this->assertMatchesRegularExpression( '#<form hx-post="[^"]*settings/tax-rates" hx-target="next \.einv-result"#', $html );
		$this->assertSame( 3, substr_count( $html, 'class="einv-result"' ) );

		$fragment = $this->html( $this->save( array( $this->kct_id => 'kct' ) ) );
		$this->assertStringNotContainsString( 'name="default_unit"', $fragment );
		$this->assertStringNotContainsString( 'name="reference_prefix"', $fragment );
	}

	public function test_no_zero_rate_shows_guidance(): void {
		WC_Tax::_delete_tax_rate( $this->kct_id );
		WC_Tax::_delete_tax_rate( $this->kkknt_id );

		$html = SettingsPage::render_tabs( 'invoicing' );

		$this->assertStringContainsString( 'Chưa có thuế suất 0%', $html );
		$this->assertStringNotContainsString( 'settings/tax-rates', $html );
	}

	public function test_deleted_rate_is_not_listed_and_does_not_break_the_page(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );
		WC_Tax::_delete_tax_rate( $this->kct_id );

		$html = SettingsPage::render_tabs( 'invoicing' );

		$this->assertStringNotContainsString( sprintf( 'name="tax_rate_kind[%d]"', $this->kct_id ), $html );
		$this->assertArrayHasKey( $this->kct_id, ( new Settings() )->exempt_tax_rates() );
	}

	public function test_deleted_rate_keeps_its_assignment_when_another_is_saved(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );
		WC_Tax::_delete_tax_rate( $this->kct_id );

		$this->assertSame( 200, $this->save( array( $this->kkknt_id => 'kkknt' ) )->get_status() );
		$this->assertSame(
			array(
				$this->kct_id   => -1,
				$this->kkknt_id => -2,
			),
			( new Settings() )->exempt_tax_rates()
		);
	}

	public function test_success_message_counts_only_the_saved_rates(): void {
		( new Settings() )->save_exempt_tax_rates( array( 777 ), array() );

		$html = $this->html( $this->save( array( $this->kct_id => 'kct' ) ) );

		$this->assertStringContainsString( 'Đã lưu. 1 thuế suất KCT, 0 thuế suất KKKNT.', $html );
		$this->assertSame(
			array(
				777           => -1,
				$this->kct_id => -1,
			),
			( new Settings() )->exempt_tax_rates()
		);
	}

	public function test_rate_edited_away_from_zero_keeps_its_assignment(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );
		WC_Tax::_update_tax_rate( $this->kct_id, array( 'tax_rate' => '10' ) );

		$this->assertSame( 200, $this->save( array( $this->kkknt_id => 'kkknt' ) )->get_status() );
		$this->assertSame(
			array(
				$this->kct_id   => -1,
				$this->kkknt_id => -2,
			),
			( new Settings() )->exempt_tax_rates()
		);
	}

	public function test_invalid_keys_are_rejected(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );

		foreach ( array( '0', '-5', 'abc' ) as $key ) {
			$this->assertSame( 422, $this->htmx( 'POST', '/settings/tax-rates', array( 'tax_rate_kind' => array( $key => 'kct' ) ) )->get_status(), $key );
			$this->assertSame( array( $this->kct_id => -1 ), ( new Settings() )->exempt_tax_rates(), $key );
		}
	}

	public function test_label_shows_country_and_escapes_the_name(): void {
		$vn = $this->insert_rate( '', '0', '<b>x</b>' );
		WC_Tax::_update_tax_rate( $vn, array( 'tax_rate_country' => 'VN' ) );

		$html = SettingsPage::render_tabs( 'invoicing' );

		$this->assertMatchesRegularExpression( sprintf( '/for="einv-tax-rate-%d"[^>]*>&lt;b&gt;x&lt;\/b&gt; · Tiêu chuẩn · VN</u', $vn ), $html );
		$this->assertMatchesRegularExpression( sprintf( '/for="einv-tax-rate-%d"[^>]*>Miễn thuế A · kct · \*</u', $this->kct_id ), $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
	}

	public function test_stale_assignment_is_listed_with_warning_and_unassign_button(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );
		WC_Tax::_update_tax_rate( $this->kct_id, array( 'tax_rate' => '10' ) );

		$html  = SettingsPage::render_tabs( 'invoicing' );
		$block = $this->stale_block( $html );

		$this->assertStringContainsString( 'Miễn thuế A · kct · 10% · KCT', $block );
		$this->assertMatchesRegularExpression( '#<button[^>]*aria-label="[^"]*Miễn thuế A[^"]*"#u', $block );
		$this->assertStringContainsString( 'Đơn mới dùng thuế suất này sẽ bị chặn', $block );
		$this->assertMatchesRegularExpression( '#<form hx-post="[^"]*settings/tax-rates/unassign"#', $block );
		$this->assertStringContainsString( sprintf( '<input type="hidden" name="rate_id" value="%d"', $this->kct_id ), $block );
		$this->assertStringNotContainsString( sprintf( 'tax_rate_kind[%d]', $this->kct_id ), $html );
	}

	public function test_stale_block_is_shown_when_no_zero_rate_remains(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );
		WC_Tax::_update_tax_rate( $this->kct_id, array( 'tax_rate' => '10' ) );
		WC_Tax::_delete_tax_rate( $this->kkknt_id );
		WC_Tax::_delete_tax_rate( $this->ten_id );

		$html = SettingsPage::render_tabs( 'invoicing' );

		$this->assertStringContainsString( 'data-einv-stale-rates', $html );
		$this->assertStringContainsString( 'Miễn thuế A · kct · 10% · KCT', $html );
		$this->assertStringContainsString( 'Chưa có thuế suất 0%', $html );
	}

	public function test_unassign_removes_only_that_assignment(): void {
		$orphan = $this->insert_rate( 'kct', '0', 'Đã xóa' );
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id, $orphan ), array( $this->kkknt_id ) );
		WC_Tax::_update_tax_rate( $this->kct_id, array( 'tax_rate' => '10' ) );
		WC_Tax::_delete_tax_rate( $orphan );

		$response = $this->htmx( 'POST', '/settings/tax-rates/unassign', array( 'rate_id' => (string) $this->kct_id ) );

		$this->assertSame( 200, $response->get_status() );
		$html = $this->html( $response );
		$this->assertStringContainsString( 'data-einv-status="success"', $html );
		$this->assertStringContainsString( 'Đã bỏ gán thuế suất &quot;Miễn thuế A&quot;. Tải lại trang để cập nhật danh sách.', $html );
		$this->assertEqualsCanonicalizing(
			array(
				$this->kkknt_id => -2,
				$orphan         => -1,
			),
			( new Settings() )->exempt_tax_rates()
		);
	}

	public function test_unassign_accepts_integer_rate_id_from_json(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array( $this->kkknt_id ) );
		WC_Tax::_update_tax_rate( $this->kct_id, array( 'tax_rate' => '10' ) );

		$request = new WP_REST_Request( 'POST', self::NAMESPACE . '/settings/tax-rates/unassign' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'HX-Request', 'true' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'rate_id' => $this->kct_id ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( $this->kkknt_id => -2 ),
			( new Settings() )->exempt_tax_rates()
		);
	}

	public function test_unassign_rejects_invalid_targets(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array( $this->kkknt_id ) );
		WC_Tax::_update_tax_rate( $this->kct_id, array( 'tax_rate' => '10' ) );
		$expected = array(
			$this->kct_id   => -1,
			$this->kkknt_id => -2,
		);

		foreach (
			array(
				array( 'rate_id' => (string) $this->kkknt_id ),
				array( 'rate_id' => (string) $this->ten_id ),
				array( 'rate_id' => '999999' ),
				array( 'rate_id' => 'abc' ),
				array( 'rate_id' => '0' ),
				array(),
			) as $params
		) {
			$this->assertSame( 422, $this->htmx( 'POST', '/settings/tax-rates/unassign', $params )->get_status(), (string) wp_json_encode( $params ) );
			$this->assertSame( $expected, ( new Settings() )->exempt_tax_rates(), (string) wp_json_encode( $params ) );
		}
	}

	public function test_unassign_requires_permission_and_nonce(): void {
		( new Settings() )->save_exempt_tax_rates( array( $this->kct_id ), array() );
		WC_Tax::_update_tax_rate( $this->kct_id, array( 'tax_rate' => '10' ) );

		$this->login_as( 'shop_manager' );
		$this->assertSame( 403, $this->htmx( 'POST', '/settings/tax-rates/unassign', array( 'rate_id' => (string) $this->kct_id ) )->get_status() );
		$this->assertSame( array( $this->kct_id => -1 ), ( new Settings() )->exempt_tax_rates() );

		$this->login_as( 'administrator' );
		$this->assertSame( 403, $this->htmx( 'POST', '/settings/tax-rates/unassign', array( 'rate_id' => (string) $this->kct_id ), 'bad-nonce' )->get_status() );
		$this->assertSame( array( $this->kct_id => -1 ), ( new Settings() )->exempt_tax_rates() );
	}

	public function test_no_stale_block_when_nothing_is_stale(): void {
		$orphan = $this->insert_rate( 'kct', '0', 'Đã xóa' );
		( new Settings() )->save_exempt_tax_rates( array( $orphan ), array( $this->kkknt_id ) );
		WC_Tax::_delete_tax_rate( $orphan );

		$this->assertStringNotContainsString( 'data-einv-stale-rates', SettingsPage::render_tabs( 'invoicing' ) );
	}

	/**
	 * The stale-assignment block of the invoicing tab, from its marker to the end of its card.
	 *
	 * @param string $html Invoicing tab HTML.
	 */
	private function stale_block( string $html ): string {
		$start = strpos( $html, 'data-einv-stale-rates' );
		$this->assertNotFalse( $start, 'Stale block missing' );

		return substr( $html, $start, strpos( $html, '</section>', $start ) - $start );
	}

	/**
	 * Posts `tax_rate_kind` as the form would.
	 *
	 * @param array<int, string> $kinds Rate ID => kind.
	 */
	private function save( array $kinds ): \WP_REST_Response {
		return $this->htmx( 'POST', '/settings/tax-rates', array( 'tax_rate_kind' => $kinds ) );
	}

	/**
	 * Inserts a tax rate and returns its ID.
	 *
	 * @param string $class_slug Tax class slug, '' for standard.
	 * @param string $percent    Rate percentage.
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
