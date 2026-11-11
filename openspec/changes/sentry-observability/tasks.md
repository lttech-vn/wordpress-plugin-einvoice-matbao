## 1. Dependency và nền tảng

- [ ] 1.1 Thêm `sentry/sentry` ^4.36 vào `composer.json` (require) với `config.platform-check=false`, cập nhật `composer.lock`; xác nhận PHPStan/PHPCS vẫn đạt trên bản chưa scope
- [ ] 1.2 Tạo `src/Monitoring/SentryDsn.php` (parse/validate DSN, trả host và project id, không bao giờ lộ public key trong `__toString`/message) kèm test
- [ ] 1.3 Tạo `src/Monitoring/Scrubber.php` (redact giá trị người mua đã biết + che chuỗi theo mẫu, allowlist tag, xóa frame vars) kèm test với dữ liệu người mua từ fixture
- [ ] 1.4 Tạo `src/Monitoring/MonitorSlug.php` (slug `einvoice-matbao-recovery-<host16>-<hash8>`) kèm test va chạm và host dài

## 2. Cấu hình và lưu trữ

- [ ] 2.1 `CredentialStore`: thêm `sentry_dsn()`, `has_sentry_dsn()`, `save_sentry_dsn()` (mã hóa như Lark; DSN chỉ bị xóa cùng option credential khi gỡ cài đặt, không có thao tác xóa riêng trong UI); xác nhận uninstall mặc định đã xóa nó (option credential) bằng test
- [ ] 2.2 `Settings`: thêm `monitoring_enabled()`, `traces_sample_rate()` và `save_monitoring()`; mặc định tắt và 1.0
- [ ] 2.3 Tạo `src/Monitoring/MonitoringConfig.php`: gộp settings, credential và hai hằng `EINVOICE_MATBAO_SENTRY_DSN`/`EINVOICE_MATBAO_SENTRY_ENABLED`, `ext-mbstring` check, environment/release/site host, monitor slug

## 3. Backend Sentry và facade

- [ ] 3.1 Tạo `src/Monitoring/WpHttpClient.php` (implement `Sentry\HttpClient\HttpClientInterface` qua `wp_remote_post`, timeout 3 giây/connect 2 giây, circuit breaker theo request + option `einvoice_matbao_sentry_backoff_until` 60 giây/`Retry-After` ≤ 600 giây; option bị xóa khi gỡ cài đặt)
- [ ] 3.2 Tạo `src/Monitoring/SentryBackend.php` (ClientBuilder: `default_integrations=false`, `send_default_pii=false`, `server_name=''`, `enable_logs=true`, mọi `before_send*` qua Scrubber; Hub riêng, không gọi `\Sentry\init()`)
- [ ] 3.3 Tạo `src/Monitoring/Monitor.php` (facade tĩnh, khởi tạo lười, no-op khi tắt, nuốt mọi lỗi, flush ở `shutdown`, `with_order_context()` nạp giá trị người mua của đơn vào Scrubber; có seam reset/inject backend cho test)
- [ ] 3.4 Test chứng minh: tắt hoặc thiếu DSN thì không có request và không nạp class `Sentry\ClientBuilder`; không đổi `\Sentry\SentrySdk::getCurrentHub()` toàn cục; warning của code ngoài plugin không tạo event

## 4. Điểm đo trong luồng nghiệp vụ

- [ ] 4.1 Issues: thêm `Monitor::capture_exception` cạnh mọi `error_log` trong `InvoiceAlerts`, `OrderChangeWatcher`, `DraftInvoiceWorkflow` và trong catch `\Throwable` của `DraftInvoiceWorkflow::send()`; `error_log` giữ nguyên
- [ ] 4.2 Issues: `InvoiceAlerts::on_needs_attention` gọi `Monitor::capture_needs_attention` sau khi `AlertStore::create` thành công (warning, fingerprint theo `error_code`, tag theo allowlist)
- [ ] 4.3 Tracing: tạo `src/Monitoring/ActionSchedulerTracing.php` (transaction cho action group `einvoice-matbao`) và `src/Monitoring/RestTracing.php` (route `einvoice-matbao/v1`); đăng ký trong `Plugin::boot`
- [ ] 4.4 Span HTTP trong `MatbaoClient::request()` (method + path, status, `matbao.error_code`) và `LarkClient::send_text()` (`POST lark-webhook`, không URL)
- [ ] 4.5 Logs: `InvoiceRepository` gửi log `invoice status changed` sau mỗi lần đổi trạng thái thành công; log `error` tại các chỗ `error_log`
- [ ] 4.6 Cron monitor: `ActionSchedulerTracing` gửi check-in `in_progress` → `ok`/`error` với duration và `monitor_config` 15 phút cho hook recovery sweep

## 5. Admin UI tab Giám sát lỗi

- [ ] 5.1 `AdminTabs::labels()` thêm `monitoring` trước `advanced`; cập nhật test tab hiện có (AdminTabsTest, SettingsPageTest, SettingsTabsRestTest)
- [ ] 5.2 `SettingsPage::render_monitoring_panel()`: công tắc, DSN (hiện host · project id, chỉ đọc khi có hằng), sample rate, nút gửi thử; chuỗi tiếng Việt qua text domain
- [ ] 5.3 `SettingsController`: route `POST /settings/monitoring` (validate, blank DSN giữ cũ, bỏ qua trường bị hằng ghi đè, từ chối khi thiếu khóa mã hóa) và `POST /settings/monitoring/test` (event info, trả event ID, không lộ DSN); test REST đủ access/nonce/validate
- [ ] 5.4 E2E army: thêm kịch bản tab Giám sát lỗi (lưu DSN, gửi thử tới fake sink), cập nhật `tabs.army.ts` cho 6 tab

## 6. Đóng gói scope

- [ ] 6.1 `scripts/build-zip.sh`: tải phar PHP-Scoper ghim version + sha256, thêm `scoper.inc.php` (prefix `LTTech\EinvoiceMatbao\Vendor`, loại trừ namespace plugin và symbol WordPress/WooCommerce/Action Scheduler), scope `vendor/` + `src/`, dump autoload lại
- [ ] 6.2 `scripts/smoke-zip.sh`: kiểm tra không còn `namespace Sentry;` chưa prefix; cài mu-plugin giả nạp `\Sentry\SentrySdk` và `\GuzzleHttp\Psr7\Request`, rồi kích hoạt plugin, bật giám sát với DSN tới sink giả và gửi event thử thành công

## 7. Tài liệu và xác minh

- [ ] 7.1 Cập nhật `docs/huong-dan-su-dung.md` (bật giám sát, DSN, hằng wp-config, khuyến nghị system cron) và `docs/phat-trien.md` (module Monitoring, scope build, test với Rustrak)
- [ ] 7.2 Chạy `composer check` trên cả tổ hợp min và latest, `bun test`, E2E army và smoke-zip
- [ ] 7.3 Verify E2E với Rustrak thật (project 3): bật trong UI, gây lỗi Mắt Bão giả, xác nhận qua MCP có issue, transaction có span, log trạng thái và monitor `einvoice-matbao-recovery-<host>`; dọn dữ liệu thử sau đó
