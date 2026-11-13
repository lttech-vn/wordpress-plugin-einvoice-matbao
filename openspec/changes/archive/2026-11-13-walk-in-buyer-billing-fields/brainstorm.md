## Design Summary

**Vấn đề (báo cáo):** khách vãng lai (không tick "Yêu cầu xuất hóa đơn") đã điền đủ billing (tên, địa chỉ, SĐT, email) nhưng hóa đơn nháp chỉ có `NMua_Ten` = "Khách hàng không lấy hóa đơn". Hành vi này đúng theo spec hiện hành (`buyer-snapshot-privacy` → "Walk-in buyer"; `buyer-invoice-request` → "Walk-in buyer by default"), nên đây là thay đổi yêu cầu, không phải bug.

**Nguyên tắc mới:** thông tin liên hệ người mua (tên, SĐT, email, địa chỉ) luôn lấy từ billing của WooCommerce cho mọi loại người mua. Ô riêng ở checkout chỉ còn cho thông tin đặc thù hóa đơn: tên doanh nghiệp, MST, CCCD. Khách nhập một lần, có thông tin nào thì gửi thông tin đó.

**Bảng trường theo loại người mua** (kiểm tra trước khi submit checkout, ở trình duyệt và server; báo lỗi từng trường):

| Trường | Vãng lai | Cá nhân | Doanh nghiệp |
|---|---|---|---|
| Họ tên billing | gửi nếu có | bắt buộc | không bắt buộc |
| Tên doanh nghiệp, MST | không | không | bắt buộc |
| CCCD | không | bắt buộc | không |
| Địa chỉ billing | gửi nếu có | bắt buộc | bắt buộc |
| SĐT billing | gửi nếu có, đúng định dạng | bắt buộc | bắt buộc |
| Email billing | gửi nếu có, đúng định dạng | không bắt buộc | bắt buộc |

**Vãng lai:** `NMua_Ten` = họ tên billing nếu có, nếu không dùng tên vãng lai đã cấu hình. Không chặn checkout; trường sai định dạng thì bỏ qua thay vì từ chối.

**Cơ sở từ API Mắt Bão:** chỉ `NMua_Ten` là bắt buộc (demo 14/10/2026: thiếu toàn bộ buyer fields → HTTP 400 `NMua_Ten is required`; chỉ `NMua_Ten` vẫn tạo được nháp). `NMua_DChi` là một chuỗi đơn (tối đa 400), không có địa chỉ giao hàng. Mọi yêu cầu bắt buộc khác là quy tắc nghiệp vụ của shop, không phải ràng buộc API.

## Alternatives Considered

### Alternative A: Giữ nguyên
- **Approach**: vãng lai chỉ `NMua_Ten`; hướng dẫn khách tick checkbox khi cần.
- **Pros**: không đổi code, privacy chặt nhất.
- **Cons**: khách điền thông tin nhưng không lên hóa đơn; trái yêu cầu mới.
- **Why not chosen**: yêu cầu nghiệp vụ là khách điền thì phải đưa vào hóa đơn.

### Alternative B: Vãng lai gửi billing, giữ ô địa chỉ riêng
- **Approach**: chỉ sửa `BuyerSnapshotter::build()` cho vãng lai; cá nhân và doanh nghiệp vẫn nhập địa chỉ riêng.
- **Pros**: đổi nhỏ, không đụng checkout.
- **Cons**: không thống nhất (SĐT/email/tên từ billing, địa chỉ từ ô riêng); khách cá nhân/doanh nghiệp vẫn nhập địa chỉ hai lần.
- **Why not chosen**: bị chỉ ra là không nhất quán; billing là địa chỉ mua hàng nên đủ dùng.

### Alternative C: Mọi thông tin liên hệ từ billing, bỏ ô địa chỉ riêng (chọn)
- **Approach**: như mục Design Summary.
- **Pros**: một nguồn dữ liệu, khách nhập một lần, vãng lai và cá nhân/doanh nghiệp cùng quy tắc nguồn.
- **Cons**: đổi checkout Classic/Blocks, rule, snapshot, spec; doanh nghiệp có địa chỉ đăng ký khác billing phải điền địa chỉ đăng ký vào billing.
- **Why not chosen**: (là phương án được chọn).

## Agreed Approach

Alternative C. Quyết định cuối của người dùng (thay cho ý kiến trước "giữ ô riêng cho doanh nghiệp"): bỏ ô địa chỉ riêng cho mọi loại người mua, doanh nghiệp bắt buộc có địa chỉ billing và phải được báo lỗi trước khi submit.

## Key Decisions

- **Thay đổi yêu cầu đã chỉ định:** sửa `buyer-snapshot-privacy` ("Walk-in buyer": không còn chỉ `NMua_Ten`), `buyer-invoice-request` (địa chỉ cá nhân/doanh nghiệp lấy từ billing; "Classic checkout fields" và "Fields per type" không còn ô địa chỉ riêng; "Walk-in buyer by default" vẫn không validate và không lưu yêu cầu).
- Cá nhân và doanh nghiệp bắt buộc đủ trường theo bảng, kiểm tra trước khi submit cả Classic và Blocks, theo "Server-side validation is authoritative".
- Vãng lai không bị chặn checkout; chỉ gửi trường hợp lệ.
- Privacy: dữ liệu billing của vãng lai vào snapshot nên chịu cùng cơ chế exporter/eraser/REST redaction của snapshot hiện có (xử lý chi tiết ở design).

## Open Questions

- Đơn cũ đã lưu yêu cầu với `address` riêng nhưng chưa completed: dùng `address` đã lưu khi billing trống, hay luôn dùng billing (quyết ở design).
- Billing address bị tắt/không có trên shop (ví dụ chỉ bán hàng ảo): cá nhân/doanh nghiệp sẽ không đặt được; cần quyết ở design có nhắc cấu hình hay cho nhập địa chỉ ở nơi khác.
- Điều kiện phát hành/production của Mắt Bão khi thiếu field người mua chưa kiểm chứng (chỉ xác nhận ở demo).
