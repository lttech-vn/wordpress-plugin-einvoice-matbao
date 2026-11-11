<?php
/**
 * Fake Sentry ingest for scripts/smoke-zip.sh, run with the PHP built-in server.
 *
 * Saves every request body under /tmp/sentry-sink/ and answers 200, like a real ingest.
 *
 * @package EinvoiceMatbao
 */

$dir = '/tmp/sentry-sink';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0777, true );
}

$name = sprintf( '%s/%s-%s.envelope', $dir, hrtime( true ), bin2hex( random_bytes( 4 ) ) );
file_put_contents( $name, $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n" . file_get_contents( 'php://input' ) );

header( 'Content-Type: application/json' );
echo '{}';
