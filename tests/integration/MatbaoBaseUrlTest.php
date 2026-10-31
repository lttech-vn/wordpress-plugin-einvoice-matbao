<?php
/**
 * Base URL resolution of the Mat Bao client.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration;

use LTTech\EinvoiceMatbao\Matbao\MatbaoCredentials;
use PHPUnit\Framework\TestCase;

/**
 * `EINVOICE_MATBAO_BASE_URL` replaces the URL of every environment; garbage falls back to the environment's URL.
 */
final class MatbaoBaseUrlTest extends TestCase {

	public function test_without_override_each_environment_uses_its_own_url(): void {
		$this->assertSame( 'https://demo-api-hddt.matbao.in:11443', MatbaoCredentials::resolve_base_url( 'demo', null ) );
		$this->assertSame( 'https://api-hddt.matbao.in:11443', MatbaoCredentials::resolve_base_url( 'production', null ) );
	}

	public function test_override_applies_to_every_environment_and_drops_trailing_slash(): void {
		$this->assertSame( 'http://matbao-fake:8080', MatbaoCredentials::resolve_base_url( 'demo', 'http://matbao-fake:8080/' ) );
		$this->assertSame( 'http://matbao-fake:8080/base', MatbaoCredentials::resolve_base_url( 'production', ' http://matbao-fake:8080/base ' ) );
	}

	/**
	 * An override that is not an http(s) URL is ignored.
	 *
	 * @dataProvider invalid_overrides
	 *
	 * @param mixed $override Value that is not an http(s) URL.
	 */
	public function test_invalid_override_falls_back_to_the_environment_url( mixed $override ): void {
		$this->assertSame( MatbaoCredentials::BASE_URLS['demo'], MatbaoCredentials::resolve_base_url( 'demo', $override ) );
	}

	/**
	 * Values the constant must not accept.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function invalid_overrides(): array {
		return array(
			'empty'        => array( '' ),
			'no scheme'    => array( 'matbao-fake:8080' ),
			'other scheme' => array( 'ftp://matbao-fake' ),
			'whitespace'   => array( 'http://matbao fake' ),
			'not a string' => array( 123 ),
			'false'        => array( false ),
		);
	}
}
