## MODIFIED Requirements

### Requirement: Plugin errors reported as issues
Khi giám sát bật, hệ thống SHALL gửi một event lỗi kèm stacktrace cho mỗi exception bị bắt trong code plugin mà trước đây chỉ được `error_log` hoặc ghi thành lỗi `unexpected`, gắn tag `component` và `order_id` khi biết, và context `invoice` khi biết đơn và đơn có hóa đơn. Exception làm một action Action Scheduler của plugin thất bại SHALL mang context `action` (hook, action ID), và khi tham số đầu tiên của action là mã đơn thì SHALL mang tag `order_id` và được gửi trong ngữ cảnh lọc dữ liệu của đơn đó. Hệ thống SHALL gửi một event mức `warning` cho mỗi alert mới cần admin xử lý (cùng điều kiện tạo alert trong failure-alerts), với message `Hóa đơn cần xử lý: <nhãn lỗi> – Đơn #<order_id>`, tag `order_id`, `error_code`, `error_kind`, `invoice.status`, và gom các event này theo `error_code`.

#### Scenario: Unexpected error while sending
- **WHEN** gửi hóa đơn nháp ném một exception không lường trước
- **THEN** server giám sát nhận một event lỗi có stacktrace, tag `order_id` và context `invoice`, còn hóa đơn vẫn được ghi lỗi `unexpected` như trước

#### Scenario: Action Scheduler action throws
- **WHEN** action tạo nháp của đơn 901399 ném exception ra Action Scheduler
- **THEN** event lỗi có tag `order_id` = 901399 và context `action` với hook `einvoice_matbao_create_draft` và action ID

#### Scenario: Action Scheduler exception echoes the buyer
- **WHEN** action tạo nháp ném ra Action Scheduler một exception có message chứa tên và địa chỉ người mua của đơn
- **THEN** event có context `action` và không chứa các giá trị đó

#### Scenario: Action without an order
- **WHEN** action recovery sweep ném exception ra Action Scheduler
- **THEN** event có context `action` và không có tag `order_id`

#### Scenario: Needs attention once per alert
- **WHEN** hết retry và cả hai hook needs-attention lẫn retries-exhausted phát cho cùng đơn và lỗi
- **THEN** server giám sát nhận đúng một event `warning` cho alert đó

#### Scenario: Readable title
- **WHEN** đơn 901399 dừng với `line_tax_rate_count`
- **THEN** event có message `Hóa đơn cần xử lý: Sản phẩm có quá nhiều dòng thuế – Đơn #901399`

#### Scenario: Same error code on two orders
- **WHEN** hai đơn khác nhau cùng dừng với `matbao_http`
- **THEN** hai event có cùng fingerprint dựa trên `error_code`
