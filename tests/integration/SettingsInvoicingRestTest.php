<?php
/**
 * Template selection, MTChieu prefix and walk-in buyer name through the settings REST routes.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Admin\SettingsController;
use LTTech\EinvoiceMatbao\Settings;
use LTTech\EinvoiceMatbao\Tests\Integration\Support\SettingsRestTestCase;

/**
 * Invoicing settings: default template from Mat Bao, reference prefix and walk-in buyer name.
 */
final class SettingsInvoicingRestTest extends SettingsRestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->login_as( 'administrator' );
		$this->with_encryption_key();
	}

	public function test_defaults_before_anything_is_saved(): void {
		$settings = new Settings();

		$this->assertSame( 'Khách hàng không lấy hóa đơn', $settings->walk_in_buyer_name() );
		$this->assertSame( '', $settings->reference_prefix() );
		$this->assertNull( $settings->default_template() );
	}

	public function test_templates_list_shows_only_vat_templates_from_matbao(): void {
		$this->save_matbao_credentials();
		$this->fake_matbao_login_ok( 'fake-token-xyz' );
		$this->fake_matbao_templates();

		$response = $this->htmx( 'GET', '/settings/templates' );

		$this->assertSame( 200, $response->get_status() );
		$html = $this->html( $response );
		$this->assertStringContainsString( 'value="1|C26TAT"', $html );
		$this->assertStringContainsString( 'value="1|C26TXX"', $html );
		$this->assertStringNotContainsString( 'C26TMB', $html );

		$calls = $this->http->requests_to( 'GET /api/invoice/templates' );
		$this->assertCount( 1, $calls );
		$this->assertStringContainsString( 'year=' . ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Ho_Chi_Minh' ) ) )->format( 'Y' ), $calls[0]['url'] );
		$this->assertSame( 'Bearer fake-token-xyz', $calls[0]['headers']['Authorization'] );
	}

	public function test_template_year_follows_vietnam_time_whatever_the_site_timezone(): void {
		update_option( 'timezone_string', 'UTC' );
		// 2025-12-31 18:00 UTC is already 2026-01-01 01:00 in Vietnam (UTC+7).
		$instant = new \DateTimeImmutable( '2025-12-31T18:00:00', new \DateTimeZone( 'UTC' ) );

		$this->assertSame( 2026, SettingsController::template_year( $instant ) );
		$this->assertSame( 2025, SettingsController::template_year( $instant->modify( '-2 hours' ) ) );
	}

	public function test_template_calls_reuse_the_cached_token(): void {
		$this->save_matbao_credentials();
		$this->fake_matbao_login_ok();
		$this->fake_matbao_templates();

		$this->htmx( 'GET', '/settings/templates' );
		$this->htmx( 'GET', '/settings/templates' );

		$this->assertCount( 1, $this->http->requests_to( 'POST /api/auth/login' ) );
		$this->assertCount( 2, $this->http->requests_to( 'GET /api/invoice/templates' ) );
	}

	public function test_expired_token_is_refreshed_once_on_401(): void {
		$this->save_matbao_credentials();
		$this->fake_matbao_login_ok();
		$this->fake_matbao_templates();
		$this->htmx( 'GET', '/settings/templates' );

		$templates_calls = 0;
		$this->http->respond_with(
			'GET /api/invoice/templates',
			static function () use ( &$templates_calls ): array {
				++$templates_calls;
				if ( 1 === $templates_calls ) {
					return \LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp::json_response( 401, array( 'message' => 'Unauthorized' ) );
				}
				return \LTTech\EinvoiceMatbao\Tests\Integration\Support\FakeHttp::json_response(
					200,
					array(
						'errorCode' => 200,
						'success'   => true,
						'data'      => array(
							array(
								'thDon'    => 'Hóa đơn giá trị gia tăng',
								'khmshDon' => '1',
								'khhDon'   => 'C26TAT',
								'sLuong'   => 1,
								'cLai'     => 1,
							),
						),
					)
				);
			}
		);

		$html = $this->html( $this->htmx( 'GET', '/settings/templates' ) );

		$this->assertStringContainsString( 'value="1|C26TAT"', $html );
		$this->assertCount( 2, $this->http->requests_to( 'POST /api/auth/login' ) );
	}

	public function test_templates_list_without_credentials_explains_why(): void {
		$response = $this->htmx( 'GET', '/settings/templates' );

		$this->assertSame( 422, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-status="error"', $this->html( $response ) );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_selected_template_is_saved_after_verifying_it_with_matbao(): void {
		$this->save_matbao_credentials();
		$this->fake_matbao_login_ok();
		$this->fake_matbao_templates();

		$response = $this->htmx( 'POST', '/settings/template', array( 'template' => '1|C26TAT' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-status="success"', $this->html( $response ) );
		$this->assertSame(
			array(
				'form'   => '1',
				'series' => 'C26TAT',
			),
			( new Settings() )->default_template()
		);
	}

	public function test_template_not_offered_by_matbao_is_refused(): void {
		$this->save_matbao_credentials();
		$this->fake_matbao_login_ok();
		$this->fake_matbao_templates();

		$response = $this->htmx( 'POST', '/settings/template', array( 'template' => '2|C26TMB' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertNull( ( new Settings() )->default_template() );
	}

	public function test_template_with_no_remaining_numbers_is_refused(): void {
		$this->save_matbao_credentials();
		$this->fake_matbao_login_ok();
		$this->fake_matbao_templates();

		$response = $this->htmx( 'POST', '/settings/template', array( 'template' => '1|C26TXX' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertNull( ( new Settings() )->default_template() );
	}

	public function test_reference_prefix_and_walk_in_name_are_saved(): void {
		$response = $this->htmx(
			'POST',
			'/settings/invoicing',
			array(
				'reference_prefix'   => 'SHOP1',
				'walk_in_buyer_name' => 'Khách lẻ',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$settings = new Settings();
		$this->assertSame( 'SHOP1', $settings->reference_prefix() );
		$this->assertSame( 'Khách lẻ', $settings->walk_in_buyer_name() );
	}

	public function test_empty_walk_in_name_falls_back_to_default(): void {
		$this->htmx(
			'POST',
			'/settings/invoicing',
			array(
				'reference_prefix'   => '',
				'walk_in_buyer_name' => '   ',
			)
		);

		$this->assertSame( 'Khách hàng không lấy hóa đơn', ( new Settings() )->walk_in_buyer_name() );
	}

	public function test_reference_prefix_that_leaves_too_little_room_for_order_ids_is_refused(): void {
		// 20 max - 2 for "WC" leaves 18; the prefix may use at most 8 so order IDs up to 10 digits fit.
		$response = $this->htmx( 'POST', '/settings/invoicing', array( 'reference_prefix' => 'ABCDEFGHI' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertStringContainsString( 'data-einv-status="error"', $this->html( $response ) );
		$this->assertSame( '', ( new Settings() )->reference_prefix() );
	}

	public function test_reference_prefix_with_unsafe_characters_is_refused(): void {
		$response = $this->htmx( 'POST', '/settings/invoicing', array( 'reference_prefix' => 'A B<' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( '', ( new Settings() )->reference_prefix() );
	}

	public function test_walk_in_name_longer_than_matbao_limit_is_refused(): void {
		$response = $this->htmx(
			'POST',
			'/settings/invoicing',
			array(
				'reference_prefix'   => '',
				'walk_in_buyer_name' => str_repeat( 'a', 401 ),
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'Khách hàng không lấy hóa đơn', ( new Settings() )->walk_in_buyer_name() );
	}

	public function test_walk_in_name_is_escaped_in_the_page(): void {
		$this->htmx(
			'POST',
			'/settings/invoicing',
			array(
				'reference_prefix'   => '',
				'walk_in_buyer_name' => '<script>x</script>',
			)
		);

		$page = get_echo( array( \LTTech\EinvoiceMatbao\Admin\SettingsPage::class, 'render' ) );

		$this->assertStringNotContainsString( '<script>x</script>', $page );
	}
}
