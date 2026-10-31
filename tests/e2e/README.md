# Browser E2E (e2e framework)

Agentic browser tests for the invoice request at checkout, run with [`e2e`](https://e2e.tester.army/) and modelled on
the `workvps/manage` setup. **Not part of CI or any release gate.** The Mat Bao demo API checks live in
[`demo-api/`](demo-api) (Python, guarded) and their results in `docs/research/e2e-demo-results.md`.

Stack verified: WordPress 7.1.4, WooCommerce 11.2.1, PHP 8.5.12 (the `compose.yaml` stack), Chromium from the
`@e2e-dev/web` install, Node 24.8 + bun in the runner image.

## What is tested (`army/checkout-invoice.army.ts`)

Classic checkout: walk-in (no tick), individual and business buyer. Each places an order, completes it, then expects
the draft in the order meta box ("Đã tạo nháp") and in WooCommerce → invoice list. Mat Bao is answered by the fake in
`wordpress/einv-e2e-http.php` (a request to any host other than the demo API is refused; the production host never
leaves). Agent steps: invoice request fields on Classic. Exact `screen` actions: billing fields, place order, login.
Order completion goes through an E2E-only REST route of the same mu-plugin.

**Blocks** tests exist but are skipped unless `E2E_BLOCKS=1`: the decision model could not target the Blocks fields
and the exact-action version did not complete the order reliably within the time-box. Lightpanda was not tried;
Chromium is used. Blocks checkout is covered by PHP/JS unit tests only until this is finished.

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
Limits: Blocks not covered; walk-in has no agent step (nothing to act on); one browser (Chromium); the cache depends on
the exact page markup, so WooCommerce updates may require a re-record.
