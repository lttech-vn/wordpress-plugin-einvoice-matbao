#!/usr/bin/env bash
# One command for the full PHP check: starts the Docker stack (WordPress + WooCommerce + MariaDB),
# runs setup, then PHPCS, PHPStan and both PHPUnit suites inside the container.
set -euo pipefail

cd "$(dirname "$0")/.."

docker compose up -d --build --wait
docker compose exec -T wordpress einv-setup
docker compose exec -T -w /var/www/html/wp-content/plugins/einvoice-matbao wordpress composer check
