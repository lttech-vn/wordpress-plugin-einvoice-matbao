## Design Summary

**Mục đích.** Người mua có tài khoản tự xem tình trạng hóa đơn điện tử của từng đơn hàng và tự tải PDF khi hóa đơn đã phát hành hợp lệ, thay vì phải hỏi cửa hàng. Người bán đồng thời đáp ứng nghĩa vụ chuyển hóa đơn cho người mua mà không lộ hóa đơn nháp hay thông tin vận hành nội bộ.

**Người dùng và nơi hiển thị.** Chỉ trang chi tiết đơn trong My Account > Orders (`view-order`), chỉ khách đăng nhập và là chủ đơn. Guest checkout, trang order-received và email nằm ngoài phạm vi.

**Theo đơn hàng, không theo người dùng.** Mọi đơn có bản ghi hóa đơn đều hiện khối hóa đơn, kể cả đơn của **người mua vãng lai** (không yêu cầu xuất hóa đơn), vì plugin vẫn tạo hóa đơn cho đơn đó. Đơn chưa có bản ghi hóa đơn (chưa `completed`) không hiện khối.

**Khách thấy gì.** Chỉ trạng thái bằng lời thân thiện. Không hiện mã lỗi, lý do lỗi, `MTChieu`, mã tra cứu, tên trạng thái Mắt Bão hay nút thao tác của staff.

```
 Chi tiết đơn #1234
 +------------------------------------------------+
 | Hóa đơn điện tử                                |
 | Trạng thái: Đã xuất hóa đơn                    |
 | Số hóa đơn: 125 - Ký hiệu 1C26TAT - 19/11/2026 |
 | [ Tải hóa đơn (PDF) ]                          |
 +------------------------------------------------+
```

| Tình trạng nội bộ | Khách thấy | PDF |
|---|---|---|
| Chưa có bản ghi hóa đơn | ẩn khối | – |
| `pending`, `sending`; `draft_created` khi Mắt Bão chưa `ISSUED` và không bất thường | "Đang xuất hóa đơn" | không |
| `failed`, `needs_attention`, `not_configured`, `stopped`; Mắt Bão lỗi ký, đã xóa, không đủ điều kiện | "Đang xử lý, cửa hàng sẽ liên hệ nếu cần" | không |
| `skipped` (đơn 0 đồng) | "Đơn hàng không cần xuất hóa đơn" | không |
| Mắt Bão `ISSUED` (4), loại không phải xóa bỏ (3) hay bị thay thế (5) | "Đã xuất hóa đơn" + số, ký hiệu, ngày lập | **có** |
| `ISSUED` nhưng loại xóa bỏ hoặc bị thay thế | "Hóa đơn đã được điều chỉnh, vui lòng liên hệ cửa hàng" | không |

**Tải PDF.** Khách bấm nút → plugin kiểm tra quyền chủ đơn và điều kiện phát hành → tra cứu lại Mắt Bão một lần để chắc hóa đơn chưa bị xóa bỏ/thay thế sau khi đồng bộ dừng → lấy PDF từ Mắt Bão → trả file cho trình duyệt. Plugin không lưu file PDF. Mắt Bão lỗi thì khách thấy thông báo "thử lại sau".

**Goals.** Khách thấy trạng thái đúng theo đơn; chỉ hóa đơn đã phát hành hợp lệ mới tải được; mỗi lần tải đều kiểm quyền.

**Non-goals.** Gửi hóa đơn qua email; guest checkout; hiện hóa đơn ở order-received; khách tự sửa thông tin hoặc yêu cầu xuất lại; tải XML; hiện mã tra cứu để khách tự tra trên portal Mắt Bão.

**Anti-goals** (vẫn là thất bại dù chạy được): khách thấy hoặc tải được hóa đơn nháp; khách tải được PDF của đơn người khác; khách tải được hóa đơn đã bị xóa bỏ/thay thế; khách thấy chi tiết lỗi nội bộ; PDF chứa thông tin cá nhân nằm lại trên server.

**Requirement hiện có liên quan.**

- `discovery.md`: "Bản đầu không hiển thị hoặc gửi nháp cho khách". Change giữ nguyên ý này: nháp không bao giờ có PDF và chỉ hiện "Đang xuất hóa đơn".
- `matbao-status-sync` / *Final and abnormal Mat Bao statuses*, *Hourly status sync of created drafts*: đồng bộ dừng ở trạng thái cuối `ISSUED`. Change **không đổi** requirement này; rủi ro xóa bỏ/thay thế sau phát hành được xử lý bằng tra cứu lại lúc tải.
- `matbao-status-sync` / *Sync never changes the invoice workflow*: giữ nguyên. Tra cứu lúc tải chỉ đọc.
- `matbao-api-client` / *Invoice and template parsing*: **mở rộng**, đọc thêm mã tra cứu (`maTraCuu`) từ kết quả tra cứu.
- `matbao-api-client`: **thêm** lời gọi tải PDF (`download-invoice`).
- `invoice-storage-schema` / *Mat Bao status fields*: **mở rộng**, thêm trường mã tra cứu. *New Mat Bao invoice resets Mat Bao status fields*: mã tra cứu được xóa cùng các trường khác.
- `buyer-snapshot-privacy` / *REST order responses hide buyer meta*: không đổi. Khối hóa đơn của khách chỉ đọc bản ghi hóa đơn, không lộ meta người mua.

## Alternatives Considered

### Alternative A: Plugin làm trung gian, lấy PDF từ Mắt Bão mỗi lần khách bấm
- **Approach**: Endpoint của plugin kiểm quyền, gọi `download-invoice`, trả PDF; không lưu file.
- **Pros**: Mỗi lần tải đều kiểm quyền; không có file thông tin cá nhân trên server; không lộ URL Mắt Bão; tra cứu lại được ngay trước khi trả file.
- **Cons**: Mỗi lần tải tốn một hoặc hai request Mắt Bão; khách phụ thuộc Mắt Bão đang hoạt động.
- **Why not chosen**: Được chọn.

### Alternative B: Như A nhưng lưu PDF trên server
- **Approach**: Lần tải đầu lấy từ Mắt Bão rồi lưu vào uploads, các lần sau phục vụ từ file.
- **Pros**: Nhanh, vẫn tải được khi Mắt Bão lỗi.
- **Cons**: File thông tin cá nhân trên đĩa; nginx bỏ qua `.htaccess` nên dễ bị truy cập trực tiếp; phải xóa khi chạy privacy eraser; file cũ không phản ánh việc xóa bỏ/thay thế.
- **Why not chosen**: Rủi ro bảo mật và vòng đời dữ liệu lớn hơn lợi ích tốc độ.

### Alternative C: Chuyển khách sang `urlDownloadPDF` của Mắt Bão
- **Approach**: Lưu URL từ kết quả tra cứu, nút tải trỏ thẳng tới URL đó.
- **Pros**: Đơn giản nhất, không tốn băng thông server.
- **Cons**: URL là capability URL, ai có link đều tải được; chưa rõ có hết hạn không; research từng phải redact URL này; không kiểm quyền lúc tải.
- **Why not chosen**: Không bảo đảm chỉ chủ đơn tải được.

## Agreed Approach

Alternative A. Đây là cách duy nhất vừa kiểm quyền ở mỗi lần tải, vừa chặn được hóa đơn đã bị xóa bỏ/thay thế (nhờ tra cứu lại ngay trước khi trả file), vừa không để lại file thông tin cá nhân trên server. Đổi lại, mỗi lần tải phụ thuộc Mắt Bão. Việc tải PDF không thường xuyên nên chi phí này chấp nhận được.

## Key Decisions

- Hiển thị theo đơn hàng: mọi đơn có bản ghi hóa đơn, kể cả người mua vãng lai.
- Chỉ My Account > Orders > chi tiết đơn, chỉ chủ đơn đã đăng nhập; guest checkout không có.
- Hiện cả trạng thái đang chờ và lỗi, nhưng chỉ bằng lời thân thiện theo bảng trên, không có chi tiết của staff.
- PDF chỉ khi Mắt Bão `ISSUED` và loại không phải xóa bỏ hoặc bị thay thế.
- Tra cứu lại Mắt Bão một lần ngay trước khi trả PDF; nếu không còn hợp lệ thì không trả file. Đồng bộ hằng giờ giữ nguyên.
- Mã tra cứu lấy từ kết quả tra cứu sẵn có rồi lưu vào bản ghi hóa đơn; payload tạo nháp không đổi.
- Không lưu PDF trên server.

## Open Questions

- Chưa xác minh trên demo: `download-invoice` có trả PDF cho hóa đơn `ISSUED` khi gọi với `maTraCuu` do Mắt Bão tự sinh hay không (Postman mẫu cho thấy response có `data.data_PDF_Base64`). Kiểm chứng trong quá trình làm.
- Hóa đơn đã `ISSUED` trước khi có change này chưa có mã tra cứu lưu sẵn, vì đồng bộ không còn tra cứu chúng. Lần tải đầu cần lấy mã tra cứu từ lần tra cứu lại.
- Câu chữ tiếng Việt cuối cùng cho từng trạng thái có thể chỉnh lúc review.
