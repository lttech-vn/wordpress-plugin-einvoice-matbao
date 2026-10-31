#!/usr/bin/env bash
# Prepares the running Docker Compose site for the browser E2E suite. Idempotent.
#
#   EINV_E2E_MODE=fake ./tests/e2e/setup-site.sh            # default: Mat Bao answered locally
#   EINV_E2E_MODE=demo EINV_DEMO_E2E=1 EINV_DEMO_MST=... EINV_DEMO_USER=... EINV_DEMO_PASS=... \
#       ./tests/e2e/setup-site.sh                          # real demo API (drafts only)
#
# Credentials go to the container only as environment variables of one `wp eval-file` process.
set -euo pipefail

cd "$(dirname "$0")/../.."
mode="${EINV_E2E_MODE:-fake}"
if [ "$mode" = "demo" ] && [ "${EINV_DEMO_E2E:-}" != "1" ]; then
	echo "demo mode calls the Mat Bao demo API; set EINV_DEMO_E2E=1 to confirm." >&2
	exit 1
fi

compose=(docker compose)
wp() { "${compose[@]}" exec -T wordpress wp "$@" 2>/dev/null; }
plugin=/var/www/html/wp-content/plugins/einvoice-matbao

"${compose[@]}" exec -T wordpress sh -c "
	set -e
	mkdir -p /var/www/html/wp-content/mu-plugins
	cp $plugin/tests/e2e/wordpress/einv-e2e-http.php /var/www/html/wp-content/mu-plugins/einv-e2e-http.php
	# Action Scheduler runs async actions through a loopback request to the public URL (with port);
	# make Apache answer that port inside the container too.
	port=\$(printf '%s' \"\$EINV_SITE_URL\" | sed -n 's/.*:\\([0-9]*\\)\$/\\1/p')
	if [ -n \"\$port\" ] && [ \"\$port\" != 80 ] && ! grep -qs \"Listen \$port\" /etc/apache2/conf-enabled/einv-e2e-port.conf; then
		echo \"Listen \$port\" > /etc/apache2/conf-enabled/einv-e2e-port.conf
		apache2ctl -k graceful 2>/dev/null
	fi
"

if ! wp config has EINVOICE_MATBAO_ENCRYPTION_KEY --path=/var/www/html; then
	wp config set EINVOICE_MATBAO_ENCRYPTION_KEY "e2e-$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')-key-material" --path=/var/www/html >/dev/null
fi

wp user update admin --user_pass="${EINV_E2E_ADMIN_PASS:-e2e-admin-pass}" >/dev/null
wp option update woocommerce_currency VND >/dev/null
wp option update woocommerce_price_num_decimals 0 >/dev/null
wp option update woocommerce_default_country VN >/dev/null
wp option update woocommerce_calc_taxes yes >/dev/null
wp option update woocommerce_prices_include_tax no >/dev/null
wp option update woocommerce_enable_guest_checkout yes >/dev/null
wp option update woocommerce_coming_soon no >/dev/null
wp option update woocommerce_checkout_phone_field required >/dev/null
wp option update woocommerce_cod_settings --format=json '{"enabled":"yes","title":"COD","description":"","instructions":"","enable_for_methods":[],"enable_for_virtual":"yes"}' >/dev/null

"${compose[@]}" exec -T wordpress wp eval '
	global $wpdb;
	if ( ! $wpdb->get_var( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = \"\" AND tax_rate = \"10.0000\"" ) ) {
		WC_Tax::_insert_tax_rate( array( "tax_rate_country" => "", "tax_rate_state" => "", "tax_rate" => "10", "tax_rate_name" => "VAT 10", "tax_rate_priority" => 1, "tax_rate_compound" => 0, "tax_rate_shipping" => 1, "tax_rate_order" => 0, "tax_rate_class" => "" ) );
	}
	if ( ! wc_get_product_id_by_sku( "E2E-SVC" ) ) {
		$p = new WC_Product_Simple();
		$p->set_name( "Dịch vụ E2E" );
		$p->set_sku( "E2E-SVC" );
		$p->set_regular_price( "100000" );
		$p->set_virtual( true );
		$p->set_status( "publish" );
		$p->save();
	}
	if ( ! get_page_by_path( "classic-checkout" ) ) {
		wp_insert_post( array( "post_type" => "page", "post_status" => "publish", "post_name" => "classic-checkout", "post_title" => "Classic checkout", "post_content" => "<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->" ) );
	}
	// Block hooks insert the invoice block only when the page is saved in the editor, so a
	// checkout page created before the plugin was active needs it placed explicitly.
	$checkout = get_post( wc_get_page_id( "checkout" ) );
	$anchor   = "<!-- /wp:woocommerce/checkout-contact-information-block -->";
	if ( $checkout && false === strpos( $checkout->post_content, "data-block-name=\"einvoice-matbao/invoice-request\"" ) && false !== strpos( $checkout->post_content, $anchor ) ) {
		wp_update_post( array( "ID" => $checkout->ID, "post_content" => str_replace( $anchor, $anchor . "\n\n<!-- wp:einvoice-matbao/invoice-request -->\n<div data-block-name=\"einvoice-matbao/invoice-request\" class=\"wp-block-einvoice-matbao-invoice-request\"></div>\n<!-- /wp:einvoice-matbao/invoice-request -->", str_replace( "<!-- wp:einvoice-matbao/invoice-request /-->", "", $checkout->post_content ) ) ) );
	}
	echo "product ", wc_get_product_id_by_sku( "E2E-SVC" ), "\n";
' 2>/dev/null

"${compose[@]}" exec -T -e EINV_E2E_MODE="$mode" -e EINV_DEMO_MST -e EINV_DEMO_USER -e EINV_DEMO_PASS \
	wordpress wp eval-file "$plugin/tests/e2e/wordpress/configure.php" 2>/dev/null
