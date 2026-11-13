## ADDED Requirements

### Requirement: Lark alert switch
Hệ thống SHALL có công tắc "Bật cảnh báo Lark" trong form Lark của tab Thông báo, lưu qua route `POST einvoice-matbao/v1/settings/lark` cùng webhook và secret. Khi công tắc chưa từng được lưu, giá trị hiệu lực SHALL là bật nếu đã có webhook lưu và tắt nếu chưa có; khi đã lưu, giá trị đã lưu SHALL được dùng. Lưu công tắc bật khi chưa có webhook (đã lưu hoặc đang nhập) SHALL bị từ chối với notice tiếng Việt và không ghi gì. Tắt công tắc SHALL giữ nguyên webhook và secret đã lưu.
Evidence: src/Admin/SettingsController.php:334-391

#### Scenario: Existing site keeps alerts
- **WHEN** site đã lưu webhook Lark trước khi có công tắc và chưa lưu công tắc
- **THEN** form Lark hiện công tắc bật và cảnh báo vẫn được gửi tới Lark

#### Scenario: New site default
- **WHEN** site chưa có webhook Lark và chưa lưu công tắc
- **THEN** form Lark hiện công tắc tắt

#### Scenario: Switch off keeps webhook
- **WHEN** Administrator tắt công tắc và lưu form Lark với ô webhook trống
- **THEN** webhook và secret đã lưu vẫn còn và công tắc hiệu lực là tắt

#### Scenario: Enable without webhook
- **WHEN** chưa có webhook lưu và Administrator bật công tắc rồi lưu với ô webhook trống
- **THEN** notice lỗi tiếng Việt hiện và công tắc không đổi

### Requirement: Lark delivery when switched off
Khi công tắc tắt, tác vụ gửi SHALL không gọi Lark, không thử lại và SHALL ghi trạng thái Lark của alert là `disabled`; admin notice SHALL vẫn liệt kê alert kèm ghi chú "(Đã tắt cảnh báo Lark nên chưa gửi tin.)". Alert đã ghi `disabled` SHALL không được gửi bù khi công tắc bật lại. Nút gửi tin kiểm tra SHALL vẫn dùng được khi công tắc tắt.
Evidence: src/Alert/InvoiceAlerts.php:93-98, src/Alert/AdminAlertNotice.php:116-121, src/Admin/SettingsController.php:334-391

#### Scenario: Alert while switched off
- **WHEN** tác vụ gửi chạy khi công tắc tắt và đã có webhook
- **THEN** không có request tới Lark, không thử lại, trạng thái Lark của alert là `disabled` và notice vẫn liệt kê đơn kèm ghi chú đã tắt cảnh báo Lark

#### Scenario: Switch turned on later
- **WHEN** alert đã ghi `disabled` và Administrator bật công tắc
- **THEN** alert đó không được gửi tới Lark, còn alert phát sinh sau đó được gửi

#### Scenario: Test message while switched off
- **WHEN** công tắc tắt, đã có webhook và Administrator bấm gửi tin kiểm tra
- **THEN** tin kiểm tra được gửi tới Lark

## MODIFIED Requirements

### Requirement: Missing Lark configuration or queue failure
Hệ thống SHALL vẫn hiện admin notice khi chưa cấu hình Lark hoặc khi cảnh báo Lark bị tắt (không gửi, không thử lại; chưa cấu hình ghi chú "Chưa cấu hình Lark nên chưa gửi tin", tắt công tắc ghi chú theo yêu cầu "Lark alert switch"), và SHALL ghi nhận alert là không xếp được hàng khi không xếp được tác vụ gửi. Công tắc tắt được kiểm tra trước webhook, nên alert luôn ghi `disabled` khi công tắc tắt dù có webhook hay không.
Evidence: src/Alert/InvoiceAlerts.php:59-66,93-98, src/Alert/AdminAlertNotice.php:116-121, tests/integration/InvoiceAlertTest.php:469-495

#### Scenario: No webhook
- **WHEN** tác vụ gửi chạy khi công tắc bật nhưng chưa có webhook
- **THEN** không có request tới Lark, không thử lại, trạng thái là `not_configured` và notice vẫn liệt kê đơn

#### Scenario: Switched off without webhook
- **WHEN** tác vụ gửi chạy khi công tắc tắt và chưa có webhook
- **THEN** không có request tới Lark, trạng thái là `disabled` và notice vẫn liệt kê đơn

#### Scenario: Queue failure
- **WHEN** xếp tác vụ gửi Lark ném exception
- **THEN** alert được đánh dấu `queue_failed`
