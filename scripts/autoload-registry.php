<?php
/**
 * Gives the scoped Composer autoloader its own registry of loaded `files` entries.
 *
 *   php scripts/autoload-registry.php <vendor/composer dir>
 *
 * Composer skips an autoload `files` entry whose identifier any autoloader on the site already
 * loaded ($GLOBALS['__composer_autoload_files']). The identifiers do not change with the scoper
 * prefix, so another plugin's unscoped sentry/sentry would make ours skip the scoped
 * src/functions.php. Run by scripts/scope-vendor.sh after `composer dump-autoload`; any
 * deviation from the expected Composer output fails the build (exit 1).
 *
 * @package EinvoiceMatbao
 */

// phpcs:disable WordPress -- Build-time CLI script, never loaded by WordPress.

const AUTOLOAD_REGISTRY_OLD = '__composer_autoload_files';
const AUTOLOAD_REGISTRY_NEW = '__einvoice_matbao_autoload_files';

// autoload_real.php reads and then sets the registry entry once each.
const AUTOLOAD_REGISTRY_USES = 2;

/**
 * Stops the build with a message.
 *
 * @param string $message What went wrong.
 */
function autoload_registry_fail( string $message ): never {
	fwrite( STDERR, "autoload-registry: $message\n" );
	exit( 1 );
}

/**
 * Renames the registry in autoload_real.php, checking the read, the replacement count and the write.
 *
 * @param string $file autoload_real.php.
 */
function autoload_registry_rename( string $file ): void {
	$code = @file_get_contents( $file );
	if ( false === $code ) {
		autoload_registry_fail( "cannot read $file" );
	}

	$renamed = str_replace( AUTOLOAD_REGISTRY_OLD, AUTOLOAD_REGISTRY_NEW, $code, $count );
	if ( AUTOLOAD_REGISTRY_USES !== $count ) {
		autoload_registry_fail( sprintf( 'expected %d uses of %s in %s, found %d; review the rename for this Composer version', AUTOLOAD_REGISTRY_USES, AUTOLOAD_REGISTRY_OLD, $file, $count ) );
	}

	if ( strlen( $renamed ) !== @file_put_contents( $file, $renamed ) ) {
		autoload_registry_fail( "cannot write $file" );
	}
}

/**
 * Checks that no file under vendor/composer still uses the shared registry and that the
 * autoloader uses the plugin's own.
 *
 * @param string $dir vendor/composer directory.
 */
function autoload_registry_verify( string $dir ): void {
	$files = glob( $dir . '/*' );
	if ( false === $files || array() === $files ) {
		autoload_registry_fail( "no files in $dir" );
	}

	$uses_new = false;
	foreach ( $files as $file ) {
		if ( ! is_file( $file ) ) {
			continue;
		}
		$code = @file_get_contents( $file );
		if ( false === $code ) {
			autoload_registry_fail( "cannot read $file" );
		}
		if ( str_contains( $code, AUTOLOAD_REGISTRY_OLD ) ) {
			autoload_registry_fail( "$file still uses " . AUTOLOAD_REGISTRY_OLD );
		}
		$uses_new = $uses_new || str_contains( $code, AUTOLOAD_REGISTRY_NEW );
	}

	if ( ! $uses_new ) {
		autoload_registry_fail( "no file in $dir uses " . AUTOLOAD_REGISTRY_NEW );
	}
}

if ( PHP_SAPI !== 'cli' || 2 !== $argc ) {
	fwrite( STDERR, "usage: php autoload-registry.php <vendor/composer dir>\n" );
	exit( 2 );
}

$composer_dir = rtrim( $argv[1], '/' );
autoload_registry_rename( $composer_dir . '/autoload_real.php' );
autoload_registry_verify( $composer_dir );
echo 'autoload-registry: ' . AUTOLOAD_REGISTRY_NEW . " in place.\n";
