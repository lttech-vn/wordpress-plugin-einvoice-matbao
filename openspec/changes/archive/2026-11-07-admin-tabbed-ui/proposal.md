## Why

Giao diện admin hiện tách thành hai mục menu WooCommerce: trang cấu hình gồm 6 card xếp dọc phải cuộn dài, và trang "Danh sách hóa đơn" riêng. Việc admin làm hằng ngày là xem và xử lý hóa đơn, còn cấu hình chỉ làm một lần, nhưng thứ tự menu và bố cục không phản ánh điều đó. Gộp thành một màn hình có tab (Hóa đơn mặc định) giúp vào thẳng việc chính, cấu hình gọn theo nhóm và giảm một mục menu.

## What Changes

**Màn hình plugin**
- From: hai submenu `einvoice-matbao-settings` (6 card dọc) và `einvoice-matbao-invoices` (dashboard + danh sách).
- To: một submenu "Hóa đơn Mắt Bão" (`einvoice-matbao-settings`) có 5 tab: Hóa đơn (mặc định), Kết nối Mắt Bão, Xuất hóa đơn, Cảnh báo Lark, Nâng cao.
- Reason: đưa việc hằng ngày lên đầu, bỏ cuộn dài.
- Impact: non-breaking cho dữ liệu và REST route hiện có; mục menu "Danh sách hóa đơn" biến mất.

**Chuyển tab**
- From: không có tab.
- To: chuyển tab bằng htmx không reload, URL cập nhật `&tab=<key>`; mở trực tiếp URL có `tab` thì server render đúng tab; `tab` lạ quay về tab Hóa đơn.
- Reason: giữ trải nghiệm htmx liền mạch mà vẫn bookmark/F5/back được.
- Impact: thêm một route REST đọc fragment tab, cùng kiểm soát truy cập như mọi route plugin.

**Link cũ**
- From: `page=einvoice-matbao-invoices` mở trang danh sách.
- To: chuyển hướng sang `page=einvoice-matbao-settings&tab=invoices`.
- Reason: không vỡ bookmark và link đã chia sẻ.
- Impact: non-breaking.

**Polish nhẹ trong vùng tab**
- Tab bar có trạng thái đang chọn rõ ràng, spacing và tiêu đề nhất quán giữa các tab, trạng thái hóa đơn trong bảng hiển thị dạng badge màu theo nhóm (lỗi, đang chờ, đã tạo nháp, khác). Giữ design tokens `einv-*` và scope `#einv-app`.

## Capabilities

### New Capabilities
- (không có)

### Modified Capabilities
- `admin-settings`: "Settings page access" đổi thành một trang plugin có tab, mặc định tab Hóa đơn, kèm chuyển hướng slug cũ; "Admin assets scoped to plugin pages" chỉ còn một trang plugin; thêm requirement cho màn hình có tab, nhóm form theo tab và chuyển tab htmx qua route fragment.
- `admin-invoice-management`: "Invoice management page access" đổi từ trang riêng thành tab Hóa đơn của trang plugin; thêm requirement hiển thị trạng thái hóa đơn dạng badge theo nhóm.

## Impact

- Code: `src/Admin/SettingsPage.php` (menu, render theo tab, enqueue), `src/Admin/InvoicePage.php` (bỏ menu riêng, render nội dung tab Hóa đơn, badge), `src/Uninstall/UninstallSettingsController.php` (section trong tab Nâng cao), `src/Admin/SettingsController.php` hoặc controller mới cho route fragment tab, `assets/dist/admin.css` (build lại cho utility class mới; không thêm token).
- REST: thêm `GET einvoice-matbao/v1/tabs/<key>`; các route hiện có không đổi.
- Tests: `tests/integration/SettingsPageTest.php` và các test đang render trang settings (`SettingsUninstallRestTest`, `SettingsUnitRestTest`, `SettingsCredentialsRestTest`, `SettingsInvoicingRestTest`, `InvoiceAdminListRestTest`), test mới cho route tab và chuyển hướng; e2e `tests/e2e/army/*` (`PAGES`, tab mới), context agent `admin` trong `tests/e2e/e2e.army.config.ts` và ghi lại `army-cache/`.
- Docs: `README.md`, `tests/e2e/README.md` (tên menu, số entry cache).
- Dependencies: không thêm.
