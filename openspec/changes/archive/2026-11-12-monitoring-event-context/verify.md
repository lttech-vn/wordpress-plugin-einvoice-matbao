# Verification Report

**Change**: `monitoring-event-context`
**Verified at**: `2026-11-12 13:05`
**Iteration**: `1`
**Verifier**: `parent agent (inline), chạy trong worktree /Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.monitoring-event-context`

---

## 1. Structural Validation (`openspec validate monitoring-event-context`)

- [x] The command passes for this change

**Result**:

```text
openspec validate monitoring-event-context --strict
Change 'monitoring-event-context' is valid        (exit 0)
```

---

## 2. Task Completion (`tasks.md`)

- [x] Every box is ticked, or each open box has a recorded reason

`grep` trên tasks.md: 23 ô `[x]`, 0 ô `[ ]`. `openspec instructions apply`: `progress {"total":23,"complete":23,"remaining":0}`, `state: all_done`.

| Open task | Reason | Blocks archive? |
|---|---|---|
| — | — | — |

---

## 3. Requirement Evidence

- [x] ADDED / MODIFIED: implementation evidence (file / line)
- [x] REMOVED: không có requirement bị xóa
- [x] RENAMED: không có requirement bị đổi tên

| Requirement | Operation | Status | Evidence (file:line) or reason |
|---|---|---|---|
| error-monitoring / Invoice context on needs-attention events | ADDED | Pass | `src/Monitoring/InvoiceContext.php:30-44` (`for_invoice`), `:54` (`admin_path`: chỉ giữ `page/action/id/post`); dùng trong `src/Monitoring/Monitor.php:115` (`capture_needs_attention`) |
| error-monitoring / Line context on line data errors | ADDED | Pass | `src/Monitoring/FailureDetail.php:71` (`for_line`, tag `product_id` chỉ khi > 0, `:74`); `src/Invoice/DraftInvoiceWorkflow.php:816-823` (`record_data_error`) |
| error-monitoring / Mat Bao context on Mat Bao errors | ADDED | Pass | `src/Monitoring/FailureDetail.php:42` (`for_matbao`; tag `matbao.api_code` khi có mã); `src/Invoice/DraftInvoiceWorkflow.php:762` (`record_matbao_failure`) |
| error-monitoring / Failure detail scoped to its order and code | ADDED | Pass | `src/Monitoring/Monitor.php:336` (`with_failure_detail`, pop trong `finally`), `:368` (`failure_detail_for` khớp cả `order_id` và `code`) |
| error-monitoring / Plugin errors reported as issues | MODIFIED | Pass | Message `Hóa đơn cần xử lý: %1$s – Đơn #%2$d` ở `Monitor.php:134`; fingerprint `['needs-attention', code]` ở `Monitor.php:140`; context `action` ở `src/Monitoring/ActionSchedulerTracing.php:130`, tag `order_id` từ `args[0]` int > 0 ở `:82`; context `invoice` cho `CaughtFailure` qua `InvoiceContext::for_order_or_empty` (`InvoiceContext.php:79`) |
| error-monitoring / Tag allowlist | MODIFIED | Pass | `src/Monitoring/Scrubber.php:19` có `product_id`, `matbao.api_code`; giá trị vẫn qua bộ lọc |
| draft-invoice-creation / Line details on line data errors | ADDED | Pass | `src/Invoice/LineFailure.php:30-64` (`__construct`, `from_item`, `to_context`); `src/Invoice/InvoiceDataError.php:29` (`$line_failure`); `src/Invoice/DraftPayloadBuilder.php:217,273,284` (truyền `LineFailure`) |
| failure-alerts / Resolution hint per error code | ADDED | Pass | `src/Invoice/InvoiceErrorCode.php:257` (`hint()`, `match` đủ 34 case) |
| failure-alerts / Admin notice for administrators | MODIFIED | Pass | `src/Alert/AdminAlertNotice.php:45` (`<span class="einv-hint">`) |
| failure-alerts / Lark message content and privacy | MODIFIED | Pass | `src/Alert/InvoiceAlerts.php:160-169` (hint trong tin Lark, không dữ liệu người mua) |
| admin-invoice-management / Invoice detail with attempt history | MODIFIED | Pass | `src/Admin/InvoicePage.php:224,246` (dòng "Cách xử lý" + `data-einv-hint`) |
| admin-invoice-management / Order edit screen invoice meta box | MODIFIED | Pass | `src/Admin/OrderInvoiceMetaBox.php:104-106` |

---

## 4. Scenario Coverage

- [x] Every scenario maps to a test
- [x] The project test command was run fresh in this step

**Test command** (chạy trong container, cwd `/var/www/html/wp-content/plugins/einvoice-matbao` của worktree):

| Lệnh | Exit | Kết quả |
|---|---|---|
| `composer test:integration` | 0 | `OK (836 tests, 4356 assertions)` |
| `composer test:without-woocommerce` | 0 | `OK (8 tests, 21 assertions)` |
| `composer lint` (PHPCS) | 0 | không có lỗi |
| `composer analyse` (PHPStan) | 0 | `[OK] No errors` |
| `composer analyse:floor-guard` | 0 | `Floor guard OK` |

Các test được dẫn dưới đây đều tồn tại (`rg "function <tên>"` trong tests/, cùng lần chạy); 38 scenario đều có test.

| Scenario | Test |
|---|---|
| Every code has a hint / Data error hint / Config error hint / Uncertain send hint | `InvoiceErrorCodeTest` (`test_every_code_has_a_hint`, `test_data_error_hint`, `test_config_error_hint`, `test_uncertain_send_hint`) |
| Same hint everywhere | `Monitoring/SameHintEverywhereTest::test_same_hint_everywhere` |
| Administrator sees an error / User without capability / Final error / No personal data | `InvoiceAlertTest` (test hiện có, đã bổ sung assert hint) |
| Detail shown / Unknown invoice | `InvoiceAdminListRestTest` (assert chữ hiển thị của hint) |
| Error with hint / Draft created / No invoice / Skipped order | `OrderInvoiceMetaBoxTest::test_error_with_hint` + test hiện có |
| Two tax rates on a product line / Shipping line with fractional amount / Totals mismatch | `DraftPayloadLineFailureTest` (3 test + `test_fractional_amount_keeps_tax_rate_ids`) |
| Invoice context present / Admin path without sensitive parameters / Alert without failure detail / Context building fails | `NeedsAttentionEventTest`, `ExceptionContextTest::test_caught_failure_context_failure_keeps_unexpected` |
| Tax rate count error on a product line / Product name equals buyer name / Mat Bao rejection / Detail does not leak to another order / Post-draft warning during a failure | `Monitoring/WorkflowFailureDetailTest`, `EventContextTest::test_reentrant_other_order_same_code` |
| Unexpected error while sending | `WorkflowMonitoringTest::test_unexpected_error_reported` |
| Action Scheduler action throws / echoes the buyer / Action without an order | `Monitoring/ExceptionContextTest` (3 test) |
| Needs attention once per alert / Same error code on two orders | `WorkflowMonitoringTest` (test hiện có) |
| Readable title | `NeedsAttentionEventTest::test_readable_title` |
| Extra tag dropped / New filter tags kept / Filter wins over a new tag / No server name | `ScrubberTest`, `MonitorTest::test_capture_exception_scrubbed`, `EventContextTest` (2 test) |

---

## 5. Design and Contract Coherence (warning only)

- [x] Decisions in design.md match the code
- [x] Contracts in plan.md, with the Contract deviations recorded in apply.md, match the code

Kết quả theo từng mục Contracts (so sánh chỉ đọc):

| Contract | Kết quả | File so sánh |
|---|---|---|
| `InvoiceErrorCode::hint` | Khớp | `InvoiceErrorCode.php:257` |
| `LineFailure` | Khớp | `LineFailure.php:30-64` |
| `InvoiceDataError::__construct` | Khớp theo deviation (`$line_failure`) | `InvoiceDataError.php:29` |
| `DraftPayloadBuilder` helpers (`fail`, `rate`, `whole_vnd`) | Khớp | `DraftPayloadBuilder.php:217,273,284` |
| `DraftInvoiceWorkflow` helpers | Khớp | `DraftInvoiceWorkflow.php:762,816` |
| `FailureDetail` (`for_matbao`, `for_line`) | Khớp | `FailureDetail.php:42,71` |
| `Monitor::with_failure_detail` | Khớp theo deviation (`FailureDetail\|\Closure`) | `Monitor.php:336` |
| `Monitor::failure_detail_for` | Khớp | `Monitor.php:368` |
| `Monitor::capture_exception` / `capture_needs_attention` | Khớp | `Monitor.php:98,115` |
| `Backend::capture_*`, `SentryBackend::capture` | Khớp | `Backend.php:25`, `SentryBackend.php:190` |
| `InvoiceContext` | Khớp theo deviation (có `for_order_or_empty`) | `InvoiceContext.php:30,54,79,95` |
| `ActionSchedulerTracing::$runs` | Khớp | `ActionSchedulerTracing.php:36-39,81-82` |
| `Scrubber::TAG_ALLOWLIST` | Khớp | `Scrubber.php:19` |
| Lark message format | Khớp | `InvoiceAlerts.php:163` |

Ba deviation trong `apply.md` đều khớp mã và đã có trong bảng Contracts của `plan.md`.

**Drift warnings** (non-blocking):

- `design.md` D3 ghi `capture_exception` nhận context dưới dạng closure và `with_failure_detail` nhận `FailureDetail`; mã nhận thêm `\Closure` (đã nêu ở deviation, `design.md` chưa nhắc rõ).
- Hướng dẫn của mã `unexpected` (`InvoiceErrorCode.php:292`) nhắc "Rustrak", hiện trên mọi site kể cả khi tắt giám sát. Đúng với bảng D1 đã duyệt, nhưng có thể gây khó hiểu; cần user quyết định.

---

## 6. Clean Tree and Commit Range

- [x] Working tree is clean

`git status --porcelain` rỗng trước khi ghi file này.

**Commit range**: `0f3bace..203f78f` (29 commit; cộng commit receipt này)

---

## Overall Decision

- [ ] PASS: proceed with `/opsx-continue` to finalize
- [x] PASS_WITH_WARNINGS: proceed, noting: 2 warning không chặn (D3 chưa nhắc closure; hint `unexpected` nhắc Rustrak); 1 giới hạn đã ghi ở apply.md: E2E live dùng ingest Sentry giả, chưa gửi vào Rustrak thật
- [ ] FAIL: fix the cause, then re-apply (iteration N+1)

## Issues

**CRITICAL**: không có.

**WARNING**
- `src/Invoice/InvoiceErrorCode.php:292`: hint `unexpected` nhắc "Rustrak". Khuyến nghị: đổi thành cách nói trung tính ("xem event trên máy chủ giám sát nếu đã bật") và cập nhật bảng D1 ở `design.md` cùng `docs/huong-dan-su-dung.md`; chờ user quyết.
- `design.md` D3: nêu thêm rằng `with_failure_detail` nhận `FailureDetail|\Closure` và `capture_exception` nhận context dạng closure để khớp mã (có thể làm khi archive).

**SUGGESTION**
- Thêm test cho nhánh fallback hint của mã lỗi lạ ở `InvoiceAlerts.php:167`.
- `Monitor::failure_detail_for` đang `public @internal` chỉ để test; có thể chuyển thành assert qua event rồi để `private`.
- Đổi tên biến `$failure` ở `DraftPayloadBuilder::line()` thành `$line_ref` cho đúng nghĩa.

**Next step**: chạy `/opsx-continue` với worktree làm thư mục làm việc để tạo `finalize`, sau đó `/opsx-archive`.
