## MODIFIED Requirements

### Requirement: Invoice lookup by reference
Hệ thống SHALL tra cứu hóa đơn theo một hoặc nhiều `MTChieu` cùng mẫu số và ký hiệu trong một lần gọi `/api/invoice/invoice-detail-batch`, trả danh sách rỗng khi Mắt Bão báo không tìm thấy (errorCode 301), báo lỗi khi phản hồi 200 thiếu danh sách hóa đơn, và trả kèm các `MTChieu` Mắt Bão liệt kê trong `notFound`.

#### Scenario: Not found
- **WHEN** tra cứu trả errorCode 301
- **THEN** kết quả là danh sách rỗng

#### Scenario: Unreadable lookup response
- **WHEN** tra cứu trả 200 nhưng không có `data.invoices`
- **THEN** client báo lỗi, không coi là "không tìm thấy"

#### Scenario: Several references
- **WHEN** tra cứu ba `MTChieu` cùng mẫu `1/C26TAT`
- **THEN** có đúng một request với `danhSach` chứa cả ba và kết quả gồm mọi dòng Mắt Bão trả

#### Scenario: Partly not found
- **WHEN** tra cứu hai `MTChieu`, Mắt Bão trả một dòng và liệt kê `MTChieu` còn lại trong `notFound`
- **THEN** kết quả có dòng đó và `notFound` chứa `MTChieu` còn lại

### Requirement: Invoice and template parsing
Hệ thống SHALL coi hóa đơn là nháp khi `maTThaiHDon` = 1 (hoặc `tenTThaiHDon` = "Nháp" khi thiếu mã), coi mẫu là còn số khi `cLai` > 0, và bỏ qua phần tử không hợp lệ trong danh sách. Hệ thống SHALL đọc thêm số hóa đơn (`shDon`, coi 0 là chưa có), MCCQT (`mccqt`, coi rỗng là chưa có), loại hóa đơn (`maLoaiHDon`) và ghi chú CQT (`ghiChuCQT`).

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
