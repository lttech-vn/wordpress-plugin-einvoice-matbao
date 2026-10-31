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

Administrators manage invoices under **WooCommerce → Danh sách hóa đơn**: filter by status (including an
error/needs-attention group), search by order ID or the start of `MTChieu` (prefix match), open a detail
view with attempt history (national ID numbers always masked), check the draft on Mat Bao, and resend.
Resend goes through the retry workflow, which looks the invoice up by `MTChieu` before creating, so it
never makes a duplicate:

- A draft that disappeared from Mat Bao is never recreated automatically; resending it needs an explicit
  confirmation and an empty lookup under the stored template.
- A `template_changed` stop is released only by typing back the `MTChieu` **and** an empty lookup under
  the old (stored) template; anything found, or a failed lookup, refuses and changes nothing. Only the
  request that wins the restart records the `admin_absent` attempt and clears the stored template.
- After `buyer_invalid` / `buyer_snapshot_invalid` (no usable snapshot), resend checks the buyer again:
  still invalid refuses with the field names (never values); valid captures the snapshot, unless an
  earlier attempt may have reached Mat Bao, in which case the existing snapshot is kept.
- Each resend starts a new alert cycle, so a plain resend of `template_changed` that stops again sends
  one new alert (admin notice and Lark) per resend; nothing repeats without an admin action.

The WooCommerce order list has an invoice filter on both HPOS and legacy screens; it adds an `EXISTS`
on the invoice table to the order query, so it combines with other filters and has no result cap.

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
  `einvoice_matbao_retries_exhausted( $order_id, $reference )` fire once.
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
`einvoice_matbao_retries_exhausted`. It records one alert per (order, error code, alert cycle) in a
non-autoloaded option (`add_option` does nothing when the name exists; a race between two writers
can at worst send one duplicate Lark message), then queues the Lark message with Action Scheduler
(`einvoice_matbao_send_alert`) so the invoice run never waits for Lark. Every restart of an
invoice (admin resend, cancelled then completed again) bumps the invoice's `alert_cycle`, also when
it stops before any attempt, so the same error alerts again. The Lark text holds only the order
number, the error label and code, and the admin link to the order.

Lark is best-effort. A network error, HTTP 5xx, 408 or 429 is retried up to 3 times after 1, 5 and
15 minutes; other failures (other 4xx, wrong or missing webhook) are not retried. A failed message
is logged without personal data and shown as a note in the admin notice; it never touches the
invoice or queues a draft action.

The notice shows for `manage_options` users (at most 10 open alerts, one query), links to the
order and can be dismissed. The recovery sweep deletes alerts whose invoice was resent, paid or
removed, and alerts dismissed more than 30 days ago.

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
send builds the payload from it (not from the current order). Blocks checkout reuses
`BuyerRules`, `BuyerRequestStore::from_input` and `BuyerSnapshotter`; the browser rules in
`assets/src/checkout-rules.ts` are covered by `bun run test`.

## Invoice request at Checkout Blocks

The `einvoice-matbao/invoice-request` block (a child of the Checkout fields block, auto-inserted
after contact information via `blockHooks`) offers the same checkbox and fields. WooCommerce's
Additional Checkout Fields API cannot hide fields by buyer type or avoid persisting the CCCD, so
the block is an integration block (`assets/src/checkout-blocks.ts`) posting to a write-only Store
API checkout extension (`extensions.einvoice-matbao`, `BlocksCheckout`). The server re-validates
on `woocommerce_store_api_checkout_update_order_from_request` with `BuyerRules` and stores with
`BuyerRequestStore::from_input`, so a direct Store API call is checked the same way (HTTP 400 with
the Vietnamese message). The extension has no data callback: the CCCD is never echoed in a Store
API response, the cart extensions, an order note or the session.

The hook also fires on PUT/PATCH when a pending or failed order is in the session (failed-payment
retry). Those calls are ignored: only the place-order `POST` validates and stores, so a half-typed
request never gets a 400 and the encrypted CCCD is written once, on the real placement. A retry
POST on the failed order stores the request normally. Draft orders (`checkout-draft`) never hold
the CCCD. The schema caps lengths (CCCD 12, tax code 14, address and name 400), restricts
`buyer_type` to `individual`/`business`, and `request_invoice` accepts only booleans (WordPress
REST also accepts `"true"`/`1`); any other value is HTTP 400, never a silent walk-in.

Limits: the block UI has no browser test (Playwright in Docker not set up); the server seam is
covered by `tests/integration/BlocksCheckoutBuyerTest.php`. On a site whose Checkout page was
customised before activation, add the "Yêu cầu xuất hóa đơn" block in the editor.

## Privacy requests and uninstall

### Export and erase personal data (Tools → Export/Erase Personal Data)

`Privacy\BuyerPersonalData` registers a WordPress exporter and eraser (`einvoice-matbao`). Both find
the orders whose billing email is the requested address (guest orders included) plus the orders of
the user with that email, ten per page, like WooCommerce does.

- **Export**: one item per order with plugin data from a named buyer (individual or business):
  buyer type, name, MST, address, CCCD, phone, email, `MTChieu` and invoice status. The CCCD is
  exported as its 4-digit mask (`********1234`), never decrypted: ADR 0002 keeps the full number in
  encrypted order meta only, the export file is downloaded and mailed, and the person already knows
  their own number. No ciphertext or secret is exported. Walk-in orders export nothing.
- **Erase**: deletes `_einvoice_buyer_request` and `_einvoice_buyer_snapshot` from the order (the
  WooCommerce order itself is not changed), replaces `buyer_name` in the invoice row with core's
  `[deleted]` text and removes copies of the buyer's values from `status_reason` and attempt
  messages. The invoice and attempt rows, `MTChieu`, status and Mat Bao invoice ID are kept for
  accounting, reported as `items_retained` with a message saying so; the same message says that a
  draft or invoice already created on Mat Bao is outside the plugin and must be handled there.
- **Order with WooCommerce's erasers**: the plugin eraser runs at priority 5, before WooCommerce's
  order eraser (10), so it still finds orders by billing email. When WooCommerce anonymizes an order
  (its eraser with "Remove personal data from orders" on, or its scheduled removal), the plugin
  erases that order's data through `woocommerce_privacy_remove_order_personal_data` too.
- **Erasure during a send**: when the invoice is still waiting to be sent or retried, the right to
  erasure wins. The buyer meta is deleted, so the next attempt fails with a buyer-data error and
  the invoice becomes a failure for the admin to handle; a draft already created on Mat Bao stays
  there, outside the plugin. The eraser message says so.
- Orders in every status are covered, including trash and checkout drafts. The export also lists
  the buyer name on the invoice row and its status reason (ID numbers masked by `Invoice\Redactor`).
- Limits: values shorter than 3 characters are not searched for in free text. The order note
  written for a submitted ID number holds only its mask (`********1234`) and is not deleted, here
  or on uninstall. The export never carries the full ID number: a person who needs it must ask the
  store, which verifies their identity and reads it manually from the encrypted order meta.

### Deactivate and uninstall

Deactivating cancels every queued plugin action (Action Scheduler group `einvoice-matbao`: drafts,
the recovery sweep and the alert sends `einvoice_matbao_send_alert`); data stays and the sweep is queued again after
reactivation.

`uninstall.php` runs only when WordPress deletes the plugin. It loads `Uninstall\Uninstaller` by
file path, so it works without `vendor/` and with WooCommerce removed (actions are then cancelled
in the Action Scheduler tables directly). On multisite it runs on every site.

| Data | Default uninstall | With "Xóa toàn bộ dữ liệu" on |
|------|-------------------|-------------------------------|
| Mat Bao and Lark credentials (`einvoice_matbao_credentials`) | deleted | deleted |
| Transients (Mat Bao token, templates cache), deleted by name so a persistent object cache is covered; token names are listed in the option `einvoice_matbao_token_keys` | deleted | deleted |
| Alert records `einvoice_matbao_alert_*` (only order id, error code and Lark flags) and the token name list | deleted | deleted |
| Queued actions in group `einvoice-matbao` | cancelled | cancelled |
| Tables `{prefix}einvoice_matbao_invoice` and `_attempt` | kept | dropped |
| Order meta `_einvoice_buyer_request` (including the encrypted ID number `id_encrypted`), `_einvoice_buyer_snapshot` (legacy and HPOS) | kept | deleted |
| Order note with the masked ID number | kept | kept |
| Product meta `_einvoice_matbao_unit` | kept | deleted |
| Every other `einvoice_matbao_*` option (settings, schema version) | kept | deleted |
| WooCommerce orders and products | kept | kept |

The switch is in the **Gỡ plugin** section of the settings page (`POST settings/uninstall`): off by
default, Administrator and `wp_rest` nonce like every settings route, and turning it on requires
typing `XOA HET DU LIEU`. Turning it off needs no confirmation. Invoices on Mat Bao are never
affected. Deleting the meta on a very large shop runs one statement per key without batching.

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
- Order changes after the draft (#14): `OrderChangeWatcher` compares the order with `order_state`
  (keyed digests of amounts, buyer and refunded total, schema v5; no readable personal data) once per
  request at `shutdown`. After `draft_created` a change only sets `order_amounts_changed`,
  `order_buyer_changed` or `order_refunded_after_draft` (kind `order`, status unchanged), adds a note
  without personal data and fires `einvoice_matbao_invoice_needs_attention` once per change. The draft is
  never updated, cancelled or recreated; the accountant fixes it by hand. The warning stays on the row
  until the next change or a restart.
- The digests are keyed with `wp_salt('auth')`. Rotating the site salts changes every digest, so the next
  save of each order that already has a draft raises one false `order_amounts_changed` or
  `order_buyer_changed` warning (nothing else changes). A separate key was not added: one derived from
  `EINVOICE_MATBAO_ENCRYPTION_KEY` would be absent on sites without it, and any key switch has the same
  effect, so the risk is documented instead. Digests ignore line order and repeated spaces.
- Before the draft exists, an Administrator's (`manage_options`) buyer edit captures the snapshot again
  (validated first; invalid keeps the old snapshot and alerts `buyer_invalid`). After an attempt that may
  have reached Mat Bao the snapshot is kept, because adopting may bring the old buyer; the change is
  warned about once the draft exists. Edits by other roles never re-capture.
- Adopting compares `nMua_Ten`/`nMua_MST` of the found draft with the snapshot, but only when the
  lookup response has them; the demo response does not (#15 to confirm the real field names).
- Changes made through code that bypasses WooCommerce's order CRUD hooks (direct SQL) are not seen.
- `DGia` = net / quantity can be a non-terminating fraction (net 100000, quantity 3); behavior on
  the demo API is unverified (#15).
- `TgTTTBChu` carries Vietnamese diacritics; the proven demo payloads were sent without. Confirm in #15.

## Browser E2E and Mat Bao demo checks

`tests/e2e/` holds the agentic browser suite (Classic checkout, fake Mat Bao; not run in CI) and the guarded demo-API
harness. See [tests/e2e/README.md](tests/e2e/README.md) and [docs/research/e2e-demo-results.md](docs/research/e2e-demo-results.md).
