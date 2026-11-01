#!/usr/bin/env bash
# Prints the plugin version after checking that the header and EINVOICE_MATBAO_VERSION agree.
#
#   scripts/plugin-version.sh [expected-version]
set -euo pipefail

cd "$(dirname "$0")/.."
file=einvoice-matbao.php
expected="${1:-}"

header="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$file" | head -1 | tr -d '[:space:]')"
const="$(sed -n "s/^define( 'EINVOICE_MATBAO_VERSION', '\(.*\)' );.*/\1/p" "$file" | head -1)"
if [ -z "$header" ] || [ "$header" != "$const" ]; then
	echo "Version mismatch: header '$header' vs EINVOICE_MATBAO_VERSION '$const'." >&2
	exit 1
fi
if [ -n "$expected" ] && [ "$expected" != "$header" ]; then
	echo "Requested version '$expected' does not match the plugin version '$header'." >&2
	exit 1
fi
echo "$header"
