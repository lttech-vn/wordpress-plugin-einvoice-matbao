<?php
/**
 * Gate for monitoring override filters.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Monitoring;

use LTTech\EinvoiceMatbao\Monitoring\MonitoringOverrides;
use PHPUnit\Framework\TestCase;

/**
 * Overrides are allowed only under the test suite or in local/development environments.
 */
final class MonitoringOverridesTest extends TestCase {

	/**
	 * Cases of (tests domain defined, environment, expected).
	 *
	 * @return array<string, array{bool, string, bool}>
	 */
	public static function matrix(): array {
		return array(
			'production'        => array( false, 'production', false ),
			'staging'           => array( false, 'staging', false ),
			'local'             => array( false, 'local', true ),
			'development'       => array( false, 'development', true ),
			'tests in prod env' => array( true, 'production', true ),
		);
	}

	/**
	 * Covers every environment and test-domain combination.
	 *
	 * @dataProvider matrix
	 *
	 * @param bool   $tests    Whether WP_TESTS_DOMAIN is defined.
	 * @param string $env      Environment type.
	 * @param bool   $expected Expected result.
	 */
	public function test_matrix( bool $tests, string $env, bool $expected ): void {
		$this->assertSame( $expected, MonitoringOverrides::allowed( $tests, $env ) );
	}
}
