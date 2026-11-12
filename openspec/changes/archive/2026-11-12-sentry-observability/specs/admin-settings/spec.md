## MODIFIED Requirements

### Requirement: Tabbed plugin screen
Trang plugin SHALL chia nội dung thành 6 tab theo thứ tự Hóa đơn (`invoices`), Kết nối Mắt Bão (`connection`), Xuất hóa đơn (`invoicing`), Cảnh báo Lark (`lark`), Giám sát lỗi (`monitoring`), Nâng cao (`advanced`), SHALL chỉ render nội dung của tab đang chọn và đánh dấu tab đó `aria-current="page"`. Tab `invoices` SHALL là mặc định khi tham số `tab` thiếu hoặc không hợp lệ. Cảnh báo thiếu khóa mã hóa SHALL hiển thị ở mọi tab.
Evidence: src/Admin/AdminTabs.php:13-52, src/Admin/SettingsPage.php:82-156, tests/integration/AdminTabsTest.php:18-57, tests/integration/SettingsPageTest.php:94-125,149-157, tests/e2e/army/tabs.army.ts:18-23

#### Scenario: Default tab
- **WHEN** Administrator mở `admin.php?page=einvoice-matbao-settings` không kèm `tab`
- **THEN** tab Hóa đơn được đánh dấu đang chọn, danh sách hóa đơn được tải và form credential Mắt Bão không có trong trang

#### Scenario: Deep link to a tab
- **WHEN** Administrator mở `admin.php?page=einvoice-matbao-settings&tab=lark`
- **THEN** tab Cảnh báo Lark được đánh dấu đang chọn và form webhook Lark được hiển thị

#### Scenario: Monitoring tab order
- **WHEN** Administrator mở trang plugin
- **THEN** tab bar hiện 6 tab, tab Giám sát lỗi nằm ngay trước tab Nâng cao

#### Scenario: Unknown tab
- **WHEN** Administrator mở trang với `tab=foo`
- **THEN** trang hiển thị tab Hóa đơn như mặc định

#### Scenario: Missing encryption key on any tab
- **WHEN** chưa cấu hình `EINVOICE_MATBAO_ENCRYPTION_KEY` và Administrator mở tab Hóa đơn
- **THEN** cảnh báo thiếu khóa mã hóa vẫn hiển thị phía trên tab bar

### Requirement: Settings grouped by tab
Mỗi tab cấu hình SHALL chứa đúng các form của nhóm mình, giữ nguyên route và hành vi của các form đã có: `connection` chứa form credential Mắt Bão và chọn mẫu hóa đơn mặc định; `invoicing` chứa form mã tham chiếu/người mua vãng lai và form đơn vị tính; `lark` chứa form cảnh báo Lark; `monitoring` chứa form giám sát lỗi (xem error-monitoring); `advanced` chứa công tắc xóa dữ liệu khi gỡ plugin.
Evidence: src/Admin/SettingsPage.php:138-147,162-330, src/Uninstall/UninstallSettingsController.php:74-102, tests/integration/SettingsPageTest.php:158-205, tests/e2e/army/tabs.army.ts:35-40

#### Scenario: Invoicing tab contents
- **WHEN** Administrator mở tab Xuất hóa đơn
- **THEN** trang có form prefix `MTChieu`/người mua vãng lai và form đơn vị tính, không có form credential Mắt Bão hay webhook Lark

#### Scenario: Monitoring tab contents
- **WHEN** Administrator mở tab Giám sát lỗi
- **THEN** trang có form bật/tắt giám sát, DSN, tỉ lệ lấy mẫu performance và nút gửi event thử, không có form webhook Lark

#### Scenario: Advanced tab contents
- **WHEN** Administrator mở tab Nâng cao
- **THEN** trang có công tắc xóa dữ liệu khi gỡ plugin

#### Scenario: Template list stays with credentials
- **WHEN** Administrator lưu credential Mắt Bão thành công trong tab Kết nối
- **THEN** danh sách mẫu hóa đơn trong cùng tab được tải lại mà không cần chuyển tab
