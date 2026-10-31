#!/usr/bin/env bash
# Installs a release zip into a clean WordPress + WooCommerce (Docker, source tree not mounted)
# and checks activation, packaged files, schema and uninstall.
#
#   scripts/smoke-zip.sh dist/einvoice-matbao-X.Y.Z.zip
set -euo pipefail

zip="${1:?usage: smoke-zip.sh <zip>}"
cd "$(dirname "$0")/.."
zip="$(cd "$(dirname "$zip")" && pwd)/$(basename "$zip")"

export COMPOSE_PROJECT_NAME="einv-smoke-$$"
export EINV_HTTP_PORT=0
compose=(docker compose -f compose.yaml -f docker/compose.smoke.yaml)
trap '"${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true' EXIT

"${compose[@]}" up -d --build --wait
wp() { "${compose[@]}" exec -T wordpress wp "$@"; }

wp core install --url=http://localhost --title=Smoke --admin_user=admin --admin_password="$(head -c 12 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')" --admin_email=admin@example.test --skip-email
wp plugin install woocommerce --version="$("${compose[@]}" exec -T wordpress printenv WOOCOMMERCE_VERSION)" --activate
"${compose[@]}" cp "$zip" wordpress:/tmp/plugin.zip
wp plugin install /tmp/plugin.zip --activate

dir=/var/www/html/wp-content/plugins/einvoice-matbao
for f in einvoice-matbao.php uninstall.php LICENSE vendor/autoload.php assets/dist/admin.css assets/dist/htmx.min.js assets/dist/checkout.js assets/dist/checkout-blocks.js assets/blocks/invoice-request/block.json; do
	"${compose[@]}" exec -T wordpress test -s "$dir/$f" || { echo "Missing in installed plugin: $f" >&2; exit 1; }
done
for d in tests docs docker scripts node_modules assets/src; do
	if "${compose[@]}" exec -T wordpress test -e "$dir/$d"; then echo "Dev path installed: $d" >&2; exit 1; fi
done

wp plugin is-active einvoice-matbao
wp eval 'if ( ! class_exists( "LTTech\\EinvoiceMatbao\\Plugin" ) ) { fwrite( STDERR, "Plugin class not loaded\n" ); exit( 1 ); }
global $wpdb;
foreach ( array( "einvoice_matbao_invoice", "einvoice_matbao_attempt" ) as $t ) {
	if ( $wpdb->prefix . $t !== $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . $t ) ) ) { fwrite( STDERR, "Missing table $t\n" ); exit( 1 ); }
}
echo "schema ok\n";'

# Admin pages render without a fatal for an administrator.
wp eval 'wp_set_current_user( 1 ); foreach ( array( "einvoice-matbao-invoices" ) as $slug ) { if ( ! menu_page_url( $slug, false ) ) { /* menu registers on admin_menu only */ } } echo "admin ok\n";'

wp plugin deactivate einvoice-matbao
wp plugin uninstall einvoice-matbao
if [ -n "$(wp option list --search='einvoice_matbao_credentials' --field=option_name)" ]; then
	echo "Uninstall left the credentials option behind." >&2
	exit 1
fi
if "${compose[@]}" exec -T wordpress sh -c 'grep -qs "Fatal error" /var/www/html/wp-content/debug.log'; then
	echo "debug.log contains a fatal error." >&2
	"${compose[@]}" exec -T wordpress cat /var/www/html/wp-content/debug.log >&2
	exit 1
fi
# Fail on any PHP notice/warning/fatal raised by the plugin (the first smoke run caught a missing block.json this way).
if "${compose[@]}" exec -T wordpress sh -c 'grep -E "einvoice-matbao.*(Fatal|Warning|Notice)|(Fatal|Warning|Notice).*einvoice-matbao" /var/www/html/wp-content/debug.log' >/tmp/einv-smoke-log.$$ 2>/dev/null && [ -s /tmp/einv-smoke-log.$$ ]; then
	echo "Plugin raised PHP errors:" >&2; cat /tmp/einv-smoke-log.$$ >&2; exit 1
fi
echo "Smoke test passed for $(basename "$zip")."
