# Status sync backoff Implementation Plan

> Executed via `/opsx-apply`, which chooses the executor.
> Spec reference: this change's `specs/matbao-status-sync/spec.md` and `design.md`.

**Goal:** Dừng lượt đồng bộ trạng thái Mắt Bão ở lỗi tra cứu tạm thời đầu tiên và tạm dừng đồng bộ tự động theo thang 15p → 30p → 1h → 2h → 4h → 6h, kèm một lượt thử lại khi hết tạm dừng (issue #56).

**Architecture:** Class mới `StatusSyncBackoff` sở hữu trạng thái backoff (một option không autoload), lượt thử lại (single action) và log khi tạm dừng. `MatbaoStatusSync::sync_batch()` hỏi `is_paused()` trước khi gọi Mắt Bão, gặp `MatbaoError` retryable thì `record_failure()` rồi dừng, tra cứu thành công thì `clear()`. `sync_one()` chỉ `clear()` khi thành công. Action hằng giờ giữ nguyên.

**Tech Stack:** PHP 8.2+, WordPress, WooCommerce, Action Scheduler, PHPUnit integration (WordPress + WooCommerce thật trong container), phpcs, phpstan level 8.

## Global Constraints

- Thang (giây): `[900, 1800, 3600, 7200, 14400, 21600]`; lỗi thứ N ≥ 6 dùng 21600.
- Option `einvoice_matbao_status_sync_backoff`, `autoload = false`; hook thử lại `einvoice_matbao_status_sync_retry`; group `DraftInvoiceWorkflow::GROUP` (`einvoice-matbao`).
- Lỗi tạm thời = `MatbaoError::is_retryable()` (transport, HTTP 5xx/408/429, phản hồi 2xx không đọc được; 305 không xảy ra khi tra cứu). 401 được client tự đăng nhập lại; chỉ lỗi cuối cùng client ném ra mới được phân loại.
- Tra cứu thành công = client trả `MatbaoLookup` (kể cả 301/`notFound`), bất kể `apply()` trả `UPDATED`, `UNCONFIRMED`, `FAILED` hay `UNCHANGED`.
- Lỗi tra cứu và backoff không fire `NEEDS_ATTENTION_ACTION`, không gửi Lark; chỉ `Monitor::log( 'warn', … )`.
- Comment, identifier, commit tiếng Anh; docs tiếng Việt. Áp dụng `~/.agents/skills/coding-standards/SKILL.md`.
- Mọi lệnh composer chạy trong container (không chạy trên host):
  `alias einv='docker compose exec -T -w /var/www/html/wp-content/plugins/einvoice-matbao wordpress'` (stack dựng bằng `docker compose up -d --build --wait && docker compose exec -T wordpress einv-setup`, xem `docs/phat-trien.md:20-44`). PHPStan chỉ phân tích `src`; test helper vẫn phải qua PHPCS và PHPUnit.

## Review Focus

- Option hỏng (chuỗi, thiếu key, số âm, `false`) → coi như không backoff, không fatal. Retry đang chờ vẫn bị hủy khi tra cứu thành công, kể cả khi option vắng.
- `schedule()` vẫn xếp recurring khi chỉ có retry single action (khác hook).
- Lỗi đăng nhập dạng transport/5xx (trước khi gửi lookup) được tính là lỗi tạm thời và dừng lượt.
- Tra cứu thành công khi không có backoff không ghi option (không thêm một lần ghi DB mỗi nhóm).
- `as_schedule_single_action()` trả 0 → log warn "retry not queued", không fatal, backoff vẫn được ghi.
- Hai lượt chạy chồng có thể để lại retry thừa: retry đó không gọi Mắt Bão khi còn tạm dừng, nhưng nếu backoff đã bị xóa thì nó chạy như một lượt thường (tra cứu thừa, chấp nhận theo design).

---

## Contracts

| Name | new / modified | Shape | public |
|---|---|---|---|
| `StatusSyncBackoff` | new | `final class LTTech\EinvoiceMatbao\Invoice\StatusSyncBackoff { public const OPTION = 'einvoice_matbao_status_sync_backoff'; public const RETRY_ACTION = 'einvoice_matbao_status_sync_retry'; public const PAUSE_SECONDS = array( 900, 1800, 3600, 7200, 14400, 21600 ); public function is_paused(): bool; public function record_failure(): int /* pause seconds; also logs warn 'Mat Bao status sync paused' {failures, pause_seconds} and queues the retry */; public function clear(): void; /** @return array{failures: int, until: int} */ private function state(): array; }` | no (internal) |
| Option `einvoice_matbao_status_sync_backoff` | new | `array{ failures: int, until: int }` (unix giây), autoload false. Hợp lệ chỉ khi là array có cả hai key kiểu `int` và `>= 0` (không nhận chuỗi số); mọi giá trị khác đọc thành `{0, 0}` | no (internal storage) |
| Action `einvoice_matbao_status_sync_retry` | new | Action Scheduler single action, args `array()`, group `einvoice-matbao`, handler `MatbaoStatusSync::run()`; lượt tuần tự để lại nhiều nhất một action pending | no (internal) |
| `Uninstaller::STATUS_SYNC_BACKOFF_OPTION` | new | `public const STATUS_SYNC_BACKOFF_OPTION = 'einvoice_matbao_status_sync_backoff';` xóa ở nhánh gỡ cài đặt mặc định | no (internal) |
| `MatbaoStatusSync::__construct` | modified | `( InvoiceRepository $repository = new InvoiceRepository(), ?MatbaoClient $client = null, StatusSyncBackoff $backoff = new StatusSyncBackoff() )` | no (internal; thêm tham số optional cuối) |

---

## Task 1: Status sync stops early and backs off — implements 1

**Covers:** 1.1, 1.2, 1.3, 1.4
**Difficulty:** Normal
**Blocked by:** none

**Files:**
- Create: `src/Invoice/StatusSyncBackoff.php`
- Modify: `src/Invoice/MatbaoStatusSync.php` (`register`, `__construct`, `sync_batch`, `sync_one`, `sync_chunk`, `lookup`, docblock)
- Test: `tests/integration/MatbaoStatusSyncTest.php`

**Interfaces:**
- Produces: `StatusSyncBackoff` và các hằng (xem Contracts); Task 2 dùng `StatusSyncBackoff::OPTION`.

Helper thêm vào `MatbaoStatusSyncTest` (private, có PHPDoc):
- `fail_lookups( int $status, string $body = '' )`: `LOOKUP_ROUTE` trả status/body đó cho mọi request.
- `fail_lookups_with_transport_error()`: handler trả `new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' )`.
- `forget_cached_token()`: `$credentials = ( new CredentialStore() )->matbao(); $this->assertNotNull( $credentials ); ( new MatbaoClient( $credentials ) )->forget_token();`.
- `matbao_requests(): int`: tổng `requests_to( 'POST /api/auth/login' )` + `requests_to( self::LOOKUP_ROUTE )` (mọi route Mắt Bão mà sync có thể gọi).
- `backoff_state(): mixed` (`get_option( StatusSyncBackoff::OPTION )`), `pause_with( int $failures, int $until )` (ghi option trực tiếp, autoload false).
- `pending_retries(): array` = `as_get_scheduled_actions( array( 'hook' => StatusSyncBackoff::RETRY_ACTION, 'args' => array(), 'group' => DraftInvoiceWorkflow::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' )`.
- `queue_retry_at( int $timestamp ): int`: `as_schedule_single_action( $timestamp, RETRY_ACTION, array(), GROUP )`, assert id > 0.
- `assert_pause( int $seconds )`: option là array, `failures` int, `until` trong `[time()+$seconds-5, time()+$seconds+5]`, đúng một retry pending và `as_next_scheduled_action( RETRY_ACTION, array(), GROUP )` là `int` bằng `until`.

Mọi test có lượt lỗi đều assert `did_action( NEEDS_ATTENTION_ACTION )` không đổi (trừ test 301).

- [ ] **Step 1: Viết test fail cho quy tắc dừng sớm.**
  - Thay `test_lookup_503_keeps_status_and_continues` bằng `test_lookup_503_stops_the_run`: `$a` (series `C26TAA`, `matbao_status` 3) và `$b` (series `C26TBB`, `matbao_checked_at` = `'2026-01-01 00:00:00'`); `$a` có id nhỏ hơn và chưa kiểm tra nên đi trước. Lookup 503 mọi request. Assert đúng 1 request lookup; `$a` `matbao_status` vẫn 3 và `matbao_checked_at` đã đổi; `$b` `matbao_checked_at` vẫn `'2026-01-01 00:00:00'`; không alert.
  - `test_lookup_timeout_stops_the_run`: như trên với transport `WP_Error`; assert 1 request, `$b` giữ nguyên `matbao_status` và `matbao_checked_at` cũ, không alert.
  - `test_other_temporary_failures_stop_the_run` (data provider: `[408, '{}']`, `[429, '{}']`, `[200, '{"errorCode":200,"data":{}}']` (thiếu `success`), `[200, '{"success":true,"errorCode":200,"data":{}}']` (thiếu `invoices`)): hai nhóm → 1 request lookup, `assert_pause( 900 )`.
  - `test_login_failure_stops_the_run` (data provider: login 503; login transport `WP_Error`): `forget_cached_token()`, đè route `POST /api/auth/login`, hai nhóm → đúng 1 request login, 0 request lookup, nhóm đầu có `matbao_checked_at`, nhóm sau không, `assert_pause( 900 )`.
  - `test_expired_token_refreshed_does_not_pause`: handler lookup theo lần gọi: lần 1 trả 401, lần 2 trả dòng issued. Assert 2 request lookup, ≥ 1 request login sau request 401, hóa đơn `matbao_status` 4, `backoff_state()` là `false`, `pending_retries()` rỗng.
  - `test_unreadable_row_does_not_pause`: nhóm A trả dòng `maTThaiHDon` null, nhóm B trả issued → 2 request lookup, B có status 4, option vắng.
  - `test_non_temporary_failure_continues`: nhóm đầu 400, nhóm sau issued → nhóm sau status 4, option vắng, `pending_retries()` rỗng.
- [ ] **Step 2: Chạy** `einv composer test:integration -- --filter MatbaoStatusSyncTest` → các test mới FAIL (gửi nhiều hơn một request / class chưa có).
- [ ] **Step 3: Viết test fail cho backoff.**
  - `test_first_failure_pauses_15_minutes`: 503 → `assert_pause( 900 )`, `failures === 1`.
  - `test_pause_grows_up_to_six_hours` (data provider `[1, 1800]`, `[2, 3600]`, `[3, 7200]`, `[4, 14400]`, `[5, 21600]`, `[6, 21600]`): `pause_with( $n, time() - 1 )`, 503 → `assert_pause( $expected )`, `failures === $n + 1`.
  - `test_pause_expired_without_success_keeps_counting`: `pause_with( 2, time() - 1 )`, 503 → `assert_pause( 3600 )`.
  - `test_unreadable_backoff_state_is_ignored` (data provider: `'garbage'`, `array()`, `array( 'failures' => -1, 'until' => 'x' )`, `array( 'failures' => '2', 'until' => '9999999999' )`): 503 → đúng 1 request lookup, `assert_pause( 900 )`.
  - `test_hourly_run_during_pause_sends_nothing`: `pause_with( 1, time() + 600 )`, draft có `matbao_checked_at` cũ → `matbao_requests() === 0`, `matbao_checked_at` không đổi, option không đổi.
  - `test_success_then_failure_in_one_run`: `pause_with( 2, time() - 1 )`; ba nhóm A, B, C (ba series, A id nhỏ nhất); A trả status 3, B 503, C có `matbao_checked_at` cũ → A status 3, B có `matbao_checked_at` mới, C không có trong request và `matbao_checked_at` không đổi, `assert_pause( 900 )`, `failures === 1`.
  - `test_not_found_clears_backoff_and_alerts`: `pause_with( 1, time() - 1 )` và `queue_retry_at( time() + 600 )`; lookup trả errorCode 301 → option vắng, `pending_retries()` rỗng, `matbao_status` 0, `NEEDS_ATTENTION_ACTION` +1, `error_code` `matbao_draft_deleted`.
  - `test_successful_lookup_clears_backoff_whatever_apply_returns` (data provider: dòng không khớp → `UNCONFIRMED`; filter `query` làm UPDATE thất bại như `test_db_failure_isolated_to_one_invoice` → `FAILED`; đổi `matbao_invoice_id` giữa đọc và ghi như `test_stale_lookup_after_recreation_not_written` → `UNCHANGED`): `pause_with( 2, time() - 1 )` và `queue_retry_at( time() + 600 )` → option vắng, `pending_retries()` rỗng, trạng thái hóa đơn đúng như test gốc.
  - `test_success_cancels_retry_without_option`: không có option, `queue_retry_at( time() + 600 )`, lookup ok → `pending_retries()` rỗng, option vẫn vắng.
  - `test_success_without_backoff_writes_no_option`: không có option, gắn filter `pre_update_option_` + `StatusSyncBackoff::OPTION` và `add_action( 'delete_option', … )` đếm số lần chạm option; lookup ok → đếm = 0.
- [ ] **Step 4: Viết test fail cho lượt thử lại và nút thủ công.**
  - `test_retry_action_runs_the_sync`: `MatbaoStatusSync::register()`, lookup ok, `do_action( StatusSyncBackoff::RETRY_ACTION )` → 1 request lookup.
  - `test_retry_run_after_pause_clears_backoff`: `MatbaoStatusSync::schedule()`, ghi lại `$hourly = as_get_scheduled_actions( array( 'hook' => MatbaoStatusSync::ACTION, 'status' => PENDING ) )` (id, `get_schedule()->get_date()`, recurrence); chạy lượt 503 (retry được xếp); `pause_with( 1, time() - 1 )`; lookup trả status 3; `do_action( StatusSyncBackoff::RETRY_ACTION )` → hóa đơn status 3, option vắng, `pending_retries()` rỗng, action hằng giờ cùng id, cùng ngày, cùng recurrence 3600.
  - `test_retry_run_while_still_paused_sends_nothing`: `MatbaoStatusSync::register()`, `pause_with( 1, time() + 600 )`, `do_action( RETRY_ACTION )` → `matbao_requests() === 0`.
  - `test_only_one_retry_queued`: lượt 503, `pause_with( 1, time() - 1 )`, lượt 503 → `count( pending_retries() ) === 1` và `assert_pause( 1800 )`.
  - `test_schedule_queues_hourly_when_only_a_retry_exists`: `queue_retry_at( time() + 600 )`, `as_has_scheduled_action( MatbaoStatusSync::ACTION )` false; `schedule()` hai lần → đúng một action pending hook `ACTION`, group `einvoice-matbao`, recurrence 3600; retry vẫn còn.
  - `test_manual_check_during_pause_clears_backoff`: hóa đơn X, Y khác series; lượt 503 (retry pending, đang tạm dừng); lookup trả issued cho X; `sync_one( reload( $x ) )` → `SyncOutcome::UPDATED`, X status 4, option vắng, `pending_retries()` rỗng; sau đó lookup ok cho Y, `sync_batch()` → có request chứa reference của Y.
  - `test_manual_check_failure_keeps_backoff`: `pause_with( 2, $until = time() + 1800 )`, `$id = queue_retry_at( $until )`, 503, `sync_one()` → `FAILED`, option giống hệt trước, `pending_retries() === array( $id )`.
  - `test_deactivation_cancels_pending_retry`: lượt 503 → `pending_retries()` có 1; `Plugin::deactivate()` → rỗng.
- [ ] **Step 5: Chạy** filter → các test backoff FAIL (thiếu class).
- [ ] **Step 6: Viết `StatusSyncBackoff`** trong `src/Invoice/StatusSyncBackoff.php` theo Contracts.
  - `state()`: `get_option( self::OPTION )`; hợp lệ chỉ khi array, `is_int` cả hai key và `>= 0`; ngược lại `array( 'failures' => 0, 'until' => 0 )`.
  - `is_paused()`: `state()['until'] > time()`.
  - `record_failure()`: `failures + 1`, `$seconds = self::PAUSE_SECONDS[ min( $failures, count( self::PAUSE_SECONDS ) ) - 1 ]`, `update_option( OPTION, array( 'failures' => …, 'until' => time() + $seconds ), false )`, `as_unschedule_all_actions( RETRY_ACTION, array(), GROUP )`, `$id = as_schedule_single_action( $until, RETRY_ACTION, array(), GROUP )` (không truyền `$unique`, vì nó giữ mốc cũ); nếu `0 === $id` thì `Monitor::log( 'warn', 'Mat Bao status sync retry not queued' )`; `Monitor::log( 'warn', 'Mat Bao status sync paused', array( 'failures' => …, 'pause_seconds' => $seconds ) )`; trả `$seconds`.
  - `clear()`: `if ( false !== get_option( self::OPTION ) ) delete_option( self::OPTION );` và độc lập với option: `if ( as_has_scheduled_action( RETRY_ACTION, array(), GROUP ) ) as_unschedule_all_actions( RETRY_ACTION, array(), GROUP );`. Không ghi gì khi không có option và không có retry.
- [ ] **Step 7: Nối vào `MatbaoStatusSync`.**
  - `register()`: thêm `add_action( StatusSyncBackoff::RETRY_ACTION, array( self::class, 'run' ) )`.
  - Constructor nhận `private readonly StatusSyncBackoff $backoff = new StatusSyncBackoff()`.
  - `lookup()` trả `MatbaoLookup|MatbaoError` (vẫn touch nhóm và log warn khi lỗi như cũ).
  - `sync_chunk()` trả `bool` "đi tiếp": nhận `MatbaoLookup` → `$this->backoff->clear()` trước khi `apply` từng hóa đơn (bất kể kết quả apply), trả `true`; nhận lỗi → trả `! $error->is_retryable()`.
  - `sync_batch()`: sau `resolve_client()` thì `if ( $this->backoff->is_paused() ) { return; }`; khi `sync_chunk()` trả `false` thì `$this->backoff->record_failure(); return;`.
  - `sync_one()`: tra cứu thành công → `clear()` rồi `apply()`; lỗi → `SyncOutcome::FAILED`, không `record_failure()`.
  - Sửa docblock class và `sync_batch()` (bỏ câu "does not stop the other chunks").
- [ ] **Step 8: Chạy** filter → PASS hết. Rồi `einv composer lint` và `einv composer analyse` sạch.
- [ ] **Step 9: Commit** `git add src/Invoice/StatusSyncBackoff.php src/Invoice/MatbaoStatusSync.php tests/integration/MatbaoStatusSyncTest.php && git commit -m "fix(status-sync): stop at the first temporary Mat Bao failure and back off (#56)"`

---

## Task 2: Uninstall and docs — implements 2

**Covers:** 2.1, 2.2, 2.3
**Difficulty:** Easy
**Blocked by:** Task 1

**Files:**
- Modify: `src/Uninstall/Uninstaller.php` (hằng, `uninstall_site()`, docblock class)
- Modify: `docs/phat-trien.md` (mục "Đồng bộ trạng thái Mắt Bão (`MatbaoStatusSync`)" bullet lỗi tra cứu ~dòng 170; dòng deactivate ~231 thêm hook thử lại)
- Modify: `docs/huong-dan-su-dung.md` (mục "Đồng bộ trạng thái Mắt Bão" ~dòng 214)
- Test: `tests/integration/UninstallTest.php`

**Interfaces:**
- Consumes: `StatusSyncBackoff::OPTION` từ Task 1.

- [ ] **Step 1: Viết test fail.** Trong `test_uninstaller_names_match_the_plugin_storage` thêm `$this->assertSame( StatusSyncBackoff::OPTION, Uninstaller::STATUS_SYNC_BACKOFF_OPTION );`. Test mới `test_default_uninstall_removes_status_sync_backoff`: `update_option( StatusSyncBackoff::OPTION, array( 'failures' => 2, 'until' => time() + 600 ), false )`, `Uninstaller::run()` → `get_option( StatusSyncBackoff::OPTION )` là `false`, settings vẫn còn.
- [ ] **Step 2: Chạy** `einv composer test:integration -- --filter UninstallTest` → FAIL.
- [ ] **Step 3: Viết** hằng và `delete_option( self::STATUS_SYNC_BACKOFF_OPTION );` cạnh `SENTRY_BACKOFF_OPTION`; nhắc trong docblock class.
- [ ] **Step 4: Chạy** filter → PASS.
- [ ] **Step 5: Cập nhật docs** (tiếng Việt). Dev doc: thay "các lô khác vẫn chạy" bằng quy tắc dừng ở `is_retryable()`, thang, option, hook thử lại, hành vi `sync_one`, định nghĩa tra cứu thành công. User guide thêm một bullet: "Khi Mắt Bão lỗi tạm thời, lượt đồng bộ dừng và tạm nghỉ 15 phút, 30 phút, 1 giờ, 2 giờ, 4 giờ, tối đa 6 giờ, rồi tự thử lại; nút Kiểm tra trạng thái vẫn dùng được và lần tra cứu thành công đưa đồng bộ về mỗi giờ."
- [ ] **Step 6: Chạy** `einv composer check` → xanh (lint, analyse, floor guard, cả hai bộ test).
- [ ] **Step 7: Commit** `git add src/Uninstall/Uninstaller.php tests/integration/UninstallTest.php docs/phat-trien.md docs/huong-dan-su-dung.md && git commit -m "fix(status-sync): remove the backoff state on uninstall and document it (#56)"`

---

## Scenario → test

- Status sync backs off while Mat Bao fails / First failure pauses 15 minutes → `MatbaoStatusSyncTest::test_first_failure_pauses_15_minutes`
- … / Pause grows up to six hours → `MatbaoStatusSyncTest::test_pause_grows_up_to_six_hours`
- … / Pause expired without success → `MatbaoStatusSyncTest::test_pause_expired_without_success_keeps_counting`
- … / Unreadable backoff state → `MatbaoStatusSyncTest::test_unreadable_backoff_state_is_ignored`
- … / Hourly run during the pause → `MatbaoStatusSyncTest::test_hourly_run_during_pause_sends_nothing`
- … / Backoff state removed on uninstall → `UninstallTest::test_default_uninstall_removes_status_sync_backoff`
- Successful lookup resets the backoff / Success in the middle of a run → `MatbaoStatusSyncTest::test_success_then_failure_in_one_run`
- … / Not found counts as success → `MatbaoStatusSyncTest::test_not_found_clears_backoff_and_alerts` (cộng `test_successful_lookup_clears_backoff_whatever_apply_returns`, `test_success_cancels_retry_without_option`, `test_success_without_backoff_writes_no_option` cho định nghĩa thành công)
- Retry run when the pause ends / Retry run after the pause → `MatbaoStatusSyncTest::test_retry_run_after_pause_clears_backoff`, `test_retry_action_runs_the_sync`, `test_schedule_queues_hourly_when_only_a_retry_exists`
- … / Retry run while still paused → `MatbaoStatusSyncTest::test_retry_run_while_still_paused_sends_nothing`
- … / Only one retry queued → `MatbaoStatusSyncTest::test_only_one_retry_queued`
- Manual check bypasses the backoff / Manual check during the pause → `MatbaoStatusSyncTest::test_manual_check_during_pause_clears_backoff`
- … / Manual check fails → `MatbaoStatusSyncTest::test_manual_check_failure_keeps_backoff`
- Lookup failures leave the status unchanged / Mat Bao 503 → `MatbaoStatusSyncTest::test_lookup_503_stops_the_run`
- … / Mat Bao times out → `MatbaoStatusSyncTest::test_lookup_timeout_stops_the_run`
- … / Other temporary failures stop the run → `MatbaoStatusSyncTest::test_other_temporary_failures_stop_the_run`
- … / Login fails temporarily → `MatbaoStatusSyncTest::test_login_failure_stops_the_run`
- … / Expired token refreshed → `MatbaoStatusSyncTest::test_expired_token_refreshed_does_not_pause`
- … / Unreadable row is not a failure → `MatbaoStatusSyncTest::test_unreadable_row_does_not_pause`
- … / Non-temporary failure continues → `MatbaoStatusSyncTest::test_non_temporary_failure_continues`
- … / No credentials → `MatbaoStatusSyncTest::test_no_credentials_no_request` (có sẵn)
- Status sync stops with the plugin / Deactivation → `MatbaoStatusSyncTest::test_deactivation_unschedules_status_sync` (có sẵn)
- … / Deactivation during the pause → `MatbaoStatusSyncTest::test_deactivation_cancels_pending_retry`
