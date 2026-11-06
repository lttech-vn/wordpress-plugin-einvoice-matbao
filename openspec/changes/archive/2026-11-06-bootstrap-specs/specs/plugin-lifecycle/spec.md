## ADDED Requirements

### Requirement: Activation requires WooCommerce
Hệ thống SHALL khai báo WooCommerce là plugin bắt buộc để WordPress từ chối kích hoạt plugin khi WooCommerce chưa hoạt động.
Evidence: einvoice-matbao.php:14, tests/without-woocommerce/MissingWooCommerceTest.php:26-33

#### Scenario: Activation without WooCommerce
- **WHEN** quản trị viên kích hoạt plugin khi WooCommerce không hoạt động
- **THEN** kích hoạt trả về lỗi có thông báo và plugin không ở trạng thái active

### Requirement: Plugin disables itself when WooCommerce disappears
Hệ thống SHALL tự vô hiệu hóa và hiển thị notice lỗi tiếng Việt nêu cần WooCommerce khi plugin đang active mà WooCommerce không được tải, chỉ với người dùng có quyền `activate_plugins`.
Evidence: src/Plugin.php:33-36, src/WooCommerceDependency.php:18-62, tests/without-woocommerce/MissingWooCommerceTest.php:34-70

#### Scenario: Administrator visits wp-admin
- **WHEN** plugin active, WooCommerce không được tải và quản trị viên mở wp-admin
- **THEN** plugin bị vô hiệu hóa và notice lỗi chứa "Hóa đơn điện tử Mắt Bão" và "WooCommerce"

#### Scenario: Unprivileged request
- **WHEN** request ẩn danh hoặc của subscriber vào wp-admin
- **THEN** plugin vẫn active và không có notice

### Requirement: Business features run only with WooCommerce
Hệ thống SHALL chỉ bật các tính năng hóa đơn, REST route và màn hình admin khi WooCommerce đã được tải.
Evidence: src/Plugin.php:33-61, tests/without-woocommerce/MissingWooCommerceTest.php:21-70

#### Scenario: Missing WooCommerce
- **WHEN** WordPress tải plugin mà WooCommerce không có
- **THEN** chỉ có xử lý tự vô hiệu hóa và notice, không tính năng hóa đơn nào được bật

### Requirement: Plugin stays inert without dependencies
Hệ thống SHALL không làm gì và không gây lỗi fatal khi thư viện Composer của plugin chưa được cài (bản source chưa chạy `composer install`).
Evidence: einvoice-matbao.php:23-26
Confidence: inferred

#### Scenario: No vendor directory
- **WHEN** file plugin chính được nạp mà không có `vendor/autoload.php`
- **THEN** plugin không đăng ký hook nào và site không bị lỗi fatal

### Requirement: Activation succeeds and installs storage
Hệ thống SHALL kích hoạt thành công khi WooCommerce đang hoạt động và cài đặt bảng dữ liệu hóa đơn ngay khi kích hoạt (xem invoice-storage-schema).
Evidence: src/Plugin.php:25, tests/integration/PluginActivationTest.php:20-28

#### Scenario: Activation with WooCommerce
- **WHEN** quản trị viên kích hoạt plugin khi WooCommerce đang hoạt động
- **THEN** kích hoạt thành công, không có lỗi

### Requirement: Plugin declares HPOS and Blocks compatibility
Hệ thống SHALL khai báo với WooCommerce rằng plugin tương thích với HPOS (`custom_order_tables`) và checkout Blocks (`cart_checkout_blocks`).
Evidence: src/Plugin.php:21,78-86, tests/integration/PluginActivationTest.php:29-44, tests/without-woocommerce/MissingWooCommerceTest.php:71

#### Scenario: WooCommerce present
- **WHEN** WooCommerce đọc danh sách tương thích của plugin
- **THEN** plugin tương thích với `custom_order_tables` và `cart_checkout_blocks`, không bị đánh dấu không tương thích

#### Scenario: WooCommerce absent
- **WHEN** WooCommerce không có
- **THEN** việc khai báo tương thích không làm gì và không gây lỗi

### Requirement: Deactivation cancels plugin actions and keeps data
Hệ thống SHALL hủy mọi tác vụ nền của plugin khi plugin bị vô hiệu hóa, không đụng tác vụ của plugin khác, và giữ nguyên dữ liệu.
Evidence: src/Plugin.php:68-70, src/Uninstall/Uninstaller.php:73-94, tests/integration/UninstallTest.php:191, README.md:258-260

#### Scenario: Deactivate with queued actions
- **WHEN** quản trị viên vô hiệu hóa plugin khi có tác vụ của plugin và của một plugin khác đang chờ
- **THEN** tác vụ của plugin bị hủy và tác vụ của plugin khác vẫn `pending`

### Requirement: Default uninstall removes secrets and caches
Hệ thống SHALL khi WordPress xóa plugin mà công tắc xóa dữ liệu đang tắt (xem admin-settings) xóa credential đã lưu, token và cache của plugin, alert, và hủy tác vụ nền của plugin.
Evidence: src/Uninstall/Uninstaller.php:119-129, README.md:266-277, tests/integration/UninstallTest.php:78-103,130-169

#### Scenario: Default uninstall
- **WHEN** plugin bị xóa với công tắc tắt
- **THEN** credential, token, cache, alert và tác vụ của plugin bị xóa

#### Scenario: Persistent object cache
- **WHEN** token và cache mẫu chỉ nằm trong object cache ngoài
- **THEN** quá trình gỡ cài đặt vẫn xóa chúng

#### Scenario: Lookalike options
- **WHEN** có option `unrelated_option`, `einvoice_other_x` hoặc `einvoice_matbaoX`
- **THEN** các option này không bị xóa

### Requirement: Default uninstall keeps invoice data
Hệ thống SHALL giữ bảng hóa đơn, order meta của người mua, đơn vị tính của sản phẩm, settings và phiên bản schema khi gỡ cài đặt với công tắc xóa dữ liệu đang tắt.
Evidence: src/Uninstall/Uninstaller.php:119-129, README.md:266-277, tests/integration/UninstallTest.php:78-103

#### Scenario: Data kept
- **WHEN** plugin bị xóa với công tắc tắt
- **THEN** không bảng nào bị drop, và bản ghi hóa đơn, order meta, đơn vị tính sản phẩm cùng settings vẫn còn

### Requirement: Delete-all uninstall removes all plugin data
Hệ thống SHALL khi công tắc xóa dữ liệu bật xóa toàn bộ dữ liệu của plugin lúc gỡ cài đặt: hai bảng hóa đơn, order meta người mua (cả HPOS lẫn lưu trữ cũ), đơn vị tính của sản phẩm và mọi option của plugin, nhưng không xóa đơn hàng hay sản phẩm.
Evidence: src/Uninstall/Uninstaller.php:131-189, README.md:266-277, tests/integration/UninstallTest.php:104-129, tests/without-woocommerce/UninstallWithoutWooCommerceTest.php:53

#### Scenario: Switch on
- **WHEN** plugin bị xóa với công tắc bật
- **THEN** hai bảng bị drop, không còn option hay order/product meta của plugin, và đơn hàng vẫn tồn tại

### Requirement: Uninstall works without WooCommerce
Hệ thống SHALL thực hiện gỡ cài đặt đầy đủ kể cả khi WooCommerce hoặc thư viện Composer không còn, hủy trực tiếp tác vụ của plugin trong bảng Action Scheduler, và chạy cho từng site trên multisite.
Evidence: uninstall.php:11-16, src/Uninstall/Uninstaller.php:57-94, tests/without-woocommerce/UninstallWithoutWooCommerceTest.php:38-60, tests/integration/UninstallTest.php:170-180

#### Scenario: WooCommerce removed
- **WHEN** WordPress xóa plugin khi WooCommerce không còn
- **THEN** credential và cache bị xóa, tác vụ của plugin có trạng thái `canceled`, tác vụ của nhóm khác vẫn `pending`, và bảng hóa đơn còn nguyên khi công tắc tắt

### Requirement: Uninstall script runs only from WordPress
Hệ thống SHALL chỉ chạy gỡ cài đặt khi được WordPress gọi trong quá trình xóa plugin.
Evidence: uninstall.php:11-16, tests/integration/UninstallTest.php:170-190

#### Scenario: Direct request
- **WHEN** file gỡ cài đặt được gọi trực tiếp, không qua WordPress
- **THEN** không có output và không có dữ liệu nào bị xóa
