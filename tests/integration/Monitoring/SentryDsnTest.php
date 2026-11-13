<?php
/**
 * Sentry DSN parsing.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use LTTech\EinvoiceMatbao\Monitoring\SentryDsn;
use PHPUnit\Framework\TestCase;

/**
 * A DSN is accepted only when it carries a key, a host and a numeric project id; its label never exposes the key.
 */
final class SentryDsnTest extends TestCase {

	public function test_parses_valid_dsn(): void {
		$dsn = SentryDsn::parse( 'https://abc123@sentry.example.com/3' );

		$this->assertNotNull( $dsn );
		$this->assertSame( 'sentry.example.com', $dsn->host );
		$this->assertSame( 3, $dsn->project_id );
		$this->assertSame( 'sentry.example.com · project 3', $dsn->label() );
		$this->assertSame( 'https://abc123@sentry.example.com/3', $dsn->value() );
	}

	public function test_trims_whitespace(): void {
		$dsn = SentryDsn::parse( "  https://k@h.test/7\n" );

		$this->assertNotNull( $dsn );
		$this->assertSame( 7, $dsn->project_id );
		$this->assertSame( 'https://k@h.test/7', $dsn->value() );
	}

	public function test_accepts_port_and_path_prefix(): void {
		$dsn = SentryDsn::parse( 'http://k@h.test:9000/sentry/12' );

		$this->assertNotNull( $dsn );
		$this->assertSame( 'h.test', $dsn->host );
		$this->assertSame( 12, $dsn->project_id );
	}

	/**
	 * Anything but a complete DSN is refused.
	 *
	 * @dataProvider invalid_dsns
	 *
	 * @param string $raw Candidate DSN.
	 */
	public function test_rejects_invalid( string $raw ): void {
		$this->assertNull( SentryDsn::parse( $raw ) );
	}

	/**
	 * Malformed DSNs.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_dsns(): array {
		return array(
			'not a url'           => array( 'not-a-dsn' ),
			'wrong scheme'        => array( 'ftp://k@h/1' ),
			'missing key'         => array( 'https://h.test/1' ),
			'non numeric project' => array( 'https://k@h.test/abc' ),
			'empty'               => array( '' ),
		);
	}

	public function test_label_never_contains_key(): void {
		$dsn = SentryDsn::parse( 'https://abc123@sentry.example.com/3' );

		$this->assertNotNull( $dsn );
		$this->assertStringNotContainsString( 'abc123', $dsn->label() );
	}
}
