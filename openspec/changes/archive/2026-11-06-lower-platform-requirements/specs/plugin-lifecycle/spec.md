## ADDED Requirements

### Requirement: Plugin declares minimum platform versions
Hệ thống SHALL khai báo trong header plugin phiên bản tối thiểu PHP 8.2 (`Requires PHP`), WordPress 6.6 (`Requires at least`) và WooCommerce 9.8 (`WC requires at least`), và MUST khai báo cùng sàn PHP trong `composer.json` (`require.php`), để WordPress, WooCommerce và Composer có thông tin sàn đúng khi cài plugin. "WC tested up to" giữ ở 11.2.
Evidence: einvoice-matbao.php (header), composer.json

#### Scenario: Header and Composer agree
- **WHEN** đọc header của `einvoice-matbao.php` và `composer.json`
- **THEN** `Requires PHP` là `8.2`, `Requires at least` là `6.6`, `WC requires at least` là `9.8`, `WC tested up to` là `11.2`, và `require.php` là `>=8.2`

### Requirement: Plugin runs on the minimum and latest supported platforms
Hệ thống SHALL giữ nguyên hành vi đã đặc tả trên tổ hợp tối thiểu PHP 8.2 / WordPress 6.6 / WooCommerce 9.8 và tổ hợp mới nhất PHP 8.5 / WordPress 7.1 / WooCommerce 11.2 (bản vá ghim trong stack Docker). Trên mỗi tổ hợp, PHPCS, PHPStan và cả hai bộ PHPUnit (có và không có WooCommerce) MUST đạt. E2E trình duyệt chỉ chạy trên tổ hợp mới nhất.
Evidence: .github/workflows/php.yml, docker/Dockerfile, compose.yaml, tests/integration/, tests/without-woocommerce/

#### Scenario: Minimum platform
- **WHEN** chạy PHPCS, PHPStan và cả hai bộ PHPUnit trên PHP 8.2, WordPress 6.6 và WooCommerce 9.8
- **THEN** mọi kiểm tra đạt

#### Scenario: Latest platform
- **WHEN** chạy PHPCS, PHPStan và cả hai bộ PHPUnit trên PHP 8.5, WordPress 7.1 và WooCommerce 11.2
- **THEN** mọi kiểm tra đạt

### Requirement: CI checks both supported platforms
Hệ thống SHALL chạy workflow CI PHP trên pull request, `main` và release gate với một job cho mỗi tổ hợp được hỗ trợ; job nào thất bại MUST làm workflow thất bại. Cú pháp hoặc hàm PHP không có trên 8.2 MUST bị PHPStan hoặc lint PHP 8.2 báo lỗi; API WordPress/WooCommerce thiếu ở sàn chỉ bị phát hiện qua integration test.
Evidence: .github/workflows/php.yml, .github/workflows/release.yml, phpstan.neon

#### Scenario: PHP syntax above the floor
- **WHEN** một thay đổi dùng cú pháp hoặc hàm chỉ có từ PHP 8.3 trở lên
- **THEN** PHPStan hoặc lint PHP 8.2 báo lỗi và workflow CI PHP thất bại

#### Scenario: Integration test fails only on the minimum platform
- **WHEN** một integration test đạt trên tổ hợp mới nhất nhưng thất bại trên tổ hợp tối thiểu
- **THEN** job tổ hợp tối thiểu thất bại và workflow CI PHP thất bại, kể cả khi được gọi từ release gate
