## MODIFIED Requirements

### Requirement: Invoice detail with attempt history
Hệ thống SHALL hiển thị chi tiết một hóa đơn gồm đơn hàng, trạng thái, mã hóa đơn Mắt Bão, mẫu số/ký hiệu đã lưu, người mua, mã và nhãn lỗi, hướng dẫn xử lý của mã lỗi, lý do và lịch sử attempt.
Evidence: src/Admin/InvoicePage.php:181-232, tests/integration/InvoiceAdminListRestTest.php:192-255, tests/e2e/army/invoices.army.ts:119

#### Scenario: Detail shown
- **WHEN** admin mở chi tiết một hóa đơn `failed`
- **THEN** thấy `MTChieu`, mã lỗi, hướng dẫn xử lý, mẫu `1 / C26TAT`, lý do và các attempt

#### Scenario: Unknown invoice
- **WHEN** admin mở chi tiết một ID không tồn tại
- **THEN** phản hồi 404

### Requirement: Order edit screen invoice meta box
Hệ thống SHALL hiển thị hộp chỉ đọc "Hóa đơn Mắt Bão" trên màn hình sửa đơn (cả HPOS và lưu trữ cũ) với trạng thái, `MTChieu`, mã hóa đơn Mắt Bão, mẫu số/ký hiệu, ngày lập, loại lỗi, hướng dẫn xử lý khi có lỗi và lý do.
Evidence: src/Admin/OrderInvoiceMetaBox.php:22-105, tests/integration/OrderInvoiceMetaBoxTest.php:20-87, tests/e2e/army/checkout-invoice.army.ts:21-76

#### Scenario: Draft created
- **WHEN** đơn có hóa đơn `draft_created`
- **THEN** hộp hiện "Đã tạo nháp" và mã hóa đơn Mắt Bão

#### Scenario: Error with hint
- **WHEN** đơn có hóa đơn dừng với `line_tax_rate_count`
- **THEN** hộp hiện nhãn lỗi và hướng dẫn xử lý của mã đó

#### Scenario: No invoice
- **WHEN** đơn chưa có hóa đơn
- **THEN** hộp báo chưa có hóa đơn

#### Scenario: Skipped order
- **WHEN** đơn 0 đồng có hóa đơn `skipped`
- **THEN** hộp hiện lý do bỏ qua và không có nút thao tác
