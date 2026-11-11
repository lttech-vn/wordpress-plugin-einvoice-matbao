## Context

`brainstorm.md` đã nêu mục đích, phạm vi và các quyết định sản phẩm. Phần này chỉ nói cách làm.

Hiện trạng code liên quan:
- `Plugin::boot()` đăng ký mọi module sau `plugins_loaded`. Zip phát hành nạp `vendor/autoload.php` từ whitelist (`scripts/build-zip.sh`).
- `MatbaoClient::request()` và `LarkClient::send_text()` là hai chỗ duy nhất gọi HTTP ra ngoài, đều qua `wp_remote_*`. Test chặn mọi request bằng `tests/integration/Support/FakeHttp.php` (`pre_http_request`).
- Các chỗ đang nuốt lỗi gồm `DraftInvoiceWorkflow::send()` (`catch \Throwable`, ghi `UNEXPECTED`), `DraftInvoiceWorkflow` dòng 676/744/788, `OrderChangeWatcher::flush()`/`notify` và `InvoiceAlerts::send()`.
- Các action Action Scheduler của plugin nằm trong group `einvoice-matbao`: `einvoice_matbao_create_draft`, `einvoice_matbao_recover_stuck_invoices`, `einvoice_matbao_send_alert`. Route admin nằm dưới namespace REST `einvoice-matbao/v1`.
- Trạng thái hóa đơn được đổi trong `InvoiceRepository` (`claim`, `claim_unchanged`, `restart`, `update_status`, `record_error`).

Spike ngày 2026-11-11 trên Rustrak 0.25.0 (project id 3): envelope `check_in` có `monitor_config` tự tạo monitor, envelope `log` (`application/vnd.sentry.items.log+json`) hiện trong Logs. `sentry/sentry` 4.36.0 yêu cầu `php ^7.2|^8.0`, nên chạy được trên sàn PHP 8.2.

## Goals / Non-Goals

**Goals:**
- Mọi điểm đo trong plugin chỉ đi qua một facade tĩnh. Khi giám sát tắt, facade là no-op tuyệt đối: không nạp class Sentry, không gửi request.
- Chỉ một file adapter được phép tham chiếu `Sentry\…`. Bộ lọc dữ liệu là code thuần, test được mà không cần SDK.
- Gửi qua WordPress HTTP API để test chặn được bằng `FakeHttp`, tôn trọng proxy của site và không phụ thuộc `ext-curl`.

**Non-Goals:**
- Không cài error, exception hay fatal handler toàn cục. Không dùng default integrations của SDK.
- Không theo dõi JavaScript, không profiling, không release health.

## Decisions

### D1. Cấu trúc module `src/Monitoring/`
- `Monitor`: facade tĩnh duy nhất mà code khác gọi tới, gồm `capture_exception()`, `capture_needs_attention()`, `log()`, `start_transaction()`/`finish_transaction()`, `span()`, `check_in()`, `with_order_context()`. Chỉ lỗi của chính phần đo (instrumentation) bị nuốt. `span()` và `with_order_context()` chạy callable của caller **đúng một lần**, trả về giá trị của nó và ném lại nguyên vẹn exception nghiệp vụ; phần đo quanh callable có lỗi thì callable vẫn chạy như khi tắt.
- `MonitoringConfig`: value object chứa cấu hình đã hiệu lực (enabled, DSN, nguồn DSN `ui|constant`, sample rate, environment, release, site host). Đọc từ `Settings`, `CredentialStore` và hằng.
- `SentryDsn`: value object kiểm tra DSN (`https`/`http`, có public key, có project id dạng số). DSN sai bị từ chối khi lưu.
- `Scrubber` (implements `Redactor`): lọc chuỗi và mảng (xem D5).
- `SentryBackend`: adapter duy nhất (cùng `WpHttpClient`) tham chiếu `Sentry\…`. Nó dựng `Options` với `default_integrations=false`, `integrations=[]`, `send_default_pii=false`, `server_name=''`, `enable_logs=true`, `log_flush_threshold=null`, gắn `setHttpClient(new WpHttpClient())` và các callback `before_send`, `before_send_transaction`, `before_send_log`, `before_send_check_in`, `before_breadcrumb`, rồi tạo `new Hub($client)` riêng. Event, transaction và check-in đi qua `$hub->captureException/captureMessage/startTransaction/captureCheckIn`.
- **Logs không qua aggregator toàn cục:** `Sentry\Logs\Logs`/`LogsAggregator` của SDK 4.36 luôn đọc `SentrySdk::getCurrentHub()` và dùng buffer chung của runtime context, nên plugin **không** dùng chúng. `SentryBackend` tự giữ buffer `list<Sentry\Logs\Log>` (tối đa 100, quá thì bỏ cũ nhất): mỗi `log()` dựng `new Log(microtime(true), $trace_id, LogLevel::x(), $redacted_body)` với trace id lấy từ span hiện tại của Hub plugin (hoặc propagation context của scope Hub plugin), gắn attribute `sentry.release`, `sentry.environment`, `site` và attribute đã lọc. `flush()` gửi `$hub->captureEvent(Event::createLogs()->setLogs($buffer))` trên Hub plugin. Lọc làm ngay ở `log()` bằng `Redactor` nên không cần `before_send_log`. Nhờ vậy Hub và buffer log toàn cục của site không bao giờ bị đọc hay đổi, ở cả bản source lẫn bản scope.
- Bộ lọc được đưa vào `SentryBackend` qua interface `Redactor` (Scrubber implement), để test thay bằng redactor ném lỗi. Callback `before_send*` bọc try/catch; lỗi thì bỏ event (trả null).
- `WpHttpClient`: implement `Sentry\HttpClient\HttpClientInterface` bằng `wp_remote_post`, timeout 3 giây, connect timeout 2 giây, không redirect.
- `ActionSchedulerTracing`, `RestTracing`: lớp đăng ký hook (D4).
- Lý do: `Monitor` giữ API gọn cho các module nghiệp vụ; `SentryBackend` cô lập SDK để đổi hoặc nâng cấp mà không đụng chỗ khác. Phương án bỏ đi: gọi `\Sentry\captureException()` rải rác trong code, vì sẽ khóa chặt code vào SDK và không thể no-op khi tắt.

### D2. Cô lập khỏi Sentry của site: PHP-Scoper trong build
- `build-zip.sh` chạy `humbug/php-scoper` (phar ghim version và checksum, tải trong bước build, không thêm vào `composer.json`). Danh sách symbol WordPress/WooCommerce cần loại trừ được sinh từ `php-stubs/wordpress-stubs` và `php-stubs/woocommerce-stubs` cài vào một thư mục công cụ tạm của build (version lấy từ `composer.lock`), không phụ thuộc dev vendor của checkout trên `vendor/` + `src/` sau `composer install --no-dev`, với prefix `LTTech\EinvoiceMatbao\Vendor`. Namespace `LTTech\EinvoiceMatbao` và symbol global (WordPress, WooCommerce, Action Scheduler) được loại trừ khỏi prefix. Autoloader được dump lại sau khi scope.
- Bản scope có `Sentry\SentrySdk` riêng, nên "Hub toàn cục" của bản đó thực chất là Hub riêng của plugin. Adapter vẫn tự dựng client và Hub, không gọi `\Sentry\init()`.
- Môi trường dev và test dùng vendor chưa scope. Bản cài từ source checkout có thể xung đột nếu site đã nạp một bản Sentry khác; đây là giới hạn đã biết của bản source, không phải của zip phát hành.
- `scripts/smoke-zip.sh` thêm kiểm tra: không còn file nào trong zip khai báo `namespace Sentry;` ở dạng chưa prefix; plugin kích hoạt được khi một plugin mu giả nạp sẵn class `\Sentry\SentrySdk` và `\GuzzleHttp\Psr7\Request`.
- Phương án bỏ đi: Strauss. Nó dễ cấu hình hơn cho WordPress, nhưng xử lý `files` autoload (`src/functions.php` của Sentry) kém hơn và ít dùng với SDK lớn.

### D3. Kích hoạt và cấu hình
- Filter override cho test (`einvoice_matbao_monitoring_constants`, `einvoice_matbao_monitoring_mbstring`) chỉ được áp khi `MonitoringOverrides::allowed(defined('WP_TESTS_DOMAIN'), wp_get_environment_type())` trả true, tức là đang chạy test suite hoặc môi trường `local`/`development`, cùng quy tắc với `Encryption::filter_allowed()`. Hàm thuần này được test theo ma trận đầu vào.
- Giám sát chỉ chạy khi cờ bật là true, DSN hợp lệ và có `ext-mbstring`. Cờ bật lấy từ option settings, hoặc từ hằng `EINVOICE_MATBAO_SENTRY_ENABLED` nếu hằng được định nghĩa (hằng thắng UI). DSN lấy từ hằng `EINVOICE_MATBAO_SENTRY_DSN` nếu hằng là chuỗi DSN hợp lệ (hằng thắng UI), nếu không thì từ `CredentialStore::sentry_dsn()` (mã hóa).
- `traces_sample_rate` là số thực trong [0, 1], mặc định 1.0, lưu trong `einvoice_matbao_settings`.
- `environment = wp_get_environment_type()`, `release = einvoice-matbao@EINVOICE_MATBAO_VERSION`, tag `site` là host của `home_url()` viết thường.
- Hub chỉ được khởi tạo lười ở lần gọi `Monitor` đầu tiên trong request, nên request không chạm tới plugin thì không tốn gì.
- `composer.json` đặt `config.platform-check=false` để thiếu `ext-curl` không làm fatal (SDK khai báo `ext-curl`, nhưng `WpHttpClient` không dùng tới).
- Hằng DSN được định nghĩa nhưng không hợp lệ thì bị bỏ qua (dùng DSN UI) và form hiện cảnh báo. DSN lưu không giải mã được thì coi như không có DSN, form báo cần nhập lại.
- Đây là public contract (hai hằng wp-config, hai route REST mới), shape đầy đủ ghi trong plan.md.

### D4. Điểm đo
- **Transaction cho Action Scheduler:** `action_scheduler_before_execute` mở transaction `queue.task` tên là hook name, chỉ khi action thuộc group `einvoice-matbao`. `action_scheduler_after_execute` đóng với status ok; `action_scheduler_failed_execution` đóng với status `internal_error` và capture exception.
- **Transaction cho REST:** `rest_pre_dispatch` / `rest_post_dispatch` cho route `einvoice-matbao/v1/*`, op `http.server`, tên là route pattern (không có query).
- **Span HTTP:** `MatbaoClient::request()` (endpoint thật: `/api/auth/login`, `/api/invoice/templates`, `/api/invoice/create-invoice`, `/api/invoice/invoice-detail-batch`) bọc `Monitor::span('http.client', "<METHOD> <path>")` với data gồm status, `matbao.error_code` và thời gian. `LarkClient::send_text()` dùng span `http.client` mô tả `POST lark-webhook`, **không** chứa URL vì path webhook chứa token. Không có body.
- **Issues:**
  - Mọi chỗ đang `error_log` gọi thêm `Monitor::capture_exception($e, tags)`.
  - `DraftInvoiceWorkflow::send()` catch `\Throwable` capture kèm tag `order_id`.
  - `InvoiceAlerts::on_needs_attention` gọi `Monitor::capture_needs_attention()`: event mức `warning`, message `Invoice needs attention: <error_code>`, fingerprint `['needs-attention', <error_code>]`, tags `order_id`, `error_code`, `error_kind`, `invoice.status`. Hàm được gọi sau dedup của `AlertStore` nên mỗi (đơn, lỗi, chu kỳ) chỉ gửi một lần.
- **Logs:** `InvoiceRepository` gọi `Monitor::log('info', 'invoice status changed', {invoice.id, from, to, error_code})` sau mỗi lần đổi trạng thái thành công. Mỗi `error_log` có thêm log mức `error`. Log được buffer và flush ở `shutdown` và khi transaction đóng.
- **Cron monitor:** `ActionSchedulerTracing` gửi check-in khi hook là `einvoice_matbao_recover_stuck_invoices`: `in_progress` ở `action_scheduler_before_execute`, `ok` ở `after_execute`, `error` ở `failed_execution`, cùng `check_in_id`, kèm duration. Không sửa `DraftInvoiceWorkflow::sweep()`; test gây lỗi bằng một callback ném lỗi gắn vào chính hook đó. Slug là `einvoice-matbao-recovery-<host16>-<sha1(host)[0:8]>`: host viết thường, ký tự ngoài `[a-z0-9]` thành `-`, cắt còn 16 ký tự; hash luôn có nên hai host chuẩn hóa trùng nhau vẫn khác slug, tổng tối đa 50 ký tự. `monitor_config` gồm interval 15 phút, `checkin_margin` 15, `max_runtime` 10, `failure_issue_threshold` 2.

### D5. Lọc dữ liệu (`Scrubber`)
- Áp dụng cho `before_send`, `before_send_transaction`, `before_send_log`, `before_send_check_in` và `before_breadcrumb`, trên mọi chuỗi (message, exception value, span description/data, log body/attribute, breadcrumb).
- **Lớp 1, giá trị đã biết:** `Monitor::with_order_context($order_id, callable)` (bọc `DraftInvoiceWorkflow::send()`, `OrderChangeWatcher::check()`, action alert) nạp các giá trị người mua của đơn (buyer snapshot đã lưu, billing/shipping name, company, email, phone, address_1/2, MST, CCCD giải mã) vào một danh sách redact trong bộ nhớ; Scrubber thay mọi lần xuất hiện (không phân biệt hoa thường, giá trị ≥ 3 ký tự) bằng `[filtered]`. Context được xóa khi callable kết thúc; sự kiện được lọc ngay lúc capture (không đợi flush) để context còn hiệu lực. Đây là lớp chính cho tên/địa chỉ/công ty vốn không bắt được bằng regex.
- **Lớp 2, mẫu:** xem dưới.
- Trên message, exception value, span description, log body và attribute dạng chuỗi, thay các mẫu sau bằng `[filtered]`:
  - email;
  - URL (giữ scheme+host đối với host của Mắt Bão, bỏ path/query; mọi URL khác bị bỏ hẳn);
  - dãy đúng 12 chữ số (CCCD);
  - MST `\b\d{10}(-\d{3})?\b`;
  - `Bearer \S+`;
  - chuỗi liền `[A-Za-z0-9+/=_-]{32,}` có ít nhất một chữ số (chuỗi không có chữ số như tên hook `einvoice_matbao_recover_stuck_invoices` là định danh code, giữ nguyên).
- Xóa `vars` của mọi frame trong stacktrace; xóa `request`, `user`, `server_name`, `contexts.runtime`/`os` nếu có `hostname`.
- Danh sách cho phép đối với tags: `site`, `order_id`, `invoice.id`, `invoice.status`, `error_code`, `error_kind`, `matbao.env`, `component`. Tag ngoài danh sách bị bỏ.
- Lý do chọn che theo mẫu cộng allowlist tag thay vì bỏ hẳn message: message của Mắt Bão là manh mối chính để debug, còn spec `invoice-retry-dedup` đã có tiền lệ che CCCD.

### D6. Chống làm chậm luồng hóa đơn
- `WpHttpClient` dùng timeout ngắn. Mọi thất bại (transport error, timeout, HTTP ngoài 2xx, kể cả 400/401/403 do DSN sai) bật cờ trong request và ghi option `einvoice_matbao_sentry_backoff_until` (timestamp, autoload=no; dùng option thay transient để không phụ thuộc object cache). Thời gian ngừng: 60 giây; riêng 429 có `Retry-After` là số giây nguyên dương thì dùng giá trị đó, kẹp tối đa 600; `Retry-After` dạng ngày, thiếu hoặc sai dùng 60. Dữ liệu trong thời gian ngừng bị bỏ (không xếp hàng). Option này được xóa khi gỡ cài đặt cùng cache của plugin.
- Mọi callback và hook của Monitoring đều bọc `try/catch`. Lỗi của chính Monitoring chỉ được `error_log` tên class, không gửi đi.

### D7. Tab admin `monitoring`
- `AdminTabs::labels()` thêm `'monitoring' => 'Giám sát lỗi'` trước `advanced`. `SettingsPage` render panel; `SettingsController` thêm `POST /settings/monitoring` (lưu enabled, DSN, sample rate; ô DSN để trống thì giữ DSN cũ; khi hằng đặt DSN thì ô DSN hiện readonly với nhãn "Đặt trong wp-config") và `POST /settings/monitoring/test` (gửi một event mức `info` "einvoice-matbao test event", trả về event ID hoặc lỗi bằng tiếng Việt, không lộ DSN).
- DSN không bao giờ được render lại; UI chỉ hiện host và project id.

## Risks / Trade-offs

- [PHP-Scoper bỏ sót symbol nạp động (ví dụ chuỗi tên class trong SDK) → fatal trong zip] → smoke-zip kích hoạt plugin, gửi event thử tới một sink giả, và chạy với mu-plugin nạp sẵn Sentry/Guzzle không scope.
- [Rustrak hoặc mạng chậm làm chậm Action Scheduler hoặc request admin] → timeout 3 giây, circuit breaker theo request và backoff 60 giây (D6).
- [Site ít traffic: WP-Cron chạy trễ dẫn tới cron monitor báo nhầm] → `checkin_margin` 15 phút và `failure_issue_threshold` 2; ghi chú trong tài liệu khuyến nghị dùng system cron.
- [Mẫu lọc che nhầm số đơn dài hoặc bỏ sót dữ liệu cá nhân dạng lạ] → giữ allowlist tag; test Scrubber với dữ liệu người mua thật từ fixture; số đơn chỉ đi qua tag `order_id`, không qua message.
- [Zip nặng thêm khoảng 1–1.5 MB] → chấp nhận được.
- [Dev/test chạy bản chưa scope, khác bản phát hành] → smoke-zip là cổng kiểm tra bản scope; CI release đã chạy smoke-zip.

## Migration Plan

- Mặc định tắt, nên nâng cấp plugin không đổi hành vi. Admin bật trong tab mới hoặc đặt hằng.
- Rollback: tắt công tắc, hoặc hạ phiên bản plugin. Không có migration dữ liệu; DSN nằm trong option credential và bị xóa khi gỡ cài đặt như credential khác.

## Open Questions

- Không còn câu hỏi chặn. Version và checksum của phar PHP-Scoper được chốt khi viết plan.
