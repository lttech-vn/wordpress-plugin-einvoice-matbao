# `MTChieu` là khóa chống trùng duy nhất

Ngày lập là ngày gửi thành công nên đổi theo mỗi attempt. `MTChieu` (prefix + `WC` + order ID) cố định cho mọi attempt và là khóa duy nhất để chống tạo trùng hóa đơn gốc phía Mắt Bão, kết hợp `KTraMTChieuTrung=1`. Phía plugin có `UNIQUE(order_id)` ở bảng invoice.

Hệ quả: không đổi `MTChieu` để né lỗi 305; timeout thì tra cứu trước khi gửi lại. Mắt Bão chặn trùng nguyên tử chưa được xác minh, cần test đồng thời trên API demo.
