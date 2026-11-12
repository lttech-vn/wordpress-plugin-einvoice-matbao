<?php
/**
 * Gate for the test-only monitoring filters.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Monitoring;

/**
 * Decides whether the monitoring override filters may run. Same rule as the encryption key
 * filter: only under the test suite or in `local`/`development` environments, so other plugins
 * cannot reconfigure monitoring in production.
 */
final class MonitoringOverrides {

	/**
	 * Whether override filters may be applied.
	 *
	 * @param bool   $tests_domain_defined Whether `WP_TESTS_DOMAIN` is defined.
	 * @param string $environment_type     Value of wp_get_environment_type().
	 */
	public static function allowed( bool $tests_domain_defined, string $environment_type ): bool {
		return $tests_domain_defined || in_array( $environment_type, array( 'local', 'development' ), true );
	}

	/**
	 * Whether override filters may be applied in the current request.
	 */
	public static function allowed_now(): bool {
		return self::allowed( defined( 'WP_TESTS_DOMAIN' ), wp_get_environment_type() );
	}
}
