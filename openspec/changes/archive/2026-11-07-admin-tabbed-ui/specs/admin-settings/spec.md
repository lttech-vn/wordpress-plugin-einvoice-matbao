## MODIFIED Requirements

### Requirement: Settings page access
Hệ thống SHALL cung cấp một trang duy nhất "Hóa đơn Mắt Bão" (slug `einvoice-matbao-settings`) dưới menu WooCommerce chỉ cho người dùng có quyền `manage_options`, và SHALL không hiển thị nội dung trang cho người không đủ quyền. Trang SHALL là mục menu duy nhất của plugin; slug cũ `einvoice-matbao-invoices` SHALL không còn hiện trong menu và SHALL chuyển hướng Administrator tới tab Hóa đơn của trang này.
Evidence: src/Admin/SettingsPage.php:41-50,72-75, tests/integration/SettingsPageTest.php:28-56

#### Scenario: Administrator sees the menu
- **WHEN** Administrator mở trang quản trị WordPress
- **THEN** menu WooCommerce có mục `einvoice-matbao-settings` yêu cầu quyền `manage_options` và không có mục `einvoice-matbao-invoices`

#### Scenario: Shop manager denied
- **WHEN** shop manager mở trang settings
- **THEN** không có nội dung nào được hiển thị

#### Scenario: Old invoice list URL
- **WHEN** Administrator mở `admin.php?page=einvoice-matbao-invoices`
- **THEN** phản hồi HTTP 302 chuyển hướng tới `admin.php?page=einvoice-matbao-settings&tab=invoices` và trình duyệt hiển thị tab Hóa đơn

#### Scenario: Old invoice list URL without capability
- **WHEN** shop manager mở `admin.php?page=einvoice-matbao-invoices`
- **THEN** không có chuyển hướng tới trang plugin và WordPress hiển thị màn hình từ chối quyền, không có nội dung nào của plugin

### Requirement: Admin assets scoped to plugin pages
Hệ thống SHALL chỉ nạp CSS và script admin của plugin trên trang plugin `einvoice-matbao-settings`, gắn nonce REST vào mọi request htmx, và cho hiển thị fragment lỗi 4xx; giao diện nằm trong vùng `#einv-app` để CSS không ảnh hưởng phần còn lại của wp-admin.
Evidence: src/Admin/SettingsPage.php:58-67,94,292-297, docs/adr/0003-htmx-tailwind-admin-over-rest.md, tests/integration/SettingsPageTest.php:39-74

#### Scenario: Other admin page
- **WHEN** Administrator mở dashboard WordPress
- **THEN** script và style của plugin không được nạp

#### Scenario: Settings page
- **WHEN** Administrator mở trang settings của plugin
- **THEN** script và style được nạp kèm cấu hình gửi `X-WP-Nonce` cho mọi request htmx

## ADDED Requirements

### Requirement: Tabbed plugin screen
Trang plugin SHALL chia nội dung thành 5 tab theo thứ tự Hóa đơn (`invoices`), Kết nối Mắt Bão (`connection`), Xuất hóa đơn (`invoicing`), Cảnh báo Lark (`lark`), Nâng cao (`advanced`), SHALL chỉ render nội dung của tab đang chọn và đánh dấu tab đó `aria-current="page"`. Tab `invoices` SHALL là mặc định khi tham số `tab` thiếu hoặc không hợp lệ. Cảnh báo thiếu khóa mã hóa SHALL hiển thị ở mọi tab.

#### Scenario: Default tab
- **WHEN** Administrator mở `admin.php?page=einvoice-matbao-settings` không kèm `tab`
- **THEN** tab Hóa đơn được đánh dấu đang chọn, danh sách hóa đơn được tải và form credential Mắt Bão không có trong trang

#### Scenario: Deep link to a tab
- **WHEN** Administrator mở `admin.php?page=einvoice-matbao-settings&tab=lark`
- **THEN** tab Cảnh báo Lark được đánh dấu đang chọn và form webhook Lark được hiển thị

#### Scenario: Unknown tab
- **WHEN** Administrator mở trang với `tab=foo`
- **THEN** trang hiển thị tab Hóa đơn như mặc định

#### Scenario: Missing encryption key on any tab
- **WHEN** chưa cấu hình `EINVOICE_MATBAO_ENCRYPTION_KEY` và Administrator mở tab Hóa đơn
- **THEN** cảnh báo thiếu khóa mã hóa vẫn hiển thị phía trên tab bar

### Requirement: Settings grouped by tab
Mỗi tab cấu hình SHALL chứa đúng các form hiện có của nhóm mình, giữ nguyên route và hành vi: `connection` chứa form credential Mắt Bão và chọn mẫu hóa đơn mặc định; `invoicing` chứa form mã tham chiếu/người mua vãng lai và form đơn vị tính; `lark` chứa form cảnh báo Lark; `advanced` chứa công tắc xóa dữ liệu khi gỡ plugin.

#### Scenario: Invoicing tab contents
- **WHEN** Administrator mở tab Xuất hóa đơn
- **THEN** trang có form prefix `MTChieu`/người mua vãng lai và form đơn vị tính, không có form credential Mắt Bão hay webhook Lark

#### Scenario: Advanced tab contents
- **WHEN** Administrator mở tab Nâng cao
- **THEN** trang có công tắc xóa dữ liệu khi gỡ plugin

#### Scenario: Template list stays with credentials
- **WHEN** Administrator lưu credential Mắt Bão thành công trong tab Kết nối
- **THEN** danh sách mẫu hóa đơn trong cùng tab được tải lại mà không cần chuyển tab

### Requirement: Tab switching without page reload
Hệ thống SHALL chuyển tab bằng htmx qua route `GET einvoice-matbao/v1/tabs/<tab>` trả HTML fragment gồm tab bar và nội dung tab, SHALL cập nhật URL trình duyệt thành `admin.php?page=einvoice-matbao-settings&tab=<tab>`, và mỗi tab SHALL là một liên kết có `href` tới chính URL đó. Route SHALL dùng cùng kiểm soát truy cập với các route plugin khác và SHALL trả notice lỗi HTML 404 cho mọi key tab không tồn tại, kể cả key có chữ hoa hoặc chữ số.

#### Scenario: Switch tab
- **WHEN** Administrator đang ở tab Hóa đơn và bấm tab Cảnh báo Lark
- **THEN** nội dung tab Lark thay thế tab Hóa đơn không tải lại trang và URL chứa `tab=lark`

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
