## Design Summary

### Mục đích

Plugin hiện nuốt lỗi bằng `error_log()` (Lark alert, order watcher, listener của workflow) và chỉ báo ra ngoài qua Lark khi một hóa đơn dừng ở lỗi cần admin xử lý. Khi plugin chạy trên site khách, nhóm phát triển không thấy exception, không biết lần gọi Mắt Bão nào chậm, và không biết Action Scheduler có còn chạy hay không. Change này nối plugin với một server Sentry-compatible (Rustrak tự host tại `rustrak.ltt.onl`, project `einvoice-matbao`, id 3) để có issues, performance, logs và cron monitor. Admin site bật/tắt và nhập DSN trong màn hình cài đặt.

Thành công nghĩa là: một lỗi trong luồng tạo hóa đơn nháp hiện thành issue trên Rustrak, có stacktrace và tag đơn/mã lỗi; mỗi lần Action Scheduler chạy tác vụ của plugin là một transaction có span cho từng HTTP call tới Mắt Bão/Lark; các chuyển trạng thái hóa đơn có trong Logs; Rustrak báo khi recovery sweep 15 phút không chạy.

Rustrak đã có sẵn API cho transaction, logs và cron monitor (qua MCP: `list_transactions`, `list_logs`, `list_monitors`, `create_monitor`); verify E2E sẽ xác nhận từng loại nhận được thật.

### Goals

- Tab cài đặt mới bật/tắt Sentry, nhập DSN, chỉnh `traces_sample_rate` (mặc định 1.0), và gửi một event thử.
- DSN lưu mã hóa trong `CredentialStore` như webhook Lark; hằng `EINVOICE_MATBAO_SENTRY_DSN` trong `wp-config.php` ghi đè giá trị trong UI.
- **Issues**: exception bị bắt trong code plugin (mọi chỗ đang `error_log`, `catch (\Throwable)` của workflow, lỗi của `MatbaoClient`/`LarkClient`) và mỗi lần hóa đơn dừng ở lỗi cần admin xử lý (`InvoiceErrorCode::needs_attention()`).
- **Performance**: một transaction cho mỗi lần chạy action Action Scheduler của plugin (tạo nháp, recovery sweep, gửi alert, kiểm tra thay đổi đơn) và cho route REST admin; span cho từng HTTP request tới Mắt Bão và Lark.
- **Logs**: thay các `error_log` của plugin bằng log có cấu trúc gửi lên Sentry Logs, và ghi mỗi lần chuyển trạng thái hóa đơn.
- **Cron monitor**: check-in cho recovery sweep (lịch 15 phút) để Rustrak phát hiện Action Scheduler ngừng chạy.

### Non-goals

- Không bắt lỗi của WordPress, theme hay plugin khác; không cài global error/exception handler.
- Không theo dõi lỗi JavaScript ở checkout hoặc admin (có thể làm ở change sau).
- Không profiling (cần extension `excimer`), không session/release health, không user feedback.
- Không thay Lark alert: Lark vẫn là kênh báo cho chủ shop; Sentry là kênh cho người phát triển.

### Anti-goals (coi là thất bại dù chạy được)

- Plugin gây fatal hoặc xung đột class với plugin khác đã nạp Sentry SDK, Guzzle PSR-7 hay Symfony OptionsResolver.
- Bất kỳ dữ liệu cá nhân người mua (tên, MST, email, địa chỉ, CCCD, tên công ty) hoặc credential/token Mắt Bão, URL webhook Lark, DSN rơi vào event, span, log hay breadcrumb.
- Sentry chậm hoặc chết làm chậm hay hỏng luồng tạo hóa đơn: mọi lỗi của lớp quan sát phải bị nuốt.
- Plugin ghi đè Hub/Client Sentry toàn cục mà site hoặc plugin khác đang dùng.
- Khi tắt hoặc chưa có DSN, plugin vẫn nạp SDK hay gửi request ra ngoài.

### Ràng buộc

- PHP 8.2 sàn, WordPress 6.6, WooCommerce 9.8 (spec `plugin-lifecycle`); SDK chọn phải chạy trên sàn này.
- Plugin hiện không có dependency runtime; zip được build từ whitelist (`scripts/build-zip.sh`) và có guard blocklist.
- Requirement hiện có liên quan, giữ nguyên:
  - `failure-alerts` / "Lark message content and privacy": tin ra ngoài không chứa dữ liệu cá nhân, secret, token. Change mở rộng nguyên tắc này cho Sentry, không đổi requirement Lark.
  - `credential-encryption` / "Secrets encrypted with a wp-config key": DSN dùng cùng cơ chế.
  - `plugin-lifecycle` / "Default uninstall removes secrets and caches": DSN là credential nên bị xóa khi gỡ cài đặt mặc định.
  - `plugin-lifecycle` / "Plugin stays inert without dependencies": không đổi.
  - `invoice-retry-dedup` / "Error messages stored without citizen ID": scrub CCCD cũng áp cho Sentry.
- Requirement bị **thay đổi**: `admin-settings` / "Tabbed plugin screen" và "Settings grouped by tab" hiện cố định 5 tab; change thêm tab thứ 6 (Giám sát lỗi, key `monitoring`) trước tab `advanced`.

## Alternatives Considered

### Alternative A: SDK chính thức `sentry/sentry` + PHP-Scoper, Hub riêng
- **Approach**: thêm `sentry/sentry` vào composer, build zip đổi namespace mọi vendor sang tiền tố riêng của plugin; plugin tạo `Client` + `Hub` riêng, không gọi `\Sentry\init()`.
- **Pros**: đầy đủ issues/tracing/logs/check-in, stacktrace chuẩn; nâng cấp theo upstream; không xung đột class.
- **Cons**: thêm bước scoper vào build; zip nặng thêm; test phải chạy cả bản scoped.

### Alternative B: Tự viết client envelope qua `wp_remote_post`
- **Approach**: tự dựng event/transaction/log/check-in theo định dạng envelope và gửi bằng HTTP API của WordPress.
- **Pros**: không dependency; payload kiểm soát hoàn toàn.
- **Cons**: tự duy trì định dạng envelope và stacktrace cho 4 loại dữ liệu; dễ sai và tụt hậu.
- **Why not chosen**: chi phí bảo trì dài hạn cao hơn rủi ro build của A.

### Alternative C: Dựa vào plugin "WP Sentry Integration"
- **Approach**: yêu cầu site cài plugin đó; plugin này chỉ thêm tag/span vào Hub toàn cục.
- **Pros**: ít code nhất.
- **Cons**: phụ thuộc plugin ngoài; DSN và bật/tắt không nằm trong plugin; bắt cả lỗi site (trái non-goal).
- **Why not chosen**: không đáp ứng "cho phép kích hoạt và điền DSN" trong plugin và không giới hạn phạm vi lỗi.

## Agreed Approach

Alternative A, do user chọn. Plugin sở hữu một Hub Sentry riêng, chỉ được khởi tạo khi Sentry bật và có DSN hợp lệ. Mọi điểm đo trong plugin đi qua một lớp quan sát mỏng của plugin; khi tắt, lớp này là no-op và SDK không được nạp. Vendor được scope trong zip phát hành để không đụng class của plugin khác.

## Key Decisions

Do user quyết định:
- SDK `sentry/sentry` + PHP-Scoper, Hub riêng, không đè Sentry của site.
- Chỉ bắt lỗi phát sinh từ plugin.
- DSN mã hóa trong `CredentialStore`; hằng `EINVOICE_MATBAO_SENTRY_DSN` ghi đè.
- Bật cả issues, performance, logs, cron monitor ở bản đầu.
- `traces_sample_rate` mặc định 1.0, admin chỉnh được.
- Project Rustrak `einvoice-matbao` (id 3) đã được tạo để dùng khi dev/E2E.
- Một project Rustrak dùng chung cho mọi site khách: cron monitor đặt slug `einvoice-matbao-recovery-<host>` (host của site, chuẩn hóa thành slug) để mỗi site có monitor riêng; mỗi event mang tag `site` = domain shop dạng rõ (domain không phải dữ liệu người mua).
- Gửi message của exception sau khi lọc (che CCCD, MST, email, URL, token); không gửi tham số hàm trong frame stacktrace.
- Khi Sentry tắt, giữ `error_log` như hiện nay để site chưa bật Sentry không mất dấu vết lỗi cục bộ; khi bật, log vừa đi Sentry vừa giữ `error_log`.

Đề xuất của agent (user có thể sửa):
- Sentry mặc định **tắt**; chỉ bật khi admin bật công tắc và có DSN (từ UI hoặc hằng). Hằng có DSN mà công tắc tắt vẫn coi là tắt, trừ khi có hằng `EINVOICE_MATBAO_SENTRY_ENABLED` bằng true. Lý do: tránh gửi dữ liệu ra ngoài mà chủ shop không biết.
- Khi hằng ghi đè DSN, ô DSN trong UI hiện trạng thái "được đặt trong wp-config" và không cho sửa.
- `environment` lấy từ `wp_get_environment_type()`, `release` là `einvoice-matbao@<version>`; `server_name` không gửi (có thể lộ hostname nội bộ); site nhận diện qua tag `site`.
- Tag mỗi event: `order_id`, `invoice.status`, `error_code`, `error_kind`, `matbao.env` — không gửi dữ liệu người mua.
- Lỗi `needs_attention` gửi thành issue mức `warning`, fingerprint theo `error_code` để gom theo loại lỗi chứ không theo đơn.
- DSN bị xóa ở cả hai chế độ gỡ cài đặt; settings bật/tắt và sample rate theo luật settings hiện có (giữ khi gỡ mặc định).
- Không gửi request body/response body của Mắt Bão trong span; chỉ method, path, status, thời gian, errorCode.
- Lớp quan sát không bao giờ ném lỗi ra caller và không làm chậm request quá timeout ngắn khi gửi.

## Open Questions

- Tên tab và nhãn tiếng Việt: đề xuất "Giám sát lỗi" (key `monitoring`); chốt ở design.
- Cơ chế gửi: đồng bộ cuối request (`shutdown`) hay qua transport HTTP mặc định của SDK với timeout ngắn — chốt ở design sau khi kiểm thử với Rustrak.
- Cách chạy PHPUnit/PHPStan trên code dùng class đã scope — chốt ở design.
