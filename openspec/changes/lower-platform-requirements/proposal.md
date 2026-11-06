## Why

Plugin hiện chỉ cài được trên PHP ≥ 8.5, WordPress ≥ 7.0 và WooCommerce ≥ 11.0, trong khi nhiều hosting tại Việt Nam còn chạy PHP 8.2–8.4 và WordPress 6.6–6.9. Hạ sàn về PHP 8.2, WordPress 6.6, WooCommerce 9.8 (bản WooCommerce cuối cùng chạy trên WordPress 6.6) mở plugin cho các site này, với bằng chứng test tự động ở cả hai đầu khoảng hỗ trợ.

## What Changes

**Phiên bản nền tảng tối thiểu**
- From: header plugin `Requires PHP: 8.5`, `Requires at least: 7.0`, `WC requires at least: 11.0`; `composer.json` `php >=8.5`.
- To: PHP 8.2, WordPress 6.6, WooCommerce 9.8; "WC tested up to" giữ 11.2.
- Reason: cho phép cài trên hosting phổ biến.
- Impact: non-breaking; site đang chạy bản mới không bị ảnh hưởng.

**Kiểm chứng**
- From: CI PHP chỉ chạy tổ hợp PHP 8.5 / WP 7.1.4 / WC 11.2.1.
- To: CI PHP (PR, `main`, release gate) chạy PHPCS, PHPStan và cả hai bộ PHPUnit trên hai tổ hợp: tối thiểu (PHP 8.2 / WP 6.6 / WC 9.8) và mới nhất (PHP 8.5 / WP 7.1 / WC 11.2). E2E trình duyệt giữ ở tổ hợp mới nhất.
- Impact: thời gian CI phần PHP tăng khoảng gấp đôi (chạy song song).

**Cấu hình công cụ và tài liệu**
- PHPCS `testVersion`/`minimum_wp_version`, PHPStan `phpVersion`, `composer.json` `config.platform` và `composer.lock` theo sàn mới.
- README ghi khoảng hỗ trợ và các tổ hợp đã test; `docs/discovery.md` cập nhật target.

**Code plugin**: sửa chỗ nào không chạy trên sàn mới (nếu test trên tổ hợp tối thiểu phát hiện), giữ nguyên hành vi.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `plugin-lifecycle`: thêm requirement về phiên bản PHP, WordPress, WooCommerce tối thiểu mà plugin khai báo và được kiểm chứng; các requirement hiện có không đổi.

## Impact

- `einvoice-matbao.php` (header), `composer.json`, `composer.lock`, `phpcs.xml.dist`, `phpstan.neon`.
- Docker stack: `docker/Dockerfile`, `compose.yaml`, `docker/setup.sh`, `scripts/check.sh`; test bootstrap `tests/integration/bootstrap.php`, `tests/without-woocommerce/bootstrap.php`.
- CI: `.github/workflows/php.yml` (matrix); `release.yml` dùng lại qua `workflow_call`.
- Tài liệu: `README.md`, `docs/discovery.md`.
- Có thể có sửa nhỏ trong `src/` nếu phát hiện API không có trên WooCommerce 9.8 / WordPress 6.6 / PHP 8.2.
- Không thêm dependency runtime; không đổi DB schema, REST API hay hành vi người dùng.
