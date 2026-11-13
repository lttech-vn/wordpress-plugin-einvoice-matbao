## Why

Trang cấu hình hiện nằm sâu trong menu WooCommerce, khó thấy với người vận hành chỉ cần xuất hóa đơn. Tab Lark và Giám sát lỗi tách riêng dù cùng là kênh thông báo. Lark chưa có công tắc bật/tắt: muốn ngừng nhận cảnh báo thì phải xóa webhook, mất cấu hình đã nhập.

## What Changes

**Vị trí menu**
- From: submenu "Hóa đơn Mắt Bão" dưới WooCommerce.
- To: mục menu cấp cao nhất cùng tên, vẫn là một trang với các tab bên trong, giữ slug `einvoice-matbao-settings`.
- Reason: dễ thấy, không phụ thuộc menu WooCommerce.
- Impact: URL `admin.php?page=einvoice-matbao-settings` vẫn dùng được; điều kiện nạp asset admin đổi theo hook mới.

**Tab Thông báo**
- From: 6 tab, có `lark` và `monitoring` riêng.
- To: 5 tab, tab `notifications` chứa hai thẻ Lark và Sentry; `tab=lark` và `tab=monitoring` cũ là alias mở tab này.
- Reason: gom các kênh thông báo vào một nơi.
- Impact: không breaking; scenario "Monitoring tab order" và "Monitoring tab contents" bị bỏ có chủ đích, thay bằng "Notifications tab order" và "Notifications tab contents"; route REST `settings/lark` và `settings/monitoring` giữ nguyên.

**Công tắc Lark**
- From: Lark gửi khi đã có webhook.
- To: checkbox "Bật cảnh báo Lark"; tắt thì không gửi, giữ webhook, alert ghi trạng thái mới `disabled`; nút gửi thử vẫn dùng được. Site đã lưu webhook trước khi nâng cấp coi là bật; site mới mặc định tắt.
- Reason: tạm ngừng cảnh báo mà không mất cấu hình.
- Impact: thêm một option mới; admin notice vẫn hiện cho đơn lỗi.

## Capabilities

### New Capabilities

Không có.

### Modified Capabilities

- `admin-settings`: menu cấp cao nhất thay cho submenu WooCommerce; 5 tab thay vì 6; tab `notifications` và alias `lark`/`monitoring`; điều kiện nạp asset theo trang mới; scenario chuyển tab Lark.
- `failure-alerts`: thêm công tắc Lark và trạng thái alert `disabled`, mặc định theo việc đã có webhook hay chưa.
- `error-monitoring`: form giám sát lỗi nằm trong tab `notifications`, không còn tab `monitoring` riêng.

## Impact

- Code: `src/Admin/SettingsPage.php`, `src/Admin/AdminTabs.php`, `src/Admin/SettingsController.php`, `src/Settings.php`, `src/Alert/InvoiceAlerts.php`, `src/Alert/AdminAlertNotice.php`.
- Tests: `AdminTabsTest`, `SettingsPageTest`, `SettingsTabsRestTest`, `InvoiceAlertTest`, e2e `tests/e2e/army/tabs.army.ts`.
- Không thêm dependency, không đổi DB schema; uninstall đã xóa mọi option của plugin nên `plugin-lifecycle` không đổi.
