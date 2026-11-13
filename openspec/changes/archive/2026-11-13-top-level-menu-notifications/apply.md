# Apply Receipt

**Change**: `top-level-menu-notifications`
**Iteration**: `1`
**Applied at**: `2026-11-12 14:30`
**Executor**: `executing-plans` (inline; no agent met the implementer role's skill requirements, user chose inline), final whole-branch review by `code-reviewer` (read-only)

---

## Workspace

- **Worktree path**: `/Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.top-level-menu-notifications`
- **Worktree branch**: `top-level-menu-notifications`
- **Feature branch**: `change-menu`
- **Base branch**: `main`

---

## Commits

- **Range**: `f781ef7..a147163`
- **Count**: `7`

---

## Tasks

- **Completed**: `15 of 15` checkboxes in tasks.md flipped to `- [x]`
- **Remaining**: none

Evidence: `composer lint` clean, `composer analyse` no errors, `composer analyse:floor-guard` OK, `composer test` 858 + 8 tests OK (in docker, PHP 8.5.12 / WP 7.1.4 / WC 11.2.1). HTTP smoke on the running site: top-level menu present, `tab=notifications|lark|monitoring` all render the notifications tab, old `einvoice-matbao-invoices` URL still 302s to `tab=invoices`. **Not run:** browser e2e (`tests/e2e/army`, needs the `@e2e-dev/web` runner and agent API key); e2e files were edited only and are unverified.

---

## Contract deviations

- `AdminTabs::canonical` / `ALIASES` / `Settings::lark_enabled` / `save_lark_enabled` / `TOP_LEVEL_HOOK`: no deviation from plan.md.
- `POST settings/lark` (`enabled`, public): added a branch so that an unchecked `enabled` with blank webhook, secret and `clear_secret` only saves the switch (success notice "Đã tắt cảnh báo Lark…"), instead of failing webhook validation. Reason: reviewer finding, otherwise a site whose stored webhook is undecryptable (key changed) could not switch Lark off. Internal behavior; same route and parameters.
- Plan Task 5 also touched `TracingTest` and `SettingsCredentialsRestTest` (they fetched `tabs/lark`/rendered `lark`): forced by the removed tab, no contract impact.

---

## Lanes

| Task | Lane branch | Lane range | Review | Merge commit |
|---|---|---|---|---|
| 1 | sequential | `f781ef7..77dbaad` | whole-branch | n/a |
| 2 | sequential | `77dbaad..1dcddbb` | whole-branch | n/a |
| 3 | sequential | `1dcddbb..138e1d6` | whole-branch | n/a |
| 4 | sequential | `138e1d6..2db0686` | whole-branch | n/a |
| 5 | sequential | `2db0686..da7c6a6` | whole-branch | n/a |
| 6 | sequential | `da7c6a6..2ad5a98` | whole-branch | n/a |
| review fix | sequential | `2ad5a98..a147163` | n/a | n/a |

Final whole-branch review (`code-reviewer`): APPROVE, 0 Critical. Two Important fixed with RED→GREEN (missing "enabled, no webhook → not_configured" test; switch-off with undecryptable webhook). Deferred minors: notice text when enabling without a webhook says "Webhook phải là URL HTTPS…" rather than a switch-specific message; `render_lark_panel()` calls `has_lark()` three times; `save_lark()` writes two options non-atomically; class docblock nit. Open product question for the user: a never-configured site now shows "Đã tắt cảnh báo Lark" instead of "Chưa cấu hình Lark" on alerts (spec-driven).

---

## Next step

Run `/opsx-verify`.
