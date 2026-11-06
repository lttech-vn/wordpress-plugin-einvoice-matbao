## 1. Khai báo sàn và cấu hình công cụ

- [x] 1.1 Cập nhật header `einvoice-matbao.php`: `Requires at least: 6.6`, `Requires PHP: 8.2`, `WC requires at least: 9.8` (giữ `WC tested up to: 11.2`)
- [x] 1.2 `composer.json`: `require.php` `>=8.2`, `config.platform.php` `8.2.0`; cập nhật `composer.lock` để mọi dependency tương thích PHP 8.2 (vd `doctrine/instantiator` 2.0.x)
- [x] 1.3 `phpcs.xml.dist`: `testVersion` `8.2-`, `minimum_wp_version` `6.6`; `phpstan.neon`: `phpVersion` `80200`; fixture `tests/fixtures/php-floor/Php83Only.php` chứng minh PHPStan báo hàm PHP 8.3+ (loại khỏi path PHPCS/PHPStan thường)
- [x] 1.4 Thêm test integration kiểm header plugin và `composer.json` khai báo đúng sàn (scenario "Header and Composer agree")

## 2. Stack Docker chọn được tổ hợp

- [x] 2.1 `docker/Dockerfile`: tham số hóa image WordPress và WP-CLI qua `ARG` (default tổ hợp mới nhất)
- [x] 2.2 `compose.yaml`: truyền build args và `WOOCOMMERCE_VERSION`, `EINV_WP_PHPUNIT_VERSION` từ env với default tổ hợp mới nhất; thêm preset `docker/min.env` (wordpress:6.6.2-php8.2-apache, cli-2.12.0-php8.2, WooCommerce 9.8.7, wp-phpunit ~6.6.0)
- [x] 2.3 `docker/setup.sh`: khi có `EINV_WP_PHPUNIT_VERSION` thì cài wp-phpunit khớp core vào `/opt/wp-phpunit` (`EINV_WP_PHPUNIT_DIR` đến từ env compose); hai bootstrap `tests/integration/bootstrap.php`, `tests/without-woocommerce/bootstrap.php` ưu tiên thư mục đó và dừng nếu thư mục thiếu
- [x] 2.4 `scripts/check.sh` nhận `--env-file` (hoặc biến môi trường) để chạy `composer check` trên tổ hợp tối thiểu

## 3. Tương thích tổ hợp tối thiểu

- [x] 3.1 Chạy `composer check` trên tổ hợp mới nhất sau bước 1–2, ghi kết quả
- [x] 3.2 Chạy `composer check` trên tổ hợp tối thiểu, ghi mọi lỗi
- [x] 3.3 Sửa từng chỗ không tương thích trong `src/` (TDD, giữ hành vi); nếu không giữ được hành vi thì dừng hỏi user trước khi nâng sàn

## 4. CI

- [x] 4.1 `.github/workflows/php.yml`: matrix `min`/`latest`, `fail-fast: false`, `COMPOSE_PROJECT_NAME` riêng; job `min` thêm `php -l` trên PHP 8.2; bước khẳng định PHPStan báo lỗi trên fixture; release gate dùng lại qua `workflow_call`
- [x] 4.2 Kiểm tra `e2e.yml`, `release.yml`, `scripts/smoke-zip.sh` vẫn dùng tổ hợp mới nhất mặc định

## 5. Tài liệu

- [x] 5.1 `README.md`: mục yêu cầu hệ thống (PHP 8.2+, WordPress 6.6+, WooCommerce 9.8+), bảng tổ hợp đã test, cách chạy tổ hợp tối thiểu, mô tả CI
- [x] 5.2 `docs/discovery.md`: cập nhật dòng target nền tảng
