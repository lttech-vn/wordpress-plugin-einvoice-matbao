## MODIFIED Requirements

### Requirement: Settings page access
Hệ thống SHALL cung cấp một trang duy nhất "Hóa đơn Mắt Bão" (slug `einvoice-matbao-settings`) là mục menu cấp cao nhất của wp-admin, không nằm dưới menu WooCommerce, chỉ cho người dùng có quyền `manage_options`, và SHALL không hiển thị nội dung trang cho người không đủ quyền. Trang SHALL là mục menu duy nhất của plugin; slug cũ `einvoice-matbao-invoices` SHALL không còn hiện trong menu và SHALL chuyển hướng Administrator tới tab Hóa đơn của trang này.
Evidence: src/Admin/SettingsPage.php:51-60,82-89, src/Admin/InvoicePage.php:53-74, tests/integration/SettingsPageTest.php:34-69,207-212, tests/e2e/army/tabs.army.ts:58-63, tests/e2e/army/units-permissions.army.ts:61-75

#### Scenario: Administrator sees the menu
- **WHEN** Administrator mở trang quản trị WordPress
- **THEN** menu cấp cao nhất có mục `einvoice-matbao-settings` yêu cầu quyền `manage_options`, menu WooCommerce không có mục này và không có mục `einvoice-matbao-invoices`

#### Scenario: Shop manager denied
- **WHEN** shop manager mở trang settings
- **THEN** không có nội dung nào được hiển thị

#### Scenario: Old invoice list URL
- **WHEN** Administrator mở `admin.php?page=einvoice-matbao-invoices`
- **THEN** phản hồi HTTP 302 chuyển hướng tới `admin.php?page=einvoice-matbao-settings&tab=invoices` và trình duyệt hiển thị tab Hóa đơn

#### Scenario: Old invoice list URL without capability
- **WHEN** shop manager mở `admin.php?page=einvoice-matbao-invoices`
- **THEN** không có chuyển hướng tới trang plugin và WordPress hiển thị màn hình từ chối quyền, không có nội dung nào của plugin

### Requirement: Tabbed plugin screen
Trang plugin SHALL chia nội dung thành 5 tab theo thứ tự Hóa đơn (`invoices`), Kết nối Mắt Bão (`connection`), Xuất hóa đơn (`invoicing`), Thông báo (`notifications`), Nâng cao (`advanced`), SHALL chỉ render nội dung của tab đang chọn và đánh dấu tab đó `aria-current="page"`. Tab `invoices` SHALL là mặc định khi tham số `tab` thiếu hoặc không hợp lệ. Các key cũ `lark` và `monitoring` SHALL là alias của `notifications`: URL chứa một trong hai key này hiển thị tab Thông báo là tab đang chọn. Cảnh báo thiếu khóa mã hóa SHALL hiển thị ở mọi tab.
Evidence: src/Admin/AdminTabs.php:13-52, src/Admin/SettingsPage.php:82-156, tests/integration/AdminTabsTest.php:18-57, tests/integration/SettingsPageTest.php:94-125,149-157, tests/e2e/army/tabs.army.ts:18-23

#### Scenario: Default tab
- **WHEN** Administrator mở `admin.php?page=einvoice-matbao-settings` không kèm `tab`
- **THEN** tab Hóa đơn được đánh dấu đang chọn, danh sách hóa đơn được tải và form credential Mắt Bão không có trong trang

#### Scenario: Deep link to a tab
- **WHEN** Administrator mở `admin.php?page=einvoice-matbao-settings&tab=notifications`
- **THEN** tab Thông báo được đánh dấu đang chọn và cả form webhook Lark lẫn form giám sát lỗi được hiển thị

#### Scenario: Legacy tab keys
- **WHEN** Administrator mở trang với `tab=lark` hoặc `tab=monitoring`
- **THEN** tab Thông báo được đánh dấu đang chọn và nội dung tab Thông báo được hiển thị

#### Scenario: Notifications tab order
- **WHEN** Administrator mở trang plugin
- **THEN** tab bar hiện 5 tab, tab Thông báo nằm ngay trước tab Nâng cao và không có tab Cảnh báo Lark hay Giám sát lỗi riêng

#### Scenario: Monitoring tab order
- **WHEN** Administrator mở trang plugin
- **THEN** không còn tab Giám sát lỗi riêng; mục này bị bỏ có chủ đích, thay bằng "Notifications tab order"

#### Scenario: Unknown tab
- **WHEN** Administrator mở trang với `tab=foo`
- **THEN** trang hiển thị tab Hóa đơn như mặc định

#### Scenario: Missing encryption key on any tab
- **WHEN** chưa cấu hình `EINVOICE_MATBAO_ENCRYPTION_KEY` và Administrator mở tab Hóa đơn
- **THEN** cảnh báo thiếu khóa mã hóa vẫn hiển thị phía trên tab bar

### Requirement: Settings grouped by tab
Mỗi tab cấu hình SHALL chứa đúng các form của nhóm mình, giữ nguyên route và hành vi của các form đã có: `connection` chứa form credential Mắt Bão và chọn mẫu hóa đơn mặc định; `invoicing` chứa form mã tham chiếu/người mua vãng lai và form đơn vị tính; `notifications` chứa form cảnh báo Lark (xem failure-alerts) và form giám sát lỗi (xem error-monitoring), mỗi form là một thẻ riêng với vùng kết quả riêng; `advanced` chứa công tắc xóa dữ liệu khi gỡ plugin.
Evidence: src/Admin/SettingsPage.php:138-147,162-330, src/Uninstall/UninstallSettingsController.php:74-102, tests/integration/SettingsPageTest.php:158-205, tests/e2e/army/tabs.army.ts:35-40

#### Scenario: Invoicing tab contents
- **WHEN** Administrator mở tab Xuất hóa đơn
- **THEN** trang có form prefix `MTChieu`/người mua vãng lai và form đơn vị tính, không có form credential Mắt Bão, webhook Lark hay DSN

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

### Requirement: Tab switching without page reload
Hệ thống SHALL chuyển tab bằng htmx qua route `GET einvoice-matbao/v1/tabs/<tab>` trả HTML fragment gồm tab bar và nội dung tab, SHALL cập nhật URL trình duyệt thành `admin.php?page=einvoice-matbao-settings&tab=<tab>`, và mỗi tab SHALL là một liên kết có `href` tới chính URL đó. Route SHALL chấp nhận các alias `lark` và `monitoring` và trả tab `notifications`, SHALL dùng cùng kiểm soát truy cập với các route plugin khác và SHALL trả notice lỗi HTML 404 cho mọi key tab không tồn tại, kể cả key có chữ hoa hoặc chữ số.
Evidence: src/Admin/SettingsPage.php:108,122-136,376-381, src/Admin/SettingsController.php:50,134-142, tests/integration/SettingsTabsRestTest.php:52-101, tests/integration/SettingsPageTest.php:70-75,126-148, tests/e2e/army/tabs.army.ts:24-57

#### Scenario: Switch tab
- **WHEN** Administrator đang ở tab Hóa đơn và bấm tab Thông báo
- **THEN** nội dung tab Thông báo thay thế tab Hóa đơn không tải lại trang và URL chứa `tab=notifications`

#### Scenario: Legacy tab route
- **WHEN** Administrator gọi `GET einvoice-matbao/v1/tabs/lark` hoặc `tabs/monitoring` với nonce hợp lệ
- **THEN** phản hồi là fragment của tab Thông báo với tab Thông báo được đánh dấu đang chọn

#### Scenario: Reload keeps the tab
- **WHEN** Administrator tải lại trang sau khi chuyển sang tab Xuất hóa đơn
- **THEN** trang mở lại ở tab Xuất hóa đơn

#### Scenario: Back button
- **WHEN** Administrator chuyển từ tab Hóa đơn sang tab Kết nối rồi bấm Back của trình duyệt
- **THEN** trang hiển thị lại tab Hóa đơn và URL không còn `tab=connection`

#### Scenario: Unknown tab route
- **WHEN** Administrator gọi `GET einvoice-matbao/v1/tabs/foo` với nonce hợp lệ
- **THEN** phản hồi 404 là notice `data-einv-status="error"`

#### Scenario: Malformed tab key
- **WHEN** Administrator gọi `GET einvoice-matbao/v1/tabs/Lark1` với nonce hợp lệ
- **THEN** phản hồi 404 là notice `data-einv-status="error"`, không phải JSON

#### Scenario: Tab route without capability
- **WHEN** shop manager gọi `GET einvoice-matbao/v1/tabs/connection`
- **THEN** phản hồi 403 và không có nội dung form nào được trả về
