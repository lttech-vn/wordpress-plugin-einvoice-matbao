#!/usr/bin/env bash
# Idempotent setup run inside the wordpress container:
# installs WordPress, pins WooCommerce, activates this plugin and installs Composer deps.
set -euo pipefail

WP_PATH=/var/www/html
PLUGIN_DIR="$WP_PATH/wp-content/plugins/einvoice-matbao"

cd "$PLUGIN_DIR"
composer install --no-interaction --no-progress

# Minimum platform: install the wp-phpunit release matching the WordPress core outside the plugin tree.
if [ -n "${EINV_WP_PHPUNIT_VERSION:-}" ]; then
	mkdir -p /opt/wp-phpunit
	if ! grep -qF "\"wp-phpunit/wp-phpunit\": \"$EINV_WP_PHPUNIT_VERSION\"" /opt/wp-phpunit/composer.json 2>/dev/null \
		|| ! composer show --working-dir=/opt/wp-phpunit wp-phpunit/wp-phpunit >/dev/null 2>&1; then
		composer require --working-dir=/opt/wp-phpunit --no-interaction --no-progress "wp-phpunit/wp-phpunit:$EINV_WP_PHPUNIT_VERSION"
	fi
fi

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
echo "wp-phpunit: ${EINV_WP_PHPUNIT_DIR:-vendor}"
echo "Site: $EINV_SITE_URL/wp-admin (admin / admin)"
