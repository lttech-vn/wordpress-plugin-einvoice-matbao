## 1. Resolution hint per error code

Blocked by: none

- [x] 1.1 Thêm `InvoiceErrorCode::hint()` theo bảng hướng dẫn trong design D1 (match đủ mọi case, chuỗi dịch được `einvoice-matbao`)
- [x] 1.2 Test trong `tests/integration/InvoiceErrorCodeTest.php`: mọi case có hint không rỗng; hint của `line_tax_rate_count`, `credentials_missing`, `unexpected` chứa đúng thao tác (failure-alerts / Resolution hint per error code)

## 2. Line details on line data errors

Blocked by: none

- [x] 2.1 Thêm value object `src/Invoice/LineFailure.php` (item_id, item_type, product_id, variation_id, sku, name, tax_rate_ids; `from_item()`, `to_context()`)
- [x] 2.2 `InvoiceDataError` nhận thêm `?LineFailure $line = null`; `DraftPayloadBuilder::line()` dựng `LineFailure` và truyền xuống `rate()` / `whole_vnd()` thay cho `$name`, mọi lỗi theo dòng mang `LineFailure`, lỗi không theo dòng thì không
- [x] 2.3 Test các scenario của draft-invoice-creation / Line details on line data errors (hai thuế suất trên dòng sản phẩm, dòng vận chuyển lẻ đồng, totals mismatch), và kiểm tra lý do lưu không đổi

## 3. Event context plumbing in monitoring

Blocked by: none

- [x] 3.1 Mở rộng contract `Backend::capture_exception` / `capture_message` với `array $contexts`; `SentryBackend::capture()` đặt context trong `withScope`; sửa test double trong `tests/integration/Monitoring` / `Support`
- [x] 3.2 Thêm `product_id`, `matbao.api_code` vào `Scrubber::TAG_ALLOWLIST`; test "New filter tags kept" và "Filter wins over a new tag"
- [x] 3.3 Thêm `Monitor::with_failure_detail()` (stack theo request như `$order_contexts`, chi tiết gồm error code + `line` hoặc `matbao` context + tag tương ứng) và hàm đọc chi tiết khớp mã lỗi
- [x] 3.4 Test chi tiết không rò sang callback ngoài và không gắn vào mã lỗi khác (error-monitoring / Failure detail scoped to its order and code, ở mức Monitor)

## 4. Needs-attention event content

Blocked by: 1, 3

- [ ] 4.1 Thêm `src/Monitoring/InvoiceContext.php`: dựng context `invoice` từ `InvoiceRecord` gồm `admin_path` (bỏ scheme/host/fragment, chỉ giữ `page`, `action`, `id`, `post`)
- [ ] 4.2 `Monitor::capture_needs_attention`: message `Hóa đơn cần xử lý: <label> – Đơn #<id>`, fingerprint giữ nguyên, context `invoice` cùng `line`/`matbao` và tag khi có chi tiết khớp mã; lỗi khi dựng context không lan ra ngoài
- [ ] 4.3 Test qua `SentrySink`: Readable title, Invoice context present, Admin path without sensitive parameters, Alert without failure detail (`queue_failed`), Context building fails, Same error code on two orders, Needs attention once per alert

## 5. Workflow passes failure detail

Blocked by: 2, 4

- [ ] 5.1 `DraftInvoiceWorkflow`: bọc `record_error` của hai nhánh `catch ( InvoiceDataError )` và nhánh xử lý `MatbaoError` trong `fail()` bằng `Monitor::with_failure_detail()` (context `matbao`: kind, http_status, api_code, target_sent, auto_retry)
- [ ] 5.2 Test end-to-end tích hợp qua `SentrySink`: Tax rate count error on a product line, Mat Bao rejection (306 trên create-invoice), Product name equals buyer name, Detail does not leak to another order, Post-draft warning during a failure

## 6. Exception context for caught failures and Action Scheduler

Blocked by: 3, 4

- [ ] 6.1 `CaughtFailure::report` thêm context `invoice` khi tìm được hóa đơn của đơn
- [ ] 6.2 `ActionSchedulerTracing`: lưu args ở `on_begin`; `on_failed` gắn context `action` (hook, action ID), tag `order_id` và chạy trong `Monitor::with_order_context` khi args[0] là int dương
- [ ] 6.3 Test: Unexpected error while sending (có context `invoice`), Action Scheduler action throws, Action Scheduler exception echoes the buyer, Action without an order

## 7. Hint in Lark and admin UI

Blocked by: 1

- [ ] 7.1 `InvoiceAlerts::message()` thêm hướng dẫn vào tin Lark; `AdminAlertNotice` thêm hint vào mỗi mục
- [ ] 7.2 `OrderInvoiceMetaBox` và chi tiết hóa đơn trong `InvoicePage` hiện dòng "Cách xử lý" kèm `data-einv-hint`
- [ ] 7.3 Test trong `InvoiceAlertTest`, `OrderInvoiceMetaBoxTest`, `InvoiceAdminListRestTest`: Final error có hint, Administrator sees an error có hint, No personal data, Error with hint, Detail shown

## 8. Docs and end-to-end verification

Blocked by: 5, 6, 7

- [ ] 8.1 Cập nhật `docs/phat-trien.md` (phần giám sát lỗi và cảnh báo) và `docs/huong-dan-su-dung.md` (hướng dẫn xử lý hiện ở đâu), viết tiếng Việt
- [ ] 8.2 Chạy toàn bộ test integration, PHPCS và PHPStan theo `docs/phat-trien.md`
- [ ] 8.3 Verify E2E trên site local có Rustrak: tạo đơn có dòng hai thuế suất, kiểm tra event trên Rustrak có message mới, context `invoice`/`line`, tag `product_id`, và tin Lark có hướng dẫn; ghi lại nếu môi trường không cho phép
