## Why

Plugin đang nuốt lỗi bằng `error_log()` và chỉ báo ra ngoài qua Lark khi hóa đơn dừng ở lỗi cần admin xử lý. Trên site khách, nhóm phát triển không thấy exception, không biết lần gọi Mắt Bão nào chậm và không biết Action Scheduler còn chạy hay không. Đã có server Sentry-compatible tự host (Rustrak, project `einvoice-matbao`), nên giờ có thể nối plugin vào để có issues, performance, logs và cron monitor, đồng thời giữ nguyên cam kết không gửi dữ liệu người mua ra ngoài.

## What Changes

- Thêm lớp giám sát lỗi dùng SDK chính thức `sentry/sentry` trên một Hub riêng của plugin. Lớp này mặc định tắt và chỉ hoạt động khi admin bật công tắc và có DSN.
- Lưu DSN mã hóa trong `CredentialStore`. Hằng `EINVOICE_MATBAO_SENTRY_DSN` trong `wp-config.php` ghi đè DSN trong UI.
- Issues: gửi exception bị bắt trong code plugin, và mỗi lần hóa đơn dừng ở lỗi cần admin xử lý (mức `warning`, gom theo `error_code`).
- Performance: tạo transaction cho từng action Action Scheduler của plugin và cho route REST admin; tạo span cho mỗi HTTP request tới Mắt Bão và Lark.
- Logs: gửi log có cấu trúc cho mỗi chỗ đang `error_log` và cho mỗi lần chuyển trạng thái hóa đơn. `error_log` vẫn giữ.
- Cron monitor: recovery sweep check-in lên monitor `einvoice-matbao-recovery-<host>` riêng cho từng site.
- Riêng tư: mọi event, span, log và check-in đi qua bộ lọc. Bộ lọc bỏ dữ liệu người mua, credential, token, URL webhook và tham số hàm; message được che CCCD, MST, email, URL và token.
- Đóng gói: zip phát hành đổi namespace vendor bằng PHP-Scoper để không xung đột với plugin khác.

**Tab cấu hình**
- From: màn hình plugin có 5 tab `invoices`, `connection`, `invoicing`, `lark`, `advanced`.
- To: thêm tab `monitoring` ("Giám sát lỗi") ngay trước `advanced`. Tab gồm form bật/tắt, DSN, `traces_sample_rate` và nút gửi event thử.
- Reason: admin phải bật và nhập DSN được ngay trong plugin.
- Impact: không phá vỡ hành vi hiện có. Route và hành vi của các tab cũ giữ nguyên; danh sách key tab hợp lệ có thêm `monitoring`.

## Capabilities

### New Capabilities
- `error-monitoring`: bật/tắt và cấu hình Sentry (DSN, sample rate); gửi issue, transaction/span, log và cron check-in của plugin lên server Sentry-compatible; lọc dữ liệu nhạy cảm; cô lập khỏi Sentry của site.

### Modified Capabilities
- `admin-settings`: requirement "Tabbed plugin screen" và "Settings grouped by tab" thêm tab `monitoring`.

## Impact

- **Dependency runtime mới**: `sentry/sentry` ^4 cùng các dependency của nó (`guzzlehttp/psr7`, `symfony/options-resolver`, `psr/log`, `jean85/pretty-package-versions`). SDK yêu cầu `ext-curl` và `ext-mbstring`; thiếu extension thì giám sát tự tắt, không gây fatal.
- **Build**: `scripts/build-zip.sh` thêm bước PHP-Scoper; `scripts/smoke-zip.sh` kiểm tra vendor đã được scope.
- **Code**: `Plugin::boot`, `CredentialStore`, `Settings`, `Admin\AdminTabs`, `Admin\SettingsPage`, `Admin\SettingsController`, `Invoice\DraftInvoiceWorkflow`, `Invoice\OrderChangeWatcher`, `Alert\InvoiceAlerts`, `Matbao\MatbaoClient`, `Lark\LarkClient`, và namespace mới `Monitoring\`.
- **Dữ liệu**: không đổi schema DB. DSN nằm trong option credential hiện có, nên đã bị xóa khi gỡ cài đặt. Cờ bật/tắt và sample rate nằm trong `einvoice_matbao_settings`.
- **Hệ thống ngoài**: Rustrak `rustrak.ltt.onl`, project `einvoice-matbao` (id 3).
