<?php
/**
 * Cron monitor slug derivation.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use LTTech\EinvoiceMatbao\Monitoring\MonitorSlug;
use PHPUnit\Framework\TestCase;

/**
 * The slug is stable per host, always carries a hash and never exceeds 50 characters.
 */
final class MonitorSlugTest extends TestCase {

	public function test_short_host(): void {
		$this->assertSame(
			'einvoice-matbao-recovery-shop-example-vn-' . substr( sha1( 'shop.example.vn' ), 0, 8 ),
			MonitorSlug::for_host( 'shop.example.vn' )
		);
	}

	public function test_collision_is_avoided(): void {
		$this->assertNotSame( MonitorSlug::for_host( 'a.b.example' ), MonitorSlug::for_host( 'a-b.example' ) );
	}

	public function test_long_host(): void {
		$host = str_repeat( 'abcd.', 16 );
		$slug = MonitorSlug::for_host( $host );

		$this->assertSame( 80, strlen( $host ) );
		$this->assertLessThanOrEqual( 50, strlen( $slug ) );
		$this->assertStringEndsWith( '-' . substr( sha1( $host ), 0, 8 ), $slug );
	}

	/**
	 * Hosts with a port or non-ASCII characters still give a valid, stable slug.
	 *
	 * @dataProvider unusual_hosts
	 *
	 * @param string $host            Site host.
	 * @param string $expected_prefix Slug up to the hash.
	 */
	public function test_port_and_idn_hosts( string $host, string $expected_prefix ): void {
		$this->assertMatchesRegularExpression( '/^einvoice-matbao-recovery-[a-z0-9-]+-[0-9a-f]{8}$/', MonitorSlug::for_host( $host ) );
		$this->assertSame( $expected_prefix . substr( sha1( strtolower( $host ) ), 0, 8 ), MonitorSlug::for_host( $host ) );
	}

	/**
	 * Unusual hosts.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function unusual_hosts(): array {
		return array(
			'port'           => array( 'shop.vn:8443', 'einvoice-matbao-recovery-shop-vn-8443-' ),
			'idn'            => array( 'cửahàng.vn', 'einvoice-matbao-recovery-c-ah-ng-vn-' ),
			'only non-ascii' => array( 'ửữ', 'einvoice-matbao-recovery-site-' ),
			'uppercase'      => array( 'Shop.VN', 'einvoice-matbao-recovery-shop-vn-' ),
		);
	}

	public function test_case_does_not_change_slug(): void {
		$this->assertSame( MonitorSlug::for_host( 'shop.vn' ), MonitorSlug::for_host( 'SHOP.vn' ) );
	}
}
