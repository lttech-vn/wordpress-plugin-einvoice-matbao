## MODIFIED Requirements

### Requirement: Monitoring settings form
Hệ thống SHALL cho Administrator trong tab `notifications` lưu công tắc bật/tắt, DSN và `traces_sample_rate` (số từ 0 đến 1, mặc định 1.0) qua route `POST einvoice-matbao/v1/settings/monitoring` với cùng kiểm soát truy cập của các route plugin khác. DSN SHALL hợp lệ khi có scheme http(s), public key và project id dạng số; dữ liệu sai SHALL bị từ chối với notice tiếng Việt và không ghi gì. Ô DSN để trống SHALL giữ DSN đã lưu.

#### Scenario: Save valid settings
- **WHEN** Administrator bật giám sát, nhập DSN `https://<key>@sentry.example.com/3` và tỉ lệ 0.5 rồi lưu
- **THEN** notice thành công hiện và cấu hình hiệu lực là bật, project 3, tỉ lệ 0.5

### Requirement: Stored DSN never rendered
Form và mọi phản hồi của route giám sát MUST NOT chứa DSN hay public key; form SHALL chỉ hiện host và project id của DSN đang dùng, hoặc "Chưa có DSN".

#### Scenario: Form after save

- **WHEN** đã lưu DSN `https://<key>@sentry.example.com/3` và Administrator mở tab Thông báo
- **THEN** form giám sát lỗi hiện "sentry.example.com · project 3" và HTML không chứa `<key>`

#### Scenario: Invalid DSN
- **WHEN** Administrator lưu DSN `not-a-dsn`
- **THEN** notice lỗi hiện và cấu hình cũ giữ nguyên

#### Scenario: Sample rate out of range
- **WHEN** Administrator lưu tỉ lệ lấy mẫu 1.5
- **THEN** notice lỗi hiện và tỉ lệ cũ giữ nguyên

#### Scenario: Blank DSN keeps stored value
- **WHEN** đã có DSN lưu và Administrator lưu form với ô DSN trống
- **THEN** DSN cũ vẫn được dùng
