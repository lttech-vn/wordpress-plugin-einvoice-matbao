## Context

`MatbaoStatusSync::sync_batch()` lấy tối đa 200 hóa đơn, gom theo mẫu và chia nhóm 20, rồi gọi `sync_chunk()` tuần tự. `lookup()` bắt `MatbaoError`, gọi `touch_matbao_checked`, ghi `Monitor::log('warn', …)` và trả `null`; vòng lặp đi tiếp. `MatbaoClient` có timeout 30 s cho mỗi request và có thể đăng nhập lại trước khi gọi. `MatbaoError::is_retryable()` đã phân loại lỗi tạm thời (transport, 5xx/408/429, 305, phản hồi không đọc được). 305 không xảy ra ở tra cứu; 301 đã được client đổi thành `MatbaoLookup` "not found", không phải lỗi.

Mẫu backoff sẵn có: `WpHttpClient` lưu `einvoice_matbao_sentry_backoff_until` (option, không autoload); `DraftInvoiceWorkflow::BACKOFF_SECONDS` là thang delay dạng mảng hằng.

## Goals / Non-Goals

**Goals:**
- Một lượt gặp lỗi tạm thời tốn tối đa khoảng một timeout (cộng đăng nhập).
- Tạm dừng theo thang 15p → 30p → 1h → 2h → 4h → 6h (trần), thử lại ngay khi hết tạm dừng.
- Thành công đầu tiên đưa đồng bộ về nhịp hằng giờ.

**Non-Goals:**
- Retry trong cùng lượt; alert khi Mắt Bão treo lâu; đổi `MatbaoClient` (timeout, login); áp backoff cho luồng tạo nháp.

## Decisions

1. **Class riêng `StatusSyncBackoff`** (`src/Invoice/StatusSyncBackoff.php`) giữ trạng thái và thang delay: `is_paused()`, `record_failure(): int` (tăng chuỗi lỗi, trả số giây tạm dừng và ghi mốc hết hạn), `clear()`. Thời gian lấy qua `time()` của PHP; test đặt option trực tiếp để mô phỏng quá khứ/tương lai. Lý do tách: `MatbaoStatusSync` đã ôm lookup, apply, notify; backoff là một trách nhiệm riêng dễ test.
2. **Lưu trong một option** `einvoice_matbao_status_sync_backoff` = `array{failures:int, until:int}` (unix time), `autoload = false`. Giá trị hỏng hoặc thiếu coi như không backoff. Chọn option thay transient vì transient có thể bị object cache đẩy ra, làm mất chuỗi lỗi; chọn mảng một option thay hai option để ghi một lần.
3. **Lượt thử lại là single action riêng** `einvoice_matbao_status_sync_retry` trong group `einvoice-matbao`, cùng handler `run()`. Mỗi lần `record_failure` thì gỡ retry action đang chờ (`as_unschedule_all_actions`) rồi xếp action mới tại `until`, nên các lượt tuần tự chỉ để lại một retry. `clear()` cũng gỡ retry action. Hai lượt chạy chồng vẫn có thể để lại hai retry (cùng gỡ rồi cùng xếp); không thêm khóa: retry thừa chạy khi `is_paused()` còn đúng sẽ trả về ngay mà không gọi Mắt Bão; nếu backoff đã bị xóa trước đó thì retry thừa chạy như một lượt thường (một lần tra cứu thừa, chấp nhận). Bảo đảm "một retry" chỉ áp dụng cho các lượt tuần tự. Không dùng cờ `unique` của `as_schedule_single_action` vì nó giữ retry cũ thay vì dời sang mốc mới. Không dùng chung hook recurring, vì `schedule()` kiểm tra `as_has_scheduled_action(ACTION)` sẽ thấy single action rồi bỏ qua việc xếp lại recurring. Recurring hằng giờ giữ nguyên lịch; lượt nào chạy trong thời gian tạm dừng thì `sync_batch()` trả về ngay.
4. **`sync_batch()` dừng ở lỗi tạm thời:** `lookup()` trả kết quả ba trạng thái (lookup / lỗi tạm thời / lỗi khác). Lỗi tạm thời: touch nhóm đó, log `warn` như cũ, `record_failure()`, log `warn` "Mat Bao status sync paused" kèm `failures` và `pause_seconds`, rồi `return`. Lookup thành công (client trả `MatbaoLookup`, kể cả 301/`notFound`, bất kể `apply()` ghi được hay không): `clear()` (chỉ ghi option khi đang có backoff, để tránh một lần ghi DB mỗi nhóm). Lỗi 401 được client tự đăng nhập lại; chỉ lỗi cuối cùng client ném ra mới được phân loại. Kiểm tra `is_paused()` sau `resolve_client()`: không có credential thì vẫn bỏ qua như cũ.
5. **`sync_one()`** không kiểm tra `is_paused()`. Thành công thì `clear()`, thất bại không `record_failure()`.
6. **Gỡ cài đặt:** `Uninstaller::STATUS_SYNC_BACKOFF_OPTION` bị xóa ở nhánh mặc định, cạnh `SENTRY_BACKOFF_OPTION`. Retry action bị hủy theo group như các action khác.

## Risks / Trade-offs

- [Lượt thử lại và lượt hằng giờ chạy chồng nhau] → hiếm, vì lượt hằng giờ bỏ qua khi đang tạm dừng; nếu cả hai cùng thất bại thì chuỗi lỗi tăng hai bậc (tạm dừng dài hơn, vẫn giới hạn 6 giờ) và có thể còn hai retry chờ, retry thừa không gọi Mắt Bão khi còn tạm dừng, và nhiều nhất gây một lượt tra cứu thừa khi backoff đã bị xóa (xem quyết định 3). Chấp nhận, không thêm khóa.
- [`as_schedule_single_action()` trả 0] → log warn, backoff vẫn được ghi; lượt hằng giờ sau khi hết tạm dừng vẫn chạy.
- [Luồng log khi tạm dừng] → `record_failure()` tự ghi log `Mat Bao status sync paused` kèm `failures` và `pause_seconds`, vì nó sở hữu hai giá trị này.
- [Lỗi chập chờn đơn lẻ làm tạm dừng 15 phút] → lượt thử lại sau 15 phút bù lại, nhanh hơn chờ lượt hằng giờ như hiện nay.
- [Mắt Bão treo lâu không ai biết] → log `warn` mỗi lượt dừng; admin vẫn thấy trạng thái cũ và nút "Kiểm tra trạng thái" vẫn dùng được. Alert nằm ngoài phạm vi.
- [Sai credential (401) không tính là lỗi tạm thời] → lượt vẫn đi tiếp như cũ, nhưng lỗi đăng nhập trả nhanh nên không tốn timeout.

## Migration Plan

Không cần migration: option và retry action được tạo khi có lỗi đầu tiên. Rollback bằng phiên bản cũ thì option thừa bị bỏ qua và bị xóa khi gỡ cài đặt (delete-all quét prefix); retry action còn treo sẽ không có handler, Action Scheduler đánh dấu failed một lần rồi thôi.
