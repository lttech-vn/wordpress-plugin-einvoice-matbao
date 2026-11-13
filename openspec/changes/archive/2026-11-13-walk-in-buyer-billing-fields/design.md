## Context

Snapshot người mua được dựng ở `BuyerSnapshotter::build()` khi đơn completed. Hiện vãng lai trả snapshot chỉ có `NMua_Ten`; cá nhân/doanh nghiệp lấy tên, SĐT, email từ billing nhưng địa chỉ từ yêu cầu lưu trên đơn (`einv_address` / extension `address`). Validate dùng chung `BuyerRules::validate()` ở Classic, Blocks và `BuyerSnapshotter::validate()`; trình duyệt mirror bằng `checkout-rules.ts`.

## Goals / Non-Goals

**Goals:**
- Một nguồn cho thông tin liên hệ: billing.
- Vãng lai gửi mọi thông tin billing hợp lệ mà không chặn checkout.
- Cá nhân/doanh nghiệp vẫn bị kiểm tra trường bắt buộc trước khi submit (client và server).
- Privacy exporter/eraser bao phủ dữ liệu cá nhân mới của đơn vãng lai.

**Non-Goals:**
- Không đổi cách ánh xạ MST/CCCD/tên doanh nghiệp (vẫn ô riêng).
- Không thêm tùy chọn Settings, không đổi DB schema.
- Không xác minh điều kiện phát hành/production của Mắt Bão.

## Decisions

- **`BillingAddress` helper (PHP)** ghép `NMua_DChi` từ địa chỉ 1, địa chỉ 2, thành phố, tên tỉnh/thành (nhãn từ `WC()->countries`, fallback mã), bỏ phần trống, nối bằng ", ", bỏ quốc gia và mã bưu điện. Một chỗ duy nhất cho Classic (mảng posted), Blocks và snapshot (đơn). Lý do: tránh ba cách ghép lệch nhau.
- **Địa chỉ quá 400 ký tự:** cá nhân/doanh nghiệp bị từ chối (giữ luật "Length limits" hiện có); vãng lai cắt ở 400 vì không được chặn checkout.
- **Vãng lai lọc từng trường:** SĐT (qua `normalize_phone` + `PHONE_PATTERN`), email (`is_email`, ≤ 50), họ tên ≤ 100 cho `NMua_HVTNMHang`; trường sai bị bỏ, không lỗi. `NMua_Ten` = họ tên billing (cắt 400) nếu có, ngược lại tên cấu hình.
- **Cờ dữ liệu cá nhân trong snapshot:** thêm khóa `personal` (bool, mặc định `false` khi đọc snapshot cũ) vào dạng lưu của `BuyerSnapshot`. Exporter, eraser và `personal_values` dùng cờ này (hoặc type cá nhân/doanh nghiệp) thay cho `type !== WALK_IN`. Lý do: snapshot vãng lai cũ chỉ chứa tên chung của cửa hàng, không coi là dữ liệu cá nhân; snapshot mới có billing thì có.
- **Yêu cầu đã lưu giữ khóa `address`** để đọc, nhưng không ghi nữa (ghi chuỗi rỗng). Khi dựng snapshot cá nhân/doanh nghiệp: địa chỉ billing nếu có, nếu trống thì dùng `address` cũ của yêu cầu (đơn đặt trước khi đổi). Revalidation dùng cùng thứ tự.
- **Blocks:** giữ `address` trong schema extension nhưng bỏ qua giá trị, để script đã cache gửi `address` không nhận HTTP 400. Phía client lấy địa chỉ từ `billingAddress` trong `wc/store/cart`, cùng nguồn đang dùng cho tên/SĐT/email.
- **Vãng lai vẫn không validate và không lưu yêu cầu** (spec "Walk-in buyer by default" giữ nguyên); thông tin lấy từ billing lúc đơn completed.
- Thông điệp lỗi địa chỉ đổi thành nhắc nhập địa chỉ thanh toán (billing) ở phần thông tin thanh toán.

Phương án khác: giữ ô riêng chỉ cho doanh nghiệp (người dùng đã chọn bỏ ô riêng cho mọi loại, xem brainstorm); thêm công tắc Settings (loại vì thêm cấu hình mà quy tắc đã rõ).

## Risks / Trade-offs

- [Doanh nghiệp có địa chỉ đăng ký khác billing] → hướng dẫn trong tài liệu: điền địa chỉ đăng ký vào billing; admin vẫn sửa billing trước khi completed (recapture hiện có).
- [Dữ liệu cá nhân của khách không lấy hóa đơn nay nằm trong snapshot] → exporter/eraser/REST redaction bao phủ; ghi nhận ở spec privacy.
- [Client và server ghép địa chỉ lệch ở nhãn tỉnh/thành] → server là chuẩn; client chỉ kiểm tra rỗng và độ dài xấp xỉ.
- [Billing address tắt trên shop] → cá nhân/doanh nghiệp báo lỗi thiếu địa chỉ; shop cần bật địa chỉ thanh toán (ghi trong tài liệu).
- [Điều kiện phát hành/production của Mắt Bão với vãng lai có thông tin] → chưa kiểm chứng; chỉ xác nhận demo.

## Migration Plan

Không migration DB. Đơn pending cũ dựng snapshot theo thứ tự ưu tiên ở trên; snapshot đã chụp không đổi. Rollback bằng revert code; meta `personal` thừa không gây lỗi.
