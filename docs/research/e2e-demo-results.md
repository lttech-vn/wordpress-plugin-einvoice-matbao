# Mat Bao demo API verification results (ticket #15)

Raw data: [`e2e-demo-results.json`](e2e-demo-results.json), produced on 2026-10-31 (21:35 Vietnam time) by
`tests/e2e/demo-api/verify_demo.py` against `https://demo-api-hddt.matbao.in:11443` (template `1/C26TAT`; the
cross-template check also used `1/C26TKK`). Drafts only: nothing was signed or issued, the production host is blocked by
the harness allowlist, and no POST was retried. Credentials came from environment variables and are not in the repo.

## Summary

| # | Check | Observed | Conclusion | Consequence for the plugin |
|---|-------|----------|------------|----------------------------|
| a1 | 5 parallel creates, same `MTChieu`, same template | 2 drafts created (`success`, two different `maTraCuu`), 3 answered 305 "Trùng mã tham chiếu"; lookup by `MTChieu` returns 2 drafts | Mat Bao does **not** block duplicates atomically | Duplicate prevention must be the plugin's job (see below) |
| a2 | Sequential duplicate (same `MTChieu`, same template) | HTTP 200, envelope `errorCode` 201, item `errorCode` 305 "Trùng mã tham chiếu" | Sequential duplicates are rejected | 305 means "already created": look up, never change the reference (as implemented) |
| a3 | Same `MTChieu`, other template (`C26TKK`) | Created a draft (200); lookup under each template returns its own drafts | The duplicate check is per template, not across templates | The stored template must be used for lookups after a retry (as implemented) |
| b | Walk-in buyer (only `NMua_Ten`) | Created; detail shows `nMua_Ten` = "Khách hàng không lấy hóa đơn" | Matches `Settings::DEFAULT_WALK_IN_BUYER_NAME` | None |
| c1 | Discount, plugin shape (`DGia` = net unit price 180000, `TLCKhau` 0) | Accepted; `thTien` 540000, tax 54000, total 594000 | OK | Plugin sends this shape |
| c2 | Discount, list-price shape (`DGia` 200000, `TLCKhau` 10, `STCKhau` 60000) | Accepted; same totals; keeps 600000 before discount, 60000 discount | OK, also valid | Not needed |
| c3 | Inconsistent (`DGia` 200000, `TLCKhau` 0, `ThTienChuaCK` 600000, `ThTien` 540000) | Accepted; same totals | The API does not cross-check the line fields | The plugin must keep its own arithmetic consistent (`DraftPayloadBuilder` does) |
| d1 | `DGia` not divisible (3 x 33333.33333 = 100000) | Accepted; `dGia` stored as `33333.33333`, `thTien` 100000, total in words "Một trăm mười nghìn đồng" | OK; accented `TgTTTBChu` accepted | Rounding `DGia` to 6 decimals is safe because `ThTien` is sent explicitly |
| d2 | Unknown / exhausted template | Envelope 201, item 201 "Trường 'KHMSHDon','KHHDon' bị thiếu hoặc không hợp lệ." | Both cases produce the same message | Template errors are data errors (no retry) |
| d3 | Codes 308 and 327 | Not reproduced | Cannot confirm their cause or text | No behaviour change; they stay as documented |
| d4 | `expiredDate` time zone | `2026-11-01T21:35:51` at 14:35:51 UTC: 24 h ahead if read as Vietnam time, 31 h if UTC; login keys are `accessToken`, `expiredDate` | Vietnam local time (24 h token) | `MatbaoClient::cache_token` reads it as `Asia/Ho_Chi_Minh` (correct) |
| d5 | Lookup by `MTChieu` without `MaTraCuu` | Returned the drafts with `maTraCuu`, state "Nháp"; the summary row has no buyer or total | Works; the lookup response has no amounts | A lookup match is checked on what it has; the buyer and totals come from `invoice-detail-batch` only when needed |

## Duplicate prevention (a)

The concurrent test is the important result: the same `MTChieu` and template can produce two drafts when requests overlap.
The plugin never sends two create requests at once for one order, because:

- `UNIQUE(order_id)` in `Schema.php`: `InvoiceRepository::reserve` is an `INSERT IGNORE`, so one invoice row exists per order.
- `InvoiceRepository::claim(pending -> sending)` is a conditional `UPDATE`; only the request that wins the claim calls
  `create_invoice`. A second runner (Action Scheduler retry, sweep, double click) loses the claim and stops.
- After an unclear result (timeout, 305) the workflow looks up by `MTChieu` under the stored template before any further
  create, and never changes the reference.

If a lookup still returns more than one draft for one `MTChieu` (the state this test produced),
`DraftInvoiceWorkflow::lookup_mismatch` does not pick one. It records the `lookup_mismatch` attempt with the message "Mắt Bão
trả về N hóa đơn cùng mã tham chiếu ...; cần admin kiểm tra" and leaves the order for an admin. This is the correct behaviour.

## Discount shape (c)

The plugin sends the net unit price in `DGia`, `TLCKhau` = `STCKhau` = 0 and `ThTienChuaCK` = `ThTien` = the net amount
(`DraftPayloadBuilder`). The demo accepted that and the list-price shape and computed the same totals. The invoice therefore
does not show the pre-discount price or discount percent; that is a presentation choice, not an API error. Should the
shop want discounts visible on the invoice, the list-price shape (c2) works on the demo.

## Findings needing action

None for plugin correctness. One code change came out of the run: the demo wraps item errors in a failed envelope
(`errorCode` 201 plus an item 305). `MatbaoClient::create_invoice` ignored the item and so never reached the duplicate
lookup. It now reads the item result when the envelope fails (tests `4a6d637`, fix `314619a`).

## Not covered

Signing, issuing and production behaviour; codes 308 and 327; whether the 24 h token is refreshed early by Mat Bao.
