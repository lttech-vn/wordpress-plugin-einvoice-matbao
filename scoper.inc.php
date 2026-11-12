<?php
/**
 * PHP-Scoper configuration for the release zip (run by scripts/scope-vendor.sh, never by WordPress).
 *
 * Moves every bundled library under LTTech\EinvoiceMatbao\Vendor so another plugin that ships
 * its own Sentry SDK, Guzzle PSR-7 or Symfony component cannot clash with ours. The plugin's
 * own namespace and every WordPress, WooCommerce and Action Scheduler symbol keep their names.
 *
 * Environment:
 *   EINV_SCOPER_SOURCE   Directory holding the production `src/` and `vendor/` to scope.
 *   EINV_SCOPER_EXCLUDES JSON from scripts/scoper-excludes.php: {classes, functions, constants}.
 *
 * @package EinvoiceMatbao
 */

// phpcs:disable WordPress -- Build-time configuration, loaded by the PHP-Scoper phar only.

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

$einv_source   = (string) getenv( 'EINV_SCOPER_SOURCE' );
$einv_excludes = json_decode( (string) file_get_contents( (string) getenv( 'EINV_SCOPER_EXCLUDES' ) ), true );
if ( ! is_dir( $einv_source . '/vendor' ) || ! is_array( $einv_excludes ) ) {
	throw new RuntimeException( 'Set EINV_SCOPER_SOURCE and EINV_SCOPER_EXCLUDES (see scripts/scope-vendor.sh).' );
}

// Namespaced stub classes also exclude their vendor namespace (for example
// Automattic\WooCommerce), so classes newer than the stubs keep their names too.
$einv_stub_namespaces = array();
foreach ( $einv_excludes['classes'] as $einv_class ) {
	$einv_parts = explode( '\\', $einv_class );
	if ( count( $einv_parts ) > 2 ) {
		$einv_stub_namespaces[ $einv_parts[0] . '\\' . $einv_parts[1] ] = true;
	}
}

/**
 * Replaces one literal in one vendor file, failing the build if the literal is gone (the SDK
 * changed and the patch needs a fresh look).
 *
 * @param string $file_path File being scoped.
 * @param string $suffix    Path suffix the patch applies to.
 * @param string $contents  Scoped contents.
 * @param string $search    Literal to replace.
 * @param string $replace   Replacement.
 */
$einv_patch = static function ( string $file_path, string $suffix, string $contents, string $search, string $replace ): string {
	if ( ! str_ends_with( $file_path, $suffix ) ) {
		return $contents;
	}
	if ( ! str_contains( $contents, $search ) ) {
		throw new RuntimeException( "Scoper patch for $suffix no longer matches: $search" );
	}

	return str_replace( $search, $replace, $contents );
};

return array(
	'prefix'                  => 'LTTech\\EinvoiceMatbao\\Vendor',
	'php-version'             => '8.2',
	'finders'                 => array(
		Finder::create()->files()->in( $einv_source . '/src' ),
		Finder::create()
			->files()
			->ignoreVCS( true )
			->name( array( '*.php', 'LICENSE', 'composer.json', 'installed.json' ) )
			// `composer dump-autoload` regenerates the autoloader after scoping, but not the
			// installed-versions runtime its classmap points at.
			->notPath( '#^composer/(?!(installed\.json|installed\.php|InstalledVersions\.php)$)#' )
			->exclude( array( 'doc', 'docs', 'test', 'tests', 'Tests' ) )
			->in( $einv_source . '/vendor' ),
		Finder::create()->append( array( $einv_source . '/composer.json' ) ),
	),
	// String class names PHP-Scoper cannot recognise as such. Patchers see the scoped code as
	// PHP-Scoper prints it: single-quoted strings keep one backslash unless it ends the string.
	'patchers'                => array(
		static fn( string $file_path, string $prefix, string $contents ): string => $einv_patch(
			$file_path,
			'sentry/sentry/src/Options.php',
			$contents,
			"'Sentry\\Integration\\IntegrationInterface[]'",
			"'" . $prefix . "\\Sentry\\Integration\\IntegrationInterface[]'"
		),
		static fn( string $file_path, string $prefix, string $contents ): string => $einv_patch(
			$file_path,
			'sentry/sentry/src/FrameBuilder.php',
			$contents,
			"'Sentry\\\\'",
			"'" . str_replace( '\\', '\\\\', $prefix ) . "\\\\Sentry\\\\'"
		),
	),
	'exclude-namespaces'      => array(
		// The plugin itself, but not the prefix namespace nested inside it.
		'~^LTTech\\\\EinvoiceMatbao(?!\\\\Vendor(\\\\|$))(\\\\.*)?$~',
		// Composer's shared runtime (InstalledVersions) stays global, as Composer expects.
		'Composer',
		...array_keys( $einv_stub_namespaces ),
	),
	'exclude-classes'         => $einv_excludes['classes'],
	'exclude-functions'       => $einv_excludes['functions'],
	'exclude-constants'       => array(
		...$einv_excludes['constants'],
		// wp-config and core constants are define()d at runtime, so the stubs miss most of them.
		'~^[A-Z][A-Z0-9_]*$~',
	),
	'expose-global-constants' => false,
	'expose-global-classes'   => false,
	'expose-global-functions' => false,
);
