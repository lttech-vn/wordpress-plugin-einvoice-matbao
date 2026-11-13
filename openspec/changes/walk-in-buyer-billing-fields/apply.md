# Apply Receipt

**Change**: `walk-in-buyer-billing-fields`
**Iteration**: `1`
**Applied at**: `2026-11-12 21:40`
**Executor**: `subagent-driven-development`

---

## Workspace

- **Worktree path**: `/Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.walk-in-buyer-billing-fields`
- **Worktree branch**: `walk-in-buyer-billing-fields`
- **Feature branch**: `feat/walk-in-buyer-billing-fields`
- **Base branch**: `main`

---

## Commits

- **Range**: `c6ee475..e8a9e1f`
- **Count**: `18`

---

## Tasks

- **Completed**: `12 of 12` checkboxes in tasks.md flipped to `- [x]`
- **Remaining**: none

---

## Lanes

| Task | Lane branch | Lane commit range | Task review verdict | Merge commit |
|---|---|---|---|---|
| 1 | sequential | c6ee475..22a228e | approved (1 fix round) | n/a |
| 2 | sequential | 6020987..b2706c2 | approved (1 fix round) | n/a |
| 3 | sequential | c8b348a..cd33114 | approved (1 fix round, finding 1 ruled deferred) | n/a |
| 4 | sequential | c6279d2..579ef70 | approved (1 fix round, finding 1 ruled out of scope) | n/a |
| 5 | sequential | 47d52e0..c5fa681 | approved | n/a |
| final | sequential | 25b8513..db83aab | whole-branch review: 1 Important fixed, re-review approved | n/a |

---

## Contract deviations

- `OrderState::encode()` buyer digest `b:` (internal): the final whole-branch review found billing-address edits were no longer detected as a buyer change now that the address comes from billing. The digest now also covers billing address 1, address 2, city, state and country. The user decided no migration is needed (pre-release, no stored data). Entry added to plan.md Contracts.

---

## Verification evidence (implementer-reported, not yet re-run by verify)

- `composer test:integration` 871 tests / 4499 assertions OK; `composer test:without-woocommerce` 8 OK; `composer lint` clean; `composer analyse` no errors; `bun test assets/src` 16 pass; `bun run build` OK.
- e2e: cache replay was re-recorded with a model (`bun run e2e:record`, 5 stale cache files that referenced the removed address field replaced by 5 new ones) and then `bun run e2e:replay` ran with no API keys: 78 passed, 8 cache replayed, exit 0 (commit e8a9e1f). Record runs also showed model/UI noise that did not recur: a business-name trailing period in an agent step and an ambiguous alert locator in `settings.army.ts`; both passed on rerun.

---

## Rulings and deferred items

- Client length check uses the state code, PHP uses the state name (approximate by design; PHP authoritative).
- `MIN_SCRUB_LENGTH = 3` (pre-existing privacy scrub policy) unchanged.
- Classic `billing_state` select2 native-event wiring not verified in a browser (server revalidates on submit).
- Blocks e2e absence assertion for the removed address field: not added (follow-up).
- Recapture of a `buyer_invalid` order after the admin fills the address does not re-send; the admin uses the existing "Gửi lại".

---

## Next step

Run `/opsx-verify`.
