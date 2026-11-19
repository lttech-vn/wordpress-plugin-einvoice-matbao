## ADDED Requirements

### Requirement: Mat Bao status values
Hệ thống SHALL biểu diễn trạng thái hóa đơn trên Mắt Bão theo bảng mã `maTThaiHDon` 1–9 của Mắt Bão (1 Nháp, 2 Lỗi ký, 3 Chưa gửi CQT, 4 Đã cấp mã/tiếp nhận, 5 CQT chưa phản hồi, 6 Không đủ điều kiện cấp mã/tiếp nhận, 7–9 các trạng thái Mẫu 04/SS), cộng trạng thái riêng "Đã xóa trên Mắt Bão". Mã ngoài bảng SHALL hiển thị bằng `tenTThaiHDon` của Mắt Bão.

#### Scenario: Known code
- **WHEN** Mắt Bão trả `maTThaiHDon` = 5
- **THEN** trạng thái hiển thị là "CQT chưa phản hồi"

#### Scenario: Unknown code
- **WHEN** Mắt Bão trả `maTThaiHDon` = 12 với `tenTThaiHDon` "Trạng thái mới"
- **THEN** giao diện hiện "Trạng thái mới", hóa đơn tiếp tục được đồng bộ và không có cảnh báo

### Requirement: Final and abnormal Mat Bao statuses
Hệ thống SHALL coi Đã cấp mã/tiếp nhận và Đã xóa trên Mắt Bão là trạng thái cuối, và MUST coi đúng Lỗi ký, Không đủ điều kiện cấp mã/tiếp nhận và Đã xóa trên Mắt Bão là trạng thái bất thường; mọi trạng thái khác là trung gian.

#### Scenario: Issued is final
- **WHEN** Mắt Bão trả `maTThaiHDon` = 4
- **THEN** trạng thái là "Đã cấp mã/tiếp nhận", là trạng thái cuối và không bất thường

#### Scenario: Sign error is abnormal
- **WHEN** Mắt Bão trả `maTThaiHDon` = 2
- **THEN** trạng thái là "Lỗi ký", không phải trạng thái cuối và là bất thường

### Requirement: Hourly status sync of created drafts
Hệ thống SHALL chạy đồng bộ trạng thái Mắt Bão mỗi giờ qua Action Scheduler cho các hóa đơn `draft_created` có mã hóa đơn Mắt Bão và mẫu đã lưu, chưa ở trạng thái cuối, và có ngày lập (hoặc ngày tạo bản ghi khi thiếu ngày lập) trong vòng 30 ngày. Mỗi lượt SHALL xử lý tối đa 200 hóa đơn, ưu tiên hóa đơn chưa từng được kiểm tra rồi tới hóa đơn kiểm tra lâu nhất, gom theo mẫu số và ký hiệu với tối đa 20 `MTChieu` mỗi lần gọi Mắt Bão. Hóa đơn ngoài điều kiện trên MUST NOT được tra cứu bởi đồng bộ.

#### Scenario: Draft gets issued
- **WHEN** một hóa đơn `draft_created` được kế toán ký và Mắt Bão trả `maTThaiHDon` = 4 ở lượt đồng bộ kế tiếp
- **THEN** bản ghi có trạng thái Mắt Bão "Đã cấp mã/tiếp nhận", số hóa đơn, MCCQT và thời điểm đồng bộ, và status plugin vẫn là `draft_created`

#### Scenario: Final invoice not polled again
- **WHEN** hóa đơn đã ở "Đã cấp mã/tiếp nhận"
- **THEN** các lượt đồng bộ sau không tra cứu hóa đơn đó

#### Scenario: Older than 30 days
- **WHEN** hóa đơn `draft_created` có ngày lập cách đây 31 ngày và vẫn là Nháp
- **THEN** đồng bộ không tra cứu hóa đơn đó

#### Scenario: Draft created before upgrade
- **WHEN** plugin được nâng cấp và có hóa đơn `draft_created` lập 3 ngày trước, chưa có trạng thái Mắt Bão
- **THEN** lượt đồng bộ đầu tiên tra cứu và ghi trạng thái Mắt Bão cho hóa đơn đó

#### Scenario: Batch limit and rotation
- **WHEN** có 250 hóa đơn cần đồng bộ
- **THEN** một lượt tra cứu 200 hóa đơn và lượt sau ưu tiên 50 hóa đơn còn lại

#### Scenario: Requests grouped by template
- **WHEN** một lượt có 25 hóa đơn mẫu `1/C26TAT` và 3 hóa đơn mẫu `1/C26TBB`
- **THEN** có hai request cho mẫu `1/C26TAT` (20 và 5 `MTChieu`) và một request cho mẫu `1/C26TBB`

#### Scenario: Other plugin statuses ignored
- **WHEN** hóa đơn ở `failed`, `pending` hoặc `skipped`
- **THEN** đồng bộ không tra cứu hóa đơn đó

### Requirement: Sync never changes the invoice workflow
Đồng bộ SHALL chỉ ghi trạng thái và thông tin Mắt Bão của hóa đơn; đồng bộ MUST NOT đổi status plugin, tạo, gửi lại, sửa, ký hay xóa hóa đơn trên Mắt Bão.

#### Scenario: Deleted draft not recreated
- **WHEN** đồng bộ thấy nháp đã bị xóa trên Mắt Bão
- **THEN** status plugin vẫn `draft_created` và không có request `create-invoice` nào

### Requirement: Matching the stored Mat Bao invoice
Đồng bộ SHALL tra cứu theo `MTChieu` với mẫu số và ký hiệu đã lưu, và SHALL chỉ nhận dòng có `maSoHDon` bằng mã hóa đơn Mắt Bão đã lưu. Hệ thống SHALL ghi "Đã xóa trên Mắt Bão" chỉ khi Mắt Bão xác nhận không có hóa đơn: errorCode 301, hoặc `MTChieu` nằm trong danh sách `notFound`. Mọi trường hợp khác không có dòng khớp (dòng cùng `MTChieu` nhưng khác mã, dòng không đọc được, hoặc `MTChieu` vắng mặt ở cả hai danh sách) SHALL giữ nguyên trạng thái Mắt Bão và ghi log cảnh báo.

#### Scenario: Matching row
- **WHEN** Mắt Bão trả một dòng có `maSoHDon` bằng mã đã lưu với `maTThaiHDon` = 3
- **THEN** trạng thái Mắt Bão là "Chưa gửi CQT"

#### Scenario: Not found
- **WHEN** tra cứu trả errorCode 301 cho `MTChieu` của hóa đơn
- **THEN** trạng thái Mắt Bão là "Đã xóa trên Mắt Bão" và hóa đơn không còn được đồng bộ

#### Scenario: Listed as not found
- **WHEN** Mắt Bão trả thành công với `MTChieu` của hóa đơn trong `notFound`
- **THEN** trạng thái Mắt Bão là "Đã xóa trên Mắt Bão"

#### Scenario: Unreadable row
- **WHEN** Mắt Bão trả một dòng cho `MTChieu` của hóa đơn nhưng dòng đó không đọc được
- **THEN** trạng thái Mắt Bão không đổi, hóa đơn vẫn được đồng bộ ở lượt sau và một log cảnh báo được ghi

#### Scenario: Reference missing from the answer
- **WHEN** lô tra cứu ba `MTChieu`, Mắt Bão trả dòng cho hai và không liệt kê cái thứ ba ở đâu cả
- **THEN** hóa đơn thứ ba giữ trạng thái Mắt Bão cũ và không bị ghi "Đã xóa"

#### Scenario: Different invoice under the reference
- **WHEN** Mắt Bão trả một dòng cùng `MTChieu` nhưng `maSoHDon` khác mã đã lưu
- **THEN** trạng thái Mắt Bão không đổi và một log cảnh báo được ghi

### Requirement: Lookup failures leave the status unchanged
Khi tra cứu lỗi (transport, HTTP hoặc API, kể cả phản hồi 200 không đọc được), đồng bộ SHALL giữ nguyên trạng thái Mắt Bão đã lưu, chỉ ghi thời điểm đã thử, ghi log cảnh báo và tiếp tục các hóa đơn còn lại; lỗi tra cứu MUST NOT tạo alert. Khi chưa có thông tin kết nối Mắt Bão dùng được, lượt đồng bộ SHALL bỏ qua mà không gọi Mắt Bão.

#### Scenario: Mat Bao 503
- **WHEN** tra cứu một nhóm hóa đơn trả HTTP 503
- **THEN** các hóa đơn đó giữ trạng thái Mắt Bão cũ, không bị ghi "Đã xóa", không có alert, và nhóm kế tiếp vẫn được tra cứu

#### Scenario: No credentials
- **WHEN** lượt đồng bộ chạy khi chưa lưu thông tin kết nối Mắt Bão
- **THEN** không có request nào tới Mắt Bão

### Requirement: Abnormal status alerted once per transition
Khi trạng thái Mắt Bão chuyển sang trạng thái bất thường, hệ thống SHALL ghi mã cảnh báo tương ứng (`matbao_sign_failed`, `matbao_not_eligible`, `matbao_draft_deleted`), giữ status `draft_created`, tăng chu kỳ alert và phát đúng một lần hook needs-attention, kể cả khi hai tiến trình cùng ghi. Cùng trạng thái ở lần sau MUST NOT cảnh báo lại.

#### Scenario: Sign error detected
- **WHEN** đồng bộ thấy hóa đơn chuyển từ Nháp sang Lỗi ký
- **THEN** hóa đơn có `error_code` = `matbao_sign_failed`, status vẫn `draft_created`, chu kỳ alert tăng một và hook needs-attention phát đúng một lần (tin Lark và event giám sát theo failure-alerts và error-monitoring)

#### Scenario: Same abnormal status next hour
- **WHEN** lượt đồng bộ kế tiếp vẫn thấy Lỗi ký
- **THEN** hook needs-attention không phát lại và không có alert mới

#### Scenario: Concurrent sync and manual check
- **WHEN** đồng bộ tự động và nút "Kiểm tra trạng thái" cùng thấy hóa đơn chuyển sang Không đủ điều kiện cấp mã/tiếp nhận
- **THEN** chỉ có một alert

#### Scenario: Issued is not alerted
- **WHEN** hóa đơn chuyển từ Nháp sang "Đã cấp mã/tiếp nhận"
- **THEN** hook needs-attention không phát

### Requirement: Mat Bao warning shares the invoice error code
Mã cảnh báo trạng thái Mắt Bão SHALL thay mã cảnh báo đang có trên hóa đơn, kể cả `order_*_changed` vốn đã được alert riêng. Khi trạng thái Mắt Bão chuyển sang không bất thường, hệ thống SHALL chỉ xóa mã lỗi đang có nếu đó là mã cảnh báo trạng thái Mắt Bão.

#### Scenario: Recovered after re-signing
- **WHEN** hóa đơn đang `matbao_sign_failed` được ký lại và đồng bộ thấy "Đã cấp mã/tiếp nhận"
- **THEN** mã cảnh báo được xóa và admin notice của alert đó biến mất

#### Scenario: Order warning kept on recovery
- **WHEN** hóa đơn đang `matbao_sign_failed`, sau đó đơn bị đổi tổng tiền (`order_amounts_changed`), rồi đồng bộ thấy "Đã cấp mã/tiếp nhận"
- **THEN** `error_code` vẫn là `order_amounts_changed`

#### Scenario: Abnormal status replaces order warning
- **WHEN** hóa đơn đang có `order_buyer_changed` và đồng bộ thấy Lỗi ký
- **THEN** `error_code` là `matbao_sign_failed` và một alert mới được tạo

### Requirement: Recreated draft starts a fresh Mat Bao status
Khi hóa đơn nhận một mã hóa đơn Mắt Bão mới (tạo lại sau khi nháp bị xóa), hệ thống SHALL xóa trạng thái và thông tin Mắt Bão đã lưu để hóa đơn được đồng bộ lại từ đầu. Kết quả tra cứu được đọc trước khi mã hóa đơn hoặc status plugin đổi MUST NOT được ghi vào bản ghi.

#### Scenario: Draft recreated
- **WHEN** hóa đơn "Đã xóa trên Mắt Bão" được admin xác nhận tạo lại và nhận mã hóa đơn mới
- **THEN** trạng thái Mắt Bão rỗng và hóa đơn có mặt trong lượt đồng bộ kế tiếp

#### Scenario: Stale lookup after recreation
- **WHEN** một lượt đồng bộ đọc hóa đơn với mã cũ, rồi hóa đơn được tạo lại với mã mới trước khi lượt đó ghi kết quả
- **THEN** kết quả của lượt đó không được ghi

### Requirement: Status sync stops with the plugin
Action đồng bộ SHALL thuộc nhóm action của plugin để bị hủy khi plugin bị vô hiệu hóa hoặc gỡ cài đặt, và SHALL được xếp lại khi plugin chạy lại.

#### Scenario: Deactivation
- **WHEN** plugin bị vô hiệu hóa
- **THEN** không còn action đồng bộ trạng thái nào được xếp lịch
