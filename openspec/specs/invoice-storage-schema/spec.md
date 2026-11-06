# invoice-storage-schema Specification

## Purpose

Bảng invoice/attempt, nâng cấp schema và truy vấn dữ liệu hóa đơn.

## Requirements

### Requirement: Dedicated invoice and attempt tables
Hệ thống SHALL lưu hóa đơn và lịch sử các lần gọi Mắt Bão trong hai bảng riêng `{prefix}einvoice_matbao_invoice` và `{prefix}einvoice_matbao_attempt` để lọc, tìm và phân trang, tách khỏi order meta.
Evidence: src/Invoice/Schema.php:21-98, docs/adr/0002-invoice-tables-and-order-meta-split.md, tests/integration/SchemaUpgradeTest.php

#### Scenario: Tables after activation
- **WHEN** plugin được kích hoạt khi WooCommerce đang hoạt động
- **THEN** cả hai bảng tồn tại và option `einvoice_matbao_db_version` bằng phiên bản schema hiện tại

### Requirement: At most one invoice per order
Hệ thống SHALL đảm bảo mỗi đơn có tối đa một bản ghi hóa đơn, ngay cả khi hai tiến trình cùng tạo bản ghi cho một đơn.
Evidence: src/Invoice/Schema.php:78, src/Invoice/InvoiceRepository.php (reserve), tests/integration/InvoiceRepositoryTest.php:19-24

#### Scenario: Second reservation loses
- **WHEN** hai lần giữ chỗ bản ghi cho cùng một đơn
- **THEN** chỉ lần đầu thành công và bảng chỉ có một bản ghi cho đơn đó

### Requirement: Only one processor wins a status transition
Hệ thống SHALL thực hiện chuyển trạng thái hóa đơn sao cho khi nhiều tiến trình cùng chuyển từ một trạng thái, chỉ một tiến trình thành công và các tiến trình còn lại thấy thất bại.
Evidence: src/Invoice/InvoiceRepository.php (claim, claim_unchanged), tests/integration/InvoiceRepositoryTest.php:26-34

#### Scenario: Two claims
- **WHEN** hai tiến trình cùng chuyển một bản ghi từ `pending` sang `sending`
- **THEN** đúng một tiến trình thành công

#### Scenario: Row changed after read
- **WHEN** bản ghi đã bị tiến trình khác cập nhật sau khi được đọc
- **THEN** lần chuyển dựa trên dữ liệu cũ thất bại và bản ghi giữ nguyên

### Requirement: Schema upgrades preserve data
Hệ thống SHALL nâng cấp bảng khi phiên bản schema đã lưu khác phiên bản hiện tại (lúc kích hoạt hoặc lúc boot có WooCommerce), thêm cột còn thiếu, giữ nguyên dữ liệu cũ, rồi ghi phiên bản mới.
Evidence: src/Invoice/Schema.php:41-55, src/Plugin.php:25,39, tests/integration/SchemaUpgradeTest.php

#### Scenario: Upgrade from version 1
- **WHEN** bảng ở schema phiên bản 1 có một bản ghi và plugin boot
- **THEN** bảng có các cột lỗi, chu kỳ retry, chu kỳ alert và trạng thái đơn; bản ghi cũ còn nguyên; option phiên bản bằng phiên bản hiện tại

#### Scenario: Schema already current
- **WHEN** phiên bản đã lưu bằng phiên bản hiện tại
- **THEN** không có nâng cấp nào chạy

### Requirement: Ordered attempt history
Hệ thống SHALL ghi mỗi lần gọi Mắt Bão thành một attempt có thời điểm bắt đầu/kết thúc, kết quả, loại lỗi, HTTP status, mã API và message, và trả lịch sử theo thứ tự cũ nhất trước; attempt không ở kết quả `not_sent` được coi là có thể đã tới Mắt Bão.
Evidence: src/Invoice/Schema.php:84-95, src/Invoice/InvoiceRepository.php (attempts, may_have_been_created), tests/integration/DraftInvoiceRetryTest.php:32-62,334

#### Scenario: Only not_sent attempts
- **WHEN** mọi attempt của hóa đơn có kết quả `not_sent`
- **THEN** hóa đơn được coi là chưa từng tới Mắt Bão

### Requirement: Restart markers persist
Hệ thống SHALL khi khởi động lại một hóa đơn đặt về `pending`, xóa mã lỗi, ghi mốc chu kỳ retry mới và tăng chu kỳ alert, đồng thời giữ nguyên các attempt cũ.
Evidence: src/Invoice/InvoiceRepository.php (restart), tests/integration/DraftInvoiceRetryTest.php:589-648

#### Scenario: Restart failed invoice
- **WHEN** hóa đơn `failed` được khởi động lại
- **THEN** status `pending`, lỗi rỗng, chu kỳ alert tăng 1 và các attempt cũ vẫn còn

### Requirement: Order state updated only from the expected value
Hệ thống SHALL chỉ thay trạng thái đơn đã nhớ trên bản ghi hóa đơn khi giá trị hiện tại vẫn bằng giá trị đã đọc; khi kèm cảnh báo thì ghi lỗi và tăng chu kỳ alert mà không đổi status.
Evidence: src/Invoice/InvoiceRepository.php (replace_order_state)
Confidence: inferred

#### Scenario: Stale expected state
- **WHEN** giá trị đã đọc khác trạng thái đơn hiện tại trên bản ghi
- **THEN** cập nhật thất bại và bản ghi không đổi

### Requirement: Paged invoice search
Hệ thống SHALL liệt kê hóa đơn mới nhất trước, có phân trang và tổng số, lọc theo danh sách trạng thái và tìm theo order ID hoặc tiền tố `MTChieu`.
Evidence: src/Invoice/InvoiceRepository.php (search), src/Invoice/Schema.php:79-80, tests/integration/InvoiceAdminListRestTest.php:94-190

#### Scenario: Filter and search
- **WHEN** tìm với danh sách trạng thái và từ khóa "WC12"
- **THEN** chỉ bản ghi khớp được trả về, mới nhất trước, kèm tổng số bản ghi khớp

### Requirement: Bounded stale invoice query
Hệ thống SHALL trả tối đa 100 hóa đơn (theo thứ tự tạo) có trạng thái đã cho và không đổi lâu hơn ngưỡng thời gian, để recovery sweep xử lý theo lô.
Evidence: src/Invoice/InvoiceRepository.php (unchanged_for)
Confidence: inferred

#### Scenario: Old sending rows
- **WHEN** có hơn 100 bản ghi `sending` không đổi quá ngưỡng
- **THEN** chỉ 100 bản ghi cũ nhất được trả về
