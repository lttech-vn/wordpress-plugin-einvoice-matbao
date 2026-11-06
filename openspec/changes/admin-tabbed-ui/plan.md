# Admin Tabbed UI Implementation Plan

> Executed via `/opsx-apply`, which chooses the executor.
> Spec reference: this change's `specs/` (`specs/admin-settings/spec.md`, `specs/admin-invoice-management/spec.md`) and `design.md`.

**Goal:** Merge the plugin's two admin screens into one WooCommerce submenu "Hóa đơn Mắt Bão" with five htmx-switched tabs, the invoice list first, plus light visual polish (tab bar, spacing, status badges).

**Architecture:** A small `AdminTabs` registry defines tab keys, labels, default and URLs. `SettingsPage::render_tabs()` builds the tab bar plus the active panel from per-tab panel methods; the page shell renders it on first load and a new REST route `GET /tabs/{tab}` returns the same fragment for htmx swaps into `#einv-tabs` with `hx-push-url`. The old invoices slug becomes a hidden page that 302-redirects to `tab=invoices`.

**Tech Stack:** PHP 8.2+, WordPress 6.6+ / WooCommerce 9.8+, htmx 2.0.11 (vendored), Tailwind CSS 4.3.3 scoped under `#einv-app`, PHPUnit (wp-phpunit) in Docker, e2e army runner (`tests/e2e`).

## Global Constraints

- All UI strings Vietnamese via `__()`/`esc_html__()` with text domain `einvoice-matbao`; code comments, commit messages in English.
- Every route stays in namespace `einvoice-matbao/v1`, uses `SettingsController::check_permission`, and responds with HTML via `serve_html` (ADR-0003).
- Capability `manage_options` (`SettingsPage::CAPABILITY`) for every page and route.
- No new dependency, no new design token, no new JS bundle; only utility classes inside `#einv-app` (`bun run check:css-scope` must pass).
- Existing route paths, form field names, element ids (`einv-environment`, `einv-mst`, `einv-template`, `einv-invoices`, `einv-dashboard`, `einv-reload-templates`, …) and `hx-*` attributes of forms stay unchanged.
- Run PHP tooling only in Docker: `einv composer …` where `einv='docker compose exec -T -w /var/www/html/wp-content/plugins/einvoice-matbao wordpress'` (README "Tests, lint and static analysis").
- Work in a Worktrunk (`wt`) worktree, never on `main`.

## Review Focus

1. **htmx swaps re-running `load` triggers** — after switching to Hóa đơn or Kết nối, dashboard/list/template list must load; covered by e2e in Task 7 ("switching to the connection tab loads the template list").
2. **Chi tiết / pagination / dashboard cards targeting `#einv-invoices`** must not replace `#einv-tabs`; covered by e2e "detail stays inside the invoices tab" (Task 7).
3. **Malformed `tab` query on first load** (`tab[]=x`, `tab=LARK`, `tab=<script>`) must fall back to `invoices` without notices, matching the REST route's exact, case-sensitive key check; covered by `AdminTabsTest::test_resolve_falls_back_for_unknown_keys` and `SettingsPageTest::test_unknown_tab_falls_back_to_invoices` (`foo`, `LARK`, array).
4. **Missing encryption key banner** must render outside `#einv-tabs` so tab swaps never remove it; covered by `SettingsPageTest::test_encryption_banner_renders_above_the_tab_bar`.
5. **Hidden redirect page on the PHP 8.2 / WP 6.6 floor** must not emit deprecation notices; covered by `SettingsPageTest::test_old_invoices_page_redirects_to_invoices_tab` run in `scripts/check.sh --min`.

---

## Contracts

| Name | new / modified | Shape | public |
|---|---|---|---|
| `AdminTabs` | new | `final class LTTech\EinvoiceMatbao\Admin\AdminTabs` in `src/Admin/AdminTabs.php` | no |
| `AdminTabs::DEFAULT` | new | `public const DEFAULT = 'invoices';` | no |
| `AdminTabs::labels()` | new | `public static function labels(): array` → `array<string,string>` ordered: `invoices` => "Hóa đơn", `connection` => "Kết nối Mắt Bão", `invoicing` => "Xuất hóa đơn", `lark` => "Cảnh báo Lark", `advanced` => "Nâng cao" | no |
| `AdminTabs::resolve()` | new | `public static function resolve( mixed $tab ): string` — returns the key if it is a string present in `labels()` (exact, case-sensitive), else `DEFAULT` | no |
| `AdminTabs::url()` | new | `public static function url( string $tab ): string` — `admin_url( 'admin.php?page=einvoice-matbao-settings&tab=' . $tab )` | no |
| `InvoiceStatus::group()` | new | `public static function group( string $status ): string` → `'error'` \| `'waiting'` \| `'created'` \| `'other'`; constants `GROUP_ERROR`, `GROUP_WAITING`, `GROUP_CREATED`, `GROUP_OTHER` | no |
| `SettingsPage::render_tabs()` | new | `public static function render_tabs( string $tab ): string` — escaped HTML: `<nav class=… aria-label="Hóa đơn Mắt Bão">` with one `<a href="{url}" hx-get="{rest tabs/key}" hx-target="#einv-tabs" hx-push-url="{url}" data-einv-tab-link="{key}" [aria-current="page"]>` per tab, then `<div id="einv-tab-panel" data-einv-tab="{key}">{panel}</div>`; `$tab` must already be resolved | no |
| `SettingsPage::render()` | modified | page title "Hóa đơn Mắt Bão"; outputs `#einv-app` > banner (optional) > `<div id="einv-tabs" hx-history-elt>` render_tabs(resolved `$_GET['tab']`) | no |
| `SettingsPage::render_connection_panel()` / `render_invoicing_panel()` / `render_lark_panel()` | new | `private static function …(): string` — existing section markup unchanged | no |
| `InvoicePage::render_panel()` | new | `public static function render_panel(): string` — dashboard + filter form + `#einv-invoices` (former `render()` body inside `#einv-app`) | no |
| `InvoicePage::render()` / `register_menu()` | modified | `render()` removed in Task 5 (kept as `echo render_panel()` wrapper during Task 3–4); `register_menu()` registers hidden page `einvoice-matbao-invoices` and hooks `load-{hook}` to `redirect_to_tab()` | no |
| `InvoicePage::redirect_to_tab()` | new | `public static function redirect_to_tab(): void` — `if ( wp_safe_redirect( AdminTabs::url( AdminTabs::DEFAULT ), 302 ) ) { exit; }` | no |
| `UninstallSettingsController::render_section()` | modified | `public static function render_section(): string` (returns HTML instead of echoing) | no |
| REST `GET einvoice-matbao/v1/tabs/(?P<tab>[^/]+)` | new | 200: `render_tabs( key )` HTML; 404: `Fragment::error( 'Không tìm thấy tab này.', 404 )`; 401/403 via `check_permission` | yes (admin REST surface) |
| Admin URL `admin.php?page=einvoice-matbao-settings&tab=<key>` | new | query arg `tab`, keys from `AdminTabs::labels()` | yes |
| Admin URL `admin.php?page=einvoice-matbao-invoices` | modified | 302 → `…page=einvoice-matbao-settings&tab=invoices` | yes |
| Badge markup | new | `<span class="… rounded-full px-2 py-0.5 text-[12px] font-medium {colors}" data-einv-badge="{group}">{label}</span>` in the status cell | no |
| htmx config | modified | inline script additionally sets `htmx.config.refreshOnHistoryMiss=true` | no |

---

## Task 1: Tab registry — implements 1.1

**Files:**
- Create: `src/Admin/AdminTabs.php`
- Test: `tests/integration/AdminTabsTest.php`

**Interfaces:**
- Produces: `AdminTabs` contract entries (`DEFAULT`, `labels()`, `resolve()`, `url()`).

- [ ] **Step 1: Write failing tests** in `AdminTabsTest`:
  - `test_tabs_are_ordered_with_invoices_first`: `assertSame( array( 'invoices', 'connection', 'invoicing', 'lark', 'advanced' ), array_keys( AdminTabs::labels() ) )`; `assertSame( 'Hóa đơn', AdminTabs::labels()['invoices'] )`.
  - `test_resolve_keeps_known_keys`: `assertSame( 'lark', AdminTabs::resolve( 'lark' ) )`.
  - `test_resolve_falls_back_for_unknown_keys` with data provider `''`, `'foo'`, `'LARK'`, `'Lark1'`, `null`, `array( 'lark' )`, `'<script>'` → `'invoices'`.
  - `test_url_points_to_the_settings_page_tab`: `assertSame( admin_url( 'admin.php?page=einvoice-matbao-settings&tab=lark' ), AdminTabs::url( 'lark' ) )`.
- [ ] **Step 2: Run** `einv vendor/bin/phpunit -c phpunit.xml.dist --filter AdminTabsTest` — Expected: FAIL (class not found).
- [ ] **Step 3: Implement** `AdminTabs` per Contracts (labels via `__()`; `url()` builds from `SettingsPage::SLUG`).
- [ ] **Step 4: Run** same command — Expected: PASS.
- [ ] **Step 5: Commit** `feat(admin): add tab registry for the plugin screen`.

## Task 2: Status groups — implements 1.2

**Files:**
- Modify: `src/Invoice/InvoiceStatus.php`
- Test: `tests/integration/InvoiceStatusGroupTest.php` (new)

**Interfaces:**
- Produces: `InvoiceStatus::group()`, `GROUP_*` constants.

- [ ] **Step 1: Write failing test** `test_every_status_maps_to_its_group` with provider: `failed`, `needs_attention`, `not_configured` → `error`; `pending`, `sending` → `waiting`; `draft_created` → `created`; `skipped`, `stopped`, `unknown_x` → `other`.
- [ ] **Step 2: Run** `einv vendor/bin/phpunit -c phpunit.xml.dist --filter InvoiceStatusGroupTest` — Expected: FAIL.
- [ ] **Step 3: Implement** `group()` using `error_statuses()` / `waiting_statuses()` / `DRAFT_CREATED`.
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** `feat(invoice): group invoice statuses for badges`.

## Task 3: Panels and tab shell — implements 2.1, 2.2, 2.3, 2.4

**Files:**
- Modify: `src/Admin/SettingsPage.php`, `src/Admin/InvoicePage.php`, `src/Uninstall/UninstallSettingsController.php`
- Test: `tests/integration/SettingsPageTest.php`, `tests/integration/SettingsUninstallRestTest.php`, `tests/integration/SettingsUnitRestTest.php`, `tests/integration/SettingsCredentialsRestTest.php`, `tests/integration/SettingsInvoicingRestTest.php`, `tests/integration/InvoiceAdminListRestTest.php`

**Interfaces:**
- Consumes: `AdminTabs` (Task 1).
- Produces: `SettingsPage::render_tabs()`, `InvoicePage::render_panel()`, `UninstallSettingsController::render_section(): string`.

- [ ] **Step 1: Write failing tests** in `SettingsPageTest` (admin user; set `$_GET['tab']` in the test, unset in `tear_down`):
  - `test_default_tab_is_invoices`: no `tab` → page contains `data-einv-tab="invoices"`, `data-einv-tab-link="invoices" aria-current="page"` (assert via regex on the `<a …>` containing both), `id="einv-invoices"`, and does NOT contain `id="einv-mst"`.
  - `test_deep_link_renders_the_lark_tab`: `tab=lark` → contains `data-einv-tab="lark"`, `id="einv-lark-webhook"`; not `id="einv-invoices"`.
  - `test_unknown_tab_falls_back_to_invoices`: `tab=foo`, `tab=LARK` and `tab=array('lark')` → `data-einv-tab="invoices"`.
  - `test_tab_links_have_real_hrefs_and_push_url`: for each key, contains `href="` . esc_url( AdminTabs::url( key ) ) and `hx-push-url="` . esc_url( AdminTabs::url( key ) ) and `hx-get="` . esc_url( rest_url( 'einvoice-matbao/v1/tabs/' . key ) ); and `hx-target="#einv-tabs"`.
  - `test_encryption_banner_renders_above_the_tab_bar`: without key filter → `strpos( 'EINVOICE_MATBAO_ENCRYPTION_KEY' ) < strpos( 'id="einv-tabs"' )`.
  - `test_tabs_group_the_existing_forms`: `render_tabs('connection')` contains `settings/matbao` hx-post, `einv-reload-templates` and `einv-matbao-saved from:body`; `render_tabs('invoicing')` contains `settings/invoicing` and `settings/unit` hx-posts and no `settings/lark`; `render_tabs('advanced')` contains `data-einv-section="uninstall"`.
  - Rewrite `test_page_is_scoped_inside_einv_app_and_targets_plugin_rest_routes` to assert per tab via `render_tabs()` (same hx-post URLs and strings as today).
  - Retarget existing page-render assertions to the tab that now holds the form: `SettingsUninstallRestTest.php:104-118` → `render_tabs('advanced')`; `SettingsUnitRestTest.php:74-79` and `SettingsInvoicingRestTest.php:229` → `render_tabs('invoicing')`; `SettingsCredentialsRestTest.php:95,178` → `render_tabs('connection')` and `render_tabs('lark')` (keep the not-contains assertions meaningful by also asserting the form is present); `InvoiceAdminListRestTest::test_search_box_has_an_accessible_name` → `InvoicePage::render_panel()`.
  - `tear_down` unsets `$_GET['tab']`.
- [ ] **Step 2: Run** `einv vendor/bin/phpunit -c phpunit.xml.dist --filter SettingsPageTest` — Expected: FAIL.
- [ ] **Step 3: Implement**: extract `render_connection_panel()` (Kết nối + Mẫu cards), `render_invoicing_panel()` (MTChieu + Đơn vị tính cards), `render_lark_panel()` with `ob_start()`/`ob_get_clean()` around the existing markup; `UninstallSettingsController::render_section()` returns string; `InvoicePage::render_panel()` returns the former shell body; `render_tabs()` per Contracts; `render()` keeps its `current_user_can` early return, then prints `<h1>` "Hóa đơn Mắt Bão", banner, then `<div id="einv-tabs" hx-history-elt>` . `render_tabs( AdminTabs::resolve( isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : '' ) )` — no `sanitize_key` (it lowercases); `resolve()` is an exact whitelist so the value is safe; phpcs ignores for `NonceVerification.Recommended` and `ValidatedSanitizedInput.InputNotSanitized` with reason "read-only view switch, whitelisted by AdminTabs::resolve". `InvoicePage::render()` temporarily becomes `echo self::render_panel()` inside the old shell until Task 5.
- [ ] **Step 4: Run** the filter — Expected: PASS; then `einv composer lint` and `einv composer analyse` — Expected: no errors.
- [ ] **Step 5: Commit** `refactor(admin): render the plugin screen as tabs`.

## Task 4: Tab fragment route — implements 3.1, 3.2

**Files:**
- Modify: `src/Admin/SettingsController.php`
- Test: `tests/integration/SettingsTabsRestTest.php` (new, extends `SettingsRestTestCase`)

**Interfaces:**
- Consumes: `SettingsPage::render_tabs()`, `AdminTabs::resolve()`/`labels()`.
- Produces: REST `GET /tabs/{tab}`.

- [ ] **Step 1: Write failing tests**:
  - `test_each_tab_returns_its_panel` (provider over the five keys): admin, `htmx( 'GET', '/tabs/' . $key )` → status 200, HTML contains `data-einv-tab="{key}"` and `aria-current="page"` on that link.
  - `test_unknown_tab_is_not_found` (provider `foo`, `Lark1`): status 404, HTML contains `data-einv-status="error"`, does not start with `{`.
  - `test_anonymous_request_is_rejected`: user 0 → 401.
  - `test_shop_manager_is_rejected`: 403 and HTML does not contain `einv-mst`.
  - `test_invalid_nonce_is_rejected`: nonce `'bad'` → 403.
- [ ] **Step 2: Run** `einv vendor/bin/phpunit -c phpunit.xml.dist --filter SettingsTabsRestTest` — Expected: FAIL (404 no route for valid keys).
- [ ] **Step 3: Implement** route `'/tabs/(?P<tab>[^/]+)' => READABLE, 'show_tab'`; `show_tab()` compares `$request['tab']` with `array_key_exists( …, AdminTabs::labels() )` → `Fragment::response( SettingsPage::render_tabs( $key ) )`, else `Fragment::error( __( 'Không tìm thấy tab này.', 'einvoice-matbao' ), 404 )`.
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** `feat(admin): serve tab fragments over REST for htmx`.

## Task 5: Single menu, redirect, assets, history — implements 4.1, 4.2, 4.3

**Files:**
- Modify: `src/Admin/SettingsPage.php`, `src/Admin/InvoicePage.php`
- Test: `tests/integration/SettingsPageTest.php`

**Interfaces:**
- Consumes: `AdminTabs::url()`.
- Produces: hidden page + `InvoicePage::redirect_to_tab()`. No test-only hook is added: `wp_safe_redirect()` returns `false` when the `wp_redirect` filter returns a falsy location, so `redirect_to_tab()` skips `exit` in tests.

- [ ] **Step 1: Write failing tests**:
  - `test_submenu_is_registered_under_woocommerce_for_administrators_only` (update): after `SettingsPage::register_menu()` and `InvoicePage::register_menu()`, `$submenu['woocommerce']` has `einvoice-matbao-settings` with `manage_options` and menu title "Hóa đơn Mắt Bão", and has NO entry `einvoice-matbao-invoices`.
  - `test_old_invoices_page_redirects_to_invoices_tab`: add a `wp_redirect` filter (accepted args 2) that records `$location` and `$status` and returns `false`; call `InvoicePage::register_menu()` then `InvoicePage::redirect_to_tab()`; assert recorded location `=== AdminTabs::url( 'invoices' )`, status `302`, and `0 === did_action( 'deprecated_function_run' ) + did_action( 'deprecated_argument_run' )`.
  - `test_assets_load_only_on_the_plugin_page_with_nonce_attached_by_htmx_config_request` (update): additionally `enqueue_assets( 'woocommerce_page_einvoice-matbao-invoices' )` and `'admin_page_einvoice-matbao-invoices'` leave the script not enqueued; inline script contains `refreshOnHistoryMiss`.
  - `test_tab_container_owns_history_snapshots`: page contains `id="einv-tabs" hx-history-elt`.
  - `tear_down` additionally resets `$submenu['']`, `$_registered_pages`, `$_wp_submenu_nopriv` and removes actions on `load-admin_page_einvoice-matbao-invoices`. Deprecations are caught by `WP_UnitTestCase` (`_deprecated_*` → failure) plus the `did_action` assertion; PHP-level deprecations surface through `failOnWarning` in `phpunit.xml.dist`.
- [ ] **Step 2: Run** `einv vendor/bin/phpunit -c phpunit.xml.dist --filter SettingsPageTest` — Expected: FAIL.
- [ ] **Step 3: Implement**: `SettingsPage::register_menu()` menu title and page title "Hóa đơn Mắt Bão"; `InvoicePage::register_menu()` calls `add_submenu_page( '', …, self::SLUG, array( self::class, 'redirect_to_tab' ) )`, stores hook suffix and `add_action( 'load-' . $hook, array( self::class, 'redirect_to_tab' ) )`; `redirect_to_tab()` per Contracts; delete `InvoicePage::render()`. If the floor run (Step 4) reports a deprecation for the empty parent, switch to registering under `woocommerce` + `remove_submenu_page( 'woocommerce', self::SLUG )` on `admin_head`. `enqueue_assets` compares only `'woocommerce_page_' . self::SLUG`; append `htmx.config.refreshOnHistoryMiss=true;` inside `htmx_config_script` (guard `window.htmx`).
- [ ] **Step 4: Run** — Expected: PASS; `einv composer lint`, `einv composer analyse` clean; then `scripts/check.sh --min` (PHP 8.2 / WP 6.6 floor) — Expected: PASS with no deprecation output; otherwise apply the fallback now and re-run.
- [ ] **Step 5: Commit** `feat(admin): single plugin menu with redirect from the old invoice list`.

## Task 6: Visual polish — implements 5.1, 5.2, 5.3

**Files:**
- Modify: `src/Admin/InvoicePage.php` (`render_list`), `src/Admin/SettingsPage.php` (`render_tabs` classes, panel widths)
- Build: `assets/dist/admin.css`
- Test: `tests/integration/InvoiceAdminListRestTest.php`

**Interfaces:**
- Consumes: `InvoiceStatus::group()`.

- [ ] **Step 1: Write failing test** `test_status_is_shown_as_a_group_badge` with provider: (`failed`, `error`, "Lỗi"), (`needs_attention`, `error`, "Lỗi cần xử lý"), (`pending`, `waiting`, "Chờ gửi"), (`draft_created`, `created`, "Đã tạo nháp"), (`skipped`, `other`, "Bỏ qua"), (`stopped`, `other`, "Đã dừng"): reserve one invoice, GET `/invoices`, `assertMatchesRegularExpression( '#data-einv-badge="' . $group . '">' . preg_quote( $label ) . '</span>#u', $html )`.
- [ ] **Step 2: Run** `einv vendor/bin/phpunit -c phpunit.xml.dist --filter InvoiceAdminListRestTest` — Expected: FAIL.
- [ ] **Step 3: Implement** badge per Contracts with D8 colours (`error` → `bg-einv-error-bg text-einv-error`; `created` → `bg-einv-success-bg text-einv-success`; `waiting` → `bg-einv-primary/10 text-einv-primary`; `other` → `bg-einv-canvas-soft text-einv-ink-mute`). Tab bar per D9: `nav` `flex gap-6 overflow-x-auto border-b border-einv-hairline`; link `-mb-px whitespace-nowrap border-b-2 border-transparent px-1 pb-3 text-[14px] text-einv-ink-mute no-underline hover:text-einv-ink focus-visible:outline-2 focus-visible:outline-einv-primary`; active adds `border-einv-primary text-einv-primary font-medium`; panel wrapper `mt-6`; config panels wrap cards in `max-w-3xl`; drop per-card `max-w-3xl` duplication only if identical result.
- [ ] **Step 4: Run** the test — Expected: PASS. Then `bun run build:css && bun run check:css-scope` — Expected: scope check OK.
- [ ] **Step 5: Visual check** (manual, results noted in `apply.md`): on the local Docker site (`docker compose up -d --wait`, README "Development"), open each of the 5 tabs at 1440px and 782px widths with playwright-cli screenshots; confirm active underline, no wrapping tab bar, readable badges; run the Impeccable skill's `detect --json` on `src/Admin/SettingsPage.php src/Admin/InvoicePage.php` and fix findings in scope.
- [ ] **Step 6: Commit** `style(admin): tab bar, spacing and status badges`.

## Task 7: End-to-end coverage — implements 6.1, 6.2

**Files:**
- Modify: `tests/e2e/army/support.ts`, `settings.army.ts`, `invoices.army.ts`, `failures.army.ts`, `checkout-invoice.army.ts`, `units-permissions.army.ts`, `tests/e2e/e2e.army.config.ts`, `tests/e2e/army-cache/*.json`
- Create: `tests/e2e/army/tabs.army.ts`

**Prerequisite:** recording needs model credentials in `tests/e2e/.env.e2e.local` (README "Browser E2E"). If unavailable, stop after Step 3 and report the task as blocked instead of committing a stale cache.

**Interfaces:**
- Produces: `PAGES.tab(key)`, `PAGES.settings` = `tab('connection')`, `PAGES.invoices` = `tab('invoices')`, `PAGES.oldInvoices`.

- [ ] **Step 1: Update `PAGES`**: `tab: (key: string) => \`/wp-admin/admin.php?page=einvoice-matbao-settings&tab=${key}\``; `settings` → `tab("connection")`; add `invoicing`, `lark` keys where tests touch those forms; `invoices` → `tab("invoices")`; `oldInvoices: "/wp-admin/admin.php?page=einvoice-matbao-invoices"`.
- [ ] **Step 2: Fix existing suites**: `settings.army.ts` heading "Hóa đơn Mắt Bão"; the "shows sections" test checks each card heading on its tab (`connection`: "Kết nối Mắt Bão", "Mẫu hóa đơn mặc định"; `invoicing`: "Mã tham chiếu và người mua vãng lai", "Đơn vị tính"; `lark`: "Cảnh báo Lark"); tests touching MTChieu/unit/Lark open the matching tab; `checkout-invoice.army.ts` uses `PAGES.invoices`; `units-permissions.army.ts`: shop manager loops over `PAGES.tab("connection")`, `PAGES.invoices`, `PAGES.oldInvoices` expecting "Sorry, you are not allowed to access this page."; menu test expects no link "Hóa đơn Mắt Bão"; "administrator reaches both pages" → one heading "Hóa đơn Mắt Bão" on both URLs; `invoices.army.ts:98` replaces the sidebar link "Danh sách hóa đơn" assertion with `browser.locator('[data-einv-tab-link="invoices"]')` visible.
- [ ] **Step 2b: Update the `admin` agent context** in `tests/e2e/e2e.army.config.ts:38`: "'Hóa đơn Mắt Bão' is the plugin screen with tabs Hóa đơn (invoice list, default), Kết nối Mắt Bão, Xuất hóa đơn, Cảnh báo Lark, Nâng cao". This deliberately invalidates every `admin` recording; all admin suites are re-recorded in Step 4.
- [ ] **Step 3: Write `tabs.army.ts`** (exact steps, admin). Tab links are located only by `browser.locator('[data-einv-tab-link="<key>"]')` (role/name lookups are substring matches: "Hóa đơn" also hits "Xuất hóa đơn" and the sidebar). History navigation uses `browser.evaluate(() => history.back())`, not `goBack()`:
  - "the invoices tab is the default": open `/wp-admin/admin.php?page=einvoice-matbao-settings`; `[data-einv-tab-link="invoices"]` has `aria-current="page"`; `[data-einv-total]` visible.
  - "switching tabs does not reload and updates the URL": set `window.__einvMarker = 1` via `browser.evaluate`; tap `[data-einv-tab-link="lark"]`; label "Webhook URL" visible; `browser.url()` contains `tab=lark`; marker still `1`.
  - "switching to the connection tab loads the template list" (with saved credentials seam): tap `[data-einv-tab-link="connection"]`; label "Mẫu số / ký hiệu" visible.
  - "reload keeps the tab": after tapping `[data-einv-tab-link="invoicing"]`, `app.open(await browser.url())`; label "Đơn vị tính mặc định (DVTinh)" visible.
  - "back returns to the previous tab": from default tab tap `[data-einv-tab-link="connection"]`, wait for `[data-einv-tab="connection"]`, then `browser.evaluate(() => history.back())`; `[data-einv-tab="invoices"]` visible and URL lacks `tab=connection`.
  - "the old invoice list URL lands on the invoices tab": `app.open(PAGES.oldInvoices)`; URL contains `tab=invoices`; `[data-einv-tab="invoices"]` visible.
  - "detail stays inside the invoices tab": completed order → `draft_created`; open `PAGES.invoices`; `openOnlyInvoice`; "Lịch sử gửi" visible; `[data-einv-tab-link="invoices"]` still `aria-current="page"`.
- [ ] **Step 4: Record** `cd tests/e2e && bun run e2e:record` (full suite; `run-e2e.ts` forwards only `E2E_ARGS` to `e2e run`, and the changed agent context invalidates all admin entries anyway) — Expected: report all-passed. Review `test-results/runs/<id>/army-cache/*.json` per README "Cache review", replace `army-cache/` with that set (removing entries no longer produced), and update the entry count in `tests/e2e/README.md`.
- [ ] **Step 5: Replay** `bun run e2e:replay` — Expected: all-passed with zero cache misses.
- [ ] **Step 6: Commit** e2e sources, `e2e.army.config.ts` and the recorded `army-cache/` together: `test(e2e): cover the tabbed plugin screen`.

## Task 8: Docs and full verification — implements 6.3, 6.4

- [ ] **Step 0:** Update docs that name the old menu: `README.md:57` ("WooCommerce → Danh sách hóa đơn" → "WooCommerce → Hóa đơn Mắt Bão, tab Hóa đơn") and any other hit of `rg -n "Danh sách hóa đơn|einvoice-matbao-invoices" README.md docs tests/e2e/README.md`; commit `docs: point to the tabbed plugin screen`.

- [ ] **Step 1:** `scripts/check.sh` — Expected: PHPCS, PHPStan, both PHPUnit suites, floor guard pass.
- [ ] **Step 2:** `scripts/check.sh --min` — Expected: pass (already run in Task 5; re-run after all tasks).
- [ ] **Step 3:** `bun run test && bun run build && bun run check:css-scope` — Expected: pass. `cd tests/e2e && bun run e2e:replay` — Expected: all-passed.
- [ ] **Step 4:** Manual E2E in the dev site: open WooCommerce > Hóa đơn Mắt Bão, switch all 5 tabs, save Lark webhook from the Lark tab, open an invoice detail, use Back/Forward, open the old URL; record results in `apply.md`.

---

## Scenario → test

**admin-settings**
- Administrator sees the menu → `SettingsPageTest::test_submenu_is_registered_under_woocommerce_for_administrators_only`
- Shop manager denied → `SettingsPageTest::test_page_refuses_users_without_manage_options`; e2e `units-permissions.army.ts` "a shop manager cannot open the settings or the invoice list"
- Old invoice list URL → `SettingsPageTest::test_old_invoices_page_redirects_to_invoices_tab`; e2e `tabs.army.ts` "the old invoice list URL lands on the invoices tab"
- Old invoice list URL without capability → e2e `units-permissions.army.ts` shop manager loop over `PAGES.oldInvoices`
- Other admin page → `SettingsPageTest::test_assets_load_only_on_the_plugin_page_with_nonce_attached_by_htmx_config_request` (dashboard hook)
- Settings page → same test (plugin hook, nonce, `refreshOnHistoryMiss`)
- Default tab → `SettingsPageTest::test_default_tab_is_invoices`; e2e `tabs.army.ts` "the invoices tab is the default"
- Deep link to a tab → `SettingsPageTest::test_deep_link_renders_the_lark_tab`
- Unknown tab → `SettingsPageTest::test_unknown_tab_falls_back_to_invoices`; `AdminTabsTest::test_resolve_falls_back_for_unknown_keys`
- Missing encryption key on any tab → `SettingsPageTest::test_encryption_banner_renders_above_the_tab_bar`
- Template list stays with credentials → `SettingsPageTest::test_tabs_group_the_existing_forms` (connection contains both); e2e `settings.army.ts` "lists the VAT templates" after save (existing, now on `tab=connection`)
- Invoicing tab contents → `SettingsPageTest::test_tabs_group_the_existing_forms`
- Advanced tab contents → `SettingsPageTest::test_tabs_group_the_existing_forms`
- Switch tab → e2e `tabs.army.ts` "switching tabs does not reload and updates the URL"; `SettingsPageTest::test_tab_links_have_real_hrefs_and_push_url`
- Reload keeps the tab → e2e `tabs.army.ts` "reload keeps the tab"
- Back button → e2e `tabs.army.ts` "back returns to the previous tab"; `SettingsPageTest::test_tab_container_owns_history_snapshots`
- Unknown tab route → `SettingsTabsRestTest::test_unknown_tab_is_not_found` (`foo`)
- Malformed tab key → `SettingsTabsRestTest::test_unknown_tab_is_not_found` (`Lark1`)
- Tab route without capability → `SettingsTabsRestTest::test_shop_manager_is_rejected`

**admin-invoice-management**
- Shop manager → e2e `units-permissions.army.ts` menu test + REST 403 test; `InvoiceAdminListRestTest::test_non_administrator_is_rejected`
- Invoice list is the first tab → `AdminTabsTest::test_tabs_are_ordered_with_invoices_first`; e2e `tabs.army.ts` "the invoices tab is the default"
- Detail stays inside the invoices tab → e2e `tabs.army.ts` "detail stays inside the invoices tab"
- Failed invoice badge → `InvoiceAdminListRestTest::test_status_is_shown_as_a_group_badge` (failed)
- Draft created badge → same (draft_created)
- Pending invoice badge → same (pending)
- Needs attention invoice badge → same (needs_attention)
- Stopped invoice badge → same (stopped)
- Skipped invoice badge → same (skipped)
