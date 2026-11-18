# Apply Receipt

> Generated at the end of the apply phase to mark code-implementation
> complete and provide verify with the state it needs.
> Overwritten on each apply iteration; iteration counter grows.

**Change**: `explicit-kct-tax-rate`
**Iteration**: `2`
**Applied at**: `2026-11-18 21:30`
**Executor**: `subagent-driven-development` (implementer `implementer`, per-task review `code-reviewer`, final whole-branch review `code-reviewer`)

---

## Workspace

- **Worktree path**: `/Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.explicit-kct-tax-rate`
- **Worktree branch**: `explicit-kct-tax-rate`
- **Feature branch**: `feat/explicit-kct-tax-rate`
- **Base branch**: `main`

Main checkout was on `main` when apply started. The feature branch `feat/explicit-kct-tax-rate` was created from `main` (`67bb47a`) with Worktrunk and holds the step-0 artifact commit `514f186`. The dev stack for this worktree is compose project `einv-kct` on port 8093, run with `DOCKER_CONTEXT=kiri`.

Iteration 1 implemented the KCT/KKKNT-assignment design. After its apply receipt, the user replaced that design with the Mắt Bão tax mapping table:
- `brainstorm.md` rewritten and approved at the gate (`bae201a`).
- proposal, design, four specs, tasks and plan reworked and approved at the gate (`c27a52b`).

Iteration 2 implements the rework on the same branch and removes the iteration 1 code.

---

## Commits

- **Range**: `514f186..HEAD` (iteration 1 `514f186..bce8e84`, rework artifacts `bce8e84..c27a52b`, rework code `c27a52b..HEAD`)
- **Count**: `49` (+ this receipt commit)

---

## Tasks

- **Completed**: `15 of 15` checkboxes in tasks.md flipped to `- [x]`
- **Remaining**: none

---

## Contract deviations

**Iteration 1 (superseded)**

All iteration 1 code is gone: `ExemptTaxRateOptions`, the `settings/tax-rates` and `settings/tax-rates/unassign` routes, and `Settings::exempt_tax_rates()`, `save_exempt_tax_rates()`, `TAX_RATE_KCT` and `TAX_RATE_KKKNT`. Its contracts were replaced by the rework contracts in plan.md. `Settings::parse_rate_id()` was kept.

**Iteration 2**

- `TaxRateMapCard` / REST `tax-rate-map/add|remove` (public): the notice is not inside the card.
  - What changed: the route response is the card plus an out-of-band element (`hx-swap-oob="innerHTML:#einv-tax-rate-map-notice"`) that fills a persistent live region rendered after the card. 200/422 statuses and "card + notice" content are unchanged.
  - Why: the card is swapped `outerHTML`, so a freshly inserted live region may not be announced.
  - Recorded in plan.md.
- `TaxRateMapCard` forms: every card form carries `hx-disabled-elt="#einv-tax-rate-map button"`.
  - Why: two quick actions raced in the server's read-modify-write. An `hx-sync` queue was tried first, but it is lost when the card is swapped.
  - Internal.
- `InvoiceErrorCode::UNTAXED_LINE_WITH_TAX` label restored to `main`'s "Dòng không chịu thuế có tiền thuế".
  - Why: iteration 1 had renamed it; design D4 keeps existing labels of legacy codes.
  - Its hint now points to the mapping table, per spec.
- `SettingsPage::CARD`, `TITLE`, `HELP`, `FIELD`, `SECONDARY` made public so `TaxRateMapCard` reuses the class strings. Internal.

---

## Lanes

| Task | Lane branch | Lane range | Review | Merge commit |
|---|---|---|---|---|
| R2 Task 1 (store mapping) | sequential | `c27a52b..b86507e` | Approved after fix round 1 (non-finite percent) | n/a |
| R2 Task 2 (invoice lookup, error codes) | sequential | `96074b5..63f0283` | Approved | n/a |
| R2 Task 3 (mapping card, remove old code) | sequential | `a3807b2..413a0b7` | Approved after 2 fix rounds (test strength, Monitor reset) | n/a |
| R2 Task 4 (e2e) | sequential | `1d1d7ab..e1d9824` | Approved (2 controller rulings: one agent goal for KCT add; seed default map in setup-site.sh) | n/a |
| R2 Task 5 (docs) | sequential | `7115c8a..796c81d` | Approved after 2 fix rounds (conditional "Tính lại", developer doc) | n/a |
| Final fix wave | sequential | `af01a89..7342db1` | Re-review found a queue regression; controller ruling: lock all card buttons (`7342db1`) | n/a |

All tasks ran sequentially in this worktree, because each lane needs its own Docker stack and Tasks 2–5 chain by dependency. A tick commit of `tasks.md` follows each group.

**Final whole-branch review** (`514f186..af01a89`): **Ready to merge**, 0 Critical, 0 Important. Its four Minor findings and nits are fixed (`c691ebf`, `4861957`, `84aa8a8`, `7342db1`).

**Parked, not blocking:**
- Mắt Bão Demo has not been tried with `TSuat = -2` (KKKNT) or a "Khác" value (e.g. `3.5`). This must be done before release, and the docs recommend the step.
- Screen-reader announcement is not verified with real assistive technology.
- An empty mapping table after upgrade blocks every order, each with one alert. The spec accepts this during smoke test.
- The positional save click and record flakiness in the old settings e2e tests remain.

**Evidence at `7342db1`:**
- `composer test:integration`: OK (963 tests).
- `composer lint`: OK.
- `composer analyse`: OK.
- e2e replay without credentials: `report.json` summary 81/81 passed, failed 0.

The local runner still exits 1 because Kiri `docker cp` nests `.e2e/.e2e`. This is pre-existing and environment-specific; CI on Linux passes the same gate.

---

## Next step

Run `/opsx-verify` (in this worktree).
