## ADDED Requirements

### Requirement: Mat Bao tax mapping table
Tab Xuất hóa đơn SHALL có card "Ánh xạ thuế Mắt Bão" gồm bảy hàng theo thứ tự 10%, 8%, 5%, 0%, KCT, KKKNT, Khác. Mỗi hàng SHALL hiện các thuế suất WooCommerce đã chọn dạng badge có nút × và một ô chọn có nhãn để thêm thuế suất. Mỗi thuế suất WooCommerce SHALL ánh xạ tới nhiều nhất một mã thuế Mắt Bão, nên ô chọn của mọi hàng SHALL chỉ liệt kê các thuế suất còn tồn tại trong WooCommerce (lớp Standard và mọi lớp bổ sung) chưa được thêm vào bảng.

#### Scenario: Empty table
- **WHEN** chưa có ánh xạ nào
- **THEN** card hiện bảy hàng không có badge, và ô chọn của mỗi hàng liệt kê mọi thuế suất WooCommerce kèm tên, lớp, quốc gia và phần trăm

#### Scenario: Badges per group
- **WHEN** nhóm 10% có hai thuế suất và nhóm KCT có một thuế suất
- **THEN** hàng 10% hiện hai badge, hàng KCT hiện một badge, mỗi badge có tên, lớp, quốc gia và nút × có nhãn nêu tên thuế suất và nhóm

#### Scenario: Mapped rate leaves the pickers
- **WHEN** một thuế suất đã thuộc nhóm 8%
- **THEN** thuế suất đó không còn trong ô chọn của bất kỳ hàng nào

#### Scenario: Deleted rate badge
- **WHEN** thuế suất thuộc nhóm KKKNT bị xóa khỏi WooCommerce
- **THEN** hàng KKKNT vẫn hiện badge của nó, ghi "(đã xóa)", kèm nút ×

#### Scenario: No WooCommerce rate
- **WHEN** WooCommerce chưa có thuế suất nào và bảng trống
- **THEN** card hiện hướng dẫn tạo thuế suất trong WooCommerce và không có nút Thêm

### Requirement: Mat Bao tax mapping changes
Nút Thêm SHALL gửi `group` và `rate_id` tới `POST einvoice-matbao/v1/settings/tax-rate-map/add`; nút × SHALL gửi chúng tới `POST einvoice-matbao/v1/settings/tax-rate-map/remove`. Mỗi route SHALL lưu ngay và trả card đã cập nhật kèm thông báo. Route MUST từ chối 422 mà không ghi gì khi dữ liệu không hợp lệ, và SHALL dùng cùng kiểm soát truy cập với các route cài đặt khác.

#### Scenario: Add a rate
- **WHEN** admin chọn thuế suất 10% ở hàng 10% và bấm Thêm
- **THEN** phản hồi thành công, hàng 10% có badge mới, thuế suất đó biến khỏi mọi ô chọn, và hóa đơn sau đó gửi `TSuat=10` cho dòng mang thuế suất này

#### Scenario: Remove a rate
- **WHEN** admin bấm × trên badge của thuế suất thuộc nhóm KCT
- **THEN** phản hồi thành công, badge biến mất, thuế suất trở lại các ô chọn, các ánh xạ khác không đổi

#### Scenario: Remove a deleted rate
- **WHEN** admin bấm × trên badge "(đã xóa)"
- **THEN** ánh xạ đó bị xóa

#### Scenario: Add rejected
- **WHEN** request thêm gửi nhóm không thuộc bảy nhóm, `rate_id` không phải số nguyên dương, thuế suất không tồn tại, hoặc thuế suất đã thuộc một nhóm
- **THEN** phản hồi 422, card hiện thông báo lỗi và ánh xạ không đổi

#### Scenario: Remove rejected
- **WHEN** request xóa gửi thuế suất không thuộc nhóm được nêu, hoặc dữ liệu không hợp lệ
- **THEN** phản hồi 422 và ánh xạ không đổi

#### Scenario: Access control
- **WHEN** người không có quyền `manage_options` hoặc request có nonce sai gọi thêm hoặc xóa
- **THEN** phản hồi 403 và ánh xạ không đổi

## MODIFIED Requirements

### Requirement: Settings grouped by tab
Mỗi tab cấu hình SHALL chứa đúng các form của nhóm mình, giữ nguyên route và hành vi của các form đã có:
- `connection`: form credential Mắt Bão và form chọn mẫu hóa đơn mặc định.
- `invoicing`: form mã tham chiếu/người mua vãng lai, form đơn vị tính và card ánh xạ thuế Mắt Bão.
- `notifications`: form cảnh báo Lark (xem failure-alerts) và form giám sát lỗi (xem error-monitoring), mỗi form là một thẻ riêng với vùng kết quả riêng.
- `advanced`: công tắc xóa dữ liệu khi gỡ plugin.

Evidence: src/Admin/SettingsPage.php:138-147,162-330, src/Uninstall/UninstallSettingsController.php:74-102, tests/integration/SettingsPageTest.php:158-205, tests/e2e/army/tabs.army.ts:35-40

#### Scenario: Invoicing tab contents
- **WHEN** Administrator mở tab Xuất hóa đơn
- **THEN** trang có form prefix `MTChieu`/người mua vãng lai, form đơn vị tính và card ánh xạ thuế Mắt Bão, không có form credential Mắt Bão, webhook Lark hay DSN

#### Scenario: Notifications tab contents
- **WHEN** Administrator mở tab Thông báo
- **THEN** trang có form Lark (công tắc bật, webhook, secret, nút gửi tin kiểm tra) và form giám sát lỗi (công tắc bật, DSN, tỉ lệ lấy mẫu performance, nút gửi event thử), không có form credential Mắt Bão

#### Scenario: Saving one notification card
- **WHEN** Administrator lưu form Lark trong tab Thông báo
- **THEN** kết quả hiện trong vùng kết quả của thẻ Lark và thẻ giám sát lỗi không bị thay đổi

#### Scenario: Monitoring tab contents
- **WHEN** Administrator mở tab Giám sát lỗi bằng alias `tab=monitoring`
- **THEN** trang hiển thị tab Thông báo; mục này bị bỏ có chủ đích, thay bằng "Notifications tab contents"

#### Scenario: Advanced tab contents
- **WHEN** Administrator mở tab Nâng cao
- **THEN** trang có công tắc xóa dữ liệu khi gỡ plugin

#### Scenario: Template list stays with credentials
- **WHEN** Administrator lưu credential Mắt Bão thành công trong tab Kết nối
- **THEN** danh sách mẫu hóa đơn trong cùng tab được tải lại mà không cần chuyển tab
