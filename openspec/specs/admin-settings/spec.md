# admin-settings Specification

## Purpose

Trang cấu hình admin (credential, kết nối, invoicing, đơn vị tính, failure modes, uninstall).

## Requirements

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

### Requirement: Authenticated admin routes
Hệ thống SHALL yêu cầu mọi route REST `einvoice-matbao/v1` phải đăng nhập, có nonce `wp_rest` trong `X-WP-Nonce` và quyền `manage_options`; request bị từ chối không gọi API ngoài và không đổi dữ liệu.
Evidence: src/Admin/SettingsController.php:74-89, tests/integration/SettingsRestAccessTest.php:58-114, tests/integration/InvoiceAdminListRestTest.php:59-91

#### Scenario: Anonymous
- **WHEN** request chưa đăng nhập gọi một route của plugin
- **THEN** phản hồi 401 và không có HTTP request ra ngoài

#### Scenario: Wrong nonce
- **WHEN** Administrator gửi nonce của một action khác hoặc thiếu nonce
- **THEN** phản hồi 403 và option settings không được tạo

#### Scenario: Insufficient capability
- **WHEN** shop manager hoặc editor gọi route
- **THEN** phản hồi 403

### Requirement: HTML fragment responses
Hệ thống SHALL trả mọi phản hồi của route plugin dưới dạng HTML fragment có notice `data-einv-status` ("success" hoặc "error"), kể cả lỗi quyền và validate, với mọi giá trị động được escape; route REST ngoài plugin vẫn trả JSON.
Evidence: src/Admin/Fragment.php:61-80, docs/adr/0003-htmx-tailwind-admin-over-rest.md, tests/integration/SettingsRestAccessTest.php:116-156, tests/integration/SettingsFailureModesRestTest.php:71-104

#### Scenario: Permission error as notice
- **WHEN** route plugin từ chối quyền
- **THEN** body là notice `data-einv-status="error"` và không phải JSON

#### Scenario: Escaped error message
- **WHEN** Mắt Bão trả message chứa `<script>`
- **THEN** HTML chứa `&lt;script&gt;` và không chứa `<script>`

### Requirement: Admin assets scoped to plugin pages
Hệ thống SHALL chỉ nạp CSS và script admin của plugin trên trang plugin `einvoice-matbao-settings`, gắn nonce REST vào mọi request htmx, và cho hiển thị fragment lỗi 4xx; giao diện nằm trong vùng `#einv-app` để CSS không ảnh hưởng phần còn lại của wp-admin.
Evidence: src/Admin/SettingsPage.php:68-77,376-381, docs/adr/0003-htmx-tailwind-admin-over-rest.md, tests/integration/SettingsPageTest.php:213-234

#### Scenario: Other admin page
- **WHEN** Administrator mở dashboard WordPress
- **THEN** script và style của plugin không được nạp

#### Scenario: Settings page
- **WHEN** Administrator mở trang settings của plugin
- **THEN** script và style được nạp kèm cấu hình gửi `X-WP-Nonce` cho mọi request htmx

### Requirement: Mat Bao credential form
Hệ thống SHALL cho admin lưu môi trường (`demo` hoặc `production`), mã số thuế, tên đăng nhập và mật khẩu Mắt Bão (xem credential-encryption về mã hóa), giữ mật khẩu cũ khi ô mật khẩu để trống, và từ chối dữ liệu thiếu hoặc sai mà không ghi gì.
Evidence: src/Admin/SettingsController.php:131-168, src/Admin/SettingsPage.php:96-139, tests/integration/SettingsCredentialsRestTest.php:23-157, tests/e2e/army/settings.army.ts:24-58

#### Scenario: Saved
- **WHEN** admin gửi thông tin hợp lệ khi đã có khóa mã hóa
- **THEN** phản hồi notice success với header `HX-Trigger: einv-matbao-saved` và trang chỉ hiện trạng thái "Đã lưu (mã hóa)"

#### Scenario: Blank password keeps secret
- **WHEN** admin đổi tên đăng nhập và để trống mật khẩu khi đã có credential
- **THEN** tên đăng nhập mới được lưu và mật khẩu cũ được giữ

#### Scenario: Missing fields
- **WHEN** lần lưu đầu thiếu mã số thuế, tên đăng nhập hoặc mật khẩu, hoặc môi trường không hợp lệ
- **THEN** phản hồi 422 và không có credential nào được lưu

#### Scenario: Field limits
- **WHEN** mã số thuế vượt 20 ký tự, hoặc tên đăng nhập hay mật khẩu vượt 50 ký tự
- **THEN** phản hồi 422 và giá trị cũ giữ nguyên

### Requirement: Connection test
Hệ thống SHALL cho admin kiểm tra kết nối bằng cách đăng nhập Mắt Bão mới với credential đã lưu ở môi trường đã chọn, báo thành công hoặc lý do lỗi bằng tiếng Việt mà không lộ mật khẩu.
Evidence: src/Admin/SettingsController.php:173-186,396-427, tests/integration/SettingsConnectionRestTest.php:24-96, tests/integration/SettingsFailureModesRestTest.php:24-58, tests/e2e/army/settings.army.ts:60-74

#### Scenario: Success
- **WHEN** admin bấm "Kiểm tra kết nối" và Mắt Bão chấp nhận đăng nhập
- **THEN** hiện "Kết nối Mắt Bão thành công." và có một request đăng nhập tới host của môi trường đã chọn

#### Scenario: Login rejected
- **WHEN** Mắt Bão trả errorCode 401
- **THEN** hiện "Mắt Bão từ chối thông tin đăng nhập." kèm mã 401, không chứa mật khẩu

#### Scenario: Server unreachable
- **WHEN** request không tới được Mắt Bão
- **THEN** hiện "Không kết nối được tới Mắt Bão."

#### Scenario: Server error
- **WHEN** Mắt Bão trả HTTP 503
- **THEN** notice lỗi chứa "503"

#### Scenario: No credential yet
- **WHEN** chưa có credential đã lưu
- **THEN** phản hồi 422 và không có request ra ngoài

### Requirement: Default VAT template selection
Hệ thống SHALL liệt kê các mẫu hóa đơn GTGT của năm hiện tại (theo giờ Việt Nam) từ Mắt Bão, vô hiệu mẫu hết số, và chỉ lưu mẫu mặc định sau khi xác minh lại với Mắt Bão rằng mẫu vẫn còn và còn số.
Evidence: src/Admin/SettingsController.php:191-238,376-391, src/Admin/SettingsPage.php:238-272, tests/integration/SettingsInvoicingRestTest.php:33-59,112-157, tests/e2e/army/settings.army.ts:76-94

#### Scenario: List templates
- **WHEN** admin tải danh sách mẫu
- **THEN** chỉ mẫu GTGT xuất hiện và request dùng năm hiện tại theo Asia/Ho_Chi_Minh

#### Scenario: Save valid template
- **WHEN** admin chọn một mẫu còn số
- **THEN** hiện "Đã chọn mẫu mặc định 1 / C26TKK." và mẫu đó trở thành mẫu mặc định

#### Scenario: Template out of numbers
- **WHEN** lúc lưu Mắt Bão báo mẫu không còn hoặc đã hết số
- **THEN** phản hồi 422 và mẫu mặc định không đổi

### Requirement: Invoicing options
Hệ thống SHALL cho admin lưu prefix `MTChieu` (chỉ chữ và số ASCII, tối đa 8 ký tự để `MTChieu` không quá 20 ký tự) và tên người mua vãng lai (tối đa 400 ký tự, để trống thì dùng "Khách hàng không lấy hóa đơn"), từ chối giá trị không hợp lệ và giữ giá trị cũ.
Evidence: src/Admin/SettingsController.php:245-273, src/Settings.php:23-35,48-50,62-66, tests/integration/SettingsInvoicingRestTest.php:25-31,160-231, tests/e2e/army/settings.army.ts:96-111

#### Scenario: Valid save
- **WHEN** admin lưu prefix `SHOP` và tên "Khách mua lẻ E2E"
- **THEN** hiện "Đã lưu. Ví dụ MTChieu: SHOPWC1234. Tên người mua vãng lai: Khách mua lẻ E2E."

#### Scenario: Invalid prefix
- **WHEN** prefix chứa ký tự có dấu, ký tự đặc biệt hoặc dài hơn 8 ký tự
- **THEN** phản hồi 422 và prefix đã lưu không đổi

#### Scenario: Blank walk-in name
- **WHEN** tên người mua vãng lai để trống
- **THEN** hóa đơn vãng lai dùng tên "Khách hàng không lấy hóa đơn"

#### Scenario: Walk-in name too long
- **WHEN** tên dài hơn 400 ký tự
- **THEN** phản hồi 422 và giá trị cũ giữ nguyên

### Requirement: Default unit of measure
Hệ thống SHALL cho admin lưu đơn vị tính mặc định của cửa hàng (bỏ markup, cắt khoảng trắng, tối đa 50 ký tự), dùng "Gói" khi chưa đặt hoặc để trống, và từ chối giá trị quá dài.
Evidence: src/Admin/SettingsController.php:280-303, src/Settings.php:37-41,71-75, tests/integration/SettingsUnitRestTest.php:24-79, tests/e2e/army/settings.army.ts:113-117

#### Scenario: Not configured
- **WHEN** chưa lưu đơn vị mặc định
- **THEN** đơn vị mặc định là "Gói"

#### Scenario: Length limit
- **WHEN** admin lưu đúng 50 ký tự `á`, rồi 51 ký tự
- **THEN** lần đầu thành công; lần sau phản hồi 422 và giá trị 50 ký tự được giữ

#### Scenario: Markup stripped
- **WHEN** giá trị chứa `<script>` và dấu nháy
- **THEN** markup bị bỏ và trang hiển thị giá trị đã escape

### Requirement: Delete data on uninstall switch
Hệ thống SHALL cung cấp công tắc "xóa toàn bộ dữ liệu khi xóa plugin" mặc định tắt; bật công tắc yêu cầu gõ đúng `XOA HET DU LIEU`, còn tắt không cần xác nhận (xem plugin-lifecycle về tác động khi gỡ cài đặt).
Evidence: src/Uninstall/UninstallSettingsController.php:48-102, src/Settings.php:99-110, tests/integration/SettingsUninstallRestTest.php:27-117

#### Scenario: Enable without confirmation
- **WHEN** admin bật công tắc mà không gõ hoặc gõ sai xác nhận (ví dụ "xoa")
- **THEN** phản hồi 422 và công tắc vẫn tắt

#### Scenario: Enable with confirmation
- **WHEN** admin bật công tắc và gõ `XOA HET DU LIEU`
- **THEN** notice success cảnh báo xóa vĩnh viễn, công tắc bật và checkbox trên trang ở trạng thái checked

#### Scenario: Disable
- **WHEN** công tắc đang bật và admin tắt
- **THEN** phản hồi 200, công tắc tắt và notice nói dữ liệu hóa đơn được giữ

### Requirement: Retry login once from settings
Hệ thống SHALL báo lỗi cho admin bằng notice khi một thao tác settings vẫn nhận 401 sau một lần đăng nhập lại (xem matbao-api-client).
Evidence: src/Admin/SettingsController.php:396-427, tests/integration/SettingsFailureModesRestTest.php:57-66

#### Scenario: Repeated 401
- **WHEN** lấy danh sách mẫu trả 401 cả hai lần
- **THEN** notice lỗi được hiển thị sau đúng 2 lần đăng nhập

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
