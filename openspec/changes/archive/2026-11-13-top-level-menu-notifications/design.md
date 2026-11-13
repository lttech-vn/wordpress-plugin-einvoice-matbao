## Context

- `SettingsPage::register_menu()` gọi `add_submenu_page( 'woocommerce', … )`; asset chỉ nạp khi hook suffix là `woocommerce_page_einvoice-matbao-settings`.
- `AdminTabs::labels()` có 6 tab, `resolve()` chấp nhận đúng key (phân biệt hoa thường), `SettingsController::show_tab()` trả 404 cho key lạ.
- Lark: `InvoiceAlerts::deliver()` gửi khi `CredentialStore::lark()` khác null, ngược lại ghi `not_configured`. `AdminAlertNotice::lark_note()` map trạng thái sang ghi chú.
- Sentry đã có công tắc `monitoring_enabled` trong option `einvoice_matbao_settings` (xem `Settings`).
- Uninstall xóa mọi option của plugin theo prefix, nên option mới không cần code dọn riêng.

## Goals / Non-Goals

**Goals:**
- Menu cấp cao nhất, một trang, 5 tab; tab `notifications` gộp Lark và Sentry.
- Công tắc Lark lưu được, không mất webhook khi tắt, nâng cấp không làm site đang nhận cảnh báo bị im lặng.

**Non-Goals:**
- Không đổi route REST `settings/lark`, `settings/monitoring` và các route test.
- Không thêm submenu theo tab, không đổi logic gửi hay retry Lark, không đổi Sentry.
- Không có bước migration ghi DB.

## Decisions

**Menu.** Dùng `add_menu_page` cùng slug `einvoice-matbao-settings`, capability `manage_options`, vị trí 56 (ngay dưới WooCommerce) và dashicon `dashicons-media-text`. Hook suffix mới là `toplevel_page_einvoice-matbao-settings`; `enqueue_assets` so sánh với hằng này. Slug giữ nguyên nên URL cũ và redirect của `InvoicePage` không đổi. Vì sao không giữ submenu: yêu cầu của người dùng; vì sao không submenu theo tab: hai nơi chọn tab dễ lệch nhau (xem brainstorm).

**Tab và alias.** `AdminTabs::labels()` còn 5 key: `invoices`, `connection`, `invoicing`, `notifications`, `advanced`. Thêm `AdminTabs::ALIASES = array( 'lark' => 'notifications', 'monitoring' => 'notifications' )` và `AdminTabs::resolve()` map alias trước khi kiểm tra; việc map vẫn khớp chính xác, phân biệt hoa thường. `show_tab()` dùng cùng hàm chuẩn hóa để `tabs/lark` trả tab `notifications` thay vì 404, còn key hoàn toàn lạ vẫn 404. `render_tabs()` nhận key đã chuẩn hóa, nên tab bar đánh dấu đúng tab.

**Panel Thông báo.** `render_tabs()` ghép `render_lark_panel()` rồi `render_monitoring_panel()` trong tab `notifications`. Hai hàm và hai form giữ nguyên route; hai thẻ vẫn có `.einv-result` riêng nên htmx không đụng nhau.

**Công tắc Lark.** `Settings::lark_enabled( bool $webhook_saved ): bool` đọc khóa `lark_enabled` trong option `einvoice_matbao_settings`: `'1'` bật, `'0'` tắt; khi khóa chưa có thì trả `$webhook_saved`. Nhờ đó site cũ đã lưu webhook ra "bật" mà không cần migration, site mới ra "tắt", và giá trị mặc định chỉ tính khi chưa ai lưu công tắc. `Settings::save_lark_enabled( bool )` ghi `'1'` hoặc `'0'`; phải ghi `'0'` rõ ràng, nếu để rỗng thì khóa sẽ mất nghĩa. Truyền `$webhook_saved` vào thay vì để `Settings` gọi `CredentialStore` giữ hai lớp tách nhau.

**Lưu công tắc.** `save_lark()` đọc checkbox `enabled` và ghi công tắc cùng lúc với webhook, theo cách `save_monitoring()` làm. Bật khi chưa có webhook nào (đã lưu hoặc đang nhập) bị từ chối bằng notice tiếng Việt, vì không có gì để gửi. Lỗi webhook không hợp lệ vẫn được kiểm tra trước mọi ghi. `test_lark()` không kiểm tra công tắc.

**Gửi cảnh báo.** Trong `InvoiceAlerts::deliver()`, kiểm tra công tắc trước khi lấy webhook: khi tắt ghi `AlertStore::set( $key, 'lark', 'disabled' )` rồi return, không gọi Lark, không retry. Kiểm tra ở đây, lúc gửi, nên cũng đúng khi công tắc đổi giữa lúc xếp hàng và lúc chạy. Alert vẫn được tạo nên admin notice vẫn hiện. `AdminAlertNotice::lark_note()` thêm `'disabled'` với ghi chú "(Đã tắt cảnh báo Lark nên chưa gửi tin.)". Trạng thái `disabled` không phải trạng thái cuối: nếu admin bật lại, các alert cũ không tự gửi bù (xem Risks).

## Risks / Trade-offs

- [Bookmark `tab=lark` hoặc `tab=monitoring` hỏng] → alias trong `AdminTabs`, có test cho cả URL và route `tabs/<alias>`.
- [Plugin hoặc theme khác dựa vào hook suffix `woocommerce_page_…`] → chỉ code của plugin này dùng hằng đó; kiểm tra bằng grep trước khi đổi.
- [Site cũ có webhook không đọc được do đổi khóa mã hóa] → `has_lark()` vẫn đúng, nên công tắc hiển thị bật; `lark()` trả null lúc gửi và ghi `not_configured` như hiện nay, không đổi hành vi.
- [Cảnh báo phát sinh lúc tắt không gửi bù khi bật lại] → chấp nhận: bật lại chỉ ảnh hưởng alert mới; admin notice vẫn liệt kê các đơn lỗi.
- [Site mới bật công tắc rồi xóa webhook] → công tắc bật nhưng không có webhook; `deliver()` đã ghi `not_configured` như cũ.

## Migration Plan

Không có bước chạy lúc nâng cấp: không ghi option, không đổi schema. Khóa `lark_enabled` chưa tồn tại được diễn giải theo webhook đã lưu cho tới lần đầu admin lưu thẻ Lark. Rollback: gỡ bản mới, plugin cũ bỏ qua khóa thừa; menu về lại dưới WooCommerce.
