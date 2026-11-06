## Context

- `SettingsPage` đăng ký submenu `einvoice-matbao-settings` (priority 60), render 6 section trong `#einv-app`, nạp `admin.css` + `htmx.min.js` + inline script (nonce `X-WP-Nonce`, swap fragment 4xx) cho hai hook `woocommerce_page_einvoice-matbao-settings` và `woocommerce_page_einvoice-matbao-invoices`.
- `InvoicePage` đăng ký submenu `einvoice-matbao-invoices` (priority 61); shell gồm `#einv-dashboard` (`hx-trigger="load"` → `/dashboard`), form lọc và `#einv-invoices` (`load` → `/invoices`). Chi tiết, phân trang và thẻ dashboard đều swap vào `#einv-invoices`.
- Section gỡ plugin do `UninstallSettingsController::render_section()` echo.
- Mọi route nằm trong namespace `einvoice-matbao/v1`, quyền qua `SettingsController::check_permission`, phản hồi HTML qua `serve_html` (ADR-0003).
- Event `einv-matbao-saved` (header `HX-Trigger` của route lưu credential) kích hoạt tải lại danh sách mẫu `from:body`.
- Trong `wp-admin/admin.php`, kiểm tra quyền vào `page=` chạy khi dựng menu, trước `admin_init`; slug không đăng ký sẽ bị `wp_die`. Vì vậy redirect slug cũ cần trang ẩn được đăng ký.

## Goals / Non-Goals

**Goals:**
- Một màn hình `page=einvoice-matbao-settings` với 5 tab; tab mặc định `invoices`.
- Chuyển tab bằng htmx, URL phản ánh tab; F5/bookmark/back/forward đúng tab.
- Slug cũ `einvoice-matbao-invoices` chuyển hướng 302 tới `&tab=invoices`.
- Badge trạng thái theo nhóm trong bảng hóa đơn; tab bar rõ ràng.

**Non-Goals:**
- Không đổi route hiện có, payload, logic nghiệp vụ hay dữ liệu.
- Không đổi meta box đơn hàng, filter danh sách đơn WooCommerce, trường đơn vị sản phẩm.
- Không thêm dependency hay JS build mới; không thêm design token màu mới.

## Decisions

### D1. Registry tab tập trung: class `AdminTabs`
`src/Admin/AdminTabs.php` giữ danh sách key → nhãn theo thứ tự hiển thị (`invoices`, `connection`, `invoicing`, `lark`, `advanced`), `DEFAULT = 'invoices'`, `resolve(mixed): string` (so khớp chính xác, phân biệt hoa thường; key lạ/rỗng/không phải string → default) và `url(string $tab)` (URL admin của tab). Một nguồn duy nhất cho page shell, route fragment và test. Thay thế: hằng rải rác trong `SettingsPage` (dễ lệch giữa render đầu và fragment).

### D2. Slug chính giữ `einvoice-matbao-settings`
Mục menu duy nhất giữ slug cũ nên mọi link settings cũ vẫn mở được (vào tab mặc định). Thay thế: slug mới `einvoice-matbao` cần redirect cả hai slug cũ, nhiều thay đổi hơn mà không thêm giá trị. Tiêu đề trang và nhãn menu: "Hóa đơn Mắt Bão".

### D3. Redirect slug cũ qua trang ẩn + `load-{hook}`
`InvoicePage::register_menu()` đăng ký trang ẩn `einvoice-matbao-invoices` (không hiện trong menu, quyền `manage_options`) và gắn `load-<hook_suffix>` gọi `wp_safe_redirect( AdminTabs::url( 'invoices' ) )` + `exit`. Không dùng `admin_init` vì kiểm tra quyền trang chạy trước đó. Cách ẩn: parent slug rỗng, phải kiểm chứng không phát sinh deprecation notice trên PHP 8.2 / WP 6.6 (sàn hiện tại); nếu có, đăng ký dưới `woocommerce` rồi `remove_submenu_page` sau khi dựng menu. Người thiếu quyền vẫn nhận màn hình từ chối của WordPress như hiện nay.

### D4. Route fragment tab: `GET einvoice-matbao/v1/tabs/(?P<tab>[^/]+)`
Đăng ký trong `SettingsController::register_routes()` (cùng `check_permission`, cùng `serve_html`). Handler trả fragment gồm **tab bar + panel** của tab yêu cầu; key không nằm trong registry → `Fragment::error(..., 404)`. Pattern `[^/]+` (không `[a-z]+`) để key sai dạng như `Lark1` vẫn khớp route và nhận notice HTML thay vì JSON `rest_no_route` của core. Fragment chứa tab bar để trạng thái "đang chọn" luôn do server tính, không cần JS tự viết. Thay thế: chỉ trả panel và dùng `hx-swap-oob` cho tab bar (phức tạp hơn, hai nơi render); hoặc JS đổi class (lệch ADR-0003).

### D5. Cấu trúc DOM và htmx
```
#einv-app
  [banner thiếu khóa mã hóa]          <- ngoài vùng swap, thấy ở mọi tab
  #einv-tabs  (hx-history-elt)        <- target của chuyển tab, swap innerHTML
    nav[aria-label] > a[aria-current] x5
    #einv-tab-panel[data-einv-tab=<key>]
```
Mỗi tab là `<a href="AdminTabs::url(key)" hx-get="/tabs/key" hx-target="#einv-tabs" hx-push-url="AdminTabs::url(key)">`: có `href` thật nên Ctrl/Cmd-click mở tab trình duyệt mới và chạy được khi htmx lỗi. Tab đang chọn dùng `aria-current="page"` (link điều hướng, không dùng `role=tablist` vì không quản lý focus kiểu ARIA tabs). Render đầu trang: `SettingsPage::render()` đưa `$_GET['tab']` (đã `wp_unslash`, không `sanitize_key` vì hàm này hạ chữ thường) vào `AdminTabs::resolve` — whitelist chính xác, cùng quy tắc với route REST, in cùng markup với fragment (một hàm `render_tabs( string $tab ): string` dùng chung).

### D6. Lịch sử trình duyệt
`hx-history-elt` đặt trên `#einv-tabs` để snapshot nhỏ, không chụp cả wp-admin. Inline script đặt `htmx.config.refreshOnHistoryMiss = true` để khi cache lịch sử trống, back/forward tải lại trang đầy đủ (server render đúng tab) thay vì nhận cả trang admin chèn vào vùng swap. Snapshot không chứa secret vì form không bao giờ render lại mật khẩu/webhook.

### D7. Nội dung panel
Mỗi section hiện có tách thành method trả string (output buffering bọc markup sẵn có, giữ nguyên form, id, `hx-*`, class):
- `invoices`: dashboard + form lọc + `#einv-invoices` (chuyển từ `InvoicePage::render()` sang `InvoicePage::render_panel()`), rộng toàn khung.
- `connection`: card Kết nối Mắt Bão + card Mẫu hóa đơn mặc định (giữ `einv-matbao-saved` cùng tab).
- `invoicing`: card MTChieu/người mua vãng lai + card Đơn vị tính (hai form, hai route như cũ).
- `lark`: card Cảnh báo Lark.
- `advanced`: `UninstallSettingsController::render_section()` (đổi sang trả string hoặc bọc ob).
Panel cấu hình giữ `max-w-3xl`. Các phần tử `hx-trigger="load"` (dashboard, danh sách, danh sách mẫu) chạy lại khi panel được swap vào vì htmx xử lý nội dung mới; danh sách mẫu chỉ gọi Mắt Bão khi mở tab Kết nối.

### D8. Badge trạng thái
Thêm `InvoiceStatus::group( string $status ): string` trả `error` (`error_statuses()`), `waiting` (`waiting_statuses()`), `created` (`draft_created`) hoặc `other` (`skipped`, `stopped`, không rõ). `InvoicePage::render_list()` bọc nhãn trạng thái trong `<span data-einv-badge="<group>">` với màu từ token sẵn có: error → `bg-einv-error-bg text-einv-error`, created → `bg-einv-success-bg text-einv-success`, waiting → `bg-einv-primary/10 text-einv-primary`, other → `bg-einv-canvas-soft text-einv-ink-mute`. Nhãn chữ giữ nguyên nên màu không là tín hiệu duy nhất. Thay thế: thêm token `warning` (vượt phạm vi polish nhẹ).

### D9. Tab bar và nhịp trình bày
Tab bar: hàng link chữ 14px, `text-einv-ink-mute`, hover `text-einv-ink`, active `text-einv-primary` + gạch dưới 2px `border-einv-primary`; đường kẻ `border-einv-hairline` dưới cả hàng; focus-visible có outline. Khoảng cách tab bar → panel cố định (`mt-6`); trên màn hẹp tab bar cuộn ngang (`overflow-x-auto`, không xuống dòng). Mọi class đều nằm trong `#einv-app` (Tailwind scoped, `bun run check:css-scope` kiểm).

### D10. Asset chỉ nạp trên một hook
`enqueue_assets` chỉ còn so `woocommerce_page_einvoice-matbao-settings`. Trang ẩn redirect trước khi enqueue nên không cần hook của nó.

## Risks / Trade-offs

- [Trang ẩn với parent rỗng sinh deprecation notice trên PHP 8.2] → kiểm chứng trong integration test với `WP_DEBUG`; dự phòng `remove_submenu_page` (D3).
- [Restore lịch sử htmx chạy lại các `hx-trigger="load"` (dashboard, danh sách, danh sách mẫu) vì nội dung khôi phục được xử lý lại] → chấp nhận, dữ liệu được làm mới; test e2e back/forward chỉ kiểm tab và URL đúng.
- [Lỗi 403/404 từ `/tabs/*` (nonce hết hạn) được swap vào `#einv-tabs`, thay cả tab bar, và URL có thể đã đổi] → chấp nhận như hành vi hiện có của các form; tải lại trang khôi phục. Không xử lý riêng trong change này.
- [Link settings cũ không kèm `tab` giờ mở tab Hóa đơn thay vì cấu hình] → đã nêu ở brainstorm và được duyệt; tab bar luôn thấy được nên chuyển một click.
- [E2E dựa vào URL/nhãn cũ bị vỡ] → cập nhật `PAGES` trong `tests/e2e/army/support.ts` (thêm URL theo tab) và các test mở settings phải mở tab tương ứng.
- [Nonce hết hạn khi tab mở lâu] → không đổi so với hiện tại: route trả notice "Phiên làm việc đã hết hạn" vào panel.

## Migration Plan

Không có migration dữ liệu. Triển khai bằng bản cập nhật plugin; menu cũ biến mất, slug cũ redirect. Rollback: cài lại phiên bản trước, không còn dữ liệu nào cần dọn.

## Open Questions

- (không có)
