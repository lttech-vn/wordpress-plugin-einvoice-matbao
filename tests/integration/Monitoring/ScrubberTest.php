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
