# Apply Receipt

> Generated at the end of the apply phase to mark code-implementation
> complete and provide verify with the state it needs.
> Overwritten on each apply iteration; iteration counter grows.

**Change**: `monitoring-event-context`
**Iteration**: `1`
**Applied at**: `2026-11-12 13:00`
**Executor**: `subagent-driven-development`

---

## Workspace

- **Worktree path**: `/Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.monitoring-event-context`
- **Worktree branch**: `monitoring-event-context`
- **Feature branch**: `feat/monitoring-event-context`
- **Base branch**: `main`

---

## Commits

- **Range**: `0f3bace..ba7ae79` (cộng commit receipt này)
- **Count**: `28`

---

## Tasks

- **Completed**: `23 of 23` checkboxes in tasks.md flipped to `- [x]`
- **Remaining**: none

---

## Contract deviations

> Contracts changed during apply, each with its reason. Cumulative:
> keep entries from earlier iterations.

- `InvoiceDataError`: thuộc tính `$line` đổi thành `$line_failure` vì `Exception::$line` là protected, không thể khai báo lại thành public readonly. Nội bộ.
- `Monitor::with_failure_detail`: tham số `$detail` nhận `FailureDetail|\Closure` để việc dựng chi tiết nằm trong try/catch của Monitor, lỗi dựng context không thoát ra luồng hóa đơn. Nội bộ.
- `InvoiceContext`: thêm `for_order_or_empty( int ): array`; khi tra cứu hóa đơn ném lỗi, event gốc vẫn được gửi (thiếu context `invoice`) thay vì bị bỏ. Nội bộ.

---

## Verification evidence (apply)

- Full integration suite: 836 tests OK; `composer lint`, `composer analyse`, floor guard và `test:without-woocommerce` (8) OK (báo cáo task 8, chạy lại bởi các task trước đó).
- Final whole-branch review (code-reviewer, read-only): **Ready to merge: Yes**, không có Critical/Important; spec coverage không thiếu scenario nào.
- E2E live (task 8.3) trên stack local: Mắt Bão giả + ingest Sentry giả; đơn có dòng hai thuế suất cho event với message `Hóa đơn cần xử lý: Dòng không có đúng một thuế suất – Đơn #18`, context `invoice` (hint, `admin_path`), `line` (product 10, SKU `E2E-SVC`, `tax_rate_ids` [1, 999]), tag `product_id`, fingerprint không đổi; không có tên, email hay địa chỉ người mua trong bất kỳ envelope nào; tin Lark có hướng dẫn và liên kết.
- Giới hạn: không gửi event thử vào project Rustrak thật (project 3 là production, tránh làm bẩn issue), nên E2E dùng ingest giả thay cho Rustrak.

## Minor chưa xử lý (từ final review)

- `InvoiceErrorCode::UNEXPECTED` hint nhắc "Rustrak", hiện trên mọi site kể cả khi giám sát tắt (đúng bảng design D1; cần user quyết định có đổi sang cách nói trung tính).
- Minor khác (nit): test trực tiếp cho hint fallback của mã lỗi lạ, `failure_detail_for` đang public cho test, `Monitor.php` lớn dần, tên biến `$failure` trong `DraftPayloadBuilder::line()`.

---

## Next step

Chạy `/opsx-verify`.
