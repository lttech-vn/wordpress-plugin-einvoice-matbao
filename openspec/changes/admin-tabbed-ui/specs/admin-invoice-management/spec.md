## MODIFIED Requirements

### Requirement: Invoice management page access
Hệ thống SHALL chỉ hiện danh sách hóa đơn, dưới dạng tab Hóa đơn (tab mặc định) của trang plugin "Hóa đơn Mắt Bão" (xem admin-settings), cho người dùng có quyền `manage_options`; các route của tab dùng chung kiểm soát truy cập với settings (xem admin-settings). Plugin SHALL không còn mục menu "Danh sách hóa đơn" riêng.
Evidence: src/Admin/InvoicePage.php:40-60, tests/integration/InvoiceAdminListRestTest.php:59-91, tests/e2e/army/units-permissions.army.ts:61-105

#### Scenario: Shop manager
- **WHEN** shop manager mở wp-admin
- **THEN** không thấy menu "Hóa đơn Mắt Bão" lẫn danh sách hóa đơn và route hóa đơn trả 403

#### Scenario: Invoice list is the first tab
- **WHEN** Administrator bấm menu WooCommerce > Hóa đơn Mắt Bão
- **THEN** tab Hóa đơn là tab đầu tiên, đang chọn, hiển thị dashboard, bộ lọc và danh sách hóa đơn

#### Scenario: Detail stays inside the tab
- **WHEN** Administrator bấm "Chi tiết" của một hóa đơn trong tab Hóa đơn
- **THEN** chi tiết hiển thị trong vùng danh sách, tab bar vẫn hiển thị và tab Hóa đơn vẫn đang chọn

## ADDED Requirements

### Requirement: Status badges in the invoice list
Bảng danh sách hóa đơn SHALL hiển thị trạng thái mỗi dòng dưới dạng badge mang thuộc tính `data-einv-badge` theo nhóm: `error` cho `failed`, `needs_attention`, `not_configured`; `waiting` cho `pending`, `sending`; `created` cho `draft_created`; `other` cho mọi trạng thái còn lại. Mỗi nhóm SHALL có màu riêng và badge SHALL vẫn chứa nhãn trạng thái tiếng Việt để màu không là tín hiệu duy nhất.

#### Scenario: Failed invoice badge
- **WHEN** danh sách có một hóa đơn `failed`
- **THEN** dòng đó có badge `data-einv-badge="error"` chứa chữ "Lỗi"

#### Scenario: Draft created badge
- **WHEN** danh sách có một hóa đơn `draft_created`
- **THEN** dòng đó có badge `data-einv-badge="created"` chứa chữ "Đã tạo nháp"

#### Scenario: Pending invoice badge
- **WHEN** danh sách có một hóa đơn `pending`
- **THEN** dòng đó có badge `data-einv-badge="waiting"` chứa chữ "Chờ gửi"

#### Scenario: Needs attention invoice badge
- **WHEN** danh sách có một hóa đơn `needs_attention`
- **THEN** dòng đó có badge `data-einv-badge="error"` chứa chữ "Lỗi cần xử lý"

#### Scenario: Stopped invoice badge
- **WHEN** danh sách có một hóa đơn `stopped`
- **THEN** dòng đó có badge `data-einv-badge="other"` chứa chữ "Đã dừng"

#### Scenario: Skipped invoice badge
- **WHEN** danh sách có một hóa đơn `skipped`
- **THEN** dòng đó có badge `data-einv-badge="other"` chứa chữ "Bỏ qua"
