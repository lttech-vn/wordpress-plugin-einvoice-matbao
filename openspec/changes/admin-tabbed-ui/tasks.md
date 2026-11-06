## 1. Tab registry and status groups

- [x] 1.1 Add `src/Admin/AdminTabs.php` (keys `invoices`, `connection`, `invoicing`, `lark`, `advanced` in order, Vietnamese labels, `DEFAULT`, `resolve()`, `url()`) with integration test `tests/integration/AdminTabsTest.php` (order, default, unknown/empty/uppercase key fallback, URL shape)
- [x] 1.2 Add `InvoiceStatus::group()` (`error` / `waiting` / `created` / `other`) with tests covering every status constant and an unknown value

## 2. Panel rendering split

- [x] 2.1 Refactor `SettingsPage::render()` sections into string-returning panel methods for `connection`, `invoicing`, `lark` (markup, ids, `hx-*` unchanged); make `UninstallSettingsController::render_section()` usable as the `advanced` panel
- [x] 2.2 Move the invoice shell (dashboard, filter form, `#einv-invoices`) from `InvoicePage::render()` into `InvoicePage::render_panel()`
- [x] 2.3 Add shared `render_tabs( string $tab ): string` (tab bar `nav` with `href` + `hx-get` + `hx-target="#einv-tabs"` + `hx-push-url`, `aria-current="page"` on the active tab, `#einv-tab-panel[data-einv-tab]`) and render it from `SettingsPage::render()` using unslashed `$_GET['tab']` resolved by `AdminTabs::resolve()` (exact whitelist, no lowercasing); encryption-key banner stays above `#einv-tabs`; page title "Hóa đơn Mắt Bão"
- [x] 2.4 Retarget existing page-render assertions in `SettingsUninstallRestTest`, `SettingsUnitRestTest`, `SettingsCredentialsRestTest`, `SettingsInvoicingRestTest`, `InvoiceAdminListRestTest` to the tab that now holds each form

## 3. Tab fragment route

- [ ] 3.1 Register `GET /tabs/(?P<tab>[^/]+)` in `SettingsController::register_routes()` with `check_permission`; handler returns `render_tabs()` or a 404 error notice for unknown keys
- [ ] 3.2 Integration test `tests/integration/SettingsTabsRestTest.php`: each tab returns its panel and active marker, `foo` and `Lark1` return 404 HTML notice, anonymous 401, shop manager 403, wrong nonce 403

## 4. Menu, redirect and assets

- [ ] 4.1 Remove the visible "Danh sách hóa đơn" submenu; register hidden page `einvoice-matbao-invoices` whose `load-{hook}` redirects (302) to `AdminTabs::url( 'invoices' )`; verify no deprecation notice on the PHP 8.2 / WP 6.6 floor, else fall back to `remove_submenu_page`
- [ ] 4.2 `enqueue_assets` loads only on `woocommerce_page_einvoice-matbao-settings`; inline script also sets `htmx.config.refreshOnHistoryMiss = true`; `#einv-tabs` carries `hx-history-elt`
- [ ] 4.3 Update `tests/integration/SettingsPageTest.php`: single menu entry, no visible invoices entry, default tab render, deep link `tab=lark`, unknown tab fallback, banner present on invoices tab, assets not enqueued on the old hook, redirect target of the hidden page

## 5. Visual polish

- [ ] 5.1 Status badges in `InvoicePage::render_list()` (`span[data-einv-badge]`, colours from existing `einv-*` tokens per design D8) with list tests in `tests/integration/InvoiceAdminListRestTest.php` for `failed`, `needs_attention`, `pending`, `draft_created`, `skipped`, `stopped`
- [ ] 5.2 Tab bar styling and panel spacing per design D9 (active underline, hover, focus-visible, horizontal scroll on narrow screens, config panels `max-w-3xl`, invoices panel full width); rebuild `assets/dist/admin.css` and pass `bun run check:css-scope`
- [ ] 5.3 Run the Impeccable detector on changed UI files and fix findings; visual check of all 5 tabs at desktop and narrow width

## 6. End-to-end tests

- [ ] 6.1 Update the `admin` agent context in `tests/e2e/e2e.army.config.ts` and `tests/e2e/army/support.ts` `PAGES` (settings → tab URLs, invoices → `tab=invoices`, keep an `oldInvoices` URL) and adjust `settings.army.ts`, `invoices.army.ts`, `failures.army.ts`, `checkout-invoice.army.ts`, `units-permissions.army.ts` for the new headings, tabs and single menu entry
- [ ] 6.2 Add e2e for tab switching without reload (URL contains `tab=`), reload keeps tab, Back returns to previous tab, old `page=einvoice-matbao-invoices` lands on the invoices tab, detail stays inside the invoices tab
- [ ] 6.3 Re-record the e2e cache (`bun run e2e:record`), review and commit `army-cache/`, confirm `bun run e2e:replay` passes; full verification `scripts/check.sh`, `scripts/check.sh --min`, `bun run test`, `bun run build`, `bun run check:css-scope`
- [ ] 6.4 Update docs naming the old menu (`README.md`, `tests/e2e/README.md`, `docs/`)
