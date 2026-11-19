## ADDED Requirements

### Requirement: Mat Bao status fields
Hệ thống SHALL lưu trên bản ghi hóa đơn trạng thái Mắt Bão, tên trạng thái do Mắt Bão trả, số hóa đơn, MCCQT, loại hóa đơn, ghi chú CQT, thời điểm đồng bộ thành công gần nhất và thời điểm thử gần nhất; các giá trị này rỗng cho đến lần đồng bộ hoặc kiểm tra đầu tiên. Nâng cấp schema SHALL thêm các trường này mà giữ nguyên dữ liệu cũ.

#### Scenario: Upgrade from the previous schema
- **WHEN** plugin nâng cấp từ schema trước trên site có hóa đơn `draft_created`
- **THEN** hóa đơn cũ giữ nguyên mọi giá trị và các trường Mắt Bão mới rỗng

### Requirement: Mat Bao status written only from the expected value
Hệ thống SHALL chỉ ghi kết quả Mắt Bão khi status plugin, mã hóa đơn Mắt Bão và trạng thái Mắt Bão hiện tại vẫn bằng giá trị đã đọc, để khi nhiều tiến trình cùng ghi một chuyển trạng thái chỉ một tiến trình thắng và kết quả cũ không ghi đè nháp mới; khi kèm cảnh báo, cùng thao tác đó SHALL ghi mã lỗi, lý do và tăng chu kỳ alert mà không đổi status plugin.

#### Scenario: Two writers
- **WHEN** hai tiến trình cùng đọc trạng thái Nháp và cùng ghi Lỗi ký
- **THEN** chỉ một tiến trình báo thành công và chu kỳ alert chỉ tăng một

#### Scenario: Invoice ID changed meanwhile
- **WHEN** một tiến trình đọc mã hóa đơn A, rồi bản ghi được ghi mã hóa đơn B trước khi tiến trình đó ghi
- **THEN** lần ghi của tiến trình đó không đổi gì

### Requirement: New Mat Bao invoice resets Mat Bao status fields
Hệ thống SHALL xóa các trường trạng thái Mắt Bão trong cùng thao tác ghi mã hóa đơn Mắt Bão mới cho bản ghi.

#### Scenario: Recorded after recreation
- **WHEN** bản ghi có trạng thái Mắt Bão "Đã xóa trên Mắt Bão" được ghi mã hóa đơn mới
- **THEN** trạng thái, số hóa đơn, MCCQT và các thời điểm đồng bộ đều rỗng

### Requirement: Bounded status sync query
Hệ thống SHALL trả tối đa số hóa đơn được yêu cầu trong các hóa đơn `draft_created` có mã hóa đơn Mắt Bão và mẫu, chưa ở trạng thái Mắt Bão cuối, và có ngày lập (hoặc ngày tạo khi thiếu) không sớm hơn mốc đã cho, theo thứ tự hóa đơn chưa thử trước rồi thời điểm thử cũ nhất.

#### Scenario: Ordering
- **WHEN** có một hóa đơn chưa từng thử và một hóa đơn thử cách đây 2 giờ
- **THEN** hóa đơn chưa từng thử đứng trước
