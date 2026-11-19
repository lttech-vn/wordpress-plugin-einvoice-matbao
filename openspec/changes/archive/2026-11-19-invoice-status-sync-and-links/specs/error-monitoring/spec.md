## ADDED Requirements

### Requirement: Monitoring events for abnormal Mat Bao status
Khi giám sát bật, hệ thống SHALL gửi một event mức `warning` cho mỗi alert trạng thái Mắt Bão bất thường mới, giống event của alert cần xử lý khác: message `Hóa đơn cần xử lý: <nhãn lỗi> – Đơn #<order_id>`, tag `error_code` là `matbao_sign_failed`, `matbao_not_eligible` hoặc `matbao_draft_deleted`, và fingerprint theo `error_code`. Event MUST NOT chứa ghi chú CQT. Lỗi tra cứu trong đồng bộ trạng thái SHALL chỉ được ghi thành log có cấu trúc mức warning, không thành event lỗi.

#### Scenario: Sign error event
- **WHEN** hóa đơn của đơn 901399 chuyển sang Lỗi ký
- **THEN** server giám sát nhận đúng một event `warning` có tag `error_code` = `matbao_sign_failed` và tag `order_id` = 901399

#### Scenario: Lookup failure during sync
- **WHEN** một lượt đồng bộ gặp HTTP 503 từ Mắt Bão
- **THEN** server giám sát nhận một log mức warning và không nhận event lỗi nào
