# Apply Receipt

> Generated at the end of the apply phase to mark code-implementation
> complete and provide verify with the state it needs.
> Overwritten on each apply iteration; iteration counter grows.

**Change**: `explicit-kct-tax-rate`
**Iteration**: `1`
**Applied at**: `2026-11-18 17:26`
**Executor**: `subagent-driven-development` (implementer `implementer`, per-task review `code-reviewer`, final whole-branch review `code-reviewer`)

---

## Workspace

- **Worktree path**: `/Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.explicit-kct-tax-rate`
- **Worktree branch**: `explicit-kct-tax-rate`
- **Feature branch**: `feat/explicit-kct-tax-rate`
- **Base branch**: `main`

Main checkout was on `main` when apply started. The feature branch `feat/explicit-kct-tax-rate` was created from `main` (67bb47a) with Worktrunk and holds the step-0 artifact commit `514f186`. Dev stack for this worktree: compose project `einv-kct`, port 8093, `DOCKER_CONTEXT=kiri`.

---

## Commits

- **Range**: `514f186..481ade5`
- **Count**: `21` (+ this receipt commit)

---

## Tasks

- **Completed**: `16 of 16` checkboxes in tasks.md flipped to `- [x]`
- **Remaining**: none

Group 6 (unassign rates that are no longer 0%) was added after the first final review. The user chose option 2, and the revised design, spec, tasks and plan passed the gate (`b4f335d`).

---

## Contract deviations

- `ExemptTaxRateOptions`: `is_zero_rate()`/`exists()` replaced by `zero_rate_ids()`. The controller computes the zero-rate ID set once per request and keeps assignments of every non-0% ID (final-review fix wave, internal).
- `Settings::parse_rate_id( mixed ): ?int` added as public static. The controller and storage now share one canonical ID rule; the previous regexes differed (`{0,17}` vs `{0,18}`). Internal.
- REST `POST einvoice-matbao/v1/settings/tax-rates`: also keeps assignments of rates that still exist but are no longer 0%, not only deleted ones. The success counts cover only the rates saved in the request. The user approved this behaviour through the spec revision (option 2, gate approved).
- REST `POST einvoice-matbao/v1/settings/tax-rates/unassign` (new, public, user-approved via gate): a successful response is a notice only, asking the admin to reload so the list updates. The first plan text said "kèm card đã cập nhật"; it was changed because an OOB swap is not needed (Task 6 ruling, plan.md updated).
- Render detail: `<option>` uses a literal `selected` attribute (ternary) instead of WordPress `selected()`, to match the brief's test assertion. Internal.

---

## Lanes

| Task | Lane branch | Lane range | Review | Merge commit |
|---|---|---|---|---|
| Task 1 | sequential | `514f186..eaa954f` | Approved (1 Minor, fixed in final wave) | n/a |
| Task 2 | sequential | `c45a9ca..acd3f9e` | Approved | n/a |
| Task 3 | sequential | `847b30b..7569fd5` | Approved (3 Minor, fixed in final wave) | n/a |
| Task 4 | sequential | `fa9227b..50c1df5` | Approved after fix round 1 | n/a |
| Task 5 | sequential | `96dc096..1d0eda9` | Approved after fix round 1 | n/a |
| Final fix wave | sequential | `70191d2..c1c5b5b` | Re-review: 6 addressed, 1 new Important → user decision (option 2) | n/a |
| Task 6 | sequential | `b4f335d..79bd6a9` | Approved after fix round 1 | n/a |

All tasks ran sequentially in the single worktree. Tasks 2 and 3 share `DraftInvoiceWorkflowTest`, and every lane needs its own Docker stack. Each tick commit of `tasks.md` follows its group.

Final whole-branch review (`514f186..eecfbc3`): **Ready to merge**, 0 Critical, 0 Important. Its Minor 1 (the unassign contract text in plan.md) was fixed in `481ade5`.

**Parked for follow-up**, not blocking:
- After "Bỏ gán", the row stays visible until reload.
- No e2e test yet for the unassign flow.
- Stale rows do not show the country.
- The `untaxed_line_with_tax` hint could mention "Bỏ gán".
- The save click in `settings.army.ts` uses the positional `nth(2)`.
- Two old settings tests were flaky during record.

Evidence at `eecfbc3`:
- `composer test:integration`: OK (942 tests, 4861 assertions).
- `composer lint`: OK.
- `composer analyse`: OK.
- e2e replay without credentials: `report.json` summary 81/81 passed.

The local runner still exits 1 because Kiri `docker cp` nests `.e2e/.e2e`, so the report gate cannot read the report. This is pre-existing and environment-specific; CI on Linux passes the same gate.

---

## Next step

Run `/opsx-verify` (in this worktree).
