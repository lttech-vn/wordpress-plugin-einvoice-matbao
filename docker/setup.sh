#!/usr/bin/env bash
# Idempotent setup run inside the wordpress container:
# installs WordPress, pins WooCommerce, activates this plugin and installs Composer deps.
set -euo pipefail

WP_PATH=/var/www/html
PLUGIN_DIR="$WP_PATH/wp-content/plugins/einvoice-matbao"

cd "$PLUGIN_DIR"
composer install --no-interaction --no-progress

cd "$WP_PATH"
if ! wp core is-installed 2>/dev/null; then
	wp core install \
		--url="$EINV_SITE_URL" \
		--title="E-Invoice Mat Bao Dev" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.test \
		--skip-email
fi

installed_wc="$(wp plugin get woocommerce --field=version 2>/dev/null || true)"
if [ "$installed_wc" != "$WOOCOMMERCE_VERSION" ]; then
	wp plugin install woocommerce --version="$WOOCOMMERCE_VERSION" --force
fi

wp plugin activate woocommerce einvoice-matbao
chown -R www-data:www-data "$WP_PATH/wp-content/uploads" 2>/dev/null || true

echo "WordPress $(wp core version), WooCommerce $(wp plugin get woocommerce --field=version), PHP $(php -r 'echo PHP_VERSION;')"
echo "Site: $EINV_SITE_URL/wp-admin (admin / admin)"
