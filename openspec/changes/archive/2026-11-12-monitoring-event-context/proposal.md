## Why

Issue `EINVOICE-MATBAO-1` trên Rustrak chỉ có tiêu đề `Invoice needs attention: line_tax_rate_count` và vài tag. Mã đơn nằm trong tag nên dễ bỏ sót. Event không cho biết dòng hàng hay sản phẩm nào sai, không có lý do chi tiết và không nói cần làm gì tiếp. Exception làm action Action Scheduler thất bại còn không có mã đơn. Người trực phải mở WooCommerce và tự đoán. Change này cho mỗi event đủ thông tin để tra soát ngay, kèm một câu hướng dẫn xử lý dùng chung cho Sentry, Lark và admin UI.

## What Changes

- Thêm hướng dẫn xử lý ngắn bằng tiếng Việt cho mỗi mã lỗi. Hướng dẫn được định nghĩa ở một chỗ, cạnh nhãn lỗi.
- Event needs-attention:
  - Message đổi thành `Hóa đơn cần xử lý: <nhãn> – Đơn #<order_id>`. Fingerprint vẫn theo `error_code`.
  - Thêm context `invoice` gồm ID hóa đơn, mã tham chiếu, trạng thái, lý do, hướng dẫn và đường dẫn admin tương đối.
  - Thêm context `line` (dòng hàng gây lỗi dữ liệu) và context `matbao` (lỗi Mắt Bão) khi lỗi phát sinh trong cùng request.
- Lỗi dữ liệu đơn hàng mang theo thông tin dòng gây lỗi: line item ID, loại dòng, product ID, variation ID, SKU, tên, các thuế suất tìm thấy.
- Exception làm action của plugin thất bại mang `order_id`, hook và action ID, và được gửi trong ngữ cảnh lọc của đơn đó.
- Exception báo qua `CaughtFailure` có thêm context `invoice` khi biết đơn.

**Tag allowlist**
- From: 8 tag (`site`, `order_id`, `invoice.id`, `invoice.status`, `error_code`, `error_kind`, `matbao.env`, `component`).
- To: thêm `product_id` và `matbao.api_code`.
- Reason: lọc issue theo sản phẩm và theo mã lỗi Mắt Bão.
- Impact: không phá vỡ hành vi hiện có.

**Tin Lark**
- From: chỉ chứa số đơn, nhãn lỗi, mã lỗi và liên kết admin.
- To: thêm một dòng hướng dẫn xử lý, vẫn không chứa dữ liệu người mua.
- Reason: người nhận Lark biết ngay cần làm gì.
- Impact: nội dung tin thay đổi; người đọc tin không phải làm gì thêm.

**Admin UI**
- From: meta box đơn hàng, chi tiết hóa đơn và admin notice hiện nhãn lỗi (và lý do), không có hướng dẫn.
- To: hiện thêm hướng dẫn xử lý cạnh nhãn lỗi.
- Reason: cùng một hướng dẫn ở mọi nơi.
- Impact: không phá vỡ hành vi hiện có.

## Capabilities

### New Capabilities

(không có)

### Modified Capabilities

- `error-monitoring`: sửa "Plugin errors reported as issues" (message mới, context tra soát, ngữ cảnh đơn cho exception của Action Scheduler) và "Tag allowlist" (thêm 2 tag); thêm requirement về context tra soát và đường dẫn admin.
- `failure-alerts`: sửa "Lark message content and privacy" (thêm hướng dẫn) và "Admin notice for administrators" (hiện hướng dẫn).
- `admin-invoice-management`: sửa "Invoice detail with attempt history" và "Order edit screen invoice meta box" (hiện hướng dẫn).
- `draft-invoice-creation`: thêm requirement về thông tin dòng hàng đi kèm lỗi dữ liệu theo dòng.

## Impact

- **Code:**
  - `src/Invoice/InvoiceErrorCode.php`: thêm hướng dẫn xử lý.
  - `src/Invoice/InvoiceDataError.php` và `src/Invoice/DraftPayloadBuilder.php`: lỗi mang thông tin dòng.
  - `src/Invoice/DraftInvoiceWorkflow.php`: giữ chi tiết lỗi của request cho event needs-attention.
  - `src/Monitoring/` (`Monitor`, `Backend`, `SentryBackend`, `Scrubber`, `CaughtFailure`, `ActionSchedulerTracing`).
  - `src/Alert/InvoiceAlerts.php`, `src/Alert/AdminAlertNotice.php`, `src/Admin/OrderInvoiceMetaBox.php`, `src/Admin/InvoicePage.php`.
- **Tests:** test tích hợp `tests/integration/Monitoring/*`, `InvoiceAlertTest`, `OrderInvoiceMetaBoxTest`, `InvoiceAdminListRestTest`, `InvoiceErrorCodeTest`, `DraftInvoiceWorkflowTest`.
- **Không đổi:** DB schema, dependency, hook công khai, REST route và cách gom issue.
- **Riêng tư:** tên sản phẩm và lý do lỗi đi qua bộ lọc hiện có; đường dẫn admin chỉ gửi path tương đối.
