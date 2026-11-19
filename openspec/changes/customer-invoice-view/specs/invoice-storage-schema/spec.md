## MODIFIED Requirements

### Requirement: Mat Bao status fields
Hệ thống SHALL lưu trên bản ghi hóa đơn trạng thái Mắt Bão, tên trạng thái do Mắt Bão trả, số hóa đơn, MCCQT, loại hóa đơn, ghi chú CQT, mã tra cứu, thời điểm đồng bộ thành công gần nhất và thời điểm thử gần nhất; các giá trị này rỗng cho đến lần đồng bộ hoặc kiểm tra đầu tiên. Nâng cấp schema SHALL thêm các trường này mà giữ nguyên dữ liệu cũ.

#### Scenario: Upgrade from the previous schema
- **WHEN** plugin nâng cấp từ schema trước trên site có hóa đơn `draft_created`
- **THEN** hóa đơn cũ giữ nguyên mọi giá trị và các trường Mắt Bão mới rỗng

#### Scenario: Lookup code stored
- **WHEN** kết quả Mắt Bão được ghi cho hóa đơn với mã tra cứu "FM80520C49"
- **THEN** bản ghi có mã tra cứu "FM80520C49"

#### Scenario: Deleted clears the lookup code
- **WHEN** bản ghi có mã tra cứu "FM80520C49" được ghi trạng thái "Đã xóa trên Mắt Bão"
- **THEN** bản ghi không còn mã tra cứu

### Requirement: New Mat Bao invoice resets Mat Bao status fields
Hệ thống SHALL xóa các trường trạng thái Mắt Bão, kể cả mã tra cứu, trong cùng thao tác ghi mã hóa đơn Mắt Bão mới cho bản ghi.

#### Scenario: Recorded after recreation
- **WHEN** bản ghi có trạng thái Mắt Bão "Đã xóa trên Mắt Bão" được ghi mã hóa đơn mới
- **THEN** trạng thái, số hóa đơn, MCCQT, mã tra cứu và các thời điểm đồng bộ đều rỗng
