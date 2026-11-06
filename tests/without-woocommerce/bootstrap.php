<?php
/**
 * Bootstrap for the suite where WooCommerce is NOT loaded.
 *
 * @package EinvoiceMatbao
 */

// wp-phpunit reads its config path from the environment.
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . dirname( __DIR__ ) . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// The autoloader points WP_PHPUNIT__DIR at the locked wp-phpunit; the minimum platform overrides it with the release matching core.
$einv_wp_phpunit_dir = getenv( 'EINV_WP_PHPUNIT_DIR' );
if ( false !== $einv_wp_phpunit_dir && '' !== $einv_wp_phpunit_dir ) {
	if ( ! is_file( $einv_wp_phpunit_dir . '/includes/functions.php' ) ) {
		fwrite( STDERR, 'EINV_WP_PHPUNIT_DIR does not contain wp-phpunit: ' . $einv_wp_phpunit_dir . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		exit( 1 );
	}
	putenv( 'WP_PHPUNIT__DIR=' . $einv_wp_phpunit_dir ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
}

require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/einvoice-matbao.php';
	}
);

require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';
