# Verification Report

**Change**: `top-level-menu-notifications`
**Verified at**: `2026-11-12 14:50`
**Iteration**: `1`
**Verifier**: parent session (inline; no delegation, no agent met the verifier role's requirements)

---

## 1. Structural Validation (`openspec validate <change>`)

- [x] The command passes for this change

**Result**:

```text
Change 'top-level-menu-notifications' is valid
⚠ [WARNING] failure-alerts/spec.md: ADDED "Lark alert switch": Requirement text is very long (>500 characters).
```

---

## 2. Task Completion (`tasks.md`)

- [x] Every box is ticked, or each open box has a recorded reason

`grep -c "^- \[x\]"` = 15, `grep -c "^- \[ \]"` = 0; `openspec instructions apply` progress `15/15`.

| Open task | Reason | Blocks archive? |
|---|---|---|
| — | — | — |

---

## 3. Requirement Evidence

- [x] ADDED / MODIFIED: implementation evidence (file / line)
- [x] REMOVED: none in this change
- [x] RENAMED: none in this change

| Requirement | Operation | Status | Evidence (file:line) or reason |
|---|---|---|---|
| admin-settings: Settings page access | MODIFIED | Pass | `src/Admin/SettingsPage.php:59-67` (`add_menu_page`, `manage_options`, slug), redirect untouched `src/Admin/InvoicePage.php` |
| admin-settings: Tabbed plugin screen | MODIFIED | Pass | `src/Admin/AdminTabs.php:18-20,33,43-51,59` (5 tabs, `ALIASES`, exact-match `canonical`), `src/Admin/SettingsPage.php:149` |
| admin-settings: Settings grouped by tab | MODIFIED | Pass | `src/Admin/SettingsPage.php:149` (Lark + Sentry panels, each with its own `.einv-result`) |
| admin-settings: Tab switching without page reload | MODIFIED | Pass | `src/Admin/SettingsController.php:141` (`AdminTabs::canonical`, null → 404) |
| error-monitoring: Monitoring settings form | MODIFIED | Pass | form unchanged, now in tab `notifications` (`src/Admin/SettingsPage.php:149`); route `settings/monitoring` untouched |
| error-monitoring: Stored DSN never rendered | MODIFIED | Pass | `render_monitoring_panel()` unchanged; `SettingsMonitoringRestTest::test_form_never_renders_dsn` green |
| failure-alerts: Lark alert switch | ADDED | Pass | `src/Settings.php:118,131`; `src/Admin/SettingsController.php:341-368`; `src/Admin/SettingsPage.php:303`; `src/Alert/InvoiceAlerts.php:95,111-112`; `src/Alert/AdminAlertNotice.php:121` |
| failure-alerts: Missing Lark configuration or queue failure | MODIFIED | Pass | `src/Alert/InvoiceAlerts.php:111-118` (switch checked before webhook; `not_configured` kept for enabled-without-webhook) |

---

## 4. Scenario Coverage

- [x] Every scenario maps to a test (e2e-only rows noted)
- [x] The project test command was run fresh in this step

**Test command**: `docker compose exec … wordpress composer test` (= `test:integration` + `test:without-woocommerce`), also `composer lint`, `composer analyse`
**Result**: `OK (858 tests, 4446 assertions)` and `OK (8 tests, 21 assertions)`; lint exit 0; analyse exit 0 ("No errors"). Stack: PHP 8.5.12, WP 7.1.4, WC 11.2.1.

| Scenario | Test |
|---|---|
| Administrator sees the menu | `SettingsPageTest::test_menu_is_top_level_for_administrators_only` |
| Shop manager denied | `SettingsPageTest::test_page_refuses_users_without_manage_options` |
| Old invoice list URL / without capability | `SettingsPageTest::test_old_invoices_page_redirects_to_invoices_tab` (no-capability case unchanged from baseline) |
| Default tab | `SettingsPageTest::test_default_tab_is_invoices` |
| Deep link to a tab / Legacy tab keys | `SettingsPageTest::test_deep_link_renders_the_notifications_tab` (notifications, lark, monitoring), `AdminTabsTest::test_aliases_resolve_to_notifications` |
| Notifications tab order / Monitoring tab order (dropped on purpose) | `SettingsPageTest::test_tab_bar_has_five_tabs_with_notifications_before_advanced`, `AdminTabsTest::test_tabs_are_ordered_with_invoices_first` |
| Unknown tab | `AdminTabsTest::test_resolve_falls_back_for_unknown_keys`, `SettingsPageTest::test_unknown_tab_falls_back_to_invoices` |
| Missing encryption key on any tab | `SettingsPageTest::test_encryption_banner_renders_above_the_tab_bar` |
| Invoicing tab contents | `SettingsPageTest::test_tabs_group_the_existing_forms` |
| Notifications tab contents / Monitoring tab contents (dropped on purpose) | `SettingsPageTest::test_notifications_tab_contents` |
| Saving one notification card | `SettingsPageTest::test_notifications_tab_contents` (two separate `.einv-result`) — indirect; the click-through is e2e only (not run) |
| Advanced tab contents | `SettingsPageTest::test_tabs_group_the_existing_forms` |
| Template list stays with credentials | `SettingsConnectionRestTest` (unchanged, green) |
| Switch tab / Reload keeps the tab / Back button | e2e `tests/e2e/army/tabs.army.ts` — **not run** (see below) |
| Legacy tab route | `SettingsTabsRestTest::test_legacy_tab_keys_return_the_notifications_tab` |
| Unknown tab route / Malformed tab key | `SettingsTabsRestTest::test_unknown_tab_is_not_found` (`foo`, `Lark1`, `LARK`) |
| Tab route without capability | `SettingsTabsRestTest::test_shop_manager_is_rejected` |
| error-monitoring: all 5 scenarios | `SettingsMonitoringRestTest` (`test_form_never_renders_dsn`, `test_invalid_dsn_rejected`, `test_rate_out_of_range_rejected`, `test_blank_dsn_keeps_stored`, save-valid) |
| Existing site keeps alerts / New site default | `SettingsLarkSwitchTest::test_unset_switch_follows_the_saved_webhook`, `SettingsPageTest::test_lark_switch_is_checked_for_a_site_with_a_saved_webhook_and_unchecked_for_a_new_site` |
| Switch off keeps webhook | `SettingsCredentialsRestTest::test_lark_switch_off_keeps_the_stored_webhook_and_secret` |
| Enable without webhook | `SettingsCredentialsRestTest::test_lark_switch_on_without_any_webhook_is_refused_and_writes_nothing` |
| Alert while switched off | `InvoiceAlertTest::test_switched_off_lark_sends_nothing_and_marks_the_alert_disabled` |
| Switch turned on later | `InvoiceAlertTest::test_a_disabled_alert_is_not_sent_after_switching_on_but_new_alerts_are` |
| Test message while switched off | `SettingsFailureModesRestTest::test_lark_test_message_is_sent_while_the_switch_is_off` |
| No webhook | `InvoiceAlertTest::test_enabled_lark_without_a_webhook_is_not_configured_and_still_listed` |
| Switched off without webhook | `InvoiceAlertTest::test_switched_off_lark_without_a_webhook_is_disabled_not_unconfigured` |
| Queue failure | `InvoiceAlertTest::test_queue_exception_marks_the_alert_queue_failed` (unchanged) |

Scenarios covered only by browser e2e (**Not verified**): Switch tab, Reload keeps the tab, Back button, and the click-through half of Saving one notification card. The e2e files were updated (`tests/e2e/army/*.ts`, `e2e.army.config.ts`) but the `@e2e-dev/web` runner and agent API key are unavailable here. The htmx tab swap itself is exercised server-side by `SettingsTabsRestTest`, and a live HTTP smoke on the running site rendered the notifications tab for `tab=notifications|lark|monitoring`.

---

## 5. Design and Contract Coherence (warning only)

- [x] Decisions in design.md match the code
- [x] Contracts in plan.md, with the Contract deviations recorded in apply.md, match the code

| Contract | Result |
|---|---|
| `AdminTabs::labels()` | Match (`src/Admin/AdminTabs.php:28-35`) |
| `AdminTabs::ALIASES` | Match (`:18-20`) |
| `AdminTabs::canonical()` / `resolve()` | Match (`:43-51`, `:59`) |
| `SettingsController::show_tab()` | Match (`src/Admin/SettingsController.php:141`) |
| `SettingsPage::TOP_LEVEL_HOOK`, `register_menu()` | Match (`src/Admin/SettingsPage.php:28,59-67`; position 56 via private const, icon `dashicons-media-text`) |
| `Settings::lark_enabled()` / `save_lark_enabled()` | Match (`src/Settings.php:118,131`; `'1'`/`'0'`/unset → `$webhook_saved`) |
| Option key `lark_enabled` | Match (same option `einvoice_matbao_settings`) |
| Form parameter `enabled` of `POST settings/lark` | Match, plus the recorded deviation: unchecked `enabled` with blank webhook, secret and `clear_secret` saves only the switch (`src/Admin/SettingsController.php:346-351`); compared against `apply.md` "Contract deviations" |
| Alert state `disabled` | Match (`src/Alert/InvoiceAlerts.php:112`; `AlertStore` stores any string) |
| `AdminAlertNotice::lark_note('disabled')` | Match (`src/Alert/AdminAlertNotice.php:121`) |
| Tab key `notifications` | Match |

**Drift warnings** (non-blocking):

- `design.md` says enabling without a webhook is rejected "with a Vietnamese notice"; the notice shown is the generic webhook-URL validation error ("Webhook phải là URL HTTPS…"), not a switch-specific message. Behavior (rejected, nothing written) matches; wording is generic. Reviewer recorded this as a deferred minor.
- `design.md` Risks lists the unreadable-webhook case as unchanged behavior; the code also lets the switch be turned off in that state (recorded deviation above), which design does not mention.

---

## 6. Clean Tree and Commit Range

- [x] Working tree is clean

`git status --short` was empty before this receipt was written.

**Commit range**: `f781ef7..b747473` (8 commits including the apply receipt; implementation `f781ef7..a147163`)

---

## Overall Decision

- [ ] PASS: proceed with `/opsx-continue` to finalize
- [x] PASS_WITH_WARNINGS: proceed, noting: browser e2e scenarios (Switch tab, Reload keeps the tab, Back button, Saving one notification card click-through) are Not verified because the e2e runner is unavailable; two non-blocking design/wording drifts above.
- [ ] FAIL: fix the cause, then re-apply (iteration N+1)

### Issues

**CRITICAL**: none.

**WARNING**
- e2e not run (`tests/e2e/army/tabs.army.ts`, `settings.army.ts`, `monitoring.army.ts`). Recommendation: run `bun run test:e2e` (or the project's e2e command per `tests/e2e/README.md`) in an environment with the runner before merging.

**SUGGESTION**
- `src/Admin/SettingsController.php:~357`: give the "enable without webhook" refusal its own message ("Cần nhập webhook Lark trước khi bật cảnh báo.") by checking `$enabled && '' === $url` before the URL validation.
- `src/Admin/SettingsPage.php:~303-311`: call `$store->has_lark()` once and reuse it.
- `failure-alerts` "Lark alert switch" requirement text exceeds 500 characters (`openspec validate` warning): split into two requirements when next touched.

**Next step**:

Run `/opsx-continue` with the worktree as working directory to create the finalize artifact, then `/opsx-archive`.
