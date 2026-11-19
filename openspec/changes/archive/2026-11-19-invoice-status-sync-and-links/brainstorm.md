## Design Summary

### Bối cảnh và lý do

Sau khi plugin tạo nháp, kế toán ký và phát hành trên Mắt Bão. Plugin hiện dừng hiểu biết ở `draft_created`:

- Nút "Kiểm tra trạng thái" chỉ đọc (requirement *Read-only Mat Bao status check* trong `admin-invoice-management`). Khi hóa đơn đã "Đã cấp mã/tiếp nhận", admin nhận notice đỏ "Cảnh báo: … không còn ở trạng thái Nháp", bản ghi vẫn là `draft_created` mãi, và không có event nào lên Rustrak. Một kết quả thành công bị trình bày như lỗi.
- Không có cơ chế tự cập nhật trạng thái sau nháp.
- Thông tin hóa đơn trong danh sách, chi tiết và hộp "Hóa đơn Mắt Bão" của đơn không cho biết hóa đơn đã ký chưa, số hóa đơn, MCCQT; việc quản lý và tra soát phải mở Mắt Bão.
- Không có liên kết qua lại: từ đơn không mở được chi tiết hóa đơn, từ hóa đơn không mở được đơn.

Tra cứu `invoice-detail-batch` đã trả `maTThaiHDon`, `tenTThaiHDon`, `shDon`, `mccqt`, `maLoaiHDon`, `ghiChuCQT`, nhưng plugin chỉ dùng mã trạng thái để biết còn là nháp hay không.

### Mục tiêu

1. Theo dõi đầy đủ trạng thái hóa đơn trên Mắt Bão sau khi nháp được tạo, theo bảng mã của Mắt Bão: 1 Nháp, 2 Lỗi ký, 3 Chưa gửi CQT, 4 Đã cấp mã/tiếp nhận, 5 CQT chưa phản hồi, 6 Không đủ điều kiện cấp mã/tiếp nhận, 7–9 các trạng thái Mẫu 04/SS; cộng thêm "Đã xóa trên Mắt Bão" khi tra cứu không còn thấy hóa đơn.
2. Tự động cập nhật trạng thái đó định kỳ, không cần admin bấm.
3. Nút "Kiểm tra trạng thái" ghi kết quả vào bản ghi và trình bày đúng nghĩa (đã cấp mã là thành công, không phải cảnh báo).
4. Hiển thị rõ thông tin hóa đơn: số hóa đơn, MCCQT, loại hóa đơn, ghi chú CQT, trạng thái Mắt Bão, thời điểm đồng bộ gần nhất.
5. Liên kết hai chiều giữa đơn WooCommerce và chi tiết hóa đơn.
6. Báo lỗi lên Rustrak (và Lark theo cơ chế alert hiện có) khi hóa đơn rơi vào trạng thái bất thường.

### Phạm vi giao diện

```
Danh sách hóa đơn
| Đơn (link) | Số HĐ | MTChieu | Plugin   | Mắt Bão      | Đồng bộ lúc  |
| #9013 ->   | 125   | WC9013  | Đã nháp  | Đã cấp mã    | 10:05        |

Chi tiết hóa đơn
  - có URL riêng, mở thẳng được từ link
  - link "Xem đơn #9013"
  - thêm số HĐ, MCCQT, loại HĐ, ghi chú CQT, trạng thái Mắt Bão, thời điểm đồng bộ

Hộp "Hóa đơn Mắt Bão" trong đơn hàng
  - thêm số HĐ, MCCQT, trạng thái Mắt Bão
  - link "Xem chi tiết hóa đơn"
```

### Non-goals

- Không thêm hay đổi tìm kiếm và bộ lọc của danh sách hóa đơn; giữ nguyên như hiện tại.
- Không nhận callback/webhook từ Mắt Bão.
- Plugin vẫn không ký, phát hành, sửa, hủy hay xóa hóa đơn trên Mắt Bão.
- Không đổi luồng tạo nháp, retry và gửi lại hiện có.

### Anti-goals

- Đồng bộ làm thay đổi trạng thái plugin (`draft_created`, `failed`…) hoặc kích hoạt tạo lại/gửi lại hóa đơn.
- Gửi event hoặc tin Lark lặp lại mỗi lượt đồng bộ cho cùng một trạng thái bất thường.
- Gọi API Mắt Bão vô hạn cho hóa đơn không bao giờ tới trạng thái cuối.
- Lộ dữ liệu người mua mới ra Rustrak, Lark hoặc giao diện.

## Alternatives Considered

### Alternative A: Chỉ sửa lời thông báo của nút kiểm tra
- **Approach**: Giữ kiểm tra chỉ đọc, đổi notice đỏ thành thông báo đúng nghĩa theo trạng thái Mắt Bão.
- **Pros**: Rất nhỏ, không đổi dữ liệu.
- **Cons**: Danh sách và đơn hàng vẫn hiện "Đã tạo nháp" mãi; không tự cập nhật; không có số HĐ/MCCQT.
- **Why not chosen**: Không đáp ứng yêu cầu đủ trạng thái và tự cập nhật.

### Alternative B: Nhận callback (webhook) từ Mắt Bão
- **Approach**: Mở endpoint public để Mắt Bão gọi về khi tạo nháp, ký, CQT phản hồi, xóa nháp.
- **Pros**: Cập nhật gần như tức thì, ít lượt gọi API.
- **Cons**: Cần Mắt Bão cấu hình URL cho từng site; tài liệu không mô tả xác thực callback nên dễ bị giả mạo; endpoint public tăng bề mặt tấn công.
- **Why not chosen**: Phụ thuộc bên ngoài và rủi ro bảo mật; user chọn polling.

### Alternative C: Callback kết hợp polling dự phòng
- **Approach**: Callback là kênh chính, polling làm lưới an toàn.
- **Pros**: Nhanh và bền.
- **Cons**: Tốn công nhất, vẫn mang toàn bộ rủi ro của B.
- **Why not chosen**: Chi phí và rủi ro không tương xứng với nhu cầu hiện tại.

## Agreed Approach

Polling định kỳ (user chọn phương án 1), cùng với trạng thái Mắt Bão là một lớp riêng bên cạnh trạng thái plugin.

- Trạng thái plugin giữ nguyên ý nghĩa (quá trình plugin gửi hóa đơn lên). Trạng thái Mắt Bão mô tả quá trình ký và CQT xử lý, chỉ tồn tại sau khi đã có nháp. Gộp chung một cột sẽ làm rối luồng retry và gửi lại hiện có.
- Đồng bộ chạy mỗi giờ theo lô có giới hạn, chỉ cho hóa đơn có nháp và chưa ở trạng thái cuối, và ngừng sau 30 ngày kể từ khi tạo nháp.
- Nút "Kiểm tra trạng thái" dùng cùng logic đồng bộ và ghi kết quả ngay.

## Key Decisions

- **Đổi requirement đã có:** *Read-only Mat Bao status check* (`admin-invoice-management`) không còn chỉ đọc: kiểm tra ghi trạng thái Mắt Bão và thông tin hóa đơn vào bản ghi, vẫn không tạo hay gửi lại gì và không đổi trạng thái plugin. Scenario *No longer a draft* đổi từ cảnh báo sang hiển thị trạng thái thực.
- **Đổi requirement đã có:** *Invoice detail with attempt history*, *Order edit screen invoice meta box*, *Filterable searchable paginated invoice list* (chỉ phần cột hiển thị; lọc và tìm giữ nguyên) và *Dashboard counts* được xem xét để thêm thông tin Mắt Bão và liên kết. Dashboard có thêm số liệu hay không do design quyết, nhưng không thêm bộ lọc mới.
- **Đổi requirement đã có:** `invoice-storage-schema` thêm thông tin trạng thái Mắt Bão; dữ liệu cũ giữ nguyên theo *Schema upgrades preserve data*.
- **Đổi requirement đã có:** *Invoice and template parsing* (`matbao-api-client`) đọc thêm số HĐ, MCCQT, loại HĐ, ghi chú CQT.
- **Trạng thái cuối:** "Đã cấp mã/tiếp nhận" và "Đã xóa trên Mắt Bão" ngừng đồng bộ. Các trạng thái còn lại tiếp tục được hỏi đến khi tới trạng thái cuối hoặc hết 30 ngày.
- **Trạng thái bất thường cần báo:** Lỗi ký, Không đủ điều kiện cấp mã/tiếp nhận, Đã xóa trên Mắt Bão. Báo một lần mỗi lần hóa đơn chuyển sang trạng thái đó, qua Rustrak và cơ chế alert hiện có (`failure-alerts`, `error-monitoring`). "Đã cấp mã" là kết quả bình thường, không báo.
- **Trạng thái trung gian (quyết định của agent):** Nháp, Chưa gửi CQT, CQT chưa phản hồi và 7–9 Mẫu 04/SS không phải trạng thái cuối và không báo; hiển thị theo `tenTThaiHDon` của Mắt Bão.
- **Báo một lần (quyết định của agent):** áp dụng cả khi phát hiện qua đồng bộ tự động lẫn qua nút kiểm tra; cùng trạng thái bất thường không báo lại.
- **Không tự xử lý trạng thái bất thường:** đồng bộ không tạo lại nháp đã xóa; luồng *Lost draft recreation needs explicit confirmation* giữ nguyên.
- **Chi tiết hóa đơn có URL riêng** để link từ đơn và bookmark được.
- **Tìm kiếm và bộ lọc giữ nguyên** (user xác nhận không yêu cầu).

## Open Questions

- Hành vi khi hóa đơn hết 30 ngày mà chưa tới trạng thái cuối: chỉ ngừng hỏi, hay hiển thị gợi ý kiểm tra thủ công. Đề xuất: ngừng hỏi, nút "Kiểm tra trạng thái" vẫn dùng được.
- Hóa đơn `draft_created` đã tồn tại trước khi nâng cấp: đề xuất đưa vào đồng bộ nếu nháp được tạo trong vòng 30 ngày.
