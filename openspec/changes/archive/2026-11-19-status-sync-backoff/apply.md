# Apply Receipt

> Generated at the end of the apply phase to mark code-implementation
> complete and provide verify with the state it needs.
> Overwritten on each apply iteration; iteration counter grows.

**Change**: `status-sync-backoff`
**Iteration**: `2`
**Applied at**: `2026-11-19 19:20`
**Executor**: `subagent-driven-development` (implementer: `implementer` agent; task reviewer và final reviewer: `code-reviewer` agent)

---

## Workspace

- **Worktree path**: `/Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.status-sync-backoff`
- **Worktree branch**: `status-sync-backoff`
- **Feature branch**: `fix/status-sync-backoff`
- **Base branch**: `main`

---

## Commits

- **Range**: `b66d70d..d69bf77` (gồm cả receipt iteration 1 `eb5f71e` và verify iteration 1 `cd4a427`)
- **Count**: `11`
- **Iteration 2**: `cd4a427..d69bf77` (1 commit)

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
- **Parked ở iteration 1 (Important, không chặn merge), đã sửa ở iteration 2:** action hằng giờ vẫn chạy, nên khi xảy ra chồng hiếm giữa một lần thành công và một lần lỗi ở hai request, phục hồi chỉ trễ tối đa một giờ. Fix đề xuất: khi `clear()` đã hủy retry thì gọi `delete_option()` vô điều kiện (`delete_option` đọc DB trực tiếp), thêm regression tách cache, và sửa câu "guaranteed" trong docblock `StatusSyncBackoff::clear()` cùng `design.md`.

---

## Iteration 2

- Iteration 2 chạy theo yêu cầu của user, không phải do verify FAIL. Verify iteration 1 ra PASS_WITH_WARNINGS với W1 (race `clear()` qua negative cache `notoptions`) và W2 (design/docblock khẳng định quá mức).
- Fix `d69bf77`: khi `clear()` đã hủy retry thì `delete_option()` chạy vô điều kiện. Không có retry thì giữ guard cũ, nên tra cứu thành công lúc không có backoff vẫn không ghi gì. Docblock `clear()` và `design.md` (quyết định 3, Risks) đã sửa lại cho đúng bảo đảm thật.
- TDD: `test_clear_deletes_pause_written_by_another_request` RED trên code cũ (option còn, pause mà 0 retry), GREEN sau fix. `MatbaoStatusSyncTest` OK (75 tests, 1504 assertions). `composer check` exit 0 (1128 + 8 tests).
- Re-review (`code-reviewer`): W1, W2 và regression "success without backoff" đều ADDRESSED; không còn interleaving nào để lại pause mà không có retry, trừ lỗi queue/DB đã ghi nhận; không có Critical/Important mới.

---

## Verification evidence

- `composer check` (lint, phpstan level 8, floor guard, integration + without-woocommerce) exit 0 tại `d69bf77`: 1128 + 8 tests OK. `MatbaoStatusSyncTest` OK (75 tests).
- Chưa verify E2E với Mắt Bão thật hay WP-Cron thật; hành vi được kiểm qua integration test với Mắt Bão fake ở HTTP boundary.

---

## Next step

Run `/opsx-verify` (iteration 2).
