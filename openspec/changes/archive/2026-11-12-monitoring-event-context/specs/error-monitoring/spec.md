## ADDED Requirements

### Requirement: Invoice context on needs-attention events
Khi giám sát bật, event needs-attention SHALL mang context `invoice` gồm mã đơn, ID hóa đơn, mã tham chiếu, trạng thái, mã lỗi, nhãn lỗi, hướng dẫn xử lý, lý do lưu trên hóa đơn và `admin_path`. `admin_path` SHALL là đường dẫn sửa đơn không có scheme, host và fragment, chỉ giữ các query parameter `page`, `action`, `id`, `post`. Mọi giá trị context SHALL đi qua bộ lọc dữ liệu nhạy cảm; khi bộ lọc che một giá trị (ví dụ ID dài 10 hoặc 12 chữ số), bộ lọc được ưu tiên.

#### Scenario: Invoice context present
- **WHEN** đơn 901399 dừng với `line_tax_rate_count`
- **THEN** event có context `invoice` với `order_id` 901399, hướng dẫn xử lý và `admin_path` bắt đầu bằng `/`

#### Scenario: Admin path without sensitive parameters
- **WHEN** URL sửa đơn của site có thêm `_wpnonce` và fragment
- **THEN** `admin_path` chỉ có path cùng `page`, `action`, `id` hoặc `post`, không có `_wpnonce` hay fragment

#### Scenario: Alert without failure detail
- **WHEN** recovery sweep không xếp được hàng đợi và hóa đơn dừng hẳn với `queue_failed`
- **THEN** event có context `invoice` và không có context `line` hay `matbao`

#### Scenario: Context building fails
- **WHEN** dựng context `invoice` ném exception
- **THEN** trạng thái, mã lỗi và lịch retry của hóa đơn giống như khi giám sát tắt

### Requirement: Line context on line data errors
Khi lỗi dữ liệu theo dòng phát sinh trong cùng request với event needs-attention, event SHALL mang context `line` với thông tin dòng mà lỗi mang theo (xem draft-invoice-creation) và tag `product_id` khi dòng có sản phẩm. Context `line` SHALL đi qua bộ lọc dữ liệu nhạy cảm.

#### Scenario: Tax rate count error on a product line
- **WHEN** dòng sản phẩm (product 55, SKU `AO-01`) mang hai thuế suất và hóa đơn dừng với `line_tax_rate_count`
- **THEN** event có context `line` với product 55, SKU `AO-01` và hai ID thuế suất, và tag `product_id` = 55

#### Scenario: Product name equals buyer name
- **WHEN** tên dòng hàng gây lỗi trùng tên người mua của đơn
- **THEN** context `line` của event có tên đó thay bằng `[filtered]`

### Requirement: Mat Bao context on Mat Bao errors
Khi lỗi Mắt Bão phát sinh trong cùng request với event needs-attention, event SHALL mang context `matbao` (loại lỗi, HTTP status, mã lỗi API, request đã tới Mắt Bão hay chưa, có tự retry hay không) và tag `matbao.api_code` khi Mắt Bão trả mã lỗi.

#### Scenario: Mat Bao rejection
- **WHEN** create-invoice nhận phần tử data có `errorCode` 306 và hóa đơn dừng với `matbao_api`
- **THEN** event có context `matbao` với loại `api`, mã 306, `target_sent` true và tag `matbao.api_code` = 306

### Requirement: Failure detail scoped to its order and code
Chi tiết của một lỗi (context `line` hoặc `matbao`) MUST NOT gắn vào event của đơn khác hoặc của mã lỗi khác.

#### Scenario: Detail does not leak to another order
- **WHEN** recovery sweep xử lý đơn A lỗi dữ liệu theo dòng rồi đơn B dừng với `matbao_http`
- **THEN** event của đơn B không có context `line` của đơn A

#### Scenario: Post-draft warning during a failure
- **WHEN** một cảnh báo `order_amounts_changed` phát trong lúc đang xử lý chi tiết lỗi `line_tax_rate_count`
- **THEN** event của cảnh báo đó không có context `line`

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
- **THEN** event có message `Hóa đơn cần xử lý: Dòng không có đúng một thuế suất – Đơn #901399`

#### Scenario: Same error code on two orders
- **WHEN** hai đơn khác nhau cùng dừng với `matbao_http`
- **THEN** hai event có cùng fingerprint dựa trên `error_code`

### Requirement: Tag allowlist
Event gửi tới server giám sát SHALL chỉ mang tag thuộc danh sách cho phép (`site`, `order_id`, `invoice.id`, `invoice.status`, `error_code`, `error_kind`, `matbao.env`, `component`, `product_id`, `matbao.api_code`), với `site` là host của site, và MUST NOT mang thông tin request, user hay server name.

#### Scenario: Extra tag dropped
- **WHEN** code plugin gắn một tag ngoài danh sách cho phép
- **THEN** event nhận được không có tag đó nhưng có tag `site`

#### Scenario: New filter tags kept
- **WHEN** event needs-attention mang tag `product_id` = 55 và `matbao.api_code` = 306
- **THEN** event nhận được giữ cả hai tag với giá trị đó

#### Scenario: Filter wins over a new tag
- **WHEN** event mang tag `product_id` có giá trị 10 chữ số
- **THEN** tag vẫn có mặt nhưng giá trị là `[filtered]`

#### Scenario: No server name
- **WHEN** giám sát gửi một event lỗi
- **THEN** event không có `server_name`, `request` hay `user`
