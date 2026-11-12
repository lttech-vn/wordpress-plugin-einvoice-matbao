<?php
/**
 * Plugin Name: Smoke conflicting libraries
 * Description: Stands in for another plugin that ships unscoped Sentry and Guzzle PSR-7 classes.
 *
 * Installed as a mu-plugin by scripts/smoke-zip.sh, so these classes are declared before the
 * plugin under test loads. A release zip that still uses the global names breaks on them.
 *
 * @package EinvoiceMatbao
 */

namespace {
	// Composer skips an autoload `files` entry whose identifier another autoloader already
	// loaded; this is the identifier of an unscoped sentry/sentry src/functions.php.
	$GLOBALS['__composer_autoload_files']['fb4ca2d97fe7ba6af750497425204e70'] = true;
}

namespace Sentry {
	/**
	 * Empty stand-in for the SDK entry point.
	 */
	final class SentrySdk {
	}
}

namespace GuzzleHttp\Psr7 {
	/**
	 * Empty stand-in for the PSR-7 request.
	 */
	final class Request {
	}
}
