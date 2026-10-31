#!/usr/bin/env bash
# Runs the browser E2E suite in the official Playwright image against the Docker Compose site.
#
#   ./tests/e2e/run.sh                      # fake Mat Bao (default, what CI runs)
#   EINV_E2E_MODE=demo EINV_DEMO_E2E=1 EINV_DEMO_MST=... EINV_DEMO_USER=... EINV_DEMO_PASS=... \
#       ./tests/e2e/run.sh                  # real Mat Bao demo API, drafts only
#
# Needs: the stack up (`docker compose up -d --wait && docker compose exec -T wordpress einv-setup`),
# built assets (`bun run build`) and `bun install --frozen-lockfile` in tests/e2e.
set -euo pipefail

cd "$(dirname "$0")"
root="$(cd ../.. && pwd)"
if [ -f "$root/.env" ]; then
	port="$(sed -n 's/^EINV_HTTP_PORT=//p' "$root/.env" | tail -1)"
fi
port="${EINV_HTTP_PORT:-${port:-8080}}"
mode="${EINV_E2E_MODE:-fake}"

setup_log="$(EINV_E2E_MODE="$mode" ./setup-site.sh)"
product_id="$(printf '%s\n' "$setup_log" | sed -n 's/^product //p' | tail -1)"
mkdir -p results

# Host networking: the site URL is http://localhost:$port both for the browser and WordPress.
docker run --rm --network host --ipc host \
	-v "$PWD:/e2e" -w /e2e \
	-e EINV_E2E_MODE="$mode" -e EINV_E2E_PRODUCT_ID="$product_id" -e EINV_E2E_BASE_URL="http://localhost:$port" \
	mcr.microsoft.com/playwright:v1.64.1-noble \
	npx --no-install playwright test "$@"
