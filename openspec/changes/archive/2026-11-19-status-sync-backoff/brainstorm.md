## Design Summary

Issue #56: khi Mắt Bão treo (timeout thay vì trả 5xx nhanh), một lượt đồng bộ trạng thái hằng giờ gửi tối đa khoảng 10 request × 30 s, tức gần 5 phút mỗi giờ, và có thể bị `max_execution_time` cắt ngang. Nguyên nhân là requirement hiện tại **Lookup failures leave the status unchanged** (`openspec/specs/matbao-status-sync/spec.md`) yêu cầu đồng bộ "tiếp tục các hóa đơn còn lại" sau lỗi tra cứu (scenario "Mat Bao 503": "nhóm kế tiếp vẫn được tra cứu"). Mỗi nhóm vì vậy lại chờ trọn timeout.

Thiết kế đã chốt với user:

1. **Dừng sớm trong lượt:** khi gặp lỗi tra cứu tạm thời đầu tiên, lượt đồng bộ dừng lại. Lỗi tạm thời gồm lỗi transport, HTTP 5xx/408/429 và phản hồi 200 không đọc được, kể cả khi lỗi xảy ra lúc đăng nhập. Nhóm lỗi vẫn được ghi thời điểm đã thử như hiện nay. Các nhóm chưa tra cứu không bị gọi và không bị ghi thời điểm đã thử, nên vẫn giữ thứ tự ưu tiên cho lượt sau.
2. **Backoff giữa các lượt:** mỗi lượt kết thúc bằng lỗi tạm thời làm tăng chuỗi lỗi liên tiếp. Đồng bộ tự động sau đó tạm dừng 15 phút, 30 phút, 1 giờ, 2 giờ, 4 giờ, rồi giữ ở mức trần 6 giờ cho mọi lần sau. Lượt hằng giờ rơi vào thời gian tạm dừng thì kết thúc ngay, không gọi Mắt Bão. Khi hết tạm dừng, một lượt thử lại chạy ngay lúc đó, không phải chờ tới lượt hằng giờ kế tiếp; nhờ vậy mức 15 và 30 phút mới có tác dụng. Tại mỗi thời điểm chỉ có một lượt thử lại được xếp lịch.
3. **Phục hồi:** lần tra cứu thành công đầu tiên (từ đồng bộ tự động hoặc nút "Kiểm tra trạng thái") xóa chuỗi lỗi và thời gian tạm dừng.
4. **Lỗi không tạm thời** (4xx khác, sai thông tin đăng nhập, Mắt Bão từ chối): giữ hành vi hiện tại, tức là ghi log cảnh báo và tiếp tục các nhóm còn lại, không tính vào backoff.
5. **Nút "Kiểm tra trạng thái"** (tra cứu một hóa đơn do admin bấm) không bị backoff chặn.
6. **Không alert:** lỗi tra cứu và backoff kéo dài vẫn chỉ ghi log cảnh báo, không tạo alert và không gửi Lark, đúng với spec hiện tại.

Requirement được đổi: **Lookup failures leave the status unchanged** (matbao-status-sync), ở phần "tiếp tục các hóa đơn còn lại" và scenario "Mat Bao 503". Thêm requirement mới cho backoff. Các requirement khác giữ nguyên: Hourly status sync (giới hạn 200, gom 20 `MTChieu`, ưu tiên hóa đơn chưa kiểm tra), Sync never changes the invoice workflow, Matching the stored Mat Bao invoice (errorCode 301 không phải lỗi), Abnormal status alerted once per transition.

## Alternatives Considered

### Alternative A: Giữ nguyên hành vi (phương án 1 của issue)
- **Approach**: không đổi spec.
- **Pros**: không tốn công.
- **Cons**: mỗi giờ tốn khoảng 5 phút worker khi Mắt Bão treo, có nguy cơ bị cắt giữa chừng.
- **Why not chosen**: không giải quyết issue.

### Alternative B: Chỉ dừng sớm (phương án 2 của issue)
- **Approach**: dừng lượt ở lỗi tạm thời đầu tiên, lượt sau vẫn chạy đúng mỗi giờ.
- **Pros**: đơn giản, mỗi lượt khi Mắt Bão treo tối đa khoảng một timeout (cộng đăng nhập).
- **Cons**: Mắt Bão treo kéo dài vẫn tốn một timeout mỗi giờ.
- **Why not chosen**: user muốn retry kiểu backoff.

### Alternative C: Retry trong lượt với backoff ngắn và trần thời gian
- **Approach**: thử lại ngay trong lượt (2 s, 4 s…), giới hạn tổng khoảng 20 s.
- **Pros**: phục hồi nhanh với lỗi chập chờn.
- **Cons**: Mắt Bão treo thì mỗi lần thử vẫn tốn 30 s, nên trần 20 s vô nghĩa; lượt chạy càng dài.
- **Why not chosen**: làm nặng thêm đúng vấn đề cần sửa.

## Agreed Approach

Dừng sớm cộng backoff giữa các lượt (15p → 30p → 1h → 2h → 4h → 6h, trần 6h), kèm lượt thử lại lúc hết tạm dừng. Lý do chọn: khi Mắt Bão treo, mỗi lượt chỉ tốn khoảng một timeout, và số lượt tốn công giảm dần theo thời gian treo. Lần thành công đầu tiên đưa nhịp về hằng giờ. Admin vẫn kiểm tra thủ công được bất cứ lúc nào.

## Key Decisions

- Phân loại "lỗi tạm thời" dùng đúng định nghĩa retryable sẵn có của plugin (transport, 5xx/408/429, phản hồi 200 không đọc được). Lỗi trùng `MTChieu` không xảy ra khi tra cứu.
- Thang backoff: 15 phút, 30 phút, 1 giờ, 2 giờ, 4 giờ, rồi 6 giờ cho mọi lần sau.
- Hết tạm dừng thì chạy một lượt thử lại; lịch hằng giờ vẫn giữ nguyên. Lượt thử lại thành công thì mọi lượt sau chạy theo nhịp hằng giờ.
- Thành công ở nút "Kiểm tra trạng thái" cũng xóa backoff. Lỗi ở nút đó không tăng backoff, vì admin thao tác chủ động.
- Không alert khi backoff kéo dài; chỉ log cảnh báo.
- Trạng thái backoff là dữ liệu tạm, bị xóa khi gỡ cài đặt, giống backoff gửi Sentry.

## Open Questions

Không còn.
