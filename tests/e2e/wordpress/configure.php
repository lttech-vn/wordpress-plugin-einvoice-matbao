<?php
/**
 * Configures the plugin for E2E (run with `wp eval-file` by tests/e2e/setup-site.sh).
 *
 * Reads EINV_E2E_MODE (`fake` or `demo`) and, for `demo`, the demo account from EINV_DEMO_MST,
 * EINV_DEMO_USER and EINV_DEMO_PASS. Nothing is printed except the mode and the reference prefix.
 *
 * @package EinvoiceMatbao
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- E2E fixture script.

use LTTech\EinvoiceMatbao\CredentialStore;
use LTTech\EinvoiceMatbao\Matbao\MatbaoCredentials;
use LTTech\EinvoiceMatbao\Settings;

$einv_mode = (string) getenv( 'EINV_E2E_MODE' );
if ( '' === $einv_mode ) {
	$einv_mode = 'fake';
}
if ( 'demo' === $einv_mode ) {
	$einv_mst  = (string) getenv( 'EINV_DEMO_MST' );
	$einv_user = (string) getenv( 'EINV_DEMO_USER' );
	$einv_pass = (string) getenv( 'EINV_DEMO_PASS' );
	if ( '' === $einv_mst || '' === $einv_user || '' === $einv_pass ) {
		WP_CLI::error( 'demo mode needs EINV_DEMO_MST, EINV_DEMO_USER and EINV_DEMO_PASS.' );
	}
	// A fresh prefix per demo run, so order IDs reused after a database reset never hit an older draft.
	$einv_prefix = 'E' . strtoupper( substr( base_convert( (string) time(), 10, 36 ), -6 ) );
} else {
	$einv_mst    = '0000000000';
	$einv_user   = 'e2e';
	$einv_pass   = 'e2e-fake';
	$einv_prefix = 'E2E';
}

update_option( 'einv_e2e_mode', $einv_mode, false );
( new CredentialStore() )->save_matbao( new MatbaoCredentials( 'demo', $einv_mst, $einv_user, $einv_pass ) );
$einv_settings = new Settings();
$einv_settings->save_default_template( '1', 'C' . gmdate( 'y' ) . 'TAT' );
$einv_settings->save_invoicing( $einv_prefix, '' );

WP_CLI::success( 'E2E mode ' . $einv_mode . ', MTChieu prefix ' . $einv_prefix . '.' );
