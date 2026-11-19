## Why

Khi Mắt Bão treo (timeout thay vì trả 5xx nhanh), một lượt đồng bộ trạng thái hằng giờ gửi tới khoảng 10 request × 30 s, tức gần 5 phút mỗi giờ, và có thể bị `max_execution_time` cắt ngang (issue #56, finding I1, ruling R5). Spec hiện tại buộc đồng bộ tra cứu tiếp các nhóm còn lại sau lỗi, nên mỗi nhóm đều chờ trọn timeout. Dừng sớm và tạm dừng theo backoff giúp giới hạn chi phí mỗi lượt ở khoảng một timeout và thưa dần số lượt tốn công khi Mắt Bão treo lâu.

## What Changes

**Lỗi tra cứu tạm thời trong một lượt**
- From: lỗi tra cứu bất kỳ chỉ ghi thời điểm đã thử cho nhóm đó, log cảnh báo, rồi tra cứu tiếp các nhóm còn lại.
- To: lỗi tạm thời (transport, HTTP 5xx/408/429, phản hồi 200 không đọc được, kể cả khi đăng nhập) dừng lượt ngay. Các nhóm chưa tra cứu không bị gọi và không bị ghi thời điểm đã thử. Lỗi không tạm thời vẫn đi tiếp như cũ.
- Reason: không lãng phí thêm timeout khi Mắt Bão đang treo.
- Impact: non-breaking. Khi Mắt Bão lỗi, ít hóa đơn được tra cứu hơn trong lượt đó.

**Backoff giữa các lượt (mới)**
- Mỗi lượt dừng vì lỗi tạm thời làm đồng bộ tự động tạm dừng 15 phút, 30 phút, 1 giờ, 2 giờ, 4 giờ, rồi 6 giờ (trần). Lượt hằng giờ rơi vào thời gian tạm dừng thì bỏ qua. Hết tạm dừng thì chạy ngay một lượt thử lại.
- Tra cứu thành công (tự động hoặc nút "Kiểm tra trạng thái") xóa backoff. Nút "Kiểm tra trạng thái" không bị chặn, và lỗi ở nút đó không làm tăng backoff.
- Không alert; chỉ log cảnh báo. Gỡ cài đặt xóa trạng thái backoff, vô hiệu hóa plugin hủy lượt thử lại.

## Capabilities

### New Capabilities

(không có)

### Modified Capabilities
- `matbao-status-sync`: đổi requirement "Lookup failures leave the status unchanged" (dừng lượt ở lỗi tạm thời; scenario "Mat Bao 503" đổi kỳ vọng), thêm requirement backoff, và thêm scenario cho lượt thử lại vào "Status sync stops with the plugin".

## Impact

- Code: `src/Invoice/MatbaoStatusSync.php`, một class mới cho trạng thái backoff trong `src/Invoice/`, `src/Uninstall/Uninstaller.php`.
- Dữ liệu: thêm một option `einvoice_matbao_*` không autoload và một Action Scheduler single action trong group `einvoice-matbao`. Không đổi schema DB.
- Tests: `tests/integration/MatbaoStatusSyncTest.php`, `tests/integration/UninstallTest.php`.
- Docs: `docs/phat-trien.md`, `docs/huong-dan-su-dung.md`.
- Không thêm dependency, không đổi public API/REST.
