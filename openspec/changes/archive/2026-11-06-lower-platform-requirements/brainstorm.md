## Design Summary

Plugin hiện chỉ cài được trên site có PHP ≥ 8.5, WordPress ≥ 7.0 và WooCommerce ≥ 11.0 (header `einvoice-matbao.php:11-15`, `composer.json`). Nhiều hosting tại Việt Nam vẫn chạy PHP 8.2–8.4 và WordPress 6.6–6.9, nên plugin bị chặn ngay từ bước cài.

Mục tiêu: plugin cài, kích hoạt và chạy đầy đủ tính năng trên **PHP ≥ 8.2, WordPress ≥ 6.6, WooCommerce ≥ 9.8**, đồng thời vẫn chạy trên bản mới nhất hiện đang hỗ trợ (PHP 8.5, WordPress 7.1, WooCommerce 11.2).

- WooCommerce tối thiểu hạ xuống 9.8 vì WooCommerce 9.9 trở đi bỏ WordPress 6.6 và WooCommerce 11.x yêu cầu WordPress ≥ 6.9; giữ WooCommerce 11.0 thì sàn WordPress 6.6 chỉ có trên giấy (user đã chọn qua Inbox).
- "Hỗ trợ" nghĩa là có kiểm chứng: bộ test tự động (lint, static analysis, integration, without-WooCommerce) phải xanh trên cả tổ hợp tối thiểu lẫn tổ hợp mới nhất.
- Nếu plugin đang dùng API chỉ có ở PHP/WordPress/WooCommerce mới hơn sàn, phải thay bằng cách tương đương có trên sàn, không đổi hành vi người dùng thấy.

**Living specs liên quan:**
- `plugin-lifecycle` — "Activation requires WooCommerce", "Plugin declares HPOS and Blocks compatibility": giữ nguyên, nhưng phải đúng trên WooCommerce 9.8. Chưa có requirement nào về phiên bản nền tảng tối thiểu → change này **thêm** requirement mới, không sửa requirement cũ.
- `buyer-invoice-request` (checkout Classic/Blocks, Store API), `admin-invoice-management` (lọc đơn HPOS/legacy): hành vi giữ nguyên, phải chạy được trên WooCommerce 9.8.

**Goals:** khai báo sàn mới nhất quán ở mọi nơi (header plugin, Composer, công cụ lint/static analysis, README); CI chạy cả hai tổ hợp; tài liệu ghi rõ tổ hợp đã test.

**Non-goals:** không hỗ trợ PHP < 8.2, WordPress < 6.6, WooCommerce < 9.8; không thêm tính năng; không đổi hành vi nghiệp vụ; không test mọi phiên bản trung gian.

**Anti-goals:** hạ header mà không chạy test trên bản tối thiểu; dùng polyfill/shim lớn làm phình plugin; làm chậm hoặc làm đỏ CI hiện có của tổ hợp mới nhất.

## Alternatives Considered

### Alternative A: Chỉ hạ header và Composer, không test bản tối thiểu
- **Approach**: đổi các con số khai báo, CI giữ nguyên tổ hợp mới nhất.
- **Pros**: rất ít việc.
- **Cons**: không chứng minh plugin chạy trên PHP 8.2 / WP 6.6 / WC 9.8; lỗi tương thích chỉ lộ ra trên site khách hàng.
- **Why not chosen**: vi phạm anti-goal "hạ header mà không chạy test".

### Alternative B: Hai tổ hợp test — tối thiểu và mới nhất (chọn)
- **Approach**: CI PHP chạy toàn bộ lint, static analysis và integration test trên hai tổ hợp: (PHP 8.2, WP 6.6, WC 9.8) và (PHP 8.5, WP 7.1, WC 11.2). E2E trình duyệt giữ ở tổ hợp mới nhất.
- **Pros**: chặn hai đầu khoảng hỗ trợ với chi phí CI gấp đôi phần PHP; dev local chọn được tổ hợp.
- **Cons**: các bản trung gian (vd PHP 8.3, WP 6.8) không được test trực tiếp; E2E không chạy trên bản tối thiểu.
- **Why chosen**: đủ để tin hai đầu, chi phí hợp lý.

### Alternative C: Ma trận đầy đủ mọi phiên bản PHP 8.2–8.5 × WP 6.6–7.1
- **Approach**: test mọi tổ hợp chính.
- **Pros**: độ bao phủ cao nhất.
- **Cons**: CI lâu gấp nhiều lần; nhiều tổ hợp WC/WP không cài được cùng nhau.
- **Why not chosen**: chi phí lớn so với rủi ro thực tế, khoảng giữa hiếm khi vỡ khi hai đầu xanh.

## Agreed Approach

Alternative B. WooCommerce tối thiểu 9.8 do user chọn; cách test hai đầu là đề xuất của tôi (user chưa nêu lý do cụ thể cho việc hạ sàn, nên chọn mặc định test hai đầu trên mỗi PR thay vì chỉ lúc release).

## Key Decisions

- Sàn: PHP 8.2, WordPress 6.6, WooCommerce 9.8 (user quyết).
- Trần đã test giữ nguyên: PHP 8.5, WordPress 7.1, WooCommerce 11.2 ("WC tested up to" không đổi).
- Tổ hợp tối thiểu chạy trên mỗi PR và trong release gate, không chỉ lúc release (đề xuất của tôi).
- E2E trình duyệt chỉ chạy trên tổ hợp mới nhất (đề xuất của tôi).
- Mọi chỗ không tương thích với sàn được sửa trong code plugin, không bằng cách nâng sàn lại.

## Open Questions

- Khi chạy trên tổ hợp tối thiểu có thể phát hiện API WooCommerce 9.8 thiếu (checkout Blocks, Store API, HPOS). Nếu không thay được mà không đổi hành vi, sẽ dừng và hỏi user trước khi nâng sàn WooCommerce.
