# Apply Receipt

> Generated at the end of the apply phase to mark code-implementation
> complete and provide verify with the state it needs.
> Overwritten on each apply iteration; iteration counter grows.

**Change**: `invoice-status-sync-and-links`
**Iteration**: `1`
**Applied at**: `2026-11-19 13:20` (ICT)
**Executor**: `subagent-driven-development` (implementer role: `implementer`; task reviewer + final reviewer role: `code-reviewer`)

---

## Workspace

- **Worktree path**: `/Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.invoice-status-sync-and-links`
- **Worktree branch**: `invoice-status-sync-and-links`
- **Feature branch**: `feat/invoice-status-sync-and-links` (tạo bằng `wt` từ `main` trước step 0, vì session bắt đầu trên `main`)
- **Base branch**: `main`

---

## Commits

- **Range**: `0eb4b9c..d95584a` (`0eb4b9c` = commit scaffold change trên feature branch; commit receipt này nằm ngay sau `d95584a`)
- **Count**: `21` (+ commit receipt)

---

## Tasks

- **Completed**: `23 of 23` checkboxes trong tasks.md đã chuyển sang `- [x]`
- **Remaining**: none

---

## Contract deviations

- `InvoiceRepository::record_matbao_status`: tham số `$new` đổi tên thành `$snapshot` vì phpcs cảnh báo reserved word; shape và hành vi không đổi (internal, Task 1).
- `MatbaoStatusView::order_link`: thêm tham số `string $prefix = ''` cho nhãn "Xem đơn #N" (internal, Task 4).
- `MatbaoStatusView::meta_box_rows( InvoiceRecord ): string`: method mới để markup Mắt Bão của meta box chỉ nằm trong view, theo task review (internal, Task 4).
- `InvoicePage::BADGE`, `InvoicePage::BADGE_COLORS`: từ private sang public để `MatbaoStatusView` dùng chung màu badge (internal, Task 4).
- `InvoicePage::render_list` (final review I2): giữ lại cột "Cập nhật (UTC)" bên cạnh cột mới "Đồng bộ lúc (UTC)". Spec liệt kê các cột bắt buộc, không cấm cột thêm.
- `InvoiceRepository::due_for_status_sync` (final review M2): `ORDER BY matbao_checked_at, id`, vì MySQL/MariaDB sắp NULL lên đầu khi ASC; thứ tự không đổi.

---

## Lanes

| Task | Lane branch | Lane range | Review | Merge commit |
|---|---|---|---|---|
| `Task 1` | `sequential` | `0eb4b9c..0d6b747` | approved (1 minor fixed) | `0d6b747` |
| `Task 2` | `sequential` | `eb2e57b..f2a2a96` | approved after fix round 1 | `f2a2a96` |
| `Task 3` | `sequential` | `fe684b8..775eea8` | approved after fix round 1 | `775eea8` |
| `Task 4` | `sequential` | `a3eff66..5458bf7` | approved after fix round 1 | `5458bf7` |
| `Task 5` | `sequential` | `3974a6e..a111d72` | approved after fix round 1 (+ ruling R4) | `a111d72` |
| `Task 6` | `sequential` | `4de856f..b3ce0e6` | approved after fix round 2 | `b3ce0e6` |
| Final review | `sequential` | `91d4de7..d95584a` | Ready to merge: Yes | `d95584a` |

---

## Verification evidence (apply phase)

- `composer test:integration`: 1086 tests OK (baseline 963); `composer lint` và `composer analyse` sạch (implementer, head `d95584a`).
- E2E replay chạy tại `a111d72` (controller tự chạy lại): 9 files, 84/84 passed, 9 cache replayed. Wrapper `run-e2e.ts` thoát với exit 1 vì trên Kiri `docker cp` lồng `report.json` vào `.e2e/.e2e/`. Đây là lỗi tooling có sẵn từ trước, không do change này gây ra (ruling R4); CI `e2e:replay` cần xanh trước khi merge.

## Rulings and parked findings

- R1: test deactivation đặt trong `MatbaoStatusSyncTest`.
- R2/R3: không có model env, nên các bước E2E mới là bước chính xác, không thêm `agent.act` và không ghi lại cache.
- R4: replay wrapper exit 1 trên Kiri (đường dẫn `report.json`) nằm ngoài scope; nên mở issue riêng.
- R5 (cần user quyết, thay đổi spec): final review I1. Khi Mắt Bão treo, một lượt đồng bộ có thể mất khoảng 10×30 giây, vì spec "Lookup failures leave the status unchanged" yêu cầu tiếp tục các lô còn lại.
- Parked (không chặn merge):
  - M1: 301 cho lô lẫn có/không có hóa đơn → coi mọi reference là đã xóa; cần bằng chứng từ API thật.
  - M3: badge "Đã xóa trên Mắt Bão" vẫn hiện trong lúc gửi lại.
  - M4: điều kiện nhánh cảnh báo được tính lặp ở `apply()`.
  - M5: mép cửa sổ 30 ngày khi thiếu `issued_on` (UTC so với giờ Việt Nam).
  - Task 2: cleanup của test không nằm trong `finally`.

---

## Next step

Run `/opsx-verify`.
