# Apply Receipt

> Generated at the end of the apply phase to mark code-implementation
> complete and provide verify with the state it needs.
> Overwritten on each apply iteration; iteration counter grows.

**Change**: `lower-platform-requirements`
**Iteration**: `1`
**Applied at**: `2026-11-06T10:39:42Z`
**Executor**: `subagent-driven-development` (superspec.implementer per task, superspec.task-reviewer per task + final whole-branch review)

---

## Workspace

- **Worktree path**: `/Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.lower-platform-requirements`
- **Worktree branch**: `lower-platform-requirements`
- **Feature branch**: `spec/lower-platform-requirements`
- **Base branch**: `main`

---

## Commits

- **Range**: `572d610..3c43aa0` (implementation; this receipt adds one more commit)
- **Count**: `13`

---

## Tasks

- **Completed**: `15 of 15` checkboxes in tasks.md flipped to `- [x]`
- **Remaining**: none

---

## Evidence (fresh, at 3c43aa0)

- `scripts/check.sh` (latest: WordPress 7.1.4, WooCommerce 11.2.1, PHP 8.5.12): PHPCS ok, PHPStan no errors, floor guard OK, integration OK 548 tests / 3096 assertions, without-WooCommerce OK 8 / 21; exit 0.
- `scripts/check.sh --min` (WordPress 6.6.2, WooCommerce 9.8.7, PHP 8.2.25): PHPCS ok, PHPStan no errors, floor guard OK, integration 548 tests / 3086 assertions / 1 skipped (wc/v4 absent), without-WooCommerce OK 8 / 21; exit 0.
- CI workflows not executed (no push); `actionlint` clean.

## Notes

- `src/` unchanged: both min-platform failure groups were test-environment gaps (Store API fixture needed an enabled gateway because of a WooCommerce 9.8 core bug in PaymentUtils; WooCommerce 9.8 has no `wc/v4` API).
- CI check names changed to `PHP (min)` / `PHP (latest)`; update branch protection required checks if they reference "Lint, static analysis and integration tests (Docker)".
- Final whole-branch review: Ready to merge (no Critical/Important).
- Rulings: (1) wc/v4 skip stays feature-based with no skipped-count guard on latest — cost if wrong: v4 privacy check silently skipped on a future latest pin; (2) WC 9.8 no-gateway core bug documented in README known limits — cost if wrong: one README line.

---

## Contract deviations

- `composer.json` scripts `analyse:floor-guard`: adds `--memory-limit=1G` (PHPStan crashes at 128M default); otherwise as planned.
- `scripts/check.sh`: unknown argument prints usage and exits 2 (small addition beyond contract).

---

## Next step

Run `/opsx-verify`.
