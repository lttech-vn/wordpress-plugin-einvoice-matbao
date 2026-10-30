# Xuất hóa đơn Mắt Bão cho đơn hàng WooCommerce

Thuật ngữ nghiệp vụ dùng chung giữa cửa hàng WooCommerce và đơn vị xuất hóa đơn trên Mắt Bão.

## Language

**Đơn hàng completed**:
Đơn hàng có trạng thái `completed` trong WooCommerce. Trạng thái này không đồng nghĩa với việc đã có hóa đơn trên Mắt Bão.
_Avoid_: Đơn đã xuất hóa đơn

**Đơn vị xuất hóa đơn**:
Pháp nhân hoặc chủ thể bán hàng đứng tên bên bán trên hóa đơn, được xác định bằng mã số thuế.
_Avoid_: Tài khoản API, cửa hàng WooCommerce

**Hóa đơn nháp**:
Hóa đơn được tạo trên Mắt Bão nhưng chưa ký/phát hành.
_Avoid_: Hóa đơn đã phát hành, hóa đơn chính thức

**Thông tin xuất hóa đơn**:
Thông tin người mua dùng để lập hóa đơn; cá nhân dùng thông tin billing, doanh nghiệp có thêm tên đơn vị, mã số thuế và địa chỉ xuất hóa đơn.
_Avoid_: Địa chỉ giao hàng

**Mã tham chiếu (`MTChieu`)**:
Mã do plugin đặt cho mỗi hóa đơn gốc (prefix cấu hình + `WC` + order ID, tối đa 20 ký tự), lưu cố định và dùng cho mọi attempt. Là khóa chống trùng duy nhất phía Mắt Bão.
_Avoid_: Mã tra cứu, số hóa đơn

**Attempt**:
Một lần plugin gọi API Mắt Bão để tạo hoặc tra cứu hóa đơn nháp của một đơn hàng. Nhiều attempt thuộc cùng một hóa đơn gốc và cùng `MTChieu`.
_Avoid_: Hóa đơn mới, lần tạo lại

**Người mua vãng lai**:
Người mua không chọn yêu cầu xuất hóa đơn. Vẫn được tạo nháp, với `NMua_Ten` cấu hình được (mặc định "Khách hàng không lấy hóa đơn") và các field người mua khác để trống.
_Avoid_: Khách lẻ có tên billing

**Snapshot người mua**:
Bản chụp thông tin xuất hóa đơn tại thời điểm đơn `completed`; mọi attempt gửi từ snapshot. CCCD đầy đủ chỉ nằm trong order meta, bảng hóa đơn chỉ giữ bản mask.
_Avoid_: Đọc lại từ đơn mỗi lần gửi
