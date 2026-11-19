# Apply Receipt

> Generated at the end of the apply phase to mark code-implementation
> complete and provide verify with the state it needs.
> Overwritten on each apply iteration; iteration counter grows.

**Change**: `customer-invoice-view`
**Iteration**: `1`
**Applied at**: `2026-11-19T15:36:00Z`
**Executor**: `subagent-driven-development` (implementer: `implementer` cho Task 1–5, `general-purpose` cho Task 6 và đợt fix cuối vì định nghĩa agent `implementer` không còn trong `~/.pi/agent/agents`; task reviewer và review cuối toàn nhánh: `code-reviewer`)

---

## Workspace

- **Worktree path**: `/Users/naicoi/Projects/wordpress/wordpress-plugin-einvoice-matbao.customer-invoice-view`
- **Worktree branch**: `customer-invoice-view`
- **Feature branch**: `feat/customer-invoice-view`
- **Base branch**: `main`

---

## Commits

- **Range**: `0611904..<receipt commit>` (implementation `0611904..2509b3f`; commit artifact là `0611904` trên `feat/customer-invoice-view`)
- **Count**: `19` (18 implementation và tick, cộng commit receipt)

---

## Tasks

- **Completed**: `20 of 20` checkbox trong tasks.md đã chuyển thành `- [x]`
- **Remaining**: `none`

---

## Contract deviations

- `E2E seam /invoice/<id> response`: trả thêm `matbao_status`, để poll e2e của Task 6 chờ được hóa đơn đã cấp mã. Seam chỉ dùng cho test, nội bộ, chỉ cộng thêm trường. Đã ghi vào plan.md Contracts.
- `PdfResponder::clean_buffers( int $keep = 0 )`: method protected mới, `send()` gọi trước `emit()` để output buffer bị bỏ sót không làm hỏng PDF (Minor 1 của review cuối). Nội bộ. Đã ghi vào plan.md Contracts.
- `BuyerPersonalData::retained_message`: chuỗi admin liệt kê thêm "mã tra cứu" trong các trường kế toán được giữ lại khi xóa dữ liệu (Minor 4 của review cuối). Nội bộ, chỉ đổi câu chữ.

---

## Lanes

| Task | Lane branch | Lane range | Review | Merge commit |
|---|---|---|---|---|
| `1` | `sequential` | `0611904..5d20150` | approved (1 Minor được hoãn) | n/a |
| `2` | `sequential` | `e7d74b3..4ad530c` | approved sau 1 vòng fix | n/a |
| `3` | `sequential` | `a2923fd..05db91a` | approved sau 1 vòng fix | n/a |
| `4` | `sequential` | `2a2ae90..d6a3e34` | approved (1 Minor được hoãn) | n/a |
| `5` | `sequential` | `2df7ab4..64fa349` | approved sau 1 vòng fix | n/a |
| `6` | `sequential` | `285abeb..c8ec3c7` | approved sau 1 vòng fix | n/a |
| final review | `sequential` | `365c09a..2509b3f` | toàn nhánh APPROVE; đợt fix đã re-review APPROVE | n/a |

---

## Verification evidence (apply)

- `composer check` (dev container `einv-civ`, sau đợt fix cuối): PHPCS sạch, PHPStan không lỗi, floor guard OK, integration OK (1218 test), without-woocommerce OK (8 test).
- E2E: `E2E_ARGS="customer-invoice.army.ts" bun run e2e:record` pass 2/2. Bộ e2e đầy đủ chưa xanh trong worktree này vì lý do môi trường: không truy cập được model endpoint, và chưa build `assets/dist`.
- Demo Mắt Bão (read-only, `docs/research/demo-download-invoice.md`): `download-invoice` trả `success`/`errorCode` 200 kèm `data_PDF_Base64`, khớp client. Demo trả PDF cả cho nháp, nên lần tra cứu lại của plugin là lớp chặn duy nhất.

## Known follow-ups (parked)

- Chưa tắt `zlib.output_compression` trước khi gửi PDF. Trên server bật tính năng này, `Content-Length` có thể lệch kích thước thật.
- Chưa giới hạn số lần tải theo đơn. Đây là rủi ro design đã chấp nhận và đã ghi trong docs.

---

## Next step

Chạy `/opsx-verify` trong worktree này.
