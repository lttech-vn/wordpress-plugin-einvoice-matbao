# Verification Report

**Change**: `lower-platform-requirements`
**Verified at**: `2026-11-06 11:03 UTC`
**Iteration**: `1`
**Verifier**: parent session (fresh test runs, validate, actionlint) + `superspec.verifier` (static evidence for checks 1–3, 5, 6)

---

## 1. Structural Validation (`openspec validate lower-platform-requirements`)

- [x] The command passes for this change

**Result**:

```text
Change 'lower-platform-requirements' is valid   (exit 0)
```

---

## 2. Task Completion (`tasks.md`)

- [x] Every box is ticked, or each open box has a recorded reason

`openspec instructions apply` progress: 15 of 15 complete, 0 remaining.

| Open task | Reason | Blocks archive? |
|---|---|---|
| — | — | — |

---

## 3. Requirement Evidence

- [x] ADDED / MODIFIED: implementation evidence (file / line)
- [x] REMOVED: not applicable (no REMOVED requirements)
- [x] RENAMED: not applicable

| Requirement | Operation | Status | Evidence (file:line) or reason |
|---|---|---|---|
| Plugin declares minimum platform versions | ADDED | Pass | `einvoice-matbao.php:11-15` (6.6 / 8.2 / 9.8 / tested 11.2); `composer.json:7` (`>=8.2`), `composer.json:37` (platform `8.2.0`); `composer.lock` has no package requiring PHP ≥ 8.3 |
| Plugin runs on the minimum and latest supported platforms | ADDED | Pass | `docker/Dockerfile:3-7`, `compose.yaml:25-26,39-41`, `docker/min.env:1-5`, `docker/setup.sh:12-19`, `scripts/check.sh:9-25`, `tests/integration/bootstrap.php:13-21`, `tests/without-woocommerce/bootstrap.php` (same override); fresh runs below |
| CI checks both supported platforms | ADDED | Pass (static) | `.github/workflows/php.yml:18-24` (matrix min/latest, fail-fast false), `:31-33` (min.env → GITHUB_ENV), `:40-44` (php -l on 8.2), `:46-47` (floor guard); `release.yml` reuses `php.yml` via `workflow_call`; `phpstan.neon:4` (`phpVersion: 80200`); actionlint exit 0 |

---

## 4. Scenario Coverage

- [x] Every scenario maps to a test
- [x] The project test command was run fresh in this step

**Test command**: `scripts/check.sh` (latest) then `scripts/check.sh --min` (minimum), run sequentially in the worktree at `1691ef0`.
**Result**:
- latest (WordPress 7.1.4, WooCommerce 11.2.1, PHP 8.5.12): exit 0. PHPCS clean, PHPStan "No errors", floor guard OK, integration OK (548 tests, 3096 assertions), without-WooCommerce OK (8 tests, 21 assertions).
- minimum (WordPress 6.6.2, WooCommerce 9.8.7, PHP 8.2.25): exit 0. PHPCS clean, PHPStan "No errors", floor guard OK, integration 548 tests, 3086 assertions, 1 skipped (wc/v4 namespace absent), without-WooCommerce OK (8 tests, 21 assertions).

| Scenario | Test |
|---|---|
| Header and Composer agree | `tests/integration/PlatformRequirementsTest.php` — passed in both fresh runs |
| Minimum platform | `scripts/check.sh --min` — exit 0 (fresh) |
| Latest platform | `scripts/check.sh` — exit 0 (fresh) |
| PHP syntax above the floor | `composer analyse:floor-guard` (fresh: "json_validate rejected under PHP 8.2" on both combos) + `php -l` step in `php.yml:40-44` (exercised locally by Task 5 against PHP 8.2; not re-run in this step) |
| Integration test fails only on the minimum platform | **Not verified** — structural only (`php.yml` matrix with `fail-fast: false`, release gate via `workflow_call`, actionlint clean); GitHub Actions has not run because the branch is not pushed |

---

## 5. Design and Contract Coherence (warning only)

- [x] Decisions in design.md match the code (Decisions 1–6 matched; Decision 7 not applicable: `src/` unchanged, both min failures were test-environment gaps)
- [x] Contracts in plan.md, with the Contract deviations recorded in apply.md, match the code (all 13 entries matched; deviations `--memory-limit=1G` in `composer.json` floor guard and `scripts/check.sh` usage/exit 2 matched)

**Drift warnings** (non-blocking):

- None.

---

## 6. Clean Tree and Commit Range

- [x] Working tree is clean (`git status --porcelain` empty before writing this receipt)

**Commit range**: `572d610..1691ef0` (15 commits: 13 implementation + apply receipt + plan deviation note)

---

## Issues

**CRITICAL**: none.

**WARNING**
- CI behaviour not executed: scenario "Integration test fails only on the minimum platform" and the `PHP (min)` / `PHP (latest)` jobs are verified statically only. Recommendation: after push, confirm both matrix jobs run and pass on the PR, and that the release workflow lists both legs under the PHP gate.
- Required status check names change to `PHP (min)` / `PHP (latest)`. Recommendation: update branch protection on `main` if it requires "Lint, static analysis and integration tests (Docker)".

**SUGGESTION**
- `tests/integration/OrderRestBuyerPrivacyTest.php` skips the wc/v4 check when the namespace is absent with no guard on the latest combo (apply ruling 1). Recommendation: if a future latest pin drops wc/v4, add an assertion that the latest run reports 0 skipped tests.

---

## Overall Decision

- [ ] PASS: proceed with `/opsx-continue` to finalize
- [x] PASS_WITH_WARNINGS: proceed, noting: CI matrix behaviour is verified statically only (not run on GitHub Actions); one scenario is Not verified for that reason. Fresh local runs on both combinations are green.
- [ ] FAIL: fix the cause, then re-apply (iteration N+1)

**Next step**:

Run `/opsx-continue` with the worktree as working directory to create the finalize artifact, then `/opsx-archive`.
