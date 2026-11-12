<?php
/**
 * Scrubbing of personal data before anything leaves the site.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use LTTech\EinvoiceMatbao\Matbao\MatbaoCredentials;
use LTTech\EinvoiceMatbao\Monitoring\Scrubber;
use PHPUnit\Framework\TestCase;

/**
 * Known buyer values are masked first, then patterns; tags pass through an allowlist. Buyer data mirrors the fake
 * snapshots used by the draft-invoice tests.
 */
final class ScrubberTest extends TestCase {

	private const F = '[filtered]';

	public function test_masks_patterns(): void {
		$scrubber = new Scrubber();

		$this->assertSame(
			'login failed for [filtered] with [filtered]',
			$scrubber->text( 'login failed for 0312345678-001 with Bearer abc.def' )
		);
		$this->assertSame( self::F, $scrubber->text( 'nguyen.van.a@example.vn' ) );
		$this->assertSame( self::F, $scrubber->text( '012345678901' ) );
		$this->assertSame( self::F, $scrubber->text( str_repeat( 'aB3', 13 ) . 'x' ) );
		$this->assertSame( 'order 12345 failed', $scrubber->text( 'order 12345 failed' ), 'Short numbers are not personal data.' );
	}

	public function test_long_identifiers_without_digits_are_kept(): void {
		$scrubber = new Scrubber();

		foreach ( array( 'einvoice_matbao_recover_stuck_invoices', 'einvoice_matbao_invoice_needs_attention' ) as $hook ) {
			$this->assertSame( $hook, $scrubber->text( $hook ) );
			$this->assertSame( 'run ' . $hook . ' failed', $scrubber->text( 'run ' . $hook . ' failed' ) );
		}
	}

	public function test_long_tokens_with_digits_are_masked(): void {
		$scrubber = new Scrubber();

		$this->assertSame( self::F, $scrubber->text( str_repeat( 'aB3', 12 ) ) );
		$this->assertSame( 'token ' . self::F, $scrubber->text( 'token ' . sha1( 'einvoice' ) ) );
		$this->assertSame( self::F, $scrubber->text( base64_encode( str_repeat( 'k', 30 ) ) . '9' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- builds a token-shaped fixture.
		$this->assertSame( self::F, $scrubber->text( str_repeat( 'x', 31 ) . '1' ), 'One digit is enough.' );
	}

	public function test_route_patterns_are_kept(): void {
		$scrubber = new Scrubber();

		foreach ( array( '/einvoice-matbao/v1/settings/monitoring/test', '/einvoice-matbao/v1/tabs/(?P<tab>[^/]+)' ) as $route ) {
			$this->assertSame( $route, $scrubber->text( $route ) );
		}
	}

	public function test_base64_with_slashes_is_masked_per_segment(): void {
		$scrubber = new Scrubber();
		$secret   = 'abcdefgh12345678/ABCDEFGH12345678/xyzXYZ0123456789ab';

		$this->assertSame( 44, strlen( 'aaaaaaaaaaaaaaaaaaaa/bbbbbbbbbbbbbbbbbbbbbb1' ) );
		$this->assertSame( 'aaaaaaaaaaaaaaaaaaaa/bbbbbbbbbbbbbbbbbbbbbb1', $scrubber->text( 'aaaaaaaaaaaaaaaaaaaa/bbbbbbbbbbbbbbbbbbbbbb1' ), 'Segments under 32 characters stay.' );
		$this->assertSame( self::F . '/' . self::F . '/' . self::F, $scrubber->text( str_repeat( 'aB3', 12 ) . '/' . str_repeat( 'cD4', 12 ) . '/' . str_repeat( 'eF5', 12 ) ) );
		$this->assertSame( 'a/' . self::F, $scrubber->text( 'a/' . str_repeat( 'aB3', 12 ) ) );
		$this->assertStringNotContainsString( 'xyzXYZ0123456789ab', $scrubber->text( 'x/' . $secret . str_repeat( 'q1', 20 ) ) );
	}

	public function test_urls(): void {
		$scrubber = new Scrubber();
		$matbao   = MatbaoCredentials::BASE_URLS['demo'];

		$this->assertSame( 'see [filtered]', $scrubber->text( 'see https://hooks.lark.example/hook/xyz?a=1' ) );
		$this->assertSame(
			'POST https://demo-api-hddt.matbao.in failed',
			$scrubber->text( 'POST ' . $matbao . '/api/x?y failed' )
		);
		$this->assertSame( 'https://api-hddt.matbao.in', $scrubber->text( MatbaoCredentials::BASE_URLS['production'] . '/api/x' ) );
	}

	public function test_known_values_case_insensitive(): void {
		$scrubbed = ( new Scrubber() )
			->with_known_values( array( 'Nguyễn Văn A', '12 Lê Lợi, Q1' ) )
			->text( 'Buyer NGUYỄN VĂN A at 12 lê lợi, q1' );

		$this->assertSame( 'Buyer [filtered] at [filtered]', $scrubbed );
	}

	public function test_known_values_are_masked_before_patterns(): void {
		$scrubbed = ( new Scrubber() )->with_known_values( array( 'Công ty TNHH 0900000001' ) )->text( 'Công ty TNHH 0900000001 ok' );

		$this->assertSame( self::F . ' ok', $scrubbed );
	}

	public function test_short_known_values_are_ignored(): void {
		$scrubber = ( new Scrubber() )->with_known_values( array( 'A', 'Hà' ) );

		$this->assertSame( 'Hà Nội A', $scrubber->text( 'Hà Nội A' ) );
	}

	public function test_with_known_values_does_not_mutate_original(): void {
		$original = new Scrubber();
		$original->with_known_values( array( 'Nguyễn Văn A' ) );

		$this->assertSame( 'Nguyễn Văn A', $original->text( 'Nguyễn Văn A' ) );
	}

	public function test_constructor_accepts_known_values(): void {
		$this->assertSame( self::F, ( new Scrubber( array( 'Trần Thị B' ) ) )->text( 'trần thị b' ) );
	}

	/**
	 * Buyer values taken from the draft-invoice fixtures (DraftInvoiceBuyerSnapshotTest).
	 *
	 * @return array<string, array{array<int, string>, string}>
	 */
	public static function buyer_fixtures(): array {
		return array(
			'business buyer'   => array(
				array( 'Doanh nghiệp Thử', '0000000000-001', 'Số 2 Đường Thử, Quận Thử', 'Nguyễn Văn A', 'ketoan@example.vn', '0900000001' ),
				'Draft for Doanh nghiệp Thử (MST 0000000000-001) at Số 2 Đường Thử, Quận Thử; contact Nguyễn Văn A <ketoan@example.vn> 0900000001',
			),
			'individual buyer' => array(
				array( 'Nguyễn Văn A', 'Số 1 Đường Thử, Quận Thử', '000000001234', '0900000001' ),
				'NMua_CCCDan 000000001234 invalid for NGUYỄN VĂN A, số 1 đường thử, quận thử, tel 0900000001',
			),
		);
	}

	/**
	 * Every fixture value is masked wherever it appears.
	 *
	 * @dataProvider buyer_fixtures
	 *
	 * @param array<int, string> $known   Buyer values of the order.
	 * @param string             $message Message that embeds them.
	 */
	public function test_fixture_buyer_values_are_filtered( array $known, string $message ): void {
		$scrubbed = ( new Scrubber() )->with_known_values( $known )->text( $message );

		foreach ( $known as $value ) {
			$this->assertStringNotContainsStringIgnoringCase( $value, $scrubbed );
		}
		$this->assertStringContainsString( self::F, $scrubbed );
	}

	/**
	 * Patterns alone catch identifiers even when no known values are loaded.
	 */
	public function test_fixture_identifiers_are_filtered_by_pattern_alone(): void {
		$scrubbed = ( new Scrubber() )->text( 'MST 0000000000-001, CCCD 000000001234, mail ketoan@example.vn' );

		$this->assertSame( 'MST [filtered], CCCD [filtered], mail [filtered]', $scrubbed );
	}

	public function test_value_recurses(): void {
		$this->assertSame(
			array(
				'a'  => array( 'b' => self::F ),
				'n'  => 5,
				'ok' => true,
			),
			( new Scrubber() )->value(
				array(
					'a'  => array( 'b' => 'x@y.vn' ),
					'n'  => 5,
					'ok' => true,
				)
			)
		);
	}

	public function test_tags_allowlist(): void {
		$this->assertSame(
			array(
				'site'     => 's',
				'order_id' => '5',
			),
			( new Scrubber() )->tags(
				array(
					'site'     => 's',
					'foo'      => 'x',
					'order_id' => '5',
				)
			)
		);
	}

	public function test_tag_values_are_scrubbed(): void {
		$this->assertSame( array( 'error_code' => self::F ), ( new Scrubber() )->tags( array( 'error_code' => 'a@b.vn' ) ) );
	}
}
