#!/usr/bin/env bash
# Prefixes the bundled libraries of a staged plugin with LTTech\EinvoiceMatbao\Vendor
# (PHP-Scoper, scoper.inc.php) and regenerates its Composer autoloader.
#
#   scripts/scope-vendor.sh <staged-plugin-dir>
#
# The staged directory holds src/, composer.json, composer.lock and the vendor/ of
# `composer install --no-dev`. The PHP-Scoper phar is pinned in scripts/php-scoper.sha256 and
# checked before it runs. The WordPress/WooCommerce symbols to leave alone come from
# php-stubs/* at the versions in composer.lock, installed into a throwaway tools directory.
# PHP tools run on the host when it has PHP >= 8.2 and composer, otherwise in the pinned
# composer image (the same one scripts/build-zip.sh falls back to).
set -euo pipefail

pkg="$(cd "${1:?usage: scope-vendor.sh <staged-plugin-dir>}" && pwd)"
repo="$(cd "$(dirname "$0")/.." && pwd)"
composer_image=composer:2.9.8

tools="$(mktemp -d)"
trap 'rm -rf "$tools"' EXIT

host_php_ok() {
	command -v php >/dev/null 2>&1 && command -v composer >/dev/null 2>&1 &&
		php -r 'exit( PHP_VERSION_ID >= 80200 ? 0 : 1 );'
}

# Runs a PHP-side command with the repo, the staged plugin and the tools dir at the same paths.
run() {
	if host_php_ok; then
		"$@"
	else
		docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
			-e EINV_SCOPER_SOURCE -e EINV_SCOPER_EXCLUDES \
			-v "$repo:$repo:ro" -v "$pkg:$pkg" -v "$tools:$tools" -w "$repo" \
			"$composer_image" "$@"
	fi
}

sha256_of() {
	if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1"; else shasum -a 256 "$1"; fi | cut -d' ' -f1
}

# 1. Pinned PHP-Scoper.
read -r scoper_version scoper_sha256 < <(grep -v '^#' "$repo/scripts/php-scoper.sha256")
phar="$tools/php-scoper.phar"
curl -fsSL --retry 3 -o "$phar" "https://github.com/humbug/php-scoper/releases/download/$scoper_version/php-scoper.phar"
actual="$(sha256_of "$phar")"
if [ "$actual" != "$scoper_sha256" ]; then
	echo "php-scoper.phar $scoper_version checksum mismatch: got $actual, pinned $scoper_sha256." >&2
	exit 1
fi

# 2. Symbols that must keep their global names, from the locked stub versions.
stub_versions="$(run php -r '
	$lock = json_decode( file_get_contents( $argv[1] ), true );
	foreach ( array_merge( $lock["packages"], $lock["packages-dev"] ) as $p ) {
		if ( in_array( $p["name"], array( "php-stubs/wordpress-stubs", "php-stubs/woocommerce-stubs" ), true ) ) {
			echo $p["name"], ":", ltrim( $p["version"], "v" ), " ";
		}
	}' "$repo/composer.lock")"
read -r -a stubs <<<"$stub_versions"
if [ "${#stubs[@]}" -ne 2 ]; then
	echo "composer.lock must pin php-stubs/wordpress-stubs and php-stubs/woocommerce-stubs (found: ${stubs[*]:-none})." >&2
	exit 1
fi
mkdir -p "$tools/stubs"
run composer require --working-dir="$tools/stubs" --no-interaction --no-progress --quiet "${stubs[@]}"
stub_dir="$tools/stubs/vendor/php-stubs"
run php "$repo/scripts/scoper-excludes.php" \
	"$stub_dir/wordpress-stubs/wordpress-stubs.php" \
	"$stub_dir/woocommerce-stubs/woocommerce-stubs.php" \
	"$stub_dir/woocommerce-stubs/woocommerce-packages-stubs.php" >"$tools/excludes.json"

# 3. Scope src/ and vendor/ into a fresh directory, then swap it in.
export EINV_SCOPER_SOURCE="$pkg" EINV_SCOPER_EXCLUDES="$tools/excludes.json"
run php "$phar" add-prefix --config="$repo/scoper.inc.php" --output-dir="$tools/scoped" \
	--force --no-interaction --quiet
for path in src vendor composer.json; do
	test -e "$tools/scoped/$path" || { echo "PHP-Scoper produced no $path." >&2; exit 1; }
done

# 4. Swap the scoped tree in and build its autoloader, in one PHP-side step: a Docker bind mount
# may not see a host-side swap right away.
run sh -c 'set -e
	rm -rf "$1/src" "$1/vendor" "$1/composer.json"
	mv "$2/src" "$2/vendor" "$2/composer.json" "$1/"
	rm -f "$1/vendor/scoper-autoload.php" # PHP-Scoper wrapper; the plugin loads vendor/autoload.php.
	composer dump-autoload --working-dir="$1" --no-dev --no-interaction --classmap-authoritative --quiet' \
	scope-vendor "$pkg" "$tools/scoped"

# Composer skips an autoload `files` entry whose identifier another autoloader on the site
# already loaded, and the identifiers do not change with the prefix, so the scoped autoloader
# keeps its own registry of loaded files.
real="$pkg/vendor/composer/autoload_real.php"
grep -q "__composer_autoload_files" "$real" || { echo "Composer autoload_real.php changed; review the files registry rename." >&2; exit 1; }
run php -r '
	file_put_contents( $argv[1], str_replace( "__composer_autoload_files", "__einvoice_matbao_autoload_files", file_get_contents( $argv[1] ) ) );' "$real"
test -s "$pkg/vendor/autoload.php"
