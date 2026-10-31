# Browser E2E (e2e framework)

Browser tests for the plugin's user stories, run with [`e2e`](https://e2e.tester.army/) and modelled on the
`workvps/manage` setup. `bun run e2e:replay` is a gate: CI runs it on pushes to `main` and before every release
(`.github/workflows/ci.yml`, `release.yml`); pull requests do not. The Mat Bao demo API checks live in
[`demo-api/`](demo-api) (Python, guarded) and their results in `docs/research/e2e-demo-results.md`.

## Suites

| File | Stories (spec #1) | Agent |
|---|---|---|
| `checkout-invoice.army.ts` | 13-15, 17, 19: place an order at Classic and Blocks checkout, complete it, see the draft | yes (cached) |
| `checkout-validation.army.ts` | 16, 18: browser rules mirror the server rules; Store API refuses bad data; CCCD never stored in the clear | no |
| `settings.army.ts` | 28-31, 33: encrypted credentials, connection check, template pick, MTChieu prefix, walk-in name, unit | no |
| `invoices.army.ts` | 1, 3, 20-26: list, filters, search, detail, status check, resend (with the confirmations), dashboard | no |
| `failures.army.ts` | 4-6, 8, 10, 12, 38-40: blocked drafts (tax rate, totals, 0 VND, template), 503/lost reply/305/exhausted retries | no |
| `order-changes.army.ts` | 11, 12, 27: warnings after the draft, retry from the snapshot, refund before the job | no |
| `units-permissions.army.ts` | 32, 34: unit of measure default and override, non-admins refused | no |

Not covered here: Lark alerts, privacy export/erase, uninstall and the missing-WooCommerce path (PHPUnit covers them).

## Fake Mat Bao

`fake-matbao/router.php` is a small PHP server started in the WordPress container by `setup-site.sh`; the plugin reaches it
through `EINVOICE_MATBAO_BASE_URL` (set in `wp-config.php`, fake mode only). Tests script failures per endpoint
(`script("create-invoice", [{ http: 503, store: true }])`: the draft is stored but the reply is an error, the lost-reply
case) and read what Mat Bao received. Shapes follow the recorded demo answers, including its non-atomic duplicate check.
`wordpress/einv-e2e-http.php` (mu-plugin) refuses any Mat Bao host except the fake or the demo API and exposes the
`einv-e2e/v1` seams (create orders, complete, make retries due, read rows) to arrange state quickly.

Stack verified: WordPress 7.1.4, WooCommerce 11.2.1, PHP 8.5.12 (the `compose.yaml` stack), Chromium from the
`@e2e-dev/web` install, Node 26 on Debian trixie in the runner image (bun for installs).

## What is tested (`army/checkout-invoice.army.ts`)

Classic and Checkout Blocks: walk-in (no tick) and business buyer; Classic also individual. Each places an order,
completes it, then expects the draft in the order meta box ("Đã tạo nháp") and in WooCommerce → invoice list. Mat Bao
is answered by the fake in `wordpress/einv-e2e-http.php` (a request to any host other than the demo API is refused; the
production host never leaves). Agent steps: the invoice request fields. Exact `screen` actions: billing fields (exact
labels: `Address` must not match `Email address`), place order, login. Order completion goes through an E2E-only REST
route of the same mu-plugin.

Real results with the decision model (live mode, same code, one run each):

| Browser | Classic walk-in | Classic individual | Classic business | Blocks walk-in | Blocks business | Model calls (total) |
|---|---|---|---|---|---|---|
| Chromium (default) | pass 8 s | pass 19 s, 12 calls | pass 12 s, 15 calls | pass 4 s | pass 13 s, 15 calls | 42 |
| Lightpanda 1.0.2 | pass 7 s | pass 47 s, 12 calls | pass 14 s, 15 calls | pass 4 s | pass 13 s, 15 calls | 42 |

Both browsers pass everything, so the cheaper and simpler Chromium stays the default (Lightpanda: `E2E_BROWSER=lightpanda`;
the committed cache is recorded on Chromium). The `text` agent (`E2E_BLOCKS_AGENT=text`) is kept as an option but is not
needed. Blocks originally failed because Classic and Blocks bundles clobbered each other's minified globals (fixed in
PR #30: IIFE bundles, Classic script not loaded on Checkout Blocks pages). Lightpanda first failed on the bookworm image
with `GLIBC_2.38 not found`; the runner now uses `node:26-trixie-slim` (glibc 2.41) and a sha256-pinned binary.

Setup note: on a Checkout page created by WooCommerce, the invoice block does not appear on the front end by itself
(`blockHooks` only inserts it when the editor saves the page). `setup-site.sh` therefore inserts the block markup with
`wp eval`; this is a product observation, not something this test fixes.

## Modes

```bash
cd tests/e2e && bun install --frozen-lockfile
cp .env.e2e.example .env.e2e.local      # fill in the model values; gitignored
bun run e2e:list                        # lists the tests
bun run e2e:record                      # model + cache read-write, whole stack in Docker
bun run e2e:live                        # model, cache off
bun run e2e:replay                      # cache read-only + strict, any *_API_KEY in the environment is refused
```

`scripts/run-e2e.ts` starts a compose project per run (no published port, random admin password), a browser
container sharing the WordPress network namespace (`http://localhost`), copies `.e2e/` and `army-cache/` to
`test-results/runs/<id>/`, applies a report gate (all passed) and removes everything it created, also on SIGINT/SIGTERM.
The model variables (`E2E_SYSTEMONE_*`, `E2E_TEXT_*`) reach the container only in record/live. The text model URL must be
reachable from the container.

Only `checkout-invoice.army.ts` uses the model. The new suites use exact `screen`/`expect` steps, need no cache and cost
no tokens, in every mode.

Cache review: after `record`, inspect `test-results/runs/<id>/army-cache/*.json`, confirm no credentials or personal
data (admin password is random per run, buyer data is synthetic), copy it to `army-cache/` and commit. A changed
instruction, app screen or `params` needs a new recording.

Real demo: `EINV_DEMO_E2E=1 EINV_DEMO_MST=… EINV_DEMO_USER=… EINV_DEMO_PASS=… bun run e2e:live` (drafts only; never replay).

## Cost and limits

Recording: about 245k tokens (42 decision-model calls) for the five checkout tests. Replay: no model call. Full replay run: about 2 minutes (60 tests).
Limits: walk-in has no agent step (nothing to act on); one browser (Chromium); the cache depends on
the exact page markup, so WooCommerce updates may require a re-record.
