# admin-invoice-management Specification

## Purpose

Quản lý hóa đơn trong admin, gồm danh sách, dashboard, thao tác, meta box, filter và đơn vị tính sản phẩm.

## Requirements

### Requirement: Invoice management page access
Hệ thống SHALL chỉ hiện danh sách hóa đơn, dưới dạng tab Hóa đơn (tab mặc định) của trang plugin "Hóa đơn Mắt Bão" (xem admin-settings), cho người dùng có quyền `manage_options`; các route của tab dùng chung kiểm soát truy cập với settings (xem admin-settings). Plugin SHALL không còn mục menu "Danh sách hóa đơn" riêng.
Evidence: src/Admin/InvoicePage.php:53-74,81, src/Admin/AdminTabs.php:22-30, tests/integration/InvoiceAdminListRestTest.php:59-91, tests/integration/AdminTabsTest.php:18-22, tests/e2e/army/tabs.army.ts:18-23,64-72, tests/e2e/army/units-permissions.army.ts:61-105

#### Scenario: Shop manager
- **WHEN** shop manager mở wp-admin
- **THEN** không thấy menu "Hóa đơn Mắt Bão" lẫn danh sách hóa đơn và route hóa đơn trả 403

#### Scenario: Invoice list is the first tab
- **WHEN** Administrator bấm menu WooCommerce > Hóa đơn Mắt Bão
- **THEN** tab Hóa đơn là tab đầu tiên, đang chọn, hiển thị dashboard, bộ lọc và danh sách hóa đơn

#### Scenario: Detail stays inside the tab
- **WHEN** Administrator bấm "Chi tiết" của một hóa đơn trong tab Hóa đơn
- **THEN** chi tiết hiển thị trong vùng danh sách, tab bar vẫn hiển thị và tab Hóa đơn vẫn đang chọn

### Requirement: Filterable searchable paginated invoice list
Hệ thống SHALL hiển thị danh sách hóa đơn mới nhất trước, 20 dòng mỗi trang, lọc theo trạng thái (nhóm lỗi, nhóm đang chờ, hoặc một trạng thái) và tìm theo mã đơn hoặc tiền tố `MTChieu`. Mỗi dòng SHALL hiện mã đơn, số hóa đơn (khi có), `MTChieu`, trạng thái plugin, trạng thái Mắt Bão, lỗi và thời điểm đồng bộ Mắt Bão gần nhất. Bộ lọc và tìm kiếm không đổi.

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

#### Scenario: Issued invoice row
- **WHEN** danh sách có hóa đơn đã cấp mã với số hóa đơn 125
- **THEN** dòng đó hiện số 125, badge "Đã cấp mã/tiếp nhận" và thời điểm đồng bộ

### Requirement: Invoice detail with attempt history
Hệ thống SHALL hiển thị chi tiết một hóa đơn gồm đơn hàng, trạng thái, mã hóa đơn Mắt Bão, mẫu số/ký hiệu đã lưu, người mua, mã và nhãn lỗi, hướng dẫn xử lý của mã lỗi, lý do và lịch sử attempt; và khi có, trạng thái Mắt Bão, số hóa đơn, MCCQT, loại hóa đơn, ghi chú CQT (đã che dữ liệu nhạy cảm như lý do lỗi) và thời điểm đồng bộ gần nhất.

#### Scenario: Detail shown
- **WHEN** admin mở chi tiết một hóa đơn `failed`
- **THEN** thấy `MTChieu`, mã lỗi, hướng dẫn xử lý, mẫu `1 / C26TAT`, lý do và các attempt

#### Scenario: Unknown invoice
- **WHEN** admin mở chi tiết một ID không tồn tại
- **THEN** phản hồi 404

#### Scenario: Issued invoice detail
- **WHEN** admin mở chi tiết hóa đơn đã cấp mã
- **THEN** thấy trạng thái Mắt Bão "Đã cấp mã/tiếp nhận", số hóa đơn, MCCQT, loại hóa đơn "Hóa đơn mới" và thời điểm đồng bộ

#### Scenario: Tax note with identifier
- **WHEN** ghi chú CQT chứa một số định danh 12 chữ số
- **THEN** chi tiết chỉ hiện 4 số cuối của số đó

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

### Requirement: Mat Bao status check
Hệ thống SHALL cho admin kiểm tra trạng thái hóa đơn trên Mắt Bão theo `MTChieu` và mẫu đã lưu, không tạo hay gửi lại gì và không đổi status plugin. Với hóa đơn `draft_created`, kiểm tra SHALL ghi kết quả như đồng bộ trạng thái (xem matbao-status-sync), kể cả cảnh báo trạng thái bất thường, và hiển thị trạng thái Mắt Bão vừa ghi: thành công cho Nháp, trung gian và Đã cấp mã/tiếp nhận; lỗi kèm hướng dẫn xử lý cho trạng thái bất thường. Với status khác, kiểm tra SHALL chỉ đọc.

#### Scenario: Draft still exists
- **WHEN** Mắt Bão trả đúng một hóa đơn nháp khớp mã đã lưu
- **THEN** giao diện báo nháp còn tồn tại và status plugin không đổi

#### Scenario: Draft missing
- **WHEN** Mắt Bão xác nhận không có hóa đơn (errorCode 301 hoặc `MTChieu` trong `notFound`) cho một bản ghi `draft_created`
- **THEN** bản ghi có trạng thái Mắt Bão "Đã xóa trên Mắt Bão", giao diện cảnh báo nháp bị mất, hiện form tạo lại có xác nhận và không tự tạo lại

#### Scenario: No longer a draft
- **WHEN** hóa đơn `draft_created` trên Mắt Bão không còn ở trạng thái Nháp mà ở "Chưa gửi CQT"
- **THEN** giao diện hiện trạng thái "Chưa gửi CQT" (không phải cảnh báo) và bản ghi lưu trạng thái đó

#### Scenario: Unconfirmed absence
- **WHEN** Mắt Bão trả thành công cho một bản ghi `draft_created` nhưng không có dòng khớp mã đã lưu và `MTChieu` không nằm trong `notFound`
- **THEN** trạng thái Mắt Bão không đổi, giao diện báo không xác định được trạng thái và không hiện form tạo lại

#### Scenario: Issued
- **WHEN** hóa đơn `draft_created` đã "Đã cấp mã/tiếp nhận" trên Mắt Bão
- **THEN** giao diện hiện thông báo thành công với trạng thái đó, bản ghi lưu số hóa đơn và MCCQT, và không có cảnh báo

#### Scenario: Not eligible
- **WHEN** hóa đơn `draft_created` ở "Không đủ điều kiện cấp mã/tiếp nhận" trên Mắt Bão
- **THEN** giao diện hiện lỗi kèm hướng dẫn của `matbao_not_eligible`

#### Scenario: Not a created draft
- **WHEN** admin kiểm tra một hóa đơn `failed` có mẫu đã lưu
- **THEN** giao diện hiện kết quả tra cứu và bản ghi không đổi

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
Hệ thống SHALL hiển thị hộp chỉ đọc "Hóa đơn Mắt Bão" trên màn hình sửa đơn (cả HPOS và lưu trữ cũ) với trạng thái, `MTChieu`, mã hóa đơn Mắt Bão, mẫu số/ký hiệu, ngày lập, loại lỗi, hướng dẫn xử lý khi có lỗi và lý do; và khi có, trạng thái Mắt Bão, số hóa đơn và MCCQT.

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

#### Scenario: Issued invoice
- **WHEN** đơn có hóa đơn đã cấp mã
- **THEN** hộp hiện "Đã cấp mã/tiếp nhận", số hóa đơn và MCCQT

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

### Requirement: Status badges in the invoice list
Bảng danh sách hóa đơn SHALL hiển thị trạng thái mỗi dòng dưới dạng badge mang thuộc tính `data-einv-badge` theo nhóm: `error` cho `failed`, `needs_attention`, `not_configured`; `waiting` cho `pending`, `sending`; `created` cho `draft_created`; `other` cho mọi trạng thái còn lại. Mỗi nhóm SHALL có màu riêng và badge SHALL vẫn chứa nhãn trạng thái tiếng Việt để màu không là tín hiệu duy nhất.
Evidence: src/Invoice/InvoiceStatus.php:44-75, src/Admin/InvoicePage.php:29-40,201-209, tests/integration/InvoiceStatusGroupTest.php:18-48, tests/integration/InvoiceAdminListRestTest.php:108-141

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

### Requirement: Invoice detail has its own address
Hệ thống SHALL cho mở chi tiết một hóa đơn trực tiếp bằng địa chỉ trang plugin có tab Hóa đơn và tham số `invoice=<id>`, và SHALL cập nhật địa chỉ trình duyệt khi admin mở chi tiết từ danh sách hoặc quay lại danh sách. Tham số không phải số nguyên dương SHALL bị bỏ qua và trang hiện danh sách.

#### Scenario: Open by address
- **WHEN** admin mở `admin.php?page=<trang plugin>&tab=invoices&invoice=12`
- **THEN** trang hiện chi tiết hóa đơn 12

#### Scenario: Address follows navigation
- **WHEN** admin bấm "Chi tiết" của hóa đơn 12 trong danh sách
- **THEN** địa chỉ trình duyệt chứa `invoice=12`, và nút Back của trình duyệt quay về danh sách

#### Scenario: Invalid parameter
- **WHEN** admin mở trang với `invoice=abc`
- **THEN** trang hiện danh sách hóa đơn

### Requirement: Links between orders and invoices
Hệ thống SHALL liên kết mã đơn trong danh sách và chi tiết hóa đơn tới màn hình sửa đơn WooCommerce (đúng cho cả HPOS và lưu trữ cũ), và SHALL liên kết hộp "Hóa đơn Mắt Bão" của đơn tới chi tiết hóa đơn khi người xem có quyền `manage_options`. Khi đơn không còn tồn tại, mã đơn SHALL hiện dạng text.

#### Scenario: From invoice to order
- **WHEN** admin xem danh sách hoặc chi tiết hóa đơn của đơn #9013
- **THEN** "#9013" là liên kết tới màn hình sửa đơn 9013

#### Scenario: From order to invoice
- **WHEN** administrator mở đơn có hóa đơn
- **THEN** hộp "Hóa đơn Mắt Bão" có liên kết "Xem chi tiết hóa đơn" mở chi tiết hóa đơn đó

#### Scenario: Shop manager without manage_options
- **WHEN** người dùng không có `manage_options` mở đơn có hóa đơn
- **THEN** hộp không có liên kết tới chi tiết hóa đơn

#### Scenario: Deleted order
- **WHEN** đơn của một hóa đơn đã bị xóa
- **THEN** danh sách hiện mã đơn dạng text, không có liên kết

### Requirement: Mat Bao status badges
Hệ thống SHALL hiển thị trạng thái Mắt Bão dưới dạng badge mang thuộc tính `data-einv-matbao-badge` theo nhóm: `issued` cho Đã cấp mã/tiếp nhận, `error` cho trạng thái bất thường, `progress` cho các trạng thái còn lại; badge SHALL chứa nhãn tiếng Việt. Hóa đơn chưa có trạng thái Mắt Bão SHALL hiện "—".

#### Scenario: Issued badge
- **WHEN** hóa đơn có trạng thái Mắt Bão "Đã cấp mã/tiếp nhận"
- **THEN** badge có `data-einv-matbao-badge="issued"` và chứa "Đã cấp mã/tiếp nhận"

#### Scenario: Deleted badge
- **WHEN** hóa đơn có trạng thái "Đã xóa trên Mắt Bão"
- **THEN** badge có `data-einv-matbao-badge="error"`

#### Scenario: Not yet synced
- **WHEN** hóa đơn `draft_created` chưa được đồng bộ lần nào
- **THEN** ô trạng thái Mắt Bão hiện "—"
