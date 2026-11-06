## Context

Stack dev/CI là một Docker Compose stack duy nhất: `docker/Dockerfile` (`ARG WORDPRESS_IMAGE=wordpress:7.1.4-php8.5-apache`, WP-CLI từ `wordpress:cli-2.12.0-php8.5`), `compose.yaml` (`WOOCOMMERCE_VERSION: "11.2.1"`), `docker/setup.sh` (`composer install`, cài WP, cài WooCommerce đúng bản). `composer.lock` khóa theo `config.platform.php = 8.5.0`; `doctrine/instantiator 2.1.0` (dev) yêu cầu PHP ^8.4. `wp-phpunit/wp-phpunit ~7.1.0` cung cấp bộ test lõi WordPress, nạp qua `getenv('WP_PHPUNIT__DIR')` trong hai bootstrap test. Image chính thức cho WP 6.6 chỉ có tới `wordpress:6.6.2-php8.2-apache`; WP-CLI có `wordpress:cli-2.12.0-php8.2`; WooCommerce 9.8 mới nhất là 9.8.7.

## Goals / Non-Goals

**Goals:**
- Một bộ nguồn và một `composer.lock` chạy được trên cả PHP 8.2 lẫn 8.5.
- Chọn tổ hợp test bằng biến môi trường; mặc định vẫn là tổ hợp mới nhất để dev không phải đổi thói quen.
- CI chạy hai tổ hợp song song, cả hai là gate bắt buộc.

**Non-Goals:**
- Static analysis với stub WordPress 6.6 / WooCommerce 9.8 (xem Risks).
- E2E trên tổ hợp tối thiểu.

## Decisions

1. **Biến tổ hợp qua env, không thêm file compose riêng.** `docker/Dockerfile` nhận `ARG WORDPRESS_IMAGE` và `ARG WPCLI_IMAGE` (khai báo trước `FROM` đầu tiên); `compose.yaml` truyền `build.args` từ `EINV_WORDPRESS_IMAGE`/`EINV_WPCLI_IMAGE` và map `EINV_WOOCOMMERCE_VERSION` → `WOOCOMMERCE_VERSION`, default là tổ hợp mới nhất. Preset `docker/min.env` chứa giá trị tổ hợp tối thiểu; `scripts/check.sh --min` export nó (cùng `.env` nếu có) với project name và port riêng, CI nạp nó vào `$GITHUB_ENV`. Không dùng `docker compose --env-file docker/min.env` vì sẽ thay `.env` và mất project name/port. Hai tổ hợp dùng project name khác nhau để không chung volume `wordpress` (entrypoint chỉ copy core khi volume trống). Smoke-zip và E2E dùng default nên chạy tổ hợp mới nhất, miễn shell gọi không export `EINV_*`. Lý do: một nguồn sự thật cho stack, không nhân đôi file compose.
2. **`composer.lock` khóa theo sàn**: `config.platform.php = 8.2.0`, `require.php = >=8.2`, chạy `composer update --lock`/update tối thiểu để resolver chọn bản tương thích 8.2 (vd `doctrine/instantiator 2.0.x`). Lý do: lock tương thích sàn chạy được trên mọi bản cao hơn; hai lock file thì lệch nhau.
3. **wp-phpunit theo phiên bản core**: giữ `wp-phpunit ~7.1.0` trong lock cho tổ hợp mới nhất. Ở tổ hợp tối thiểu, `docker/setup.sh` cài `wp-phpunit/wp-phpunit` bản `EINV_WP_PHPUNIT_VERSION` (vd `~6.6.0`) vào thư mục riêng ngoài plugin (`/opt/wp-phpunit`, tạo nếu thiếu) bằng `composer --working-dir`; `EINV_WP_PHPUNIT_DIR` đến từ env của compose. Hai bootstrap test, ngay sau `vendor/autoload.php` (nơi wp-phpunit 7.1 tự đặt `WP_PHPUNIT__DIR`), ghi đè `WP_PHPUNIT__DIR` bằng `EINV_WP_PHPUNIT_DIR` nếu có và dừng với lỗi rõ ràng nếu thư mục đó thiếu `includes/functions.php` (không âm thầm quay về 7.1). Lý do: bộ test lõi phải khớp nhánh core; không sửa `composer.json` trong mount lúc chạy. `vendor/` dùng chung giữa hai tổ hợp trên một worktree (bind mount) nên không chạy song song hai tổ hợp trên cùng worktree.
4. **Chặn cú pháp/hàm PHP trên sàn không thêm dependency.** PHPCompatibility chưa được cài nên `testVersion` của PHPCS hiện không có tác dụng; bản stable 9.3.5 không biết PHP 8.2+, bản 10 còn alpha, và thêm dependency cần user duyệt → không dùng. Thay vào đó: PHPStan `phpVersion: 80200` (sàn) để báo hàm/hằng/cú pháp chỉ có từ 8.3+; job tổ hợp tối thiểu chạy `php -l` trên PHP 8.2 cho mọi file PHP của plugin; một fixture `tests/fixtures/php-floor/Php83Only.php` (ngoài path PHPStan/PHPCS) và bước CI khẳng định PHPStan báo lỗi trên fixture đó. Deprecation của PHP 8.5 được bắt khi chạy test trên tổ hợp mới nhất. PHPCS: `minimum_wp_version = 6.6` (WPCS dùng cho sniff hàm deprecated), `testVersion = 8.2-` giữ nhất quán dù hiện không có hiệu lực. Nếu spike ở apply cho thấy PHPStan không báo fixture, dừng hỏi user (thêm PHPCompatibility hay chấp nhận chỉ `php -l`).
5. **CI**: `php.yml` dùng `strategy.matrix` hai mục (`min`, `latest`) với `fail-fast: false`, `COMPOSE_PROJECT_NAME` riêng mỗi mục. `release.yml` gọi lại `php.yml` nên gate release tự có hai tổ hợp. E2E và smoke-zip giữ tổ hợp mới nhất.
6. **WP tối thiểu trong test là 6.6.2** (image chính thức cuối của nhánh 6.6), WooCommerce 9.8.7. Header vẫn ghi `Requires at least: 6.6`.
7. **Không tương thích phát hiện khi chạy tổ hợp tối thiểu** được sửa bằng nhánh tương thích nhỏ trong `src/` (kiểm tra `class_exists`/`method_exists` hoặc API cũ hơn có ở cả hai), kèm test; nếu không giữ được hành vi, dừng hỏi user (theo brainstorm).

## Risks / Trade-offs

- [PHPStan dùng stub WP 7.1 / WC 11.2 nên không bắt được hàm chỉ có từ WP 6.7+/WC 9.9+] → bộ integration test trên tổ hợp tối thiểu là lưới chính; chấp nhận vì thêm bộ stub thứ hai làm phức tạp dev-deps.
- [Các bản trung gian (PHP 8.3/8.4, WP 6.7–7.0, WC 9.9–10.x) không được test trực tiếp] → chấp nhận theo brainstorm; hai đầu xanh là đủ tin cậy.
- [WP 6.6.2 không phải bản vá cuối của nhánh 6.6] → chỉ ảnh hưởng môi trường test; có thể nâng khi image mới hơn xuất hiện.
- [Hạ `composer.lock` có thể đổi bản dev-deps gây khác biệt lint/test] → chạy đủ `composer check` trên cả hai tổ hợp trước khi commit lock.
- [WP-CLI 2.12 / Composer chạy trên PHP 8.2] → đã có image chính thức; không rủi ro đáng kể.

## Migration Plan

Không có migration dữ liệu. Rollback bằng revert commit: header và lock quay lại sàn cũ, CI về một tổ hợp.

## Open Questions

Không có.
