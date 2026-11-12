## 1. Tab registry và alias

- [x] 1.1 `AdminTabs`: còn 5 tab (`invoices`, `connection`, `invoicing`, `notifications`, `advanced`); thêm `ALIASES` (`lark`, `monitoring` → `notifications`) và chuẩn hóa alias trong `resolve()` (khớp chính xác, phân biệt hoa thường)
- [x] 1.2 `SettingsController::show_tab()`: chuẩn hóa alias trước khi kiểm tra, key lạ vẫn trả 404 HTML
- [x] 1.3 Cập nhật `AdminTabsTest`, `SettingsTabsRestTest` cho 5 tab, alias và 404

## 2. Menu cấp cao nhất

- [x] 2.1 `SettingsPage::register_menu()` dùng `add_menu_page` (slug `einvoice-matbao-settings`, `manage_options`, vị trí 56, icon `dashicons-media-text`) và `enqueue_assets` so khớp hook `toplevel_page_einvoice-matbao-settings`
- [x] 2.2 Cập nhật `SettingsPageTest` (menu, quyền, asset) và kiểm tra redirect `InvoicePage` vẫn đúng

## 3. Công tắc Lark

- [x] 3.1 `Settings::lark_enabled( bool $webhook_saved )` và `save_lark_enabled( bool )`: khóa `lark_enabled` trong option hiện có, `'1'`/`'0'`, chưa lưu thì theo `$webhook_saved`; test đơn vị cho site cũ, site mới, đã lưu
- [x] 3.2 `SettingsController::save_lark()`: đọc checkbox `enabled`, từ chối bật khi chưa có webhook, tắt giữ nguyên webhook và secret; `test_lark()` không kiểm tra công tắc; test REST
- [x] 3.3 `InvoiceAlerts::deliver()`: công tắc tắt thì ghi `disabled`, không gọi Lark, không retry; không gửi bù; test cho tắt có và không có webhook
- [x] 3.4 `AdminAlertNotice::lark_note()`: thêm ghi chú "(Đã tắt cảnh báo Lark nên chưa gửi tin.)" cho `disabled`; test notice

## 4. Giao diện tab Thông báo

- [x] 4.1 `SettingsPage::render_tabs()`: tab `notifications` ghép panel Lark và panel Sentry, mỗi thẻ có `.einv-result` riêng; xóa case `lark` và `monitoring`
- [x] 4.2 Thêm checkbox "Bật cảnh báo Lark" vào `render_lark_panel()`
- [x] 4.3 Cập nhật `SettingsPageTest`, `SettingsMonitoringRestTest` theo tab mới và bỏ domain nội bộ trong test (thay `sentry.example.com`)

## 5. Tài liệu và kiểm chứng

- [ ] 5.1 Cập nhật e2e: `tests/e2e/army/tabs.army.ts`, `settings.army.ts`, `monitoring.army.ts`, `support.ts` (URL `tab=lark`/`tab=monitoring`) và mô tả giao diện trong `tests/e2e/e2e.army.config.ts`
- [ ] 5.2 Cập nhật `README.md`, `docs/phat-trien.md` và `docs/huong-dan-su-dung.md` (menu không còn dưới WooCommerce, tab Thông báo, công tắc Lark)
- [ ] 5.3 Chạy phpcs, phpstan và bộ test tích hợp; ghi kết quả
