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
`create-invoice` with `LoaiHDon=0` (draft) and `KTraMTChieuTrung=1`; each call
is logged in `{prefix}einvoice_matbao_attempt`. Status, `MTChieu`, Mat Bao invoice ID and errors
show in the **Hóa đơn Mắt Bão** box on the order screen (HPOS and legacy).

The payload uses the amounts and taxes stored on each order line (discounted prices, fees and
shipping as their own lines, `TSuat` 0/5/8/10 or -1 for tax status `none`) and must reconcile with
the order totals; otherwise nothing is sent and the invoice is marked failed. 0 VND orders are
skipped with a reason.

### Retry and recovery

- Temporary failures (no response, HTTP 5xx/408/429, an unreadable 2xx body, duplicate `MTChieu`
  305; codes where `InvoiceErrorCode::is_auto_retryable()` is true) keep the row `pending` with
  the error code and queue the same action again after 1 min, 5 min, 15 min, 1 h and 6 h. No alert
  fires while a retry waits. After the fifth retry the status becomes `needs_attention`
  ("Lỗi cần xử lý") with the last error code, and both
  `einvoice_matbao_invoice_needs_attention( $invoice, $code )` and
  `einvoice_matbao_retries_exhausted( $order_id, $reference )` fire once (alerting is #7).
- Other failures are final (see the table below) and alert at once.
- Before any send that may follow an earlier create call, the plugin looks the invoice up by `MTChieu`
  (`invoice-detail-batch`) under the template stored on the row at that earlier call, not the
  current default. The template is stored before every create call. Exactly one row with the same
  `mtChieu`, status Nháp (`maTThaiHDon` 1) and, when Mat Bao reports `tgTTTBSo`, the order total is
  adopted (no second create). Anything else found (several rows, another `mtChieu`, issued or
  deleted, another total) stops with `matbao_lookup_mismatch` (`needs_attention`, alert). When
  nothing is found the same `MTChieu` is sent again with `KTraMTChieuTrung=1`; if the default
  template changed meanwhile, the send stops with `template_changed` instead of creating under
  another template. Error 305 triggers a lookup, never a new `MTChieu`. A first send with no
  earlier attempt, or after failures before the create call (login, the templates check), skips
  the lookup.
- A recurring sweep (every 15 min) recovers rows stuck in `sending` (runner died after the claim;
  claimed back only if `updated_at` is unchanged) and `pending` rows with no queued action after
  15 min. The sweep adds no attempt and uses up no retry; only attempts that called Mat Bao and
  failed count. If the sweep cannot queue the action either, the row becomes `failed` with
  `queue_failed` and alerts.
- `DraftInvoiceWorkflow::restart( $order_id, $from_status )` sends a row again in a new retry cycle
  (`retry_cycle_start`, schema v3); earlier attempts still decide the lookup. An order cancelled
  and then completed again after a `stopped` invoice uses it; the admin resend (#9) can too.

Before the create call the action re-reads the order and settings and asks Mat Bao for the
templates of the invoice year (`/api/invoice/templates`): the default template must still be
listed as a VAT template with numbers left, so a template of last year is blocked after New Year. The
list is cached for 5 minutes (`TemplateCache`, one transient per account and year) and dropped
when the default template or the credentials are saved, and when the template is unavailable or
rejected by Mat Bao.

### Blocked drafts and error classification

Every reason a draft is not created is stored on the invoice row as a stable `error_code`
(`InvoiceErrorCode`) and `error_kind` (`InvoiceErrorKind`) next to the Vietnamese
`status_reason`. The order status is never changed. The order meta box shows the kind, a short
label and the reason (`data-einv-error-code` / `data-einv-error-kind` attributes).

| Kind     | Status           | Codes                                                                                   | Auto retry |
|----------|------------------|-----------------------------------------------------------------------------------------|------------|
| `data`   | `failed`         | non-VND currency, fractional VND, unsupported or missing tax rate, several rates on a line, totals mismatch, `MTChieu` > 20 characters, buyer no longer valid at completion (`buyer_invalid`), buyer snapshot missing or unusable (`buyer_snapshot_invalid`), citizen ID cannot be decrypted (`buyer_id_unreadable`), ... | no |
| `config` | `not_configured` | credentials missing, template missing, template not offered or out of numbers, template rejected by Mat Bao (`errorCode` 308/327), default template changed after an uncertain create (`template_changed`), encryption key missing when a snapshot with a citizen ID is sent (`buyer_id_key_missing`) | no |
| `order`  | `stopped`        | order left `completed`, refunded before sending                                         | no |
| `matbao` | `pending` → `needs_attention` | request did not complete, HTTP 5xx/408/429, unreadable 2xx body (create-invoice or templates), duplicate `MTChieu` 305 | yes; a create-invoice retry looks `MTChieu` up first |
| `matbao` | `failed`         | other HTTP status (4xx), Mat Bao `success` false with another `errorCode` or none (rejected invoice, login) | no |
| `matbao` | `needs_attention` | the lookup by `MTChieu` found something that may not be this order's draft (`matbao_lookup_mismatch`) | no |
| `system` | `pending` → `failed` | queueing failed (the sweep tries once more, then final)                          | sweep only |
| `system` | `failed`         | unexpected exception                                                                    | no |

`InvoiceErrorCode` has 31 codes. Buyer errors name only the invalid field, never its value or the
citizen ID, and the order stays `completed`.

`InvoiceErrorCode::is_auto_retryable()` is the retry switch: `matbao_transport`,
`matbao_http_temporary`, `matbao_response_unreadable` and `matbao_duplicate_reference`. These may
be retried, but a create-invoice call that failed this way may still have created the draft, so the
retry MUST look the invoice up by `MTChieu` first. A full refund moves the order to
`refunded` (`order_not_completed`, no alert); a partial refund keeps it `completed`
(`order_refunded`, alert). Each recorded error an admin must act
on fires `do_action( 'einvoice_matbao_invoice_needs_attention', InvoiceRecord $invoice,
InvoiceErrorCode $code )` once; an order the shop moved out of `completed` on purpose, and an
error still waiting for a retry, do not.
Admin and Lark alerts (#7) hook there. The `InvoiceRecord` carries `buyer_name` (a person's name or a
business name), so an alert listener must not forward that column.

## Alerts (admin notice and Lark)

`Alert\InvoiceAlerts` listens to `einvoice_matbao_invoice_needs_attention` and
`einvoice_matbao_retries_exhausted`. It records one alert per (order, error code, retry cycle) in a
non-autoloaded option (`add_option` is atomic and survives restarts), then queues the Lark message
with Action Scheduler (`einvoice_matbao_send_alert`) so the invoice run never waits for Lark. A
resend by an admin starts a new retry cycle, so the same error alerts again. The Lark text holds
only the order number, the error label and code, and the admin link to the order. Lark failures
(network, HTTP, no webhook) are logged without personal data and shown as a note in the admin
notice; they never touch the invoice or queue a draft action. The notice shows for `manage_options`
users, links to the order and can be dismissed.

## Unit of measure (`DVTinh`)

The store default is set on the settings page; fees and shipping always use it. A product or
variation can override it, and a variation without its own override inherits its parent's.

Limits: the per-product unit can only be edited on the product edit screen (and in its variation
panels). Quick edit, bulk edit and CSV import do not support it yet; they leave an existing
override untouched.

## Invoice request at Classic checkout

A **Yêu cầu xuất hóa đơn** checkbox below the billing form opens an individual/business choice.
Individuals must give address and CCCD (12 digits) besides billing name and phone; businesses give
business name, MST (10 digits, or 13 with an optional `-`), address, plus billing phone and email.
`BuyerRules` validates the structure only (never identity) on the server through WooCommerce's
checkout hooks and again when the order is created; `assets/src/checkout.ts` (built by
`bun run build`) mirrors it in the browser. Without the checkbox the buyer is always a walk-in
buyer, even with billing data.

The CCCD is stored only in order meta, encrypted with `EINVOICE_MATBAO_ENCRYPTION_KEY`; without the
key the individual option is disabled and refused server-side. Logs, order notes and invoice rows
show only the last 4 digits; 12-digit runs in Mat Bao error messages are masked the same way. The
request and snapshot meta are removed from WooCommerce REST order responses (wc/v2, wc/v3 and the
experimental wc/v4). When the order completes and its invoice row is reserved, the buyer is checked
against `BuyerRules` again (billing may have been edited); an invalid buyer fails the draft with a
reason naming the fields only. Otherwise a **buyer snapshot** is captured into order meta and every
send builds the payload from it (not from the current order). Blocks checkout (#9) reuses
`BuyerRules`, `BuyerRequestStore::from_input` and `BuyerSnapshotter`; the browser rules in
`assets/src/checkout-rules.ts` are covered by `bun run test`.

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

### Draft invoice rules and limits

- Money is whole VND: a line whose net, tax or total is fractional is refused (`failed`), whatever
  `price_num_decimals` is. Fee and shipping lines of 0 đồng are left off the invoice; a free product
  line is sent with `TSuat` from its tax status (`none` → -1, otherwise 0).
- Retries use Action Scheduler, so the delays are lower bounds: WP-Cron must run for them to fire.
- Mat Bao's atomic duplicate check is unverified under concurrency (ADR 0001); the lookup narrows the
  window but a lookup/create race at Mat Bao is not excluded. Real-API checks of the lookup response
  shape beyond `docs/research` samples belong to #15.
- The lookup searches one template (`mauSo`/`kiHieu` are required); a draft under another template
  is not found, which is why a changed default template stops instead of creating.
- The demo lookup response has no total; the total check runs only when Mat Bao returns `tgTTTBSo`.
- The sweep runs on Action Scheduler too: if Action Scheduler is down entirely, nothing is recovered
  and nothing alerts.
- A draft adopted by the lookup keeps the `NLap` Mat Bao reports, which may predate the day it was adopted.
- The manual re-send button is #9.
- A `buyer_invalid` row is `failed` and holds no buyer snapshot, and nothing restarts a `failed` row yet.
  A re-send (#9) must run `BuyerSnapshotter::validate()` again and `capture()` when the row has no
  snapshot or it needs updating. Capturing again is only safe while the invoice cannot have been created
  (`!may_have_been_created`); otherwise #14 must compare `NMua_Ten`/`NMua_MST` in `lookup_mismatch`,
  since adopting a draft compares only the total.
- `DGia` = net / quantity can be a non-terminating fraction (net 100000, quantity 3); behavior on
  the demo API is unverified (#15).
- `TgTTTBChu` carries Vietnamese diacritics; the proven demo payloads were sent without. Confirm in #15.
