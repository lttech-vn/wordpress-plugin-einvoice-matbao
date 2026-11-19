# Apply Receipt

> Generated at the end of the apply phase to mark code-implementation
> complete and provide verify with the state it needs.
> Overwritten on each apply iteration; iteration counter grows.

**Change**: `status-sync-backoff`
**Iteration**: `1`
**Applied at**: `2026-11-19 18:51`
**Executor**: `subagent-driven-development` (implementer: `implementer` agent; task reviewer và final reviewer: `code-reviewer` agent)

---

## Workspace

- **Worktree path**: `/Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.status-sync-backoff`
- **Worktree branch**: `status-sync-backoff`
- **Feature branch**: `fix/status-sync-backoff`
- **Base branch**: `main`

---

## Commits

- **Range**: `b66d70d..1ea8715` (receipt commit nằm ngay sau range này)
- **Count**: `8`

---

## Tasks

- **Completed**: `7 of 7` checkboxes in tasks.md flipped to `- [x]`
- **Remaining**: none

---

## Contract deviations

none

---

## Lanes

| Task | Lane branch | Lane range | Review | Merge commit |
|---|---|---|---|---|
| `1` | `sequential` | `b66d70d..640602a` | approved sau fix round 1 (2 Important đã sửa) | n/a (sequential) |
| `2` | `sequential` | `de5255a..a8aa7cc` | approved sau fix round 1 (1 Important docs đã sửa) | n/a (sequential) |

---

## Final whole-branch review

- Review `b66d70d..79a2be7`: With fixes. I1 là `clear()`/`record_failure()` chạy chồng có thể để lại pause mà không còn retry; M1 là nhánh không xếp được retry chưa có test; M2 là docs ghi "200 hóa đơn mỗi giờ".
- Fix wave `79a2be7..1ea8715`: M1 và M2 đã sửa. I1: `clear()` giờ hủy retry trước rồi mới xóa option, có regression test cho interleaving trong cùng một request.
- Re-review: M1 và M2 ADDRESSED. I1 vẫn còn một residual: negative cache `notoptions` của từng request. Lượt A đã cache "không có option", lượt B ghi pause và xếp retry, rồi `clear()` của A hủy retry nhưng bỏ qua việc xóa option vì đọc cache cũ. Kết quả là pause còn nhưng không còn retry. Reviewer đã tái hiện bằng WP/AS thật với cache tách riêng.
- **Parked (Important, không chặn merge):** action hằng giờ vẫn chạy, nên khi xảy ra chồng hiếm giữa một lần thành công và một lần lỗi ở hai request, phục hồi chỉ trễ tối đa một giờ. Fix đề xuất: khi `clear()` đã hủy retry thì gọi `delete_option()` vô điều kiện (`delete_option` đọc DB trực tiếp), thêm regression tách cache, và sửa câu "guaranteed" trong docblock `StatusSyncBackoff::clear()` cùng `design.md`.

---

## Verification evidence

- `composer check` (lint, phpstan level 8, floor guard, integration + without-woocommerce) exit 0 tại `1ea8715`: 1127 + 8 tests OK. `MatbaoStatusSyncTest` OK (73 tests).
- Chưa verify E2E với Mắt Bão thật hay WP-Cron thật; hành vi được kiểm qua integration test với Mắt Bão fake ở HTTP boundary.

---

## Next step

Run `/opsx-verify`. Quyết định residual I1 (sửa thêm, hoặc chấp nhận và ghi vào finalize).
