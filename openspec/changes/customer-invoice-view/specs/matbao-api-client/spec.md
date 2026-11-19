## ADDED Requirements

### Requirement: Invoice PDF download
Hệ thống SHALL lấy PDF của một hóa đơn bằng một lần gọi `/api/invoice/download-invoice` với `MaSoHDon` và `MaTraCuu`, qua cùng cơ chế token, đăng nhập lại khi 401 và ánh xạ lỗi như các lời gọi khác, và trả nội dung PDF đã giải mã base64 từ `data.data_PDF_Base64`. Client SHALL đọc tên trường không phân biệt hoa thường, và SHALL báo lỗi `api` khi phản hồi thành công nhưng thiếu trường PDF, base64 không hợp lệ, hoặc nội dung giải mã không bắt đầu bằng `%PDF-`.

#### Scenario: PDF returned
- **WHEN** `download-invoice` trả `success` true với `data.data_PDF_Base64` là base64 của một file PDF
- **THEN** client trả đúng các byte của file PDF đó và đã gửi đúng một request `POST` với `MaSoHDon`, `MaTraCuu` đã cho

#### Scenario: Field name casing
- **WHEN** `download-invoice` trả `Data.Data_PDF_Base64` thay vì `data.data_PDF_Base64`
- **THEN** client vẫn trả đúng các byte của file PDF

#### Scenario: Invalid base64
- **WHEN** `data_PDF_Base64` chứa ký tự ngoài bảng base64
- **THEN** client báo lỗi `api`

#### Scenario: Missing PDF field
- **WHEN** `download-invoice` trả `success` true nhưng `data` không có `data_PDF_Base64`
- **THEN** client báo lỗi `api`

#### Scenario: Not a PDF
- **WHEN** `data_PDF_Base64` giải mã ra nội dung không bắt đầu bằng `%PDF-`
- **THEN** client báo lỗi `api`

#### Scenario: Download rejected
- **WHEN** `download-invoice` trả `success` false với một errorCode
- **THEN** client báo lỗi `api` kèm errorCode đó

## MODIFIED Requirements

### Requirement: Invoice and template parsing
Hệ thống SHALL coi hóa đơn là nháp khi `maTThaiHDon` = 1 (hoặc `tenTThaiHDon` = "Nháp" khi thiếu mã), coi mẫu là còn số khi `cLai` > 0, và bỏ qua phần tử không hợp lệ trong danh sách. Hệ thống SHALL đọc thêm số hóa đơn (`shDon`, coi 0 là chưa có), MCCQT (`mccqt`, coi rỗng là chưa có), loại hóa đơn (`maLoaiHDon`), ghi chú CQT (`ghiChuCQT`) và mã tra cứu (`maTraCuu`, coi rỗng là chưa có).

#### Scenario: Draft by name
- **WHEN** hóa đơn không có `maTThaiHDon` và `tenTThaiHDon` là "Nháp"
- **THEN** hóa đơn được coi là nháp

#### Scenario: Template out of numbers
- **WHEN** mẫu có `cLai` = 0
- **THEN** mẫu được coi là hết số

#### Scenario: Issued invoice fields
- **WHEN** hóa đơn có `shDon` = 125, `mccqt` = "00ABC", `maLoaiHDon` = 2
- **THEN** số hóa đơn là 125, MCCQT là "00ABC" và loại là "Hóa đơn mới"

#### Scenario: Draft without number
- **WHEN** hóa đơn nháp có `shDon` = 0 và `mccqt` rỗng
- **THEN** số hóa đơn và MCCQT là chưa có

#### Scenario: Lookup code read
- **WHEN** hóa đơn có `maTraCuu` = "FM80520C49"
- **THEN** mã tra cứu là "FM80520C49"

#### Scenario: Lookup code missing
- **WHEN** hóa đơn không có `maTraCuu` hoặc `maTraCuu` rỗng
- **THEN** mã tra cứu là chưa có
