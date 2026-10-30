# Admin UI: htmx + Tailwind scoped, transport qua REST

Trang quản lý là menu con WooCommerce, giao diện htmx và Tailwind scoped trong `#einv-app`, tắt preflight để không phá CSS WP admin. htmx gọi REST namespace riêng trả HTML fragment, quyền kiểm tra bằng `permission_callback` (Administrator) và nonce `X-WP-Nonce`. Tailwind build bằng bun, htmx vendored, không dùng CDN.

Hệ quả: route REST là bề mặt test chính; không dùng `admin-ajax.php`.
