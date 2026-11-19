## ADDED Requirements

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

## MODIFIED Requirements

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

## RENAMED Requirements

- FROM: `### Requirement: Read-only Mat Bao status check`
- TO: `### Requirement: Mat Bao status check`
