# Hóa đơn điện tử Mắt Bão cho WooCommerce

Plugin WordPress tự tạo **hóa đơn GTGT nháp** trên hệ thống hóa đơn điện tử Mắt Bão khi đơn WooCommerce chuyển sang **Hoàn thành**. Kế toán chỉ cần kiểm tra, ký và phát hành trên Mắt Bão.

Plugin dành riêng cho cửa hàng tại Việt Nam: tiền VND, thuế suất GTGT Việt Nam, giao diện tiếng Việt.

## Tính năng

- **Tạo nháp tự động** khi đơn hoàn thành, từ số tiền và thuế đã lưu trên đơn (0%, 5%, 8%, 10%, KCT).
- **Không tạo trùng:** mỗi đơn một hóa đơn gốc, mã tham chiếu cố định, tra cứu Mắt Bão trước mỗi lần gửi lại.
- **Tự thử lại** khi Mắt Bão lỗi tạm thời (1 phút, 5 phút, 15 phút, 1 giờ, 6 giờ).
- **Yêu cầu xuất hóa đơn ở checkout** cho cá nhân (CCCD) hoặc doanh nghiệp (MST), hỗ trợ Classic và Checkout Blocks. Khách không yêu cầu được xuất cho người mua vãng lai.
- **Quản lý hóa đơn trong wp-admin:** tổng quan, danh sách, bộ lọc, lịch sử gửi, kiểm tra trạng thái, gửi lại.
- **Cảnh báo** qua thông báo wp-admin và Lark.
- **Giám sát lỗi (tùy chọn, mặc định tắt):** gửi lỗi, hiệu năng, log và cron monitor tới máy chủ Sentry của bạn, với dữ liệu người mua được che trước khi gửi.
- **Bảo vệ dữ liệu:** credential và CCCD mã hóa, CCCD luôn được che, hỗ trợ xuất/xóa dữ liệu cá nhân của WordPress.

Plugin **không** ký, phát hành, sửa hay hủy hóa đơn trên Mắt Bão.

## Yêu cầu

PHP 8.2+, WordPress 6.6+, WooCommerce 9.8+, tiền tệ cửa hàng là VND, WP-Cron hoạt động, tài khoản API hóa đơn điện tử Mắt Bão.

## Cài đặt nhanh

1. Tải `einvoice-matbao-X.Y.Z.zip` ở trang [Releases](https://github.com/lttech-vn/wordpress-plugin-einvoice-matbao/releases).
2. wp-admin → **Plugin → Cài mới → Tải plugin lên**, chọn file zip rồi kích hoạt (WooCommerce phải đang hoạt động).
3. Thêm khóa mã hóa vào `wp-config.php`:

   ```php
   define( 'EINVOICE_MATBAO_ENCRYPTION_KEY', '<chuỗi ngẫu nhiên, tối thiểu 32 ký tự>' );
   ```

4. Mở **WooCommerce → Hóa đơn Mắt Bão**: nhập kết nối Mắt Bão, kiểm tra kết nối, chọn mẫu hóa đơn mặc định.
5. Chạy thử với môi trường **Demo**, rồi chuyển sang **Production**.

Chi tiết từng bước, quy trình hằng ngày và xử lý lỗi: **[Hướng dẫn sử dụng](docs/huong-dan-su-dung.md)**.

## Tài liệu

| Tài liệu | Dành cho |
|---|---|
| [Hướng dẫn sử dụng](docs/huong-dan-su-dung.md) | Chủ cửa hàng, quản trị viên, kế toán |
| [Tài liệu phát triển](docs/phat-trien.md) | Developer: môi trường dev, kiến trúc, kiểm thử, CI, phát hành |
| [Browser E2E](tests/e2e/README.md) | Developer: bộ test trình duyệt và kiểm tra API demo |
| [Thuật ngữ](GLOSSARY.md), [Khảo sát](docs/discovery.md), [ADR](docs/adr/) | Bối cảnh nghiệp vụ và quyết định thiết kế |
| [Kết quả API demo](docs/research/e2e-demo-results.md) | Hành vi đã kiểm chứng trên API demo Mắt Bão |

## Giấy phép

[GPL-2.0-or-later](LICENSE). Phát triển bởi LTTech.
