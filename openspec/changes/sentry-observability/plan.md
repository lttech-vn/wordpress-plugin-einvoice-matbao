# Sentry Observability Implementation Plan

> Executed via `/opsx-apply`, which chooses the executor.
> Spec reference: this change's `specs/` (`error-monitoring`, `admin-settings`) and `design.md`.

**Goal:** Plugin gửi issue, transaction/span, log và cron check-in của riêng nó tới một server Sentry-compatible (Rustrak), bật/tắt và nhập DSN trong tab admin mới, không bao giờ gửi dữ liệu người mua.

**Architecture:** Module mới `src/Monitoring/`. Code nghiệp vụ chỉ gọi facade tĩnh `Monitor`. Chỉ `SentryBackend` và `WpHttpClient` tham chiếu `Sentry\…`; client chạy trên Hub riêng, không có default integrations, gửi qua `wp_remote_post`, và lọc mọi payload bằng `Scrubber` thuần. Zip phát hành scope vendor bằng PHP-Scoper.

**Tech Stack:** PHP 8.2+, WordPress 6.6+/WooCommerce 9.8+, `sentry/sentry` ^4.36, Action Scheduler, htmx admin (ADR 0003), PHPUnit 9 + `FakeHttp`, PHP-Scoper (phar, chỉ trong build).

## Global Constraints

- Sàn PHP 8.2 / WP 6.6 / WC 9.8; trần PHP 8.5 / WP 7.1 / WC 11.2; `composer check` phải đạt trên cả hai tổ hợp (`docker/min.env`).
- Code comments/docblock, identifier, commit viết tiếng Anh; chuỗi UI tiếng Việt qua text domain `einvoice-matbao`; tài liệu `docs/` viết tiếng Việt.
- PHPCS WordPress ruleset, PHPStan level 8; không dùng `error_log` mới ngoài chỗ đã có và lỗi nội bộ của Monitoring (chỉ tên class).
- Mọi HTTP ra ngoài qua `wp_remote_*` (test chặn bằng `tests/integration/Support/FakeHttp.php`).
- Không thêm dev dependency Composer cho PHP-Scoper; phar ghim version + sha256 trong `scripts/`.
- Mọi điểm đo được bọc `try/catch \Throwable`; Monitoring không bao giờ ném lỗi ra caller.
- Lệnh chạy test (trong stack Docker): `docker compose exec -T -w /var/www/html/wp-content/plugins/einvoice-matbao wordpress composer test:integration -- --filter <Name>`; viết tắt bên dưới là `ctest <Name>`.

## Review Focus

- DSN dán có khoảng trắng/xuống dòng ở đầu cuối → trim trước khi validate; test trong Task 1 (`SentryDsnTest::test_trims_whitespace`).
- Host site có cổng hoặc IDN (`shop.vn:8443`, `cửahàng.vn`) → slug vẫn hợp lệ `[a-z0-9-]` và ổn định; test trong Task 1 (`MonitorSlugTest::test_port_and_idn_hosts`).
- Giá trị người mua ngắn hoặc phổ biến (tên 2 ký tự, "Hà Nội") → chỉ redact giá trị ≥ 3 ký tự, không làm vỡ chuỗi khác; test trong Task 1 (`ScrubberTest::test_short_known_values_are_ignored`).
- Server giám sát trả 200 nhưng chậm gần timeout trong action tạo nháp → action vẫn hoàn tất và hóa đơn đúng trạng thái; test trong Task 4 (`WorkflowMonitoringTest::test_slow_sink_success_keeps_invoice_flow`) cùng `WpHttpClientTest::test_timeout_values_are_passed`.
- Sweep ném exception giữa chừng → check-in `error` vẫn được gửi và exception vẫn lan ra Action Scheduler như trước; test trong Task 5 (`RecoveryMonitorTest::test_failed_sweep_reports_error_and_rethrows`).

---

## Contracts

| Name | new / modified | Shape | public |
|---|---|---|---|
| `EINVOICE_MATBAO_SENTRY_DSN` | new | hằng wp-config, `string` DSN `{http\|https}://<public_key>@<host>[:port]/<path/>?<project_id:digits>`; hợp lệ thì thắng DSN UI, sai thì bị bỏ qua kèm cảnh báo | yes |
| `EINVOICE_MATBAO_SENTRY_ENABLED` | new | hằng wp-config, `bool`; được định nghĩa thì thắng công tắc UI | yes |
| `POST einvoice-matbao/v1/settings/monitoring` | new | params `enabled` (`'1'`\|absent), `dsn` (string, trống = giữ), `traces_sample_rate` (numeric string 0..1); phản hồi HTML fragment `data-einv-status` | yes |
| `POST einvoice-matbao/v1/settings/monitoring/test` | new | không param; fragment success chứa event ID 32 hex, hoặc error | yes |
| Admin tab key `monitoring` | new | `AdminTabs::labels()` thứ tự `invoices, connection, invoicing, lark, monitoring, advanced`; nhãn `Giám sát lỗi`; `GET einvoice-matbao/v1/tabs/monitoring` | yes |
| `einvoice_matbao_settings` keys | modified | thêm `monitoring_enabled` (`'1'`\|`''`), `traces_sample_rate` (string số, mặc định `'1'`) | no |
| `einvoice_matbao_credentials['sentry']` | modified | bản mã của JSON `{"dsn": string}` | no |
| `einvoice_matbao_sentry_backoff_until` | new | option int unix timestamp, autoload `no`; xóa khi uninstall | no |
| `Monitoring\SentryDsn` | new | `final class { public static function parse(string $raw): ?self; public readonly string $host; public readonly int $project_id; public function value(): string; public function label(): string /* "host · project N" */ }` | no |
| `Monitoring\MonitorSlug` | new | `final class { public static function for_host(string $host): string }` → `einvoice-matbao-recovery-<host16>-<sha1(strtolower(host))[0:8]>` (prefix `einvoice-matbao-recovery-` 25 ký tự + host ≤ 16 + `-` + hash 8 ≤ 50) | no |
| `Monitoring\Redactor` | new | `interface { public function text(string $s): string; public function value(mixed $v): mixed; public function tags(array $tags): array; public function with_known_values(array $values): static; }` | no |
| `Monitoring\Scrubber` | new | `final class implements Redactor { public const FILTERED = '[filtered]'; public const TAG_ALLOWLIST = ['site','order_id','invoice.id','invoice.status','error_code','error_kind','matbao.env','component']; public function __construct(array $known_values = []); public function with_known_values(array $values): self; public function text(string $s): string; public function value(mixed $v): mixed; public function tags(array $tags): array }` | no |
| `Monitoring\MonitoringOverrides` | new | `final class { public static function allowed(bool $tests_domain_defined, string $environment_type): bool }` — true khi `$tests_domain_defined` hoặc env ∈ {`local`,`development`} | no |
| `Monitoring\MonitoringConfig` | new | `final class { public static function current(): self; public readonly bool $enabled; public readonly ?SentryDsn $dsn; public readonly string $dsn_source /* 'constant'\|'ui'\|'none' */; public readonly bool $dsn_constant_invalid; public readonly bool $stored_dsn_unreadable; public readonly bool $enabled_from_constant; public readonly float $traces_sample_rate; public readonly string $environment; public readonly string $release; public readonly string $site; public function active(): bool }` | no |
| `Monitoring\Backend` | new | `interface { capture_exception(\Throwable $e, array $tags, array $known): ?string; capture_message(string $msg, string $level, array $tags, array $fingerprint, array $known): ?string; start_transaction(string $name, string $op, array $known): void; finish_transaction(string $status): void; span(string $op, string $description, callable $callback, callable $data_of_result, array $known): mixed; log(string $level, string $msg, array $attrs, array $known): void; check_in(string $slug, string $status, ?string $id, ?float $duration, array $schedule): ?string; flush(): void }` — Task 3: `span()` và `start_transaction()` nhận thêm `$known` để lọc description/data ngay khi mở span (transaction gửi sau, lúc order context có thể đã đóng) | no |
| `Monitoring\CallOutcome` | new | `final class { public function run(callable $callback): mixed; public function has_run(): bool; public function is_failure(\Throwable $e): bool; public function replay(): mixed }` — Task 3: ghi kết quả/exception của callable để `Monitor::span()` không gọi lại và không nuốt exception nghiệp vụ khi backend lỗi sau callable | no |
| `Monitoring\LogAttributes` | new | `final class { public static function normalize(array $attrs, Redactor $r): array<string, bool\|int\|float\|string> }` — Task 3 fix: đưa attribute log/span về dạng cuối (Stringable → string, object/array → JSON, quá sâu/không biểu diễn được → `[filtered]`) rồi mới lọc, kể cả key | no |
| `Monitoring\InternalFailure` | new | `final class { public static function report(\Throwable $e): void }` — Task 3 fix: chính sách duy nhất `error_log('einvoice-matbao: monitoring failed (<class>).')` dùng chung cho Monitor và SentryBackend | no |
| `Monitoring\TraceRedaction` | new | `final class { public function __construct(array $configured); public function trace_context(array $trace, Redactor $r): array; public function dsc_entry(string $key, string $value, Redactor $r): string }` — Task 3 fix round 2: lọc `contexts.trace`/DSC nhưng giữ định danh giao thức đúng dạng (trace_id 32 hex, span_id/parent_span_id 16 hex, sample_rate/rand, sampled, release/environment/public_key theo cấu hình) | no |
| `Monitoring\Monitor` | new | static: `capture_exception(\Throwable $e, array $tags = []): ?string`, `capture_needs_attention(InvoiceRecord $i, InvoiceErrorCode $c): void`, `log(string $level, string $msg, array $attrs = []): void`, `start_transaction(string $name, string $op): void`, `finish_transaction(string $status = 'ok'): void`, `span(string $op, string $desc, callable $callback, ?callable $data_of_result = null): mixed`, `check_in(string $status, ?string $id = null, ?float $duration = null): ?string`, `with_order_context(int $order_id, callable $callback): mixed`, `send_test_event(): ?string`, `flush(): void`, `reset_for_tests(?Backend $b = null): void`, `is_active(): bool` (Task 5: chỉ đọc `MonitoringConfig`, không tải Sentry, cache theo request). Ngữ nghĩa (Task 5: `start_transaction`/`finish_transaction` đếm độ sâu — chỉ cặp ngoài cùng tới backend, finish ở depth 0 là no-op): chỉ lỗi instrumentation bị nuốt; `span()` và `with_order_context()` gọi `$callback` đúng một lần, trả giá trị của nó, ném lại nguyên vẹn exception của `$callback` (span vẫn được đóng với status `internal_error`) (Task 3: đổi tên tham số `$fn` → `$callback` theo WPCS `NoReservedKeywordParameterNames`) | no |
| `Monitoring\CaughtFailure` | new | `final class { public static function report(\Throwable $failure, string $component, string $what, ?int $order_id = null): void }` — Task 4: một chỗ duy nhất cho mọi catch đang `error_log` (issue có tag `component`/`order_id` + log `error` có `exception.type`), chạy trong `with_order_context` của đơn khi biết vì catch thường nằm ngoài context của caller | no |
| `Invoice\InvoiceRepository::update_status` / `record_error` | modified | thêm tham số cuối tùy chọn `?string $from = null`: `update_status(int $invoice_id, string $status, string $reason = '', array $fields = array(), ?string $from = null): void`, `record_error(int $invoice_id, InvoiceErrorCode $code, string $reason, ?string $status = null, ?string $from = null): void` — Task 4 fix round 1: `from` của log `invoice status changed` lấy từ trạng thái caller đang giữ (vd. `sending` sau claim), `null` → `unknown`; không bao giờ SELECT lại (không thêm query khi tắt giám sát, không race) | no |
| `Monitoring\SentryBackend` | new | `final class implements Backend { public function __construct(MonitoringConfig $config, Redactor $redactor, ?Sentry\HttpClient\HttpClientInterface $http = null) }`; dùng `Sentry\Options` + `ClientBuilder(new Options([...]))`, `Options::setHttpClient()`, `new Sentry\State\Hub($client)`, `$hub->captureCheckIn($slug, CheckInStatus, $duration, new MonitorConfig(MonitorSchedule::interval(15, MonitorScheduleUnit::minute()), 15, 10, 'UTC', 2, 1), $id)`; logs: buffer riêng `list<Sentry\Logs\Log>` (≤ 100) dựng bằng `new Log($ts, $trace_id, LogLevel::x(), $body)`, gửi bằng `$hub->captureEvent(Event::createLogs()->setLogs($buffer))`; không dùng `Sentry\Logs\Logs`/`LogsAggregator` (design D1) | no |
| `Monitoring\WpHttpClient::sendRequest` | new | `public function sendRequest(Sentry\HttpClient\Request $request, Sentry\Options $options): Sentry\HttpClient\Response` — POST `$options->getDsn()->getEnvelopeApiEndpointUrl()`, body `$request->getStringBody()`, header `Content-Type: application/x-sentry-envelope`, `X-Sentry-Auth: Sentry sentry_version=7, sentry_client=<sdk>/<ver>, sentry_key=<public key>`; trả `new Response($status, $headers, $error)` | no |
| `tests\…\Support\FakeHttp` | modified | thêm `raw_requests(): list<array{method,url,args,body:string\|null}>` (args đầy đủ gồm `timeout`, `redirection`; body thô chưa decode) | no |
| `tests\…\Support\SentrySink` | new | `final class { public static function install(FakeHttp $http, string $host = 'sentry.test', int $project = 3): self; public function respond_with(int $status, array $headers = []): void; public function fail_transport(): void; public function events(): array; public function transactions(): array; public function logs(): array; public function check_ins(): array; public function request_count(): int }` — parse envelope (header JSON + từng item header/payload) | no |
| `Monitoring\WpHttpClient` | new | `final class implements Sentry\HttpClient\HttpClientInterface { public const TIMEOUT = 3.0; public const CONNECT_TIMEOUT = 2.0; public const BACKOFF_SECONDS = 60; public const MAX_RETRY_AFTER = 600; public function __construct(?callable $now = null) }` | no |
| `CredentialStore` | modified | thêm `sentry_dsn(): ?string`, `has_sentry_dsn(): bool`, `save_sentry_dsn(string $dsn): void` (throws `MissingEncryptionKey`) | no |
| `Settings` | modified | thêm `monitoring_enabled(): bool`, `traces_sample_rate(): float`, `save_monitoring(bool $enabled, float $rate): void` | no |
| `composer.json` | modified | `require."sentry/sentry": "^4.36"`, `config."platform-check": false` | no |

---

## Task 1: Pure building blocks (DSN, slug, scrubber) — implements 1.1, 1.2, 1.3, 1.4

Blocked by: none · Difficulty: Normal

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `src/Monitoring/SentryDsn.php`, `src/Monitoring/MonitorSlug.php`, `src/Monitoring/Redactor.php`, `src/Monitoring/Scrubber.php`
- Test: `tests/integration/Monitoring/SentryDsnTest.php`, `tests/integration/Monitoring/MonitorSlugTest.php`, `tests/integration/Monitoring/ScrubberTest.php`

**Interfaces:**
- Produces: `SentryDsn`, `MonitorSlug`, `Redactor`, `Scrubber` (xem Contracts).

- [ ] **Step 1:** `composer require sentry/sentry:^4.36` trong container, đặt `config.platform-check=false`; chạy `composer analyse` và `composer lint` → đạt (vendor chưa bị scan).
- [ ] **Step 2: Failing tests `SentryDsnTest`**
  - `test_parses_valid_dsn`: `parse('https://abc123@rustrak.ltt.onl/3')` → `host === 'rustrak.ltt.onl'`, `project_id === 3`, `label() === 'rustrak.ltt.onl · project 3'`.
  - `test_trims_whitespace`: `parse("  https://k@h.test/7\n")` không null.
  - `test_rejects_invalid` (data provider): `'not-a-dsn'`, `'ftp://k@h/1'`, `'https://h.test/1'` (thiếu key), `'https://k@h.test/abc'`, `''` → `null`.
  - `test_label_never_contains_key`: `label()` không chứa `abc123`.
- [ ] **Step 3:** Implement `SentryDsn` bằng `wp_parse_url`; project id là đoạn path cuối, toàn chữ số.
- [ ] **Step 4: Failing tests `MonitorSlugTest`**
  - `test_short_host`: `for_host('shop.example.vn') === 'einvoice-matbao-recovery-shop-example-vn-' . substr(sha1('shop.example.vn'), 0, 8)` (15 ký tự host, không cắt).
  - `test_collision_is_avoided`: `for_host('a.b.example') !== for_host('a-b.example')`.
  - `test_long_host`: host 80 ký tự → `strlen <= 50` và kết thúc bằng `'-' . substr(sha1($host), 0, 8)`.
  - `test_port_and_idn_hosts`: `'shop.vn:8443'` và `'cửahàng.vn'` → khớp `/^einvoice-matbao-recovery-[a-z0-9-]+-[0-9a-f]{8}$/`.
- [ ] **Step 5:** Implement `MonitorSlug::for_host()`: `strtolower`, thay `[^a-z0-9]+` bằng `-`, trim `-`, cắt 16, trim `-` lần nữa, nối `-` + hash.
- [ ] **Step 6: Failing tests `ScrubberTest`**
  - `test_masks_patterns`: `text('login failed for 0312345678-001 with Bearer abc.def')` === `'login failed for [filtered] with [filtered]'`; email, `'012345678901'`, chuỗi 40 ký tự `[A-Za-z0-9]` đều thành `[filtered]`.
  - `test_urls`: `'see https://hooks.lark.example/hook/xyz?a=1'` → `'see [filtered]'`; URL host Mắt Bão (`https://api-demo.matbao.in/api/x?y`) → `'https://api-demo.matbao.in'`.
  - `test_known_values_case_insensitive`: `with_known_values(['Nguyễn Văn A', '12 Lê Lợi, Q1'])->text('Buyer NGUYỄN VĂN A at 12 lê lợi, q1')` không chứa hai giá trị.
  - `test_short_known_values_are_ignored`: known `['A', 'Hà']` → `text('Hà Nội A')` giữ nguyên.
  - `test_value_recurses`: `value(['a' => ['b' => 'x@y.vn']])` → `['a' => ['b' => '[filtered]']]`.
  - `test_tags_allowlist`: `tags(['site'=>'s','foo'=>'x','order_id'=>'5'])` → `['site'=>'s','order_id'=>'5']`.
  - Fixture: dùng dữ liệu người mua từ `tests/fixtures` hiện có (buyer snapshot doanh nghiệp + cá nhân).
- [ ] **Step 7:** Implement `Scrubber`. Host Mắt Bão lấy từ `MatbaoCredentials` base URL constants (demo + production); known values được redact trước, sau đó mới đến mẫu.
- [ ] **Step 8:** `ctest Monitoring` → PASS; `composer lint && composer analyse` → đạt.
- [ ] **Step 9:** Commit `feat(monitoring): add DSN, monitor slug and scrubber`.

---

## Task 2: Configuration and storage — implements 2.1, 2.2, 2.3

Blocked by: Task 1 · Difficulty: Normal

**Files:**
- Modify: `src/CredentialStore.php`, `src/Settings.php`, `src/Uninstall/Uninstaller.php`
- Create: `src/Monitoring/MonitoringConfig.php`, `src/Monitoring/MonitoringOverrides.php`
- Test: `tests/integration/Monitoring/MonitoringConfigTest.php`, `tests/integration/Monitoring/MonitoringOverridesTest.php`, `tests/integration/UninstallTest.php` (thêm case)

**Interfaces:**
- Consumes: `SentryDsn::parse`, `MonitorSlug`.
- Produces: `CredentialStore::sentry_dsn/has_sentry_dsn/save_sentry_dsn`, `Settings::monitoring_enabled/traces_sample_rate/save_monitoring`, `MonitoringConfig::current()`.

- [ ] **Step 1: Failing tests `MonitoringConfigTest`** (seam: `MonitoringConfig::current()` đọc hằng qua `defined()`; test dùng filter `einvoice_matbao_monitoring_constants` và `einvoice_matbao_monitoring_mbstring`, chỉ được áp khi `MonitoringOverrides::allowed(defined('WP_TESTS_DOMAIN'), wp_get_environment_type())`)
  - `MonitoringOverridesTest::test_matrix` (data provider): `(false,'production')→false`, `(false,'staging')→false`, `(false,'local')→true`, `(false,'development')→true`, `(true,'production')→true`.
  - `test_defaults_off`: không cấu hình → `enabled === false`, `active() === false`, `traces_sample_rate === 1.0`.
  - `test_enabled_without_dsn_is_inactive`.
  - `test_ui_dsn_used`: `save_monitoring(true, 0.5)` + `save_sentry_dsn(valid)` → `active()`, `dsn_source === 'ui'`, rate 0.5.
  - `test_dsn_constant_wins`, `test_invalid_dsn_constant_falls_back` (`dsn_constant_invalid === true`, dùng DSN UI), `test_enabled_constant_false_wins`, `test_dsn_constant_alone_does_not_enable`.
  - `test_undecryptable_stored_dsn`: lưu DSN, đổi khóa → `dsn === null`, `stored_dsn_unreadable === true`.
  - `test_missing_mbstring_is_inactive`.
  - `test_site_and_release`: `site === wp_parse_url(home_url(), PHP_URL_HOST)` viết thường; `release === 'einvoice-matbao@' . EINVOICE_MATBAO_VERSION`.
  - `test_stored_ciphertext`: option `einvoice_matbao_credentials` không chứa chuỗi DSN rõ.
- [ ] **Step 2:** Implement `CredentialStore` (set `sentry`, field `dsn`), `Settings` (rate lưu dạng string, đọc kẹp `[0,1]`, giá trị không phải số → 1.0), `MonitoringConfig`.
- [ ] **Step 3: Failing test `UninstallTest::test_default_uninstall_removes_sentry_dsn_and_backoff`**: lưu DSN + option backoff, uninstall mặc định → không còn DSN (option credential bị xóa) và không còn `einvoice_matbao_sentry_backoff_until`; `monitoring_enabled` trong settings còn.
- [ ] **Step 4:** `Uninstaller::uninstall_site()` xóa thêm `einvoice_matbao_sentry_backoff_until` ở nhánh mặc định.
- [ ] **Step 5:** `ctest MonitoringConfig`, `ctest Uninstall`, `composer test:without-woocommerce` → PASS.
- [ ] **Step 6:** Commit `feat(monitoring): store monitoring settings and encrypted DSN`.

---

## Task 3: Sentry backend, HTTP client and facade — implements 3.1, 3.2, 3.3, 3.4

Blocked by: Task 2 · Difficulty: Hard

**Files:**
- Create: `src/Monitoring/Backend.php`, `src/Monitoring/WpHttpClient.php`, `src/Monitoring/SentryBackend.php`, `src/Monitoring/Monitor.php`, `src/Monitoring/OrderRedaction.php`
- Modify (test support): `tests/integration/Support/FakeHttp.php` (thêm `raw_requests()`)
- Create (test support): `tests/integration/Support/SentrySink.php` (cài `FakeHttp` route cho `https://sentry.test/api/3/envelope/`, parse envelope thành list item `{type, payload}`; helper `events()`, `transactions()`, `logs()`, `check_ins()`, `respond_with(int $status, array $headers)`)
- Test: `tests/integration/Monitoring/WpHttpClientTest.php`, `tests/integration/Monitoring/MonitorTest.php`, `tests/integration/Monitoring/MonitorIsolationTest.php`

**Interfaces:**
- Consumes: `MonitoringConfig`, `Scrubber`.
- Produces: `Monitor` static API, `Backend` interface, `SentrySink` test helper; `OrderRedaction::values_for(int $order_id): list<string>` (tên/công ty/email/phone/address_1/address_2 billing+shipping, các field của buyer snapshot đã lưu, MST, CCCD giải mã).

- [ ] **Step 0: SDK contract gate.** Với `sentry/sentry` đã khóa ở Task 1, đọc `vendor/sentry/sentry/src/{Options.php,ClientBuilder.php,State/Hub.php,HttpClient/*.php,Logs/*.php,MonitorConfig.php,MonitorSchedule.php}` và xác nhận từng signature trong Contracts (`SentryBackend`, `WpHttpClient::sendRequest`). Signature lệch thì sửa Contracts trong plan và báo parent trước khi viết code.
- [ ] **Step 1: Failing tests `WpHttpClientTest`** (clock seam `new WpHttpClient(fn() => $now)`)
  - `test_timeout_values_are_passed`: `FakeHttp::raw_requests()` có `args.timeout === 3.0` và `args.redirection === 0`.
  - `test_failure_opens_breaker_for_request`: sink trả 503 → lần gửi thứ hai trong cùng instance không tạo request.
  - `test_backoff_across_requests`: 503 tại t, instance mới tại t+30 → không request; t+61 → có request.
  - `test_retry_after`: 429 `Retry-After: 120` → t+119 không gửi, t+121 gửi; `Retry-After: 99999` → gửi lại từ t+601; `Retry-After: Wed, 21 Oct` → 60.
  - `test_4xx_also_backs_off`: 401 → backoff 60.
- [ ] **Step 2:** Implement `WpHttpClient::sendRequest()` dùng `wp_remote_post($dsn->getEnvelopeApiEndpointUrl(), …)` với header từ `Request`/`Options` (`Content-Type: application/x-sentry-envelope`, `X-Sentry-Auth`), trả `Sentry\HttpClient\Response`; lỗi transport trả `Response(0, [], error_code)`; breaker theo instance + option `einvoice_matbao_sentry_backoff_until` (autoload no).
- [ ] **Step 3: Failing tests `MonitorTest`** (dùng `SentrySink`, cấu hình DSN `https://k@sentry.test/3`)
  - `test_disabled_sends_nothing_and_loads_no_sdk`: tắt → `capture_exception` trả null, FakeHttp không ghi request, `class_exists('Sentry\\ClientBuilder', false) === false` (chạy trong process riêng: `@runInSeparateProcess`).
  - `test_capture_exception_scrubbed`: exception message có email/MST/CCCD → event `exception.values[0].value` không chứa; event không có `server_name`, `request`, `user`; mọi frame không có `vars`; tag `site` có mặt, tag lạ bị bỏ.
  - `test_with_order_context_redacts_known_values`: tạo đơn có billing name/company/address; `with_order_context($id, fn() => Monitor::capture_exception(new RuntimeException("x {$address}")))` → event không chứa address; sau callable, context đã xóa.
  - `test_log_attribute_scrubbed`: `log('info','m',['e'=>'a@b.vn'])` + `flush()` → log attribute `[filtered]`.
  - `test_transaction_and_span`: `start_transaction('t','queue.task')`, `span('http.client','POST /api/x', fn()=>1, fn()=>['http.response.status_code'=>200])`, `finish_transaction()` với rate 1.0 → một transaction tên `t` có span `POST /api/x`; rate 0 → không transaction.
  - `test_check_in`: `check_in('ok', null, 1.5)` → item `check_in` có `monitor_slug === MonitorSlug::for_host(site)`, `monitor_config.schedule` `{type: interval, value: 15, unit: minute}`, `checkin_margin` 15, `max_runtime` 10, `failure_issue_threshold` 2.
  - `test_internal_error_is_swallowed`: inject backend ném lỗi qua `reset_for_tests()` → `capture_exception` trả null, không ném.
  - `test_redactor_failure_drops_event`: `SentryBackend` với `Redactor` ném lỗi → sink không nhận event, caller không thấy exception.
  - `test_span_runs_callable_once_and_rethrows`: `span()` với callable đếm số lần gọi và ném `DomainException` → đếm = 1, `DomainException` lan ra; khi backend ném trong lúc mở span thì callable vẫn chạy và giá trị trả về giữ nguyên.
  - `test_with_order_context_returns_value_and_rethrows`.
  - `test_dsn_routing`: DSN constant `https://k2@sentry.test/4` (qua override filter) và DSN UI project 3 → request tới `/api/4/envelope/`.
  - `test_send_test_event_returns_id`: sink 200 → trả 32 hex; event level `info`, message `einvoice-matbao test event`.
- [ ] **Step 4:** Implement `SentryBackend` (ClientBuilder options: dsn, environment, release, `default_integrations` false, `integrations` [], `send_default_pii` false, `server_name` '', `enable_logs` true, `traces_sample_rate`, `http_client` WpHttpClient, `before_send`/`before_send_transaction`/`before_send_log`/`before_send_check_in`/`before_breadcrumb` → Scrubber; tạo `new Hub($client)` riêng, `SentrySdk` global không bị đụng; scope tag `site`). Scrub ngay khi capture bằng snapshot known values hiện tại của `Monitor`.
- [ ] **Step 5:** Implement `Monitor` (lazy backend từ `MonitoringConfig::current()->active()`, mọi method bọc try/catch, lỗi nội bộ `error_log('einvoice-matbao: monitoring failed (' . get_class($e) . ').')`, `add_action('shutdown', [Monitor::class,'flush'], 100)` một lần khi backend được tạo; `with_order_context` lồng được bằng stack).
- [ ] **Step 6: Failing tests `MonitorIsolationTest`**
  - `test_global_hub_untouched`: đặt `SentrySdk::setCurrentHub(new Hub($site_client))` với site client có transport giả; plugin capture → transport giả không nhận gì; `SentrySdk::getCurrentHub()` vẫn là hub đó.
  - `test_site_logs_untouched`: Hub site có client `enable_logs` + transport giả; `\Sentry\logger()->info('site log')` (buffer của site); plugin `log('info','p')` + `Monitor::flush()` → sink plugin chỉ có log `p`, transport site không nhận gì, `\Sentry\Logs\Logs::getInstance()->aggregator()->all()` vẫn chứa `site log`, `SentrySdk::getCurrentHub()` vẫn là Hub site.
  - `test_unrelated_warning_not_captured`: bật giám sát, `trigger_error('x', E_USER_WARNING)` (với handler test nuốt) → sink không có event; `set_error_handler`/`set_exception_handler` trước và sau khi Monitor init giống nhau.
- [ ] **Step 7:** `ctest Monitoring`, `composer lint && composer analyse` → đạt.
- [ ] **Step 8:** Commit `feat(monitoring): add isolated Sentry backend and monitor facade`.

---

## Task 4: Issues, logs and HTTP spans in business code — implements 4.1, 4.2, 4.4, 4.5

Blocked by: Task 3 · Difficulty: Normal

**Files:**
- Modify: `src/Invoice/DraftInvoiceWorkflow.php` (send ~L289-306, L676-678, L744-746, L788-790), `src/Invoice/OrderChangeWatcher.php` (flush ~L84-94, L228-231, check wrap), `src/Alert/InvoiceAlerts.php` (on_needs_attention, send), `src/Invoice/InvoiceRepository.php` (claim, claim_unchanged, restart, update_status), `src/Matbao/MatbaoClient.php` (request), `src/Lark/LarkClient.php` (send_text)
- Test: `tests/integration/Monitoring/WorkflowMonitoringTest.php`

**Interfaces:**
- Consumes: `Monitor::capture_exception`, `capture_needs_attention`, `log`, `span`, `with_order_context`.

- [ ] **Step 1: Failing tests `WorkflowMonitoringTest`** (extends `InvoiceWorkflowTestCase`, giám sát bật tới `SentrySink`)
  - `test_unexpected_error_reported`: route FakeHttp create-invoice ném `RuntimeException('token ' . str_repeat('aB3', 12) . ' at https://internal.example/path')` (cùng seam với `DraftInvoiceWorkflowTest::test_unexpected_error_after_claiming_…`; token 36 ký tự khớp mẫu ≥ 32) → sink có đúng 1 event level `error` có stacktrace, tag `order_id`, `component=draft_workflow`, exception value không chứa token lẫn `internal.example`; event `warning` needs-attention tính riêng; record vẫn `failed` với `unexpected`.
  - `test_needs_attention_once_per_alert`: hết retry (cả hai hook phát) → đúng 1 event level `warning`, fingerprint `['needs-attention','<code>']`, tag `error_code`, `error_kind`, `invoice.status`.
  - `test_same_code_two_orders_same_fingerprint`.
  - `test_status_changes_logged`: một lần gửi thành công → logs gồm `invoice status changed` `pending→sending` và `sending→draft_created`.
  - `test_matbao_spans`: trong transaction mở tay → span `POST /api/auth/login` và `POST /api/invoice/create-invoice` với `http.response.status_code`; không span nào chứa body/`Authorization`.
  - `test_lark_failure_has_no_webhook`: Lark trả 500 → event/span không chứa webhook URL/secret, span description `POST lark-webhook`.
  - `test_address_echoed_by_matbao_is_redacted`: fake create-invoice trả lỗi message chứa địa chỉ người mua → event và log không chứa địa chỉ.
  - `test_disabled_behaviour_unchanged`: tắt giám sát → không request sink; `error_log` vẫn được gọi ở order watcher (bắt bằng `ini_set('error_log', tmp)`).
  - `test_server_down_keeps_invoice_flow`: create-invoice trả 503 (retry) và sink `fail_transport()` → hóa đơn vẫn `pending` với retry lên lịch như khi tắt; `SentrySink::request_count() <= 1` trong action.
  - `test_slow_sink_success_keeps_invoice_flow`: route sink `usleep(1_500_000)` rồi trả 200, create-invoice OK; test mở `Monitor::start_transaction('t','queue.task')` thủ công quanh `complete_and_run()`, rồi `finish_transaction()` + `Monitor::flush()` trước khi assert → action hoàn tất, hóa đơn `draft_created`, sink nhận transaction `t`; `args.timeout` của request sink là 3.0.
- [ ] **Step 2:** Thêm lời gọi `Monitor` tại các chỗ trên; `DraftInvoiceWorkflow::send()` và `OrderChangeWatcher::check()` chạy trong `Monitor::with_order_context($order_id, …)`; log trạng thái chỉ khi update trả true/affected rows > 0 (`invoice.id`, `from`, `to`, `error_code`).
- [ ] **Step 3:** `ctest WorkflowMonitoring` rồi toàn bộ `composer test` → PASS (không hồi quy).
- [ ] **Step 4:** Commit `feat(monitoring): report plugin errors, status logs and HTTP spans`.

---

## Task 5: Transactions and recovery cron monitor — implements 4.3, 4.6

Blocked by: Task 4 · Difficulty: Normal

**Files:**
- Create: `src/Monitoring/ActionSchedulerTracing.php`, `src/Monitoring/RestTracing.php`
- Modify: `src/Plugin.php` (boot đăng ký hai lớp; Task 5 là owner duy nhất của file này). Không sửa `DraftInvoiceWorkflow.php`.
- Test: `tests/integration/Monitoring/TracingTest.php`, `tests/integration/Monitoring/RecoveryMonitorTest.php`

**Interfaces:**
- Consumes: `Monitor::start_transaction/finish_transaction/capture_exception/check_in`.
- Produces: `ActionSchedulerTracing::register(): void`, `RestTracing::register(): void`.

- [ ] **Step 1: Failing tests `TracingTest`**
  - `test_draft_action_creates_transaction`: chạy action `einvoice_matbao_create_draft` qua Action Scheduler runner (`ActionScheduler::runner()->process_action($id)`) → transaction tên `einvoice_matbao_create_draft`, op `queue.task`, status `ok`, có span login và create-invoice.
  - `test_failed_action_marks_internal_error`.
  - `test_other_group_ignored`: action group `woocommerce` → không transaction.
  - `test_rest_route_transaction`: `GET einvoice-matbao/v1/tabs/lark` → transaction tên `/einvoice-matbao/v1/tabs/(?P<tab>…)` (route pattern), op `http.server`; route REST ngoài plugin → không.
  - `test_sampling_zero_still_reports_errors`.
- [ ] **Step 2:** Implement hook `action_scheduler_before_execute`/`after_execute`/`failed_execution` (lọc group qua `ActionScheduler::store()->fetch_action($id)->get_group() === DraftInvoiceWorkflow::GROUP`) và `rest_pre_dispatch`/`rest_post_dispatch` (lọc `str_starts_with($request->get_route(), '/einvoice-matbao/v1')`).
- [ ] **Step 3: Failing tests `RecoveryMonitorTest`**
  - `test_sweep_reports_in_progress_then_ok`: `sweep()` → 2 check-in cùng `check_in_id`, status `in_progress` rồi `ok` có `duration`, slug `MonitorSlug::for_host('example.org')` (host test).
  - `test_failed_sweep_reports_error_and_rethrows`: gắn `add_action('einvoice_matbao_recover_stuck_invoices', fn() => throw new RuntimeException('x'), 5)` rồi chạy action qua runner → check-in `error` cùng `check_in_id`, Action Scheduler ghi action failed như khi tắt.
  - `test_disabled_sweep_sends_nothing`.
- [ ] **Step 4:** Thêm nhánh check-in trong `ActionSchedulerTracing` cho hook `DraftInvoiceWorkflow::RECOVERY_ACTION` (id lưu theo action id giữa begin/after/failed; Task 5: hook thực tế `action_scheduler_begin_execute` vì `before_execute` chạy trước khi kiểm tra status, và `rest_request_before_callbacks`/`rest_request_after_callbacks` vì `rest_post_dispatch` không chạy với `rest_do_request`).
- [ ] **Step 5:** `ctest Tracing`, `ctest RecoveryMonitor`, `composer test` → PASS.
- [ ] **Step 6:** Commit `feat(monitoring): trace plugin actions and REST routes, add recovery cron monitor`.

---

## Task 6: Monitoring admin tab — implements 5.1, 5.2, 5.3, 5.4

Blocked by: Task 3 · Difficulty: Normal

**Files:**
- Modify: `src/Admin/AdminTabs.php`, `src/Admin/SettingsPage.php` (panel map ~L138-147 + `render_monitoring_panel()`), `src/Admin/SettingsController.php` (routes ~L45, handlers)
- Test: `tests/integration/AdminTabsTest.php`, `tests/integration/SettingsPageTest.php`, `tests/integration/SettingsTabsRestTest.php`, new `tests/integration/SettingsMonitoringRestTest.php`, `tests/e2e/army/tabs.army.ts`, new `tests/e2e/army/monitoring.army.ts`

**Interfaces:**
- Consumes: `MonitoringConfig`, `SentryDsn`, `CredentialStore`, `Settings`, `Monitor::send_test_event`.

- [ ] **Step 1:** Cập nhật test tab hiện có cho 6 tab (thứ tự trong Contracts; data provider `SettingsTabsRestTest` thêm `monitoring`); thêm `SettingsPageTest::test_monitoring_tab_contents` (có form bật/tắt, DSN, tỉ lệ, nút gửi thử; không có form Lark) → FAIL.
- [ ] **Step 2: Failing tests `SettingsMonitoringRestTest`** (extends `SettingsRestTestCase`)
  - `test_save_valid_settings`: enabled+DSN+0.5 → `data-einv-status="success"`, `MonitoringConfig::current()` active, rate 0.5.
  - `test_form_never_renders_dsn`: sau khi lưu, tab HTML chứa `rustrak.ltt.onl · project 3` và không chứa key.
  - `test_invalid_dsn_rejected`, `test_rate_out_of_range_rejected` (`1.5`, `-0.1`, `abc`) → error, cấu hình cũ giữ.
  - `test_blank_dsn_keeps_stored`.
  - `test_missing_key_rejects_save`.
  - `test_undecryptable_dsn_warns`: form hiện thông báo DSN không đọc được.
  - `test_constant_fields_readonly_and_ignored`: filter hằng DSN + ENABLED → input `readonly`/`disabled` kèm `Đặt trong wp-config`; POST DSN khác → DSN lưu không đổi.
  - `test_invalid_dsn_constant_warning`.
  - `test_test_event_success`: sink 200 → success chứa event ID 32 hex, không chứa DSN.
  - `test_test_event_when_disabled`: error "cần bật giám sát và nhập DSN", FakeHttp không có request.
  - Access: anonymous / shop manager / nonce sai → bị từ chối, không ghi gì (dùng helper có sẵn).
- [ ] **Step 3:** Implement tab + panel + routes; chuỗi UI tiếng Việt, escape mọi giá trị động; `Fragment::success/error`.
- [ ] **Step 4:** E2E: `tabs.army.ts` kiểm tra 6 tab và vị trí `Giám sát lỗi`; `monitoring.army.ts` lưu DSN trỏ tới fake sink của stack E2E (`tests/e2e/fake-matbao` thêm route `/api/3/envelope/` trả 200 và ghi lại envelope), bấm gửi thử, thấy notice có event ID.
- [ ] **Step 5:** `composer test`, `bun run check:css-scope`, E2E army theo `tests/e2e/README.md` → PASS.
- [ ] **Step 6:** Commit `feat(admin-settings): add error monitoring tab`.

---

## Task 7: Scoped release build — implements 6.1, 6.2

Blocked by: Task 4, Task 5, Task 6 · Difficulty: Hard

**Files:**
- Create: `scoper.inc.php`, `scripts/php-scoper.sha256`
- Modify: `scripts/build-zip.sh` (bước 3), `scripts/smoke-zip.sh`, `docker/compose.smoke.yaml` (nếu cần sink giả)
- Test: `scripts/smoke-zip.sh` chính là test

**Interfaces:**
- Consumes: toàn bộ plugin.

- [ ] **Step 1:** Ghim PHP-Scoper `0.18.x` mới nhất hỗ trợ PHP 8.2 (đọc release GitHub `humbug/php-scoper`, ghi version + sha256 vào `scripts/php-scoper.sha256`); `build-zip.sh` tải phar vào thư mục tạm, kiểm sha256, chạy bằng PHP của Docker image composer nếu host không có PHP.
- [ ] **Step 2:** `scoper.inc.php`: prefix `LTTech\EinvoiceMatbao\Vendor`; `exclude-namespaces` `LTTech\EinvoiceMatbao` (chừa `LTTech\EinvoiceMatbao\Vendor`); `expose-global-*` false; `exclude-classes`/`exclude-functions`/`exclude-constants` cho symbol WordPress/WooCommerce/Action Scheduler, sinh lúc build từ `php-stubs/wordpress-stubs` và `php-stubs/woocommerce-stubs` cài bằng `composer require --working-dir=<tmp-tools>` với version lấy từ `composer.lock` của repo (không dựa vào dev vendor của checkout); `vendor/sentry/sentry/src/functions.php` (autoload `files`) phải được scope và nạp qua autoloader mới; patcher cho chuỗi tên class trong SDK nếu smoke phát hiện.
- [ ] **Step 3:** Bước 3 của build: `composer install --no-dev` → chạy scoper trên `src/` + `vendor/` vào thư mục mới → `composer dump-autoload --classmap-authoritative` trong output → thay `$pkg`.
- [ ] **Step 4:** `smoke-zip.sh` thêm (kiểm tra thêm: `vendor/composer/installed.json` trong zip đã scope, không còn chuỗi `"Sentry\\\\"` ở namespace gốc trong `autoload_*.php`): (a) `unzip -p` mọi `.php` dưới `vendor/sentry` không còn dòng `^namespace Sentry;`; (b) cài mu-plugin `conflict-libs.php` định nghĩa `namespace Sentry; final class SentrySdk {}` và `namespace GuzzleHttp\Psr7; final class Request {}` trước khi kích hoạt plugin; (c) bật giám sát bằng `wp option`/`wp eval` với DSN tới sink giả trong container (PHP built-in server trả 200), gọi `Monitor::send_test_event()` → trả event ID, sink nhận envelope.
- [ ] **Step 5:** Chạy `scripts/build-zip.sh` rồi `scripts/smoke-zip.sh dist/einvoice-matbao-0.3.0.zip` → `Built …` và smoke đạt; zip tăng ≤ 2 MB.
- [ ] **Step 6:** Commit `build: scope vendor libraries in release zip`.

---

## Task 8: Docs and end-to-end verification — implements 7.1, 7.2, 7.3

Blocked by: Task 7 · Difficulty: Normal

**Files:**
- Modify: `docs/huong-dan-su-dung.md`, `docs/phat-trien.md`, `README.md` (mục tính năng)

- [ ] **Step 1:** Viết tài liệu tiếng Việt: bật giám sát, DSN, hai hằng wp-config, dữ liệu nào được gửi/không gửi, khuyến nghị system cron cho cron monitor; phần phát triển: module `Monitoring`, `Monitor` facade, scope build, test bằng `SentrySink`.
- [ ] **Step 2:** `composer check` trên tổ hợp latest và min (`docker/min.env`), `bun test`, E2E army, build + smoke zip → tất cả đạt; ghi số liệu vào apply.md.
- [ ] **Step 3:** Verify với Rustrak thật: stack E2E, bật giám sát với DSN project 3 (`https://2d08f383db174f0eb7915377727a7649@rustrak.ltt.onl/3`), gửi thử từ UI; hoàn thành một đơn với fake Mắt Bão trả lỗi 4xx; chạy sweep. Qua MCP xác nhận: `list_issues` có event warning `needs-attention`, `list_transactions` có `einvoice_matbao_create_draft` với span, `list_logs` có `invoice status changed`, `list_monitors` có `einvoice-matbao-recovery-localhost-<hash>`; `get_event` không chứa tên/địa chỉ người mua của đơn thử.
- [ ] **Step 4:** Dọn dữ liệu thử trên Rustrak (`bulk_delete_issues`, `delete_monitor`); commit docs `docs: document error monitoring`.

---

## Scenario → test

**error-monitoring**
- Fresh install → `MonitorTest::test_disabled_sends_nothing_and_loads_no_sdk`, `WorkflowMonitoringTest::test_disabled_behaviour_unchanged`
- Enabled without DSN → `MonitoringConfigTest::test_enabled_without_dsn_is_inactive`
- Save valid settings → `SettingsMonitoringRestTest::test_save_valid_settings`
- Form after save → `SettingsMonitoringRestTest::test_form_never_renders_dsn`
- Invalid DSN → `SettingsMonitoringRestTest::test_invalid_dsn_rejected`, `SentryDsnTest::test_rejects_invalid`
- Sample rate out of range → `SettingsMonitoringRestTest::test_rate_out_of_range_rejected`
- Blank DSN keeps stored value → `SettingsMonitoringRestTest::test_blank_dsn_keeps_stored`
- Stored ciphertext → `MonitoringConfigTest::test_stored_ciphertext`
- Missing key on save → `SettingsMonitoringRestTest::test_missing_key_rejects_save`
- Undecryptable stored DSN → `MonitoringConfigTest::test_undecryptable_stored_dsn`, `SettingsMonitoringRestTest::test_undecryptable_dsn_warns`
- Default uninstall removes DSN → `UninstallTest::test_default_uninstall_removes_sentry_dsn_and_backoff`
- DSN constant wins → `MonitoringConfigTest::test_dsn_constant_wins`, `MonitorTest::test_dsn_routing`
- DSN constant alone does not enable → `MonitoringConfigTest::test_dsn_constant_alone_does_not_enable`
- Enabled constant false wins → `MonitoringConfigTest::test_enabled_constant_false_wins`, `SettingsMonitoringRestTest::test_constant_fields_readonly_and_ignored`
- Overridden field ignored on save → `SettingsMonitoringRestTest::test_constant_fields_readonly_and_ignored`
- Invalid DSN constant → `MonitoringConfigTest::test_invalid_dsn_constant_falls_back`, `SettingsMonitoringRestTest::test_invalid_dsn_constant_warning`
- Server accepts → `SettingsMonitoringRestTest::test_test_event_success`, `monitoring.army.ts`
- Monitoring disabled → `SettingsMonitoringRestTest::test_test_event_when_disabled`
- Site already uses Sentry → `MonitorIsolationTest::test_global_hub_untouched`
- Unrelated PHP warning → `MonitorIsolationTest::test_unrelated_warning_not_captured`
- Conflicting libraries in release zip → `scripts/smoke-zip.sh` bước (b)+(c)
- Unexpected error while sending → `WorkflowMonitoringTest::test_unexpected_error_reported`
- Needs attention once per alert → `WorkflowMonitoringTest::test_needs_attention_once_per_alert`
- Same error code on two orders → `WorkflowMonitoringTest::test_same_code_two_orders_same_fingerprint`
- Draft run traced → `TracingTest::test_draft_action_creates_transaction`
- Sampling off → `TracingTest::test_sampling_zero_still_reports_errors`
- Other plugin's action → `TracingTest::test_other_group_ignored`
- Status change logged → `WorkflowMonitoringTest::test_status_changes_logged`
- Sweep runs → `RecoveryMonitorTest::test_sweep_reports_in_progress_then_ok`
- Two sites share a project → `MonitorSlugTest::test_collision_is_avoided`
- Long host → `MonitorSlugTest::test_long_host`
- Buyer name and address in exception message → `MonitorTest::test_with_order_context_redacts_known_values`
- Buyer data in log attribute → `MonitorTest::test_log_attribute_scrubbed`
- Address echoed by Mat Bao error → `WorkflowMonitoringTest::test_address_echoed_by_matbao_is_redacted`
- Token and tax code masked → `ScrubberTest::test_masks_patterns`
- Buyer data in exception message → `MonitorTest::test_capture_exception_scrubbed`
- Lark webhook failure → `WorkflowMonitoringTest::test_lark_failure_has_no_webhook`
- Stack frame arguments → `MonitorTest::test_capture_exception_scrubbed`
- Extra tag dropped → `MonitorTest::test_capture_exception_scrubbed`, `ScrubberTest::test_tags_allowlist`
- No server name → `MonitorTest::test_capture_exception_scrubbed`
- Monitoring internal error → `MonitorTest::test_redactor_failure_drops_event`, `MonitorTest::test_internal_error_is_swallowed`
- Next request during backoff → `WpHttpClientTest::test_backoff_across_requests`
- After backoff → `WpHttpClientTest::test_backoff_across_requests`
- Rate limited with Retry-After → `WpHttpClientTest::test_retry_after`
- Retry-After too long → `WpHttpClientTest::test_retry_after`
- Server down → `WorkflowMonitoringTest::test_server_down_keeps_invoice_flow`
- Missing mbstring → `MonitoringConfigTest::test_missing_mbstring_is_inactive`

**admin-settings**
- Default tab → `SettingsPageTest` (hiện có, giữ)
- Deep link to a tab → `SettingsPageTest` (hiện có, giữ)
- Monitoring tab order → `AdminTabsTest::test_tabs_are_ordered_with_invoices_first` (cập nhật), `tabs.army.ts`
- Unknown tab → `AdminTabsTest::test_resolve_falls_back_for_unknown_keys` (hiện có)
- Missing encryption key on any tab → `SettingsPageTest` (hiện có, giữ)
- Invoicing tab contents → `SettingsPageTest` (hiện có, giữ)
- Monitoring tab contents → `SettingsPageTest::test_monitoring_tab_contents`
- Advanced tab contents → `SettingsPageTest` (hiện có, giữ)
- Template list stays with credentials → `SettingsPageTest`/E2E hiện có, giữ
