#!/usr/bin/env bash
# Builds the installable plugin zip: dist/einvoice-matbao-<version>.zip (+ .sha256).
#
#   scripts/build-zip.sh [expected-version]
#
# The version comes from the plugin header; the constant must match, and so must
# [expected-version] when given (the release workflow passes the tag). Only the
# whitelisted paths below are packaged, so dev files can never leak in.
# Needs bun, zip and either composer or Docker (fallback: the pinned composer image).
set -euo pipefail

cd "$(dirname "$0")/.."
slug=einvoice-matbao
expected="${1:-}"

version="$(scripts/plugin-version.sh "$expected")"

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
pkg="$stage/$slug"
mkdir -p "$pkg"

# 1. Front-end bundles.
bun install --frozen-lockfile
bun run build
for f in admin.css htmx.min.js checkout.js checkout-blocks.js checkout-blocks-editor.js; do
	test -s "assets/dist/$f" || { echo "Missing assets/dist/$f after build." >&2; exit 1; }
done

# 2. Whitelist copy.
cp "$slug.php" uninstall.php LICENSE "$pkg/"
cp -R src "$pkg/src"
mkdir -p "$pkg/assets"
cp -R assets/dist "$pkg/assets/dist"
cp -R assets/blocks "$pkg/assets/blocks"
[ -d languages ] && cp -R languages "$pkg/languages"

# 3. Production dependencies from the lock file (no dev packages).
cp composer.json composer.lock "$pkg/"
composer_args=(install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --classmap-authoritative)
if command -v composer >/dev/null 2>&1; then
	(cd "$pkg" && composer "${composer_args[@]}")
else
	docker run --rm -u "$(id -u):$(id -g)" -v "$pkg:/app" -w /app composer:2.9.8 "${composer_args[@]}"
fi
rm -f "$pkg/composer.json" "$pkg/composer.lock"
test -s "$pkg/vendor/autoload.php"

# 4. Reproducible zip: sorted entries, normalised modes and mtimes, no extra attributes.
chmod -R u=rwX,go=rX "$pkg"
find "$pkg" -exec touch -t 202001010000 {} +
mkdir -p dist
out="$PWD/dist/$slug-$version.zip"
rm -f "$out" "$out.sha256"
(cd "$stage" && find "$slug" -type f | LC_ALL=C sort | zip -X -q "$out" -@)
(cd dist && if command -v sha256sum >/dev/null 2>&1; then sha256sum "$(basename "$out")"; else shasum -a 256 "$(basename "$out")"; fi >"$(basename "$out").sha256")

test -s "$pkg/assets/blocks/invoice-request/block.json"

# 5. Guard: nothing from the blocklist may be inside.
if unzip -Z1 "$out" | grep -E "^$slug/(tests|docs|docker|scripts|node_modules|\.github|\.git|assets/src|OneDrive_)" >/dev/null; then
	echo "Dev files leaked into the zip." >&2
	exit 1
fi
echo "Built $out"
cat "$out.sha256"
