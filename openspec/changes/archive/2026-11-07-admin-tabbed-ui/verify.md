# Verification Report

**Change**: `admin-tabbed-ui`
**Verified at**: `2026-11-07 00:20`
**Iteration**: `1`
**Verifier**: parent session (fresh test runs, validate, clean tree) + `superspec.verifier` run `e614875c` (independent requirement/scenario/contract evidence), worktree `/Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.admin-tabbed-ui`

---

## 1. Structural Validation (`openspec validate admin-tabbed-ui`)

- [x] The command passes for this change

**Result**:

```text
$ openspec validate admin-tabbed-ui      (cwd: worktree)
Change 'admin-tabbed-ui' is valid        (exit 0)
```

---

## 2. Task Completion (`tasks.md`)

- [x] Every box is ticked, or each open box has a recorded reason

`openspec instructions apply --change admin-tabbed-ui --json` → `progress {"total":18,"complete":18,"remaining":0}`, state `all_done`. tasks.md: 18 `- [x]`, 0 `- [ ]`.

| Open task | Reason | Blocks archive? |
|---|---|---|
| — | — | — |

---

## 3. Requirement Evidence

- [x] ADDED / MODIFIED: implementation evidence (file / line)
- [x] REMOVED: none in either delta spec (Not applicable)
- [x] RENAMED: none in either delta spec (Not applicable)

| Requirement | Operation | Status | Evidence (file:line) or reason |
|---|---|---|---|
| admin-settings / Settings page access | MODIFIED | Pass | `src/Admin/SettingsPage.php:51-60` single submenu `einvoice-matbao-settings`, `manage_options`, title "Hóa đơn Mắt Bão"; `src/Admin/InvoicePage.php:53-65` hidden page `einvoice-matbao-invoices` (parent `''`) + `load-{hook}`; `InvoicePage.php:70-74` `wp_safe_redirect( AdminTabs::url( AdminTabs::DEFAULT ), 302 )` |
| admin-settings / Admin assets scoped to plugin pages | MODIFIED | Pass | `SettingsPage.php:68-77` enqueue gated on `woocommerce_page_einvoice-matbao-settings` only; `SettingsPage.php:376-380` inline nonce + 4xx swap script |
| admin-settings / Tabbed plugin screen | ADDED | Pass | `src/Admin/AdminTabs.php:13-52` (DEFAULT `invoices`, ordered labels, exact `resolve()`, `url()`); `SettingsPage.php:89` resolves `$_GET['tab']`; `SettingsPage.php:108` `#einv-tabs hx-history-elt`, banner rendered above it; `SettingsPage.php:128-136` `aria-current="page"` on the active tab |
| admin-settings / Settings grouped by tab | ADDED | Pass | `SettingsPage.php:138-147` `match( $tab )` → `render_connection_panel` (:162, credentials + template card :219), `render_invoicing_panel` (:232), `render_lark_panel` (:284), `UninstallSettingsController::render_section()` (`src/Uninstall/UninstallSettingsController.php:74`) |
| admin-settings / Tab switching without page reload | ADDED | Pass | `SettingsPage.php:128` `href` + `hx-get` + `hx-target="#einv-tabs"` + `hx-push-url`; `src/Admin/SettingsController.php:50` route `/tabs/(?P<tab>[^/]+)` with `check_permission`; `SettingsController.php:134-142` `show_tab` exact key check, 404 `Fragment::error`; `SettingsPage.php:378` `refreshOnHistoryMiss=true` |
| admin-invoice-management / Invoice management page access | MODIFIED | Pass | `InvoicePage.php:53-74` no visible "Danh sách hóa đơn" menu, old slug redirects; `InvoicePage.php:81` `render_panel()` used as default tab; `AdminTabs::labels()` lists `invoices` first |
| admin-invoice-management / Status badges in the invoice list | ADDED | Pass | `src/Invoice/InvoiceStatus.php:50-75` `group()` + `GROUP_*`; `InvoicePage.php:29-40` badge base class and group colours; `InvoicePage.php:163,201-209` `span[data-einv-badge]` with escaped label |

---

## 4. Scenario Coverage

- [x] Every scenario maps to a test
- [x] The project test command was run fresh in this step

**Test command** (cwd: worktree):
- `scripts/check.sh` → exit 0: PHPCS clean, PHPStan `[OK] No errors`, floor guard OK, PHPUnit integration `OK (598 tests, 3228 assertions)`, without-WooCommerce `OK (8 tests, 21 assertions)`.
- `bun run test` → 12 pass, 0 fail; `bun run build` → exit 0; `bun run check:css-scope` → all rules scoped under `#einv-app`.
- `cd tests/e2e && env -u <every *_API_KEY/_AUTH_TOKEN/_ACCESS_TOKEN> bun run e2e:replay` → exit 0, run `251749a07293`: 74 discovered / 74 passed / 0 failed / 0 flaky. (A first attempt was refused by the runner's guard because the shell exported `*_API_KEY` variables; no test ran in that attempt.)
- Independent re-run by the verifier subagent: `phpunit --filter 'AdminTabsTest|InvoiceStatusGroupTest|SettingsTabsRestTest|SettingsPageTest|InvoiceAdminListRestTest'` → `OK (76 tests, 260 assertions)`.

**Result**: all green (598 + 8 PHPUnit, 12 bun, 74 e2e).

| Scenario | Test |
|---|---|
| Administrator sees the menu | `SettingsPageTest::test_submenu_is_registered_under_woocommerce_for_administrators_only` |
| Shop manager denied | `SettingsPageTest::test_page_refuses_users_without_manage_options`; e2e `units-permissions.army.ts` shop manager loop |
| Old invoice list URL | `SettingsPageTest::test_old_invoices_page_redirects_to_invoices_tab`; e2e `tabs.army.ts` "the old invoice list URL lands on the invoices tab" |
| Old invoice list URL without capability | e2e `units-permissions.army.ts` shop manager loop over `PAGES.oldInvoices` |
| Other admin page | `SettingsPageTest::test_assets_load_only_on_the_plugin_page_with_nonce_attached_by_htmx_config_request` |
| Settings page | same test (plugin hook, nonce, `refreshOnHistoryMiss`) |
| Default tab | `SettingsPageTest::test_default_tab_is_invoices`; e2e `tabs.army.ts` default tab |
| Deep link to a tab | `SettingsPageTest::test_deep_link_renders_the_lark_tab` |
| Unknown tab | `SettingsPageTest::test_unknown_tab_falls_back_to_invoices`; `AdminTabsTest::test_resolve_falls_back_for_unknown_keys` |
| Missing encryption key on any tab | `SettingsPageTest::test_encryption_banner_renders_above_the_tab_bar` |
| Invoicing tab contents | `SettingsPageTest::test_tabs_group_the_existing_forms` |
| Advanced tab contents | `SettingsPageTest::test_tabs_group_the_existing_forms` |
| Template list stays with credentials | `SettingsPageTest::test_tabs_group_the_existing_forms` (`einv-matbao-saved from:body` in connection panel); e2e `settings.army.ts` template list test |
| Switch tab | e2e `tabs.army.ts` switch without reload; `SettingsPageTest::test_tab_links_have_real_hrefs_and_push_url` |
| Reload keeps the tab | e2e `tabs.army.ts` reload keeps the tab |
| Back button | e2e `tabs.army.ts` back returns to previous tab; `SettingsPageTest::test_tab_container_owns_history_snapshots` |
| Unknown tab route | `SettingsTabsRestTest::test_unknown_tab_is_not_found` (`foo`) |
| Malformed tab key | `SettingsTabsRestTest::test_unknown_tab_is_not_found` (`Lark1`) |
| Tab route without capability | `SettingsTabsRestTest::test_shop_manager_is_rejected` |
| Shop manager (invoice management) | `InvoiceAdminListRestTest::test_non_administrator_is_rejected`; e2e `units-permissions.army.ts` menu + REST tests |
| Invoice list is the first tab | `AdminTabsTest::test_tabs_are_ordered_with_invoices_first`; e2e `tabs.army.ts` default tab |
| Detail stays inside the invoices tab | e2e `tabs.army.ts` detail stays inside the invoices tab |
| Failed / Draft created / Pending / Needs attention / Stopped / Skipped invoice badge | `InvoiceAdminListRestTest::test_status_is_shown_as_a_group_badge` (provider rows for each status) |

---

## 5. Design and Contract Coherence (warning only)

- [x] Decisions in design.md match the code (D1 registry, D2 slug, D3 hidden page + `load-{hook}`, D4 route `[^/]+` + 404 notice, D5 DOM/htmx attributes, D6 `hx-history-elt` + `refreshOnHistoryMiss`, D7 panels, D8 badge groups/colours, D9 tab bar + `max-w-3xl` config wrapper, D10 single enqueue hook)
- [x] Contracts in plan.md, with the Contract deviations recorded in apply.md (`none`), match the code

| Contract (plan.md) | Result | Compared with |
|---|---|---|
| `AdminTabs` / `DEFAULT` / `labels()` / `resolve()` / `url()` | Match | `src/Admin/AdminTabs.php` |
| `InvoiceStatus::group()` + `GROUP_*` | Match | `src/Invoice/InvoiceStatus.php:50-75` |
| `SettingsPage::render_tabs()` | Match | `SettingsPage.php:122-156` |
| `SettingsPage::render()` | Match | `SettingsPage.php:82-110` |
| Panel methods | Match | `SettingsPage.php:162,232,284` |
| `InvoicePage::render_panel()` | Match | `InvoicePage.php:81` |
| `InvoicePage::render()` / `register_menu()` | Match (`render()` removed) | `InvoicePage.php:53-65`; grep finds no `render()` |
| `InvoicePage::redirect_to_tab()` | Match | `InvoicePage.php:70-74` |
| `UninstallSettingsController::render_section(): string` | Match | `UninstallSettingsController.php:74` |
| REST `GET einvoice-matbao/v1/tabs/(?P<tab>[^/]+)` | Match | `SettingsController.php:50,134-142` |
| Admin URL `…&tab=<key>` | Match | `AdminTabs::url()` |
| Admin URL `page=einvoice-matbao-invoices` → 302 | Match | `InvoicePage.php:70-74` |
| Badge markup | Match | `InvoicePage.php:29-40,201-209` |
| htmx config `refreshOnHistoryMiss` | Match | `SettingsPage.php:378` |

**Drift warnings** (non-blocking):

- None. Note: tab link classes additionally use Tailwind important utilities (`!text-…`, `focus:!shadow-none`, `focus-visible:!outline-…`), added in apply to beat WordPress admin's unlayered link CSS; this refines D9 and does not change any Contracts shape.

---

## 6. Clean Tree and Commit Range

- [x] Working tree is clean (`git status --porcelain` → empty, before writing this receipt)

**Commit range**: `e9bc5b5..86a7346` (20 commits; implementation `e9bc5b5..25aac02`, apply receipt `86a7346`)

---

## Issues

**CRITICAL**: none.

**WARNING**: none.

**SUGGESTION**:
- Replaying e2e locally requires unsetting every `*_API_KEY` / `*_AUTH_TOKEN` / `*_ACCESS_TOKEN` variable (the runner's model-free guard). Add one line to `tests/e2e/README.md` showing `env -u …` or a helper script, so `bun run e2e:replay` works from a developer shell.
- Follow-ups recorded in apply.md (not part of this change): restore keyboard focus to the active tab after an htmx tab swap; keep the tab bar when a tab fragment returns 4xx; separate issue for the pre-existing "headers already sent … SettingsController.php" PHP warning; refresh spec `Evidence:` line numbers at archive.

---

## Overall Decision

- [x] PASS: proceed with `/opsx-continue` to finalize
- [ ] PASS_WITH_WARNINGS
- [ ] FAIL

All six checks pass on fresh evidence; no CRITICAL or WARNING issues. Suggestions are non-blocking and do not affect the outcome.

**Next step**:

Run `/opsx-continue` with the worktree as the working directory to create the finalize artifact, then `/opsx-archive`.
