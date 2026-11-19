# Kiểm chứng `download-invoice` trên API demo

Ngày chạy: 2026-11-19. Script: [`demo-download-probe.py`](demo-download-probe.py), chỉ đọc (đăng nhập, tra cứu `templates` và `invoice-detail`, gọi `download-invoice`); không tạo, ký hay xóa hóa đơn. Thông tin đăng nhập lấy từ biến môi trường `EINV_DEMO_MST`, `EINV_DEMO_USER`, `EINV_DEMO_PASS`, không ghi vào repo. Probe chỉ in `success`/`errorCode` và độ dài `message`, không in nội dung. Kết quả bên dưới đã bỏ token, base64, `maTraCuu`, `maSoHDon`.

Chạy lại: `EINV_DEMO_MST=… EINV_DEMO_USER=… EINV_DEMO_PASS=… uv run --no-project python -I docs/research/demo-download-probe.py`.

## Kết quả

| Bước | Kết quả |
|---|---|
| `POST /api/auth/login` (`MST`, `TDNhap`, `MKhau`) | HTTP 200, `success: true` |
| `GET /api/invoice/templates?year=2026` | HTTP 200, 228 mẫu |
| `POST /api/invoice/invoice-detail` mẫu `1/C26TAT`, `TThaiHDon` 4 | HTTP 200, `success: true`, 152 hóa đơn trong 9 ngày; mỗi dòng có `maSoHDon`, `maTraCuu`, `mtChieu`, `maLoaiHDon`, `maTThaiHDon`, `shDon`, `nLap` |
| `POST /api/invoice/invoice-detail` cùng mẫu, `TThaiHDon` 0 | HTTP 200, `success: true`, 217 dòng |
| `POST /api/invoice/download-invoice` hóa đơn đã cấp | HTTP 200, `Content-Type: application/json; charset=utf-8` |

Phản hồi `download-invoice` (hóa đơn đã cấp):

- Envelope: `success: true`, `errorCode: 200`, `data` (object).
- `data` có đúng hai khóa: `data_PDF_Base64` (chuỗi, ~159 KB) và `data_XML_Base64` (chuỗi, ~13,7 KB).
- Giải mã `data_PDF_Base64` bằng base64 chặt: 119 230 byte, **bắt đầu bằng `%PDF-`**.

## Khớp với plugin

`MatbaoClient::download_invoice_pdf` đọc `data.data_PDF_Base64` (không phân biệt hoa thường), giải mã base64 chặt và kiểm tra tiền tố `%PDF-`; envelope `success`/`errorCode` do lớp `request` xử lý chung. Shape thật khớp, không cần đổi code production.

## Phát hiện thêm

- `invoice-detail` giới hạn khoảng tìm 10 ngày (`errorCode` 201); khoảng lớn hơn thất bại với HTTP 200, `success: false`.
- `invoice-detail` không có kết quả trả `errorCode` 301 với HTTP 200, `success: false` (mẫu/ngày không có hóa đơn đúng trạng thái).
- **`download-invoice` trên demo cũng trả PDF hợp lệ cho một nháp** (`TThaiHDon` 0, HTTP 200, `%PDF-`, 119 376 byte). Mắt Bão không tự chặn tải nháp, nên việc chỉ cho tải hóa đơn đã cấp mã (`maTThaiHDon` 4 và không bị xóa bỏ/thay thế) phụ thuộc hoàn toàn vào kiểm tra phía plugin (tra lại trạng thái trước mỗi lần tải). PDF của nháp không có giá trị pháp lý và không được đưa cho khách.
- Chưa quan sát được dạng lỗi của `download-invoice` (mã tra cứu sai hoặc hóa đơn không tồn tại); không cố gọi vì ngoài phạm vi chỉ đọc an toàn. Plugin coi mọi `success: false` là `MatbaoError` và không lộ chi tiết cho khách.
