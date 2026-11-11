# Apply Receipt

> Generated at the end of the apply phase to mark code-implementation
> complete and provide verify with the state it needs.
> Overwritten on each apply iteration; iteration counter grows.

**Change**: `sentry-observability`
**Iteration**: `1`
**Applied at**: `2026-11-12 02:15`
**Executor**: `subagent-driven-development`

---

## Workspace

- **Worktree path**: `/Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.sentry-observability`
- **Worktree branch**: `sentry-observability`
- **Feature branch**: `feat/sentry-observability`
- **Base branch**: `main`

---

## Commits

- **Range**: `720fdd8..2a42205` (cộng commit receipt này)
- **Count**: `37`

---

## Tasks

- **Completed**: `26 of 26` checkboxes in tasks.md flipped to `- [x]`
- **Remaining**: none

---

## Executor

- Implementer: agent `general-purpose`, một implementer cho mỗi plan task, chạy tuần tự trong một worktree. Có ruling ghi trong ledger: T4 và T6 cùng dùng `tests/integration/Support` và cùng một Docker stack.
- Task reviewer: agent `code-reviewer`, mỗi task một seat review gộp spec và quality, kèm fix loop.
- Final whole-branch review: `code-reviewer` (`2eff26bd`) trên `720fdd8..a6ea8b6`, kết luận "With fixes", không có Critical. Đợt sửa đầu xử lý 4 finding Important; re-review `a26785cb` còn mở #2. Commit `7129806` và `2a42205` sửa #2; re-review `cced9300` không còn finding mở.

## Verification evidence (controller)

- `composer test` trên stack latest (WP 7.1.4 / WC 11.2.1 / PHP 8.5): integration `OK (782 tests, 4055 assertions)`, without-WooCommerce `OK (8 tests, 21 assertions)`. `composer lint` sạch, `composer analyse` `[OK] No errors`.
- `composer check` trên stack min (WP 6.6.2 / WC 9.8.7 / PHP 8.2), lần chạy ở Task 8: đạt. Có 1 skip có từ trước: test REST v4 (WC 9.8 không có `wc/v4`).
- `bun test` 12/12. `bun run check:css-scope` sạch.
- E2E army replay 78/78 sau khi ghi lại cache `settings.army.ts` (commit `db02798`; user duyệt và cung cấp model qua `.env.e2e.local`).
- Release zip `dist/einvoice-matbao-0.3.0.zip` 607 574 B, tăng khoảng 442 KB so với v0.3.0. `scripts/smoke-zip.sh` đạt trên latest; Task 7 cũng đã chạy smoke trên min.
- `openspec validate sentry-observability --strict`: hợp lệ.
- Rustrak thật (project 3, `rustrak.ltt.onl`, stack verify riêng, environment `staging`):
  - Nhận được event thử qua `wp eval` và qua route REST của tab admin.
  - Issue `warning` "Invoice needs attention: matbao_http": fingerprint `needs-attention` + mã lỗi, tag chỉ thuộc allowlist, không có request/user/server_name.
  - Transaction: `einvoice_matbao_create_draft` có span Mắt Bão, `einvoice_matbao_send_alert`, `einvoice_matbao_recover_stuck_invoices`, và REST `/einvoice-matbao/v1/settings/monitoring/test` (`ok`, `unauthenticated`).
  - Log `invoice status changed` (pending→sending, sending→failed). Monitor `einvoice-matbao-recovery-localhost-33438904` tự tạo, check-in `ok`.
  - Quét PII trên mọi transaction, event và log: 0 lần lộ tên, công ty, MST, email, SĐT, địa chỉ người mua, DSN hoặc token.
  - Sau các bản sửa: span 400 có status `invalid_argument`; Mắt Bão timeout cho span `deadline_exceeded`, hóa đơn vẫn `pending`/retry; luồng DSN sai → sửa → gửi thử thành công ngay.
  - Dữ liệu thử đã dọn: issue và monitor đã xóa. Transaction và log hết hạn theo retention (90 ngày / 7 ngày), vì Rustrak MCP không có API xóa.

---

## Contract deviations

Tất cả là contract nội bộ (`public = no`), đã ghi trong mục Contracts của plan.md. Không có contract public nào bị đổi.

- `Monitoring\Backend::span()` / `start_transaction()`: nhận thêm `array $known` để lọc tên và data ngay khi mở, vì transaction được gửi sau, khi order context có thể đã đóng. (Task 3)
- `Monitoring\CallOutcome` (mới): ghi lại kết quả hoặc exception của callable, để `span()` không gọi callable lần hai và không nuốt exception nghiệp vụ. (Task 3)
- `Monitoring\LogAttributes`, `Monitoring\InternalFailure`, `Monitoring\TraceRedaction` (mới): lọc attribute về dạng cuối trước khi gửi; chính sách `error_log` chỉ ghi tên class; lọc trace context và DSC nhưng giữ nguyên trace/span ID. (Task 3, fix rounds)
- `Monitor::span()` và `with_order_context()`: đổi tham số `$fn` thành `$callback` theo WPCS. (Task 3)
- `Monitoring\CaughtFailure` (mới): một chỗ duy nhất cho mọi catch đang dùng `error_log`. (Task 4)
- `InvoiceRepository::update_status()` / `record_error()`: thêm `?string $from = null`; log trạng thái lấy `from` từ transition, không pre-read. (Task 4, ruling)
- `Monitor::is_active()` (mới) và transaction lồng nhau đếm độ sâu. Hook đổi sang `action_scheduler_begin_execute` và `rest_request_before_callbacks` / `rest_request_after_callbacks`, vì `rest_post_dispatch` không chạy với `rest_do_request` và `before_execute` chạy trước bước kiểm tra trạng thái. (Task 5, ruling)
- Scrubber, mẫu token: chuỗi ≥32 ký tự chỉ bị che khi có chữ số, và `/` bị bỏ khỏi lớp ký tự để tên hook và route REST không bị che. Spec "Pattern masking" và design D5 cập nhật cùng commit. (Task 5 follow-up, ruling)
- `Monitor::finish_transaction_for_http_status(int)` / `Backend::finish_transaction_for_http_status(int)` (mới): ánh xạ HTTP → status nằm trong `SentryBackend`, nên chỉ `SentryBackend` và `WpHttpClient` chạm tới SDK. (Final review #3)
- `Monitoring\TransportErrorData` (mới): quy tắc chung cho Mắt Bão và Lark, `cURL error 28` hoặc "timed out" → `error.type=timeout` → `deadline_exceeded`. (Final review #1)
- `Monitor::reconfigure()` (mới): bỏ backend hiện tại mà không gửi gì; `save_monitoring` gọi sau khi lưu rồi mới xóa backoff. (Final review #2)

## Rulings and deferred items (from the SDD ledger)

- Mọi task chạy tuần tự trong một worktree. Nếu sai, chỉ tốn thêm thời gian.
- Lưu cờ bật và tỉ lệ lấy mẫu không cần khóa mã hóa; chỉ việc lưu DSN mới cần khóa. Hành vi này đã có test.
- Request REST bị từ chối vẫn được trace, status lấy theo mã HTTP.
- Chấp nhận thiếu bằng chứng RED lịch sử cho Task 2 ban đầu và cho `WpHttpClient` (đã ghi deviation; regression có RED → GREEN).
- Minor đã hoãn, final review xếp loại "chấp nhận":
  - URL lạ nuốt dấu câu phía sau.
  - `before_send` không xóa `vars` của threads, vì SDK 4.36 không có threads.
  - Test chuyển `error_log` vào `/dev/null`.
  - E2E chập chờn "connection check reports an unreachable Mat Bao", có từ trước; nên mở issue theo dõi.
  - smoke-zip chưa có biến thể min.
  - Final review minors 2–7: config đọc hai lần, `fetch_action` khi bật, `values_for` lồng nhau, UI không có nút xóa DSN, nhãn "Đang dùng" không tự cập nhật, transaction không đóng khi action fatal.

---

## Next step

Run `/opsx-verify sentry-observability` (trong worktree này).
