#!/usr/bin/env bash
# Installs a release zip into a clean WordPress + WooCommerce (Docker, source tree not mounted)
# and checks activation, packaged files, schema, library scoping, a real monitoring event and
# uninstall.
#
#   scripts/smoke-zip.sh dist/einvoice-matbao-X.Y.Z.zip
set -euo pipefail

zip="${1:?usage: smoke-zip.sh <zip>}"
cd "$(dirname "$0")/.."
zip="$(cd "$(dirname "$zip")" && pwd)/$(basename "$zip")"

# Bundled libraries must live under the plugin's prefix (scripts/scope-vendor.sh), so another
# plugin shipping its own copy cannot clash with them.
libs='Sentry|GuzzleHttp|Psr|Symfony|Jean85'
if unzip -p "$zip" 'einvoice-matbao/vendor/*.php' | grep -E "^namespace ($libs)(\\\\|;| )" >/dev/null; then
	echo "Unprefixed library namespace in the zip:" >&2
	unzip -p "$zip" 'einvoice-matbao/vendor/*.php' | grep -E "^namespace ($libs)(\\\\|;| )" | sort -u | head >&2
	exit 1
fi
if unzip -p "$zip" 'einvoice-matbao/vendor/composer/autoload_*.php' | grep -E "'($libs)\\\\" >/dev/null; then
	echo "The zip autoloader still maps unprefixed library names." >&2
	exit 1
fi
if ! unzip -p "$zip" einvoice-matbao/vendor/composer/installed.json | grep -F '"LTTech\\EinvoiceMatbao\\Vendor\\Sentry\\"' >/dev/null; then
	echo "vendor/composer/installed.json in the zip is not scoped." >&2
	exit 1
fi
echo "Checked: bundled libraries are prefixed."

export COMPOSE_PROJECT_NAME="einv-smoke-$$"
export EINV_HTTP_PORT=0
compose=(docker compose -f compose.yaml -f docker/compose.smoke.yaml)
trap '"${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true' EXIT

"${compose[@]}" up -d --build --wait
wpx() { "${compose[@]}" exec -T wordpress "$@"; }
wp() { wpx wp "$@"; }

wp core install --url=http://localhost --title=Smoke --admin_user=admin --admin_password="$(head -c 12 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')" --admin_email=admin@example.test --skip-email
wp plugin install woocommerce --version="$(wpx printenv WOOCOMMERCE_VERSION)" --activate

# Another plugin's unscoped Sentry and Guzzle classes, loaded before ours.
wpx mkdir -p /var/www/html/wp-content/mu-plugins
"${compose[@]}" cp docker/smoke/conflict-libs.php wordpress:/var/www/html/wp-content/mu-plugins/conflict-libs.php

"${compose[@]}" cp "$zip" wordpress:/tmp/plugin.zip
wp plugin install /tmp/plugin.zip --activate

dir=/var/www/html/wp-content/plugins/einvoice-matbao
for f in einvoice-matbao.php uninstall.php LICENSE vendor/autoload.php assets/dist/admin.css assets/dist/htmx.min.js assets/dist/checkout.js assets/dist/checkout-blocks.js assets/blocks/invoice-request/block.json; do
	wpx test -s "$dir/$f" || { echo "Missing in installed plugin: $f" >&2; exit 1; }
done
for d in tests docs docker scripts node_modules assets/src; do
	if wpx test -e "$dir/$d"; then echo "Dev path installed: $d" >&2; exit 1; fi
done

wp plugin is-active einvoice-matbao
# shellcheck disable=SC2016 # PHP code for wp eval: $wpdb and $t are PHP variables.
wp eval 'if ( ! class_exists( "LTTech\\EinvoiceMatbao\\Plugin" ) ) { fwrite( STDERR, "Plugin class not loaded\n" ); exit( 1 ); }
global $wpdb;
foreach ( array( "einvoice_matbao_invoice", "einvoice_matbao_attempt" ) as $t ) {
	if ( $wpdb->prefix . $t !== $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . $t ) ) ) { fwrite( STDERR, "Missing table $t\n" ); exit( 1 ); }
}
echo "schema ok\n";'

# Exercise the plugin on a real admin request so a fatal at admin_menu/init time reaches debug.log.
wp eval 'wp_set_current_user( 1 ); do_action( "admin_menu" ); echo "admin_menu ok\n";'

wp eval 'if ( ! str_contains( ( new ReflectionClass( "Sentry\\SentrySdk" ) )->getFileName(), "mu-plugins" ) ) { fwrite( STDERR, "The conflicting mu-plugin did not load first\n" ); exit( 1 ); }
// Composer skips autoload files whose identifier is already loaded; ours must not be skipped.
if ( ! function_exists( "LTTech\\EinvoiceMatbao\\Vendor\\Sentry\\init" ) ) { fwrite( STDERR, "Scoped sentry/sentry functions.php was not loaded\n" ); exit( 1 ); }
echo "scoped libraries loaded next to unscoped ones\n";'

# A real event through the scoped SDK and WpHttpClient, to a fake ingest inside the container.
"${compose[@]}" cp docker/smoke/sentry-sink.php wordpress:/tmp/sentry-sink.php
"${compose[@]}" exec -T -d wordpress php -S 127.0.0.1:9999 /tmp/sentry-sink.php
# A transport error would open WpHttpClient's 60 s backoff, so wait until the sink answers.
# shellcheck disable=SC2016 # PHP code: $i is a PHP variable.
wpx php -r 'for ( $i = 0; $i < 50; $i++ ) { if ( @fsockopen( "127.0.0.1", 9999 ) ) { exit( 0 ); } usleep( 100000 ); } fwrite( STDERR, "Fake ingest did not start\n" ); exit( 1 );'
wp config set EINVOICE_MATBAO_SENTRY_DSN 'http://smokepublickey@127.0.0.1:9999/7' --type=constant
wp config set EINVOICE_MATBAO_SENTRY_ENABLED true --raw --type=constant
# shellcheck disable=SC2016 # PHP code for wp eval: $id, $sent, ... are PHP variables.
wp eval 'use LTTech\EinvoiceMatbao\Monitoring\Monitor;
if ( ! Monitor::is_active() ) { fwrite( STDERR, "Monitoring is not active\n" ); exit( 1 ); }
$id = Monitor::send_test_event();
if ( 1 !== preg_match( "/^[0-9a-f]{32}$/", (string) $id ) ) { fwrite( STDERR, "send_test_event() returned no event ID\n" ); exit( 1 ); }
$files = glob( "/tmp/sentry-sink/*.envelope" ) ?: array();
$sent  = implode( "\n", array_map( "file_get_contents", $files ) );
if ( ! str_contains( $sent, "POST /api/7/envelope/" ) || ! str_contains( $sent, $id ) || ! str_contains( $sent, Monitor::TEST_EVENT_MESSAGE ) ) { fwrite( STDERR, "The fake ingest did not receive the test event\n" ); exit( 1 ); }
// The other SDK paths (tracing, logs, cron check-ins) through the scoped code as well.
$check_in = Monitor::check_in( "in_progress" );
Monitor::start_transaction( "smoke", "smoke.run" );
Monitor::span( "smoke.step", "smoke step", static fn(): int => 1 );
Monitor::log( "info", "smoke log" );
Monitor::finish_transaction();
Monitor::check_in( "ok", $check_in, 0.1 );
Monitor::flush();
$sent = implode( "\n", array_map( "file_get_contents", glob( "/tmp/sentry-sink/*.envelope" ) ?: array() ) );
foreach ( array( "\"type\":\"transaction\"", "\"type\":\"log\"", "\"type\":\"check_in\"" ) as $item ) {
	if ( ! str_contains( $sent, $item ) ) { fwrite( STDERR, "The fake ingest received no $item envelope item\n" ); exit( 1 ); }
}
echo "test event $id, transaction, logs and check-ins received by the fake ingest\n";'
wp config delete EINVOICE_MATBAO_SENTRY_DSN --type=constant
wp config delete EINVOICE_MATBAO_SENTRY_ENABLED --type=constant
wp eval 'echo "WP " . get_bloginfo( "version" ) . ", PHP " . PHP_VERSION . ", WooCommerce " . WC_VERSION . "\n";'

wp plugin deactivate einvoice-matbao
wp plugin uninstall einvoice-matbao
if [ -n "$(wp option list --search='einvoice_matbao_credentials' --field=option_name)" ]; then
	echo "Uninstall left the credentials option behind." >&2
	exit 1
fi
# WP_DEBUG_LOG is on (docker/compose.smoke.yaml). The log must exist, or this check would pass vacuously.
wpx test -f /var/www/html/wp-content/debug.log || { echo "debug.log was not written; the PHP error check cannot run." >&2; exit 1; }
log="$(wpx cat /var/www/html/wp-content/debug.log)"
if printf '%s\n' "$log" | grep -E "PHP (Fatal error|Parse error|Warning|Notice)" | grep -F "einvoice-matbao" >/dev/null; then
	echo "Plugin raised PHP errors:" >&2
	printf '%s\n' "$log" | grep -F "einvoice-matbao" >&2
	exit 1
fi
echo "Checked: no PHP errors from the plugin in debug.log."
echo "Smoke test passed for $(basename "$zip")."
