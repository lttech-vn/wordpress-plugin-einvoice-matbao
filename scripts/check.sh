#!/usr/bin/env bash
# One command for the full PHP check: starts the Docker stack (WordPress + WooCommerce + MariaDB),
# runs setup, then PHPCS, PHPStan and both PHPUnit suites inside the container.
set -euo pipefail

cd "$(dirname "$0")/.."

# --min selects the minimum platform combination (docker/min.env) on its own project name and port.
if [ "${1:-}" = "--min" ]; then
	if [ -f .env ]; then
		set -a
		# shellcheck disable=SC1091
		. ./.env
		set +a
	fi
	set -a
	# shellcheck disable=SC1091
	. docker/min.env
	set +a
	export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-einvoice-matbao}-min"
	export EINV_HTTP_PORT=$(( ${EINV_HTTP_PORT:-8080} + 1 ))
elif [ $# -gt 0 ]; then
	echo "usage: $0 [--min]" >&2
	exit 2
fi

docker compose up -d --build --wait
docker compose exec -T wordpress einv-setup
docker compose exec -T -w /var/www/html/wp-content/plugins/einvoice-matbao wordpress composer check
