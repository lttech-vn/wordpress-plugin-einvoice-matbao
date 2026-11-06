# failure-alerts Specification

## Purpose

Cảnh báo lỗi hóa đơn qua admin notice và Lark webhook.

## Requirements

### Requirement: One alert per order, error and cycle
Hệ thống SHALL tạo đúng một alert cho mỗi tổ hợp (đơn, mã lỗi, chu kỳ alert) khi hóa đơn dừng ở lỗi cần admin xử lý, kể cả khi nhiều hook báo lỗi cùng phát.
Evidence: src/Alert/AlertStore.php:37-64, src/Alert/InvoiceAlerts.php:49-80, tests/integration/InvoiceAlertTest.php:134-192

#### Scenario: Both hooks fire
- **WHEN** hết retry và cả hook needs-attention lẫn retries-exhausted phát cho cùng đơn và lỗi
- **THEN** chỉ có một alert và một tin Lark

#### Scenario: Resend fails again
- **WHEN** admin gửi lại hóa đơn và nó lại dừng với cùng mã lỗi
- **THEN** một alert mới được tạo vì chu kỳ alert đã tăng

#### Scenario: Different error on the same order
- **WHEN** cùng đơn dừng với một mã lỗi khác
- **THEN** một alert riêng được tạo

### Requirement: No alert for waiting retries or intentional order changes
Hệ thống SHALL NOT tạo alert khi hóa đơn đang chờ retry, hoặc khi đơn được chuyển khỏi `completed` có chủ đích (`order_not_completed`).
Evidence: src/Alert/InvoiceAlerts.php:50, src/Invoice/InvoiceErrorCode.php:200-210, tests/integration/InvoiceAlertTest.php:237-249, tests/e2e/army/failures.army.ts:112-125

#### Scenario: Temporary 503
- **WHEN** Mắt Bão trả 503 và hóa đơn chờ retry
- **THEN** admin notice không liệt kê hóa đơn đó

#### Scenario: Order moved out of completed
- **WHEN** admin chuyển đơn từ completed về processing
- **THEN** không có alert và không có tin Lark

### Requirement: Admin notice for administrators
Hệ thống SHALL hiển thị notice lỗi trong wp-admin cho người dùng có quyền `manage_options`, liệt kê tối đa 10 alert chưa ẩn (mới nhất trước) kèm liên kết tới đơn, nhãn lỗi và liên kết "Ẩn".
Evidence: src/Alert/AdminAlertNotice.php:24-65, src/Alert/AlertStore.php:25,98-121, tests/integration/InvoiceAlertTest.php:94-104,250-265,318-334, tests/e2e/army/failures.army.ts:156-170,200-207

#### Scenario: Administrator sees an error
- **WHEN** một hóa đơn dừng ở `failed` và admin mở wp-admin
- **THEN** notice lỗi hiện "#<order>" kèm liên kết sửa đơn

#### Scenario: User without capability
- **WHEN** người dùng không có `manage_options` mở wp-admin
- **THEN** không có notice

### Requirement: Notice reflects current invoice state
Hệ thống SHALL chỉ hiện alert khi hóa đơn vẫn có đúng mã lỗi và chu kỳ alert đó và vẫn cần admin xử lý.
Evidence: src/Alert/AlertStore.php:98-121, tests/integration/InvoiceAlertTest.php:266-274

#### Scenario: Invoice resolved
- **WHEN** admin gửi lại và hóa đơn không còn ở trạng thái lỗi
- **THEN** alert biến mất khỏi notice

### Requirement: Dismissing an alert
Hệ thống SHALL cho admin ẩn mọi alert của một đơn (kể cả alert ngoài 10 mục đang hiện) qua liên kết có nonce, yêu cầu quyền `manage_options`, rồi quay lại trang trước.
Evidence: src/Alert/AdminAlertNotice.php:72-109, tests/integration/InvoiceAlertTest.php:380-390,496

#### Scenario: Missing capability
- **WHEN** shop manager mở liên kết ẩn
- **THEN** phản hồi 403 và alert không bị ẩn

#### Scenario: Invalid nonce
- **WHEN** Administrator dùng nonce không hợp lệ
- **THEN** yêu cầu bị chặn và alert vẫn hiện

### Requirement: Lark message content and privacy
Hệ thống SHALL gửi tin Lark dạng text chỉ chứa số đơn, nhãn loại lỗi, mã lỗi và liên kết admin tới đơn; tin Lark và notice MUST NOT chứa dữ liệu cá nhân người mua, URL webhook, secret hay token.
Evidence: src/Alert/InvoiceAlerts.php:142-153, tests/integration/InvoiceAlertTest.php:105-133

#### Scenario: Final error
- **WHEN** một alert mới được gửi
- **THEN** có đúng một request tới webhook với text chứa "#<order>", mã lỗi và liên kết đơn

#### Scenario: No personal data
- **WHEN** đơn có tên, email, địa chỉ và tên công ty người mua
- **THEN** tin Lark và notice không chứa các giá trị đó

### Requirement: Lark delivery is asynchronous
Hệ thống SHALL gửi tin Lark từ một tác vụ nền riêng, không bao giờ gửi trực tiếp trong lúc xử lý hóa đơn.
Evidence: src/Alert/InvoiceAlerts.php:60,87-101, tests/integration/InvoiceAlertTest.php:230-236

#### Scenario: Queue not yet run
- **WHEN** hook needs-attention vừa phát
- **THEN** chưa có request nào tới Lark

### Requirement: Signed Lark request and success check
Hệ thống SHALL gửi tin Lark bằng POST JSON với timeout 10 giây, không theo redirect, kèm `timestamp` và chữ ký HMAC-SHA256 khi có secret, và chỉ coi là thành công khi HTTP 2xx và mã trả về của Lark bằng 0.
Evidence: src/Lark/LarkClient.php:32-67
Confidence: inferred

#### Scenario: Webhook with secret
- **WHEN** gửi tin với secret không rỗng
- **THEN** body có `timestamp` và `sign`

#### Scenario: Non-zero Lark code
- **WHEN** Lark trả HTTP 200 với code 9499
- **THEN** lần gửi được coi là thất bại

### Requirement: Lark failures never affect the invoice
Hệ thống SHALL xử lý lỗi gửi Lark mà không đổi trạng thái hay mã lỗi hóa đơn và không gửi lại hóa đơn; lỗi được log không kèm dữ liệu cá nhân hay URL, và notice ghi chú "(Không gửi được tin Lark.)".
Evidence: src/Alert/InvoiceAlerts.php:87-109, src/Alert/AdminAlertNotice.php:116-121, docs/discovery.md:25, tests/integration/InvoiceAlertTest.php:194-229

#### Scenario: Lark rejects the message
- **WHEN** Lark trả HTTP 400
- **THEN** hóa đơn giữ nguyên, không có request create-invoice mới, và notice ghi chú không gửi được tin Lark mà không lộ webhook

### Requirement: Lark retry for temporary failures
Hệ thống SHALL thử lại tin Lark tối đa 3 lần sau 60, 300 và 900 giây khi gặp lỗi tạm thời (không có phản hồi, HTTP 5xx, 408, 429), và không thử lại các lỗi 4xx khác.
Evidence: src/Alert/InvoiceAlerts.php:29,103-134, src/Lark/LarkClient.php:65, README.md:159-162, tests/integration/InvoiceAlertTest.php:391-468

#### Scenario: 503 then success
- **WHEN** Lark trả 503 hai lần rồi 200
- **THEN** các lần thử lại diễn ra sau khoảng 1 phút và 5 phút, lần thứ ba thành công và không còn ghi chú lỗi

#### Scenario: Persistent 502
- **WHEN** Lark luôn trả 502
- **THEN** có 4 request (1 lần đầu và 3 lần thử lại), sau đó notice ghi chú không gửi được tin Lark

#### Scenario: Client error
- **WHEN** Lark trả 400
- **THEN** không thử lại

### Requirement: Missing Lark configuration or queue failure
Hệ thống SHALL vẫn hiện admin notice khi chưa cấu hình Lark (không gửi, không thử lại, ghi chú "Chưa cấu hình Lark nên chưa gửi tin"), và SHALL ghi nhận alert là không xếp được hàng khi không xếp được tác vụ gửi.
Evidence: src/Alert/InvoiceAlerts.php:59-66,93-98, src/Alert/AdminAlertNotice.php:116-121, tests/integration/InvoiceAlertTest.php:469-495

#### Scenario: No webhook
- **WHEN** tác vụ gửi chạy khi chưa có webhook
- **THEN** không có request tới Lark, không thử lại, và notice vẫn liệt kê đơn

#### Scenario: Queue failure
- **WHEN** xếp tác vụ gửi Lark ném exception
- **THEN** alert được đánh dấu `queue_failed`

### Requirement: Alert cleanup and retention
Hệ thống SHALL trong recovery sweep xóa tối đa 200 alert cũ nhất mỗi lần, gồm alert của hóa đơn không còn tồn tại, không còn cần admin, đã sang chu kỳ alert mới, hoặc đã ẩn hơn 30 ngày; alert chỉ lưu ID, mã lỗi và trạng thái gửi Lark.
Evidence: src/Alert/AlertStore.php:13-18,27-28,144-181, README.md:165-167,270, tests/integration/InvoiceAlertTest.php:335-379

#### Scenario: Dismissed over 30 days
- **WHEN** sweep chạy và alert đã ẩn hơn 30 ngày
- **THEN** alert bị xóa

#### Scenario: Recently dismissed
- **WHEN** alert vừa được ẩn và hóa đơn vẫn lỗi trong cùng chu kỳ
- **THEN** alert được giữ và hook phát lại không tạo alert mới

### Requirement: Lark webhook URL validation
Hệ thống SHALL chỉ chấp nhận webhook Lark là URL HTTPS tới host `open.larksuite.com` hoặc `open.feishu.cn`, có đường dẫn `/open-apis/bot/v2/hook/<token>`, không port và không thông tin đăng nhập trong URL.
Evidence: src/Lark/LarkWebhook.php:15-61, tests/integration/SettingsCredentialsRestTest.php:186-206

#### Scenario: Rejected URLs
- **WHEN** admin lưu webhook dùng `http`, host khác, có port, có user, thiếu token, hoặc host giả mạo như `open.larksuite.com.evil.com`
- **THEN** phản hồi 422 và không có webhook nào được lưu

### Requirement: Lark test message from settings
Hệ thống SHALL cho admin gửi tin kiểm tra Lark từ trang settings và hiển thị kết quả thành công hoặc lỗi (kèm mã Lark nếu có) với nội dung đã escape HTML.
Evidence: src/Admin/SettingsController.php:340-367, tests/integration/SettingsFailureModesRestTest.php:89-104

#### Scenario: Lark message contains script
- **WHEN** Lark trả code 9499 với msg `<script>alert(1)</script>`
- **THEN** fragment hiển thị `&lt;script&gt;` và không chứa `<script>`
