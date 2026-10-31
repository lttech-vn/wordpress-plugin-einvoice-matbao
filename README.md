# wordpress-plugin-einvoice-matbao

WooCommerce plugin that creates Mat Bao VAT draft e-invoices for completed orders.
Product scope and decisions: `docs/discovery.md`, `GLOSSARY.md`, `docs/adr/`.

## Requirements

- Docker with Compose v2 (PHP, Composer, WP-CLI, WordPress and WooCommerce all run in containers).
- [bun](https://bun.sh) for the admin CSS/JS build.

The host PHP version does not matter; do not run Composer or PHPUnit on the host.

## Development environment

```sh
cp .env.example .env          # set a unique COMPOSE_PROJECT_NAME and EINV_HTTP_PORT per worktree
docker compose up -d --build --wait
docker compose exec -T wordpress einv-setup
```

`einv-setup` is idempotent: it runs `composer install`, installs WordPress, installs the pinned
WooCommerce version and activates WooCommerce and this plugin. The site is at
`http://localhost:$EINV_HTTP_PORT/wp-admin` (user `admin`, password `admin`; local only).

Stop with `docker compose down` (add `-v` to wipe the database and WordPress volume).

## Settings and credentials

Settings live under **WooCommerce → Hóa đơn Mắt Bão** (Administrators only). Mat Bao credentials and
the Lark webhook are stored encrypted (libsodium) with key material from a `wp-config.php` constant:

```php
define( 'EINVOICE_MATBAO_ENCRYPTION_KEY', '<random string, at least 32 characters>' );
```

Without the constant the plugin refuses to save credentials and shows a notice. Changing or removing
the key makes stored credentials unreadable; re-enter them after rotating. Secrets are never rendered
back in the UI. The Mat Bao access token is cached encrypted in a transient (at most one hour).

The page talks to the `einvoice-matbao/v1` REST namespace through htmx; every route returns an HTML
fragment, requires `manage_options` and a `wp_rest` nonce in `X-WP-Nonce`.

## Draft invoices for completed orders

When an order moves to `completed` (only while the plugin is active; older orders are never
scanned), the plugin reserves one row in `{prefix}einvoice_matbao_invoice` (`UNIQUE(order_id)`,
fixed `MTChieu` = prefix + `WC` + order ID) and queues one Action Scheduler action
(`einvoice_matbao_create_draft`, group `einvoice-matbao`). The action claims the row and calls
`create-invoice` with `LoaiHDon=0` (draft) and `KTraMTChieuTrung=1` for a walk-in buyer; each call
is logged in `{prefix}einvoice_matbao_attempt`. Status, `MTChieu`, Mat Bao invoice ID and errors
show in the **Hóa đơn Mắt Bão** box on the order screen (HPOS and legacy).

The payload uses the amounts and taxes stored on each order line (discounted prices, fees and
shipping as their own lines, `TSuat` 0/5/8/10 or -1 for tax status `none`) and must reconcile with
the order totals; otherwise nothing is sent and the invoice is marked failed. 0 VND orders are
skipped with a reason. Failures are not retried yet.

## Tests, lint and static analysis

One command runs everything (starts the stack if needed):

```sh
./scripts/check.sh
```

Individual steps, inside the running stack:

```sh
alias einv='docker compose exec -T -w /var/www/html/wp-content/plugins/einvoice-matbao wordpress'
einv composer lint                      # PHPCS, WordPress standard (phpcs.xml.dist)
einv composer analyse                   # PHPStan level 8 with WordPress/WooCommerce stubs
einv composer test:integration          # PHPUnit on real WordPress + WooCommerce
einv composer test:without-woocommerce  # PHPUnit with WooCommerce not loaded
```

The PHPUnit suites use a separate `wordpress_test` database, so they never touch the dev site.

## Admin assets

```sh
bun install --frozen-lockfile
bun run build      # assets/dist/admin.css (Tailwind) + assets/dist/htmx.min.js (vendored)
bun run watch:css  # rebuild CSS on change
```

Tailwind utilities are scoped under `#einv-app` and preflight is not included, so WordPress admin
styles are untouched; `bun run check:css-scope` (run in CI) fails if the build emits an unscoped rule.
Design tokens (`einv-*`) come from Stripe `DESIGN.md` in VoltAgent/awesome-design-md. htmx is copied from the pinned npm package; nothing is loaded from a CDN.
`assets/dist/` is gitignored and rebuilt by CI; release packages must run `bun run build` first.

## CI

`.github/workflows/ci.yml` builds the assets with bun and runs PHPCS, PHPStan and both PHPUnit
suites through the same Docker Compose stack used locally.

## Pinned versions and known limits

| Component   | Version                                   |
|-------------|-------------------------------------------|
| WordPress   | 7.1.4 (`wordpress:7.1.4-php8.5-apache`)   |
| PHP         | 8.5 (from the WordPress image)            |
| WooCommerce | 11.2.1 (`WOOCOMMERCE_VERSION` in compose) |
| MariaDB     | 11.8                                      |
| wp-phpunit  | ~7.1.0                                    |

- WordPress, WooCommerce and PHP 8.5 are recent releases; only the versions above are tested.
  Bump them together in `docker/Dockerfile`, `compose.yaml` and `composer.json`.
- WP-CLI 2.12 prints PHP 8.5 deprecation notices during `einv-setup`; they are harmless.
- Activation without WooCommerce is refused by WordPress core through the `Requires Plugins`
  header. If WooCommerce is later deactivated or removed, the plugin deactivates itself on the
  next admin request and shows a Vietnamese admin notice.
- Without `composer install` (no `vendor/`), the plugin stays inert instead of fataling. Release
  packages must include `vendor/` built with `composer install --no-dev`.
