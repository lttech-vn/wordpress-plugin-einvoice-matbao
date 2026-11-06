## ADDED Requirements

### Requirement: Invoice management page access
Hệ thống SHALL chỉ hiện trang "Danh sách hóa đơn" cho người dùng có quyền `manage_options`; các route của trang dùng chung kiểm soát truy cập với settings (xem admin-settings).
Evidence: src/Admin/InvoicePage.php:40-60, tests/integration/InvoiceAdminListRestTest.php:59-91, tests/e2e/army/units-permissions.army.ts:61-105

#### Scenario: Shop manager
- **WHEN** shop manager mở wp-admin
- **THEN** không thấy menu danh sách hóa đơn và route hóa đơn trả 403

### Requirement: Filterable searchable paginated invoice list
Hệ thống SHALL hiển thị danh sách hóa đơn mới nhất trước, 20 dòng mỗi trang, lọc theo trạng thái (nhóm lỗi, nhóm đang chờ, hoặc một trạng thái) và tìm theo mã đơn hoặc tiền tố `MTChieu`.
Evidence: src/Admin/InvoiceController.php:89-117, src/Invoice/InvoiceStatus.php:54-81, tests/integration/InvoiceAdminListRestTest.php:94-190, tests/e2e/army/invoices.army.ts:42

#### Scenario: Error group filter
- **WHEN** admin lọc nhóm lỗi
- **THEN** chỉ hiện hóa đơn `failed`, `needs_attention` và `not_configured`

#### Scenario: Unknown status filter
- **WHEN** giá trị lọc trạng thái không hợp lệ
- **THEN** bộ lọc bị bỏ qua và mọi hóa đơn được hiển thị

#### Scenario: Search
- **WHEN** admin tìm "202"
- **THEN** khớp đơn #202 hoặc `MTChieu` bắt đầu bằng "WC202"; ký tự wildcard được hiểu theo nghĩa đen

#### Scenario: Pagination
- **WHEN** có 25 hóa đơn
- **THEN** trang 1 có 20 dòng, trang 2 có 5 dòng, và số trang vượt quá được đưa về trang hợp lệ

### Requirement: Invoice detail with attempt history
Hệ thống SHALL hiển thị chi tiết một hóa đơn gồm đơn hàng, trạng thái, mã hóa đơn Mắt Bão, mẫu số/ký hiệu đã lưu, người mua, mã và nhãn lỗi, lý do và lịch sử attempt.
Evidence: src/Admin/InvoicePage.php:181-232, tests/integration/InvoiceAdminListRestTest.php:192-255, tests/e2e/army/invoices.army.ts:119

#### Scenario: Detail shown
- **WHEN** admin mở chi tiết một hóa đơn `failed`
- **THEN** thấy `MTChieu`, mã lỗi, mẫu `1 / C26TAT`, lý do và các attempt

#### Scenario: Unknown invoice
- **WHEN** admin mở chi tiết một ID không tồn tại
- **THEN** phản hồi 404

### Requirement: Sensitive text hidden in admin views
Hệ thống SHALL escape nội dung HTML, che số định danh 9 hoặc 12 chữ số còn 4 số cuối và thay URL bằng `[đã ẩn]` trong mọi lý do lỗi và message hiển thị cho admin.
Evidence: src/Invoice/Redactor.php:22-30, src/Admin/InvoicePage.php:181-232, tests/integration/InvoiceAdminListRestTest.php:225-238

#### Scenario: Raw CCCD in a stored reason
- **WHEN** lý do lỗi chứa CCCD dạng thô, thẻ HTML và URL
- **THEN** HTML danh sách và chi tiết không chứa số thô, hiển thị `********8901`, thẻ HTML bị escape và URL không hiện

### Requirement: Dashboard counts linked to filtered list
Hệ thống SHALL hiển thị số hóa đơn lỗi, đang chờ, đã tạo nháp và đã tạo nháp hôm nay (theo múi giờ cửa hàng), mỗi số liên kết tới danh sách đã lọc tương ứng và không chứa dữ liệu người mua.
Evidence: src/Admin/DashboardStats.php:26-68, src/Admin/InvoicePage.php:86-110, tests/integration/InvoiceAdminDashboardRestTest.php:53-170, tests/e2e/army/invoices.army.ts:101

#### Scenario: Counts
- **WHEN** có một hóa đơn `failed`, một `pending` và một `draft_created`
- **THEN** số lỗi, đang chờ và đã tạo nháp đều là 1

#### Scenario: Empty table
- **WHEN** chưa có hóa đơn nào
- **THEN** cả ba số đều là 0

#### Scenario: Created today
- **WHEN** hai nháp được tạo trong ngày theo giờ Việt Nam và hai nháp ngoài ngày
- **THEN** đã tạo nháp là 4 và hôm nay là 2, lấy ranh giới 00:00 giờ cửa hàng

### Requirement: Read-only Mat Bao status check
Hệ thống SHALL cho admin kiểm tra trạng thái hóa đơn trên Mắt Bão theo `MTChieu` và mẫu đã lưu mà không đổi bản ghi hóa đơn và không tạo hay gửi lại gì.
Evidence: src/Admin/InvoiceController.php:140-160, src/Admin/InvoicePage.php:243-272, tests/integration/InvoiceAdminActionsRestTest.php:169-215, tests/e2e/army/invoices.army.ts:131-150

#### Scenario: Draft still exists
- **WHEN** Mắt Bão trả đúng một hóa đơn nháp
- **THEN** giao diện báo nháp còn tồn tại và trạng thái bản ghi không đổi

#### Scenario: Draft missing
- **WHEN** tra cứu không thấy hóa đơn của một bản ghi `draft_created`
- **THEN** giao diện cảnh báo nháp bị mất và không tự tạo lại

#### Scenario: No longer a draft
- **WHEN** hóa đơn trên Mắt Bão không còn ở trạng thái Nháp
- **THEN** giao diện cảnh báo hóa đơn không còn là nháp

#### Scenario: No stored template
- **WHEN** hóa đơn chưa từng được gửi với mẫu nào
- **THEN** phản hồi 409 và không gọi Mắt Bão

### Requirement: Confirmed resend through retry workflow
Hệ thống SHALL cho admin gửi lại hóa đơn `failed`, `needs_attention`, `not_configured`, `stopped` hoặc `draft_created` sau khi xác nhận, luôn qua chu kỳ retry mới có tra cứu theo `MTChieu` trước khi tạo (xem invoice-retry-dedup), và từ chối hóa đơn `pending`, `sending` hoặc `skipped`.
Evidence: src/Admin/InvoiceController.php:44-50,167-201, tests/integration/InvoiceAdminActionsRestTest.php:70-117, tests/e2e/army/invoices.army.ts:178

#### Scenario: Missing confirmation
- **WHEN** admin gửi lại mà không kèm xác nhận `confirm=1`
- **THEN** yêu cầu bị từ chối và không có tác vụ nào được xếp

#### Scenario: Ineligible status
- **WHEN** admin gửi lại hóa đơn `pending` hoặc `skipped`
- **THEN** phản hồi 409 và không có tác vụ nào được xếp

#### Scenario: Resend after uncertain create
- **WHEN** admin gửi lại hóa đơn `failed` mà Mắt Bão đã có nháp
- **THEN** hệ thống nhận lại nháp đó và không tạo thêm

#### Scenario: Double submit
- **WHEN** yêu cầu gửi lại được gửi hai lần đồng thời
- **THEN** chỉ một tác vụ được xếp và yêu cầu thua trả 409

### Requirement: Lost draft recreation needs explicit confirmation
Hệ thống SHALL chỉ tạo lại hóa đơn `draft_created` khi admin xác nhận thêm rằng nháp đã mất (`confirm_lost=1`) và tra cứu Mắt Bão không thấy hóa đơn.
Evidence: src/Admin/InvoiceController.php:259-276, tests/integration/InvoiceAdminActionsRestTest.php:119-167, tests/e2e/army/invoices.army.ts:151

#### Scenario: Without lost confirmation
- **WHEN** admin gửi lại hóa đơn `draft_created` chỉ với xác nhận thông thường
- **THEN** yêu cầu bị từ chối, trạng thái giữ nguyên và không có tác vụ nào được xếp

#### Scenario: Draft still on Mat Bao
- **WHEN** admin xác nhận nháp đã mất nhưng tra cứu vẫn thấy hóa đơn
- **THEN** phản hồi 409 và không gửi lại

#### Scenario: Draft really deleted
- **WHEN** admin xác nhận nháp đã mất và tra cứu không thấy hóa đơn
- **THEN** hệ thống tạo lại nháp

### Requirement: Template-changed escape hatch
Hệ thống SHALL cho gửi lại hóa đơn dừng vì `template_changed` bằng mẫu mặc định hiện tại chỉ khi admin xác nhận không có hóa đơn, nhập đúng `MTChieu`, và tra cứu dưới mẫu cũ không thấy hóa đơn; hành động được ghi thành một attempt kèm user ID của admin.
Evidence: src/Admin/InvoiceController.php:278-334, tests/integration/InvoiceAdminActionsRestTest.php:293-372,469

#### Scenario: Plain resend blocked
- **WHEN** admin gửi lại hóa đơn `template_changed` mà không có hai xác nhận
- **THEN** yêu cầu bị chặn

#### Scenario: Confirmed and absent
- **WHEN** admin xác nhận đủ và Mắt Bão không có hóa đơn dưới mẫu cũ
- **THEN** có attempt `admin_absent` ghi user ID, mẫu đã lưu được xóa và tác vụ gửi lại được xếp

#### Scenario: Invoice found under old template
- **WHEN** Mắt Bão vẫn có hóa đơn dưới mẫu cũ
- **THEN** yêu cầu bị từ chối và bản ghi không đổi

#### Scenario: Wrong reference typed
- **WHEN** admin nhập sai `MTChieu`
- **THEN** yêu cầu bị từ chối và bản ghi không đổi

### Requirement: Buyer revalidation on resend
Hệ thống SHALL khi gửi lại hóa đơn dừng vì dữ liệu người mua không hợp lệ thì kiểm tra lại người mua (xem buyer-snapshot-privacy), từ chối kèm tên trường (không kèm giá trị) nếu vẫn sai, và chỉ chụp lại snapshot khi chưa attempt nào có thể đã tới Mắt Bão.
Evidence: src/Admin/InvoiceController.php:203-250, tests/integration/InvoiceAdminActionsRestTest.php:373-486

#### Scenario: Still invalid
- **WHEN** billing vẫn sai
- **THEN** lỗi liệt kê tên trường và không có tác vụ nào được xếp

#### Scenario: Fixed billing
- **WHEN** billing đã được sửa và chưa có attempt nào tới Mắt Bão
- **THEN** snapshot được chụp lại và hóa đơn được tạo đúng một lần

#### Scenario: Earlier attempt may have reached Mat Bao
- **WHEN** đã có attempt có thể tới Mắt Bão
- **THEN** snapshot cũ được giữ

### Requirement: Order edit screen invoice meta box
Hệ thống SHALL hiển thị hộp chỉ đọc "Hóa đơn Mắt Bão" trên màn hình sửa đơn (cả HPOS và lưu trữ cũ) với trạng thái, `MTChieu`, mã hóa đơn Mắt Bão, mẫu số/ký hiệu, ngày lập, loại lỗi và lý do.
Evidence: src/Admin/OrderInvoiceMetaBox.php:22-105, tests/integration/OrderInvoiceMetaBoxTest.php:20-87, tests/e2e/army/checkout-invoice.army.ts:21-76

#### Scenario: Draft created
- **WHEN** đơn có hóa đơn `draft_created`
- **THEN** hộp hiện "Đã tạo nháp" và mã hóa đơn Mắt Bão

#### Scenario: No invoice
- **WHEN** đơn chưa có hóa đơn
- **THEN** hộp báo chưa có hóa đơn

#### Scenario: Skipped order
- **WHEN** đơn 0 đồng có hóa đơn `skipped`
- **THEN** hộp hiện lý do bỏ qua và không có nút thao tác

### Requirement: Invoice status filter on the order list
Hệ thống SHALL thêm bộ lọc "Hóa đơn" trên danh sách đơn WooCommerce (HPOS và lưu trữ cũ) chỉ cho Administrator, thu hẹp danh sách theo trạng thái hóa đơn và kết hợp được với các điều kiện lọc khác.
Evidence: src/Admin/OrderListInvoiceFilter.php:51-176, tests/integration/OrderListInvoiceFilterTest.php:52-181, tests/e2e/army/invoices.army.ts:77

#### Scenario: Error filter
- **WHEN** admin chọn lọc hóa đơn lỗi
- **THEN** chỉ đơn có hóa đơn lỗi được hiển thị, kể cả khi kết hợp với bộ lọc khác

#### Scenario: No or unknown filter
- **WHEN** không chọn bộ lọc hoặc giá trị không hợp lệ
- **THEN** danh sách đơn không bị thay đổi

#### Scenario: Non-administrator
- **WHEN** shop manager mở danh sách đơn
- **THEN** không có bộ lọc và danh sách không bị lọc

### Requirement: Per-product invoice unit
Hệ thống SHALL cho người có quyền sửa sản phẩm đặt "Đơn vị tính hóa đơn" cho sản phẩm và từng biến thể, bỏ markup và khoảng trắng thừa, và từ chối giá trị dài hơn 50 ký tự mà giữ giá trị cũ.
Evidence: src/Admin/ProductUnitField.php:44-164, tests/integration/ProductUnitFieldTest.php:33-319, tests/e2e/army/units-permissions.army.ts:27-54

#### Scenario: Saved and sent
- **WHEN** admin lưu đơn vị "Tháng" cho sản phẩm rồi đơn có sản phẩm đó completed
- **THEN** dòng hóa đơn gửi `DVTinh` = "Tháng"

#### Scenario: Blank value
- **WHEN** admin lưu đơn vị trống
- **THEN** đơn vị riêng của sản phẩm bị xóa

#### Scenario: Too long
- **WHEN** admin lưu đơn vị dài hơn 50 ký tự
- **THEN** hiện lỗi và giá trị cũ được giữ

#### Scenario: Missing nonce or permission
- **WHEN** nonce sai hoặc người dùng không sửa được sản phẩm
- **THEN** đơn vị không đổi
