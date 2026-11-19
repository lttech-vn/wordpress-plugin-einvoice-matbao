## ADDED Requirements

### Requirement: Alerts for abnormal Mat Bao status
Hệ thống SHALL tạo alert (admin notice và tin Lark theo các requirement hiện có) cho các mã cảnh báo `matbao_sign_failed`, `matbao_not_eligible` và `matbao_draft_deleted`. Ghi chú CQT MUST NOT xuất hiện trong tin Lark hay notice.

#### Scenario: Not eligible alert
- **WHEN** đồng bộ thấy hóa đơn của đơn #9013 chuyển sang Không đủ điều kiện cấp mã/tiếp nhận
- **THEN** admin notice và tin Lark chứa "#9013", mã `matbao_not_eligible` và hướng dẫn của mã đó, không chứa ghi chú CQT

#### Scenario: Notice disappears when resolved
- **WHEN** hóa đơn đang có alert `matbao_sign_failed` được ký lại thành công
- **THEN** alert không còn hiện trong admin notice

### Requirement: Resolution hints for Mat Bao status codes
Hệ thống SHALL định nghĩa cho mỗi mã `matbao_sign_failed`, `matbao_not_eligible` và `matbao_draft_deleted` một hướng dẫn xử lý đúng nguyên văn như các scenario dưới, dùng ở mọi nơi hướng dẫn được hiển thị (xem Resolution hint per error code).

#### Scenario: Sign error hint
- **WHEN** hóa đơn có mã `matbao_sign_failed`
- **THEN** hướng dẫn là `Hóa đơn bị lỗi ký trên Mắt Bão. Mở hóa đơn trên Mắt Bão, kiểm tra chữ ký số rồi ký lại; trạng thái tự cập nhật ở lần đồng bộ sau.`

#### Scenario: Not eligible hint
- **WHEN** hóa đơn có mã `matbao_not_eligible`
- **THEN** hướng dẫn là `Cơ quan thuế không cấp mã/tiếp nhận hóa đơn. Xem ghi chú CQT trong chi tiết hóa đơn và xử lý trên Mắt Bão (điều chỉnh hoặc thay thế).`

#### Scenario: Deleted draft hint
- **WHEN** hóa đơn có mã `matbao_draft_deleted`
- **THEN** hướng dẫn là `Hóa đơn nháp không còn trên Mắt Bão. Kiểm tra trên Mắt Bão; nếu nháp bị xóa nhầm, mở chi tiết hóa đơn, bấm "Kiểm tra trạng thái" rồi xác nhận tạo lại.`
