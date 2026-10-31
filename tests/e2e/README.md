# Browser E2E (e2e framework)

Agentic browser tests for the invoice request at checkout, run with [`e2e`](https://e2e.tester.army/) and modelled on
the `workvps/manage` setup. **Not part of CI or any release gate.** The Mat Bao demo API checks live in
[`demo-api/`](demo-api) (Python, guarded) and their results in `docs/research/e2e-demo-results.md`.

Stack verified: WordPress 7.1.4, WooCommerce 11.2.1, PHP 8.5.12 (the `compose.yaml` stack), Chromium from the
`@e2e-dev/web` install, Node 26 on Debian trixie in the runner image (bun for installs).

## What is tested (`army/checkout-invoice.army.ts`)

Classic checkout: walk-in (no tick), individual and business buyer. Each places an order, completes it, then expects
the draft in the order meta box ("Đã tạo nháp") and in WooCommerce → invoice list. Mat Bao is answered by the fake in
`wordpress/einv-e2e-http.php` (a request to any host other than the demo API is refused; the production host never
leaves). Agent steps: invoice request fields on Classic. Exact `screen` actions: billing fields, place order, login.
Order completion goes through an E2E-only REST route of the same mu-plugin.

**Blocks** tests exist (agent `text`: standard tool-calling agent on the text endpoint) but are skipped unless
`E2E_BLOCKS=1`; no combination was stable inside the time-box. Real results (one run each, live mode):

| Combination | Result |
|---|---|
| Blocks walk-in, Chromium, agent `text` | Passes (no agent step: exact fills + place order) |
| Blocks business, Chromium, agent `text` | Fails: the model reports no invoice-request checkbox on the checkout (2 model calls, ~5 s). The block is in the page content and registered, but the agent does not find it; not diagnosed further |
| Blocks, Chromium, decision model (`default`) | Fails: `scroll_to` without a target, then 25 calls without concluding |
| Blocks walk-in, Lightpanda, agent `text` | Fails at order placement (`expect.toHaveURL`) |
| Blocks business, Lightpanda, agent `text` | Fails: no invoice checkbox found (1 call) |
| Classic x3, Lightpanda, decision model | Pass (walk-in 8 s, individual 18 s / 12 calls, business 14 s / 15 calls) |
| Classic x3, Chromium, decision model | Pass (same step counts) |

Lightpanda first failed with `GLIBC_2.38 not found` on the bookworm image; the runner now uses `node:26-trixie-slim`
(glibc 2.41) and the pinned aarch64/x86_64 Lightpanda 1.0.2 binary. Select it with `E2E_BROWSER=lightpanda`. Chromium
stays the default and the recorded cache is for Chromium. "No payment methods" seen earlier on Blocks was the
`Address` label matching `Email address`; COD is available (Store API lists `cod`), so no extra gateway was added.
Until Blocks is stable it is covered by unit tests only.

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

Cache review: after `record`, inspect `test-results/runs/<id>/army-cache/*.json`, confirm no credentials or personal
data (admin password is random per run, buyer data is synthetic), copy it to `army-cache/` and commit. A changed
instruction, app screen or `params` needs a new recording.

Real demo: `EINV_DEMO_E2E=1 EINV_DEMO_MST=… EINV_DEMO_USER=… EINV_DEMO_PASS=… bun run e2e:live` (drafts only; never replay).

## Cost and limits

Recording the three Classic tests: about 190k tokens (29 decision-model calls). Replay: no model call.
Limits: Blocks not covered (table above); walk-in has no agent step (nothing to act on); one browser (Chromium); the cache depends on
the exact page markup, so WooCommerce updates may require a re-record.
