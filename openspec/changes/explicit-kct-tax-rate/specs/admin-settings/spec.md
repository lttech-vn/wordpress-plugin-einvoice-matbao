## ADDED Requirements

### Requirement: Exempt tax rate assignment
Hệ thống SHALL cho admin gán mỗi thuế suất WooCommerce tỷ lệ 0% (lớp Standard và mọi lớp bổ sung) là `0%`, `KCT` hoặc `KKKNT` bằng cách chọn từ danh sách trong form "Thuế KCT/KKKNT" của tab Xuất hóa đơn, và mỗi thuế suất SHALL thuộc nhiều nhất một nhóm.

#### Scenario: Not configured
- **WHEN** chưa lưu phép gán nào
- **THEN** mọi thuế suất 0% hiện lựa chọn `0%` và không thuế suất nào là KCT hay KKKNT

#### Scenario: Assign exempt rates
- **WHEN** admin gửi `tax_rate_kind[<id KCT>]=kct` và `tax_rate_kind[<id KKKNT>]=kkknt`
- **THEN** hiện thông báo đã lưu, hai thuế suất mang lựa chọn mới khi mở lại tab, và hóa đơn sau đó ánh xạ chúng sang `TSuat` -1 và -2

#### Scenario: Several rates in one group
- **WHEN** admin gán `kct` cho thuế suất 0% của lớp Standard và thuế suất 0% của một lớp bổ sung
- **THEN** cả hai được lưu là KCT

#### Scenario: Only zero rates listed
- **WHEN** WooCommerce có thuế suất 10%, 8% và 0%
- **THEN** form chỉ liệt kê thuế suất 0%, mỗi dòng có tên, lớp thuế, quốc gia và một ô chọn có nhãn

#### Scenario: No zero rate
- **WHEN** WooCommerce không có thuế suất 0% nào
- **THEN** form hiện hướng dẫn tạo thuế suất 0% và không có nút lưu

### Requirement: Exempt tax rate saving
Form SHALL gửi `tax_rate_kind[<rate_id>]` với giá trị `vat0`, `kct` hoặc `kkknt` tới `POST einvoice-matbao/v1/settings/tax-rates`. Mỗi lần lưu SHALL thay phép gán của các thuế suất 0% đang tồn tại, coi thuế suất vắng mặt là `vat0`, và SHALL giữ phép gán của thuế suất đã bị xóa khỏi WooCommerce. Route MUST từ chối 422 mà không ghi gì khi giá trị hoặc ID thuế suất không hợp lệ.

#### Scenario: Missing field clears the assignment
- **WHEN** admin gửi form không có `tax_rate_kind` sau khi đã gán KCT
- **THEN** phản hồi thành công và không còn thuế suất hiện có nào là KCT hay KKKNT

#### Scenario: Deleted rate keeps its assignment
- **WHEN** thuế suất A đã gán KCT bị xóa khỏi WooCommerce, sau đó admin lưu form gán thuế suất B là KKKNT
- **THEN** A vẫn được coi là KCT cho đơn cũ mang A, B là KKKNT, và trang cài đặt không liệt kê A

#### Scenario: Non-zero rate rejected
- **WHEN** request gửi `tax_rate_kind[<id thuế suất 10%>]=kct`
- **THEN** phản hồi 422 và phép gán đã lưu không đổi

#### Scenario: Unknown rate or kind rejected
- **WHEN** request gửi ID thuế suất không tồn tại, hoặc giá trị ngoài `vat0`, `kct`, `kkknt`
- **THEN** phản hồi 422 và phép gán đã lưu không đổi

#### Scenario: Result area per card
- **WHEN** admin lưu form thuế KCT/KKKNT
- **THEN** kết quả hiện trong vùng kết quả riêng của thẻ này, các thẻ khác của tab không đổi

## MODIFIED Requirements

### Requirement: Settings grouped by tab
Mỗi tab cấu hình SHALL chứa đúng các form của nhóm mình, giữ nguyên route và hành vi của các form đã có:
- `connection`: form credential Mắt Bão và form chọn mẫu hóa đơn mặc định.
- `invoicing`: form mã tham chiếu/người mua vãng lai, form đơn vị tính và form thuế KCT/KKKNT.
- `notifications`: form cảnh báo Lark (xem failure-alerts) và form giám sát lỗi (xem error-monitoring), mỗi form là một thẻ riêng với vùng kết quả riêng.
- `advanced`: công tắc xóa dữ liệu khi gỡ plugin.

Evidence: src/Admin/SettingsPage.php:138-147,162-330, src/Uninstall/UninstallSettingsController.php:74-102, tests/integration/SettingsPageTest.php:158-205, tests/e2e/army/tabs.army.ts:35-40

#### Scenario: Invoicing tab contents
- **WHEN** Administrator mở tab Xuất hóa đơn
- **THEN** trang có form prefix `MTChieu`/người mua vãng lai, form đơn vị tính và form thuế KCT/KKKNT, không có form credential Mắt Bão, webhook Lark hay DSN

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
