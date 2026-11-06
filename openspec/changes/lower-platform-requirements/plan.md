# Lower Platform Requirements Implementation Plan

> Executed via `/opsx-apply`, which chooses the executor.
> Spec reference: this change's `specs/` and `design.md`.

**Goal:** Hạ sàn plugin về PHP 8.2 / WordPress 6.6 / WooCommerce 9.8 và chứng minh bằng CI chạy hai tổ hợp (tối thiểu, mới nhất).

**Architecture:** Một stack Docker Compose tham số hóa bằng biến môi trường (default = tổ hợp mới nhất), preset `docker/min.env` cho tổ hợp tối thiểu. Một `composer.lock` khóa theo PHP 8.2. Bộ test lõi WordPress (wp-phpunit) khớp phiên bản core được cài riêng ở tổ hợp tối thiểu. `php.yml` chạy matrix hai tổ hợp.

**Tech Stack:** PHP 8.2/8.5, WordPress 6.6.2/7.1.4, WooCommerce 9.8.7/11.2.1 (WC 9.8.7 header `Requires at least: 6.6`, đã kiểm), Composer, PHPUnit 9 + wp-phpunit, PHPCS (WPCS, không có PHPCompatibility), PHPStan, Docker Compose, GitHub Actions.

## Global Constraints

- Sàn: PHP `8.2`, WordPress `6.6`, WooCommerce `9.8`; `WC tested up to: 11.2` giữ nguyên.
- Tổ hợp tối thiểu ghim: `wordpress:6.6.2-php8.2-apache`, `wordpress:cli-2.12.0-php8.2`, WooCommerce `9.8.7`, wp-phpunit `~6.6.0`.
- Tổ hợp mới nhất ghim (không đổi): `wordpress:7.1.4-php8.5-apache`, `wordpress:cli-2.12.0-php8.5`, WooCommerce `11.2.1`, wp-phpunit `~7.1.0` (trong `composer.lock`).
- Không thêm dependency (runtime lẫn dev); không sửa `composer.json` trong mount lúc chạy test.
- Không chạy song song hai tổ hợp trên cùng worktree (chung `vendor/` qua bind mount).
- Mọi lệnh PHP/Composer chạy trong container (README: host PHP không dùng).
- Không đổi hành vi plugin; nếu không giữ được hành vi trên sàn → dừng hỏi user (brainstorm).
- Commit message tiếng Anh, Conventional Commits.

## Review Focus

1. Chuyển qua lại hai tổ hợp trên cùng worktree: volume `wordpress` giữ core cũ (entrypoint chỉ copy core khi trống) → stack min phải dùng project name riêng (`<name>-min`) và port riêng. Kiểm bằng Task 3 Step 5 (log `einv-setup` in đúng phiên bản, chạy latest → min → latest).
2. `composer.lock` hạ về PHP 8.2 vẫn phải chạy sạch trên PHP 8.5 (deprecation từ dev-deps) → Task 4 chạy `composer check` cả hai tổ hợp.
3. wp-phpunit 7.1 chạy trên core 6.6 sẽ hỏng ngầm → bootstrap phải dùng bản 6.6 khi `EINV_WP_PHPUNIT_DIR` có giá trị; Task 3 Step 5 kiểm `WP_PHPUNIT__DIR` thực tế.
4. Smoke-zip và E2E dựng stack bằng `compose.yaml` → default env phải đúng tổ hợp mới nhất khi không có biến nào; Task 5 Step 3.
5. API WooCommerce/WordPress thiếu ở sàn mà PHPStan (stub 11.2/7.1) không bắt → chỉ integration test min bắt được; Task 4.

---

## Contracts

| Name | new / modified | Shape | public |
|---|---|---|---|
| Plugin header `einvoice-matbao.php` | modified | `Requires at least: 6.6`, `Requires PHP: 8.2`, `WC requires at least: 9.8`, `WC tested up to: 11.2` | yes |
| `composer.json` | modified | `require.php: ">=8.2"`, `config.platform.php: "8.2.0"` | yes |
| `docker/Dockerfile` build args | modified | `ARG WORDPRESS_IMAGE=wordpress:7.1.4-php8.5-apache`, `ARG WPCLI_IMAGE=wordpress:cli-2.12.0-php8.5` (khai báo trước `FROM` đầu) | no |
| `compose.yaml` env | modified | `build.args.WORDPRESS_IMAGE: ${EINV_WORDPRESS_IMAGE:-wordpress:7.1.4-php8.5-apache}`, `build.args.WPCLI_IMAGE: ${EINV_WPCLI_IMAGE:-wordpress:cli-2.12.0-php8.5}`, `WOOCOMMERCE_VERSION: ${EINV_WOOCOMMERCE_VERSION:-11.2.1}`, `EINV_WP_PHPUNIT_VERSION: ${EINV_WP_PHPUNIT_VERSION:-}`, `EINV_WP_PHPUNIT_DIR: ${EINV_WP_PHPUNIT_DIR:-}` | no |
| `docker/min.env` | new | dotenv: `EINV_WORDPRESS_IMAGE=wordpress:6.6.2-php8.2-apache`, `EINV_WPCLI_IMAGE=wordpress:cli-2.12.0-php8.2`, `EINV_WOOCOMMERCE_VERSION=9.8.7`, `EINV_WP_PHPUNIT_VERSION=~6.6.0`, `EINV_WP_PHPUNIT_DIR=/opt/wp-phpunit/vendor/wp-phpunit/wp-phpunit` | no |
| `scripts/check.sh` | modified | `scripts/check.sh [--min]`; `--min` nạp `.env` (nếu có) rồi export biến trong `docker/min.env`, đặt `COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-einvoice-matbao}-min`, `EINV_HTTP_PORT=$(( ${EINV_HTTP_PORT:-8080} + 1 ))`; không đối số = hành vi cũ. Deviation (apply): đối số lạ in usage và exit 2 | no |
| `docker/setup.sh` | modified | nếu `EINV_WP_PHPUNIT_VERSION` khác rỗng: `mkdir -p /opt/wp-phpunit`; nếu bản đã cài (`composer show --working-dir=/opt/wp-phpunit wp-phpunit/wp-phpunit`) không khớp thì `composer require --working-dir=/opt/wp-phpunit --no-interaction --no-progress "wp-phpunit/wp-phpunit:$EINV_WP_PHPUNIT_VERSION"`; dòng in cuối thêm `wp-phpunit: ${EINV_WP_PHPUNIT_DIR:-vendor}` | no |
| Test bootstrap override | new | trong `tests/integration/bootstrap.php` và `tests/without-woocommerce/bootstrap.php`, ngay sau `require vendor/autoload.php` (wp-phpunit 7.1 đặt `WP_PHPUNIT__DIR` trong autoload, nên phải ghi đè sau đó): nếu `getenv('EINV_WP_PHPUNIT_DIR')` khác rỗng thì yêu cầu `<dir>/includes/functions.php` tồn tại (nếu không: `fwrite(STDERR, …)` + `exit(1)`), rồi `putenv('WP_PHPUNIT__DIR=' . <dir>)` | no |
| `phpcs.xml.dist` | modified | `minimum_wp_version` `6.6`, `testVersion` `8.2-`; thêm `<exclude-pattern>tests/fixtures/*</exclude-pattern>` | no |
| `phpstan.neon` | modified | `phpVersion: 80200` | no |
| `tests/fixtures/php-floor/Php83Only.php` | new | file PHP gọi `json_validate( '{}' )` (PHP 8.3); không nằm trong `paths` PHPStan, không autoload | no |
| `composer.json` scripts | modified | thêm `"analyse:floor-guard"`: chạy `phpstan analyse --no-progress --error-format=raw tests/fixtures/php-floor` và thành công chỉ khi PHPStan exit khác 0 và output chứa `json_validate`; thêm vào `check`. Deviation (apply): thêm `--memory-limit=1G` vì PHPStan crash ở 128M mặc định | no |
| `.github/workflows/php.yml` | modified | `strategy.fail-fast: false`, `matrix.platform: [min, latest]`; bước `if: matrix.platform == 'min'` chạy `grep -E '^[A-Za-z_][A-Za-z0-9_]*=' docker/min.env >> "$GITHUB_ENV"`; `COMPOSE_PROJECT_NAME: einv-ci-${{ matrix.platform }}`; job `min` thêm bước `find einvoice-matbao.php uninstall.php src tests -name '*.php' -not -path 'tests/fixtures/*' -not -path 'tests/e2e/*' -print0 \| xargs -0 -n1 php -l` trong container; job name `PHP (${{ matrix.platform }})` | no |

---

## Task 1: Header, Composer và test khai báo sàn — implements 1.1, 1.2, 1.4

**Files:** Modify `einvoice-matbao.php:11-15`, `composer.json`, `composer.lock`; Create `tests/integration/PlatformRequirementsTest.php`.

- [ ] **Step 1:** Viết `PlatformRequirementsTest` (`WP_UnitTestCase`):
  - `test_plugin_header_declares_platform_floor`: `get_file_data( EINVOICE_MATBAO_FILE, array( 'wp' => 'Requires at least', 'php' => 'Requires PHP', 'wc' => 'WC requires at least', 'wc_tested' => 'WC tested up to' ) )` → assert `'6.6'`, `'8.2'`, `'9.8'`, `'11.2'`.
  - `test_composer_declares_php_floor`: decode `composer.json` của plugin → `require.php === '>=8.2'` và `config.platform.php === '8.2.0'`.
- [ ] **Step 2:** `scripts/check.sh` không cần; chạy trong stack hiện tại: `docker compose exec -T -w /var/www/html/wp-content/plugins/einvoice-matbao wordpress vendor/bin/phpunit -c phpunit.xml.dist --filter PlatformRequirementsTest` → FAIL (header 7.0/8.5).
- [ ] **Step 3:** Sửa header (Contracts) và `composer.json`; trong container chạy `composer update --lock --no-install` rồi `composer update doctrine/instantiator --with-all-dependencies` để resolver hạ các gói dev cần PHP > 8.2. Kiểm: `composer check-platform-reqs --lock` không lỗi với platform 8.2; `jq` trên lock không còn gói `require.php` vượt 8.2.
- [ ] **Step 4:** `composer install` rồi chạy lại test Step 2 → PASS.
- [ ] **Step 5:** Commit `build: lower platform floor to PHP 8.2, WordPress 6.6, WooCommerce 9.8`.

## Task 2: PHPCS, PHPStan và floor guard — implements 1.3, 3.1

**Files:** Modify `phpcs.xml.dist:13-14`, `phpstan.neon:4`, `composer.json` (scripts); Create `tests/fixtures/php-floor/Php83Only.php`.

- [ ] **Step 1 (spike):** Tạo fixture, đổi `phpstan.neon` sang `phpVersion: 80200`, chạy `vendor/bin/phpstan analyse --no-progress tests/fixtures/php-floor` → kỳ vọng báo `json_validate` không tồn tại. Nếu KHÔNG báo: dừng, hỏi user qua Inbox (thêm `phpcompatibility/phpcompatibility-wp` hay chỉ dựa vào `php -l`) theo design Decision 4.
- [ ] **Step 2:** Thêm script `analyse:floor-guard` vào `composer.json` và vào `check`; đổi `phpcs.xml.dist` theo Contracts.
- [ ] **Step 3:** `composer check` trong stack mới nhất → PASS (gồm floor guard). Lỗi PHPStan mới do hạ `phpVersion` (hàm/hằng PHP > 8.2 trong `src/`) → sửa ở `src/` giữ hành vi. WPCS không báo hàm WP mới hơn 6.6; việc đó do Task 4.
- [ ] **Step 4:** Commit `chore: target PHP 8.2 in static analysis and guard the floor`.

## Task 3: Stack Docker chọn tổ hợp — implements 2.1, 2.2, 2.3, 2.4

**Files:** Modify `docker/Dockerfile`, `compose.yaml`, `docker/setup.sh`, `scripts/check.sh`, `tests/integration/bootstrap.php`, `tests/without-woocommerce/bootstrap.php`; Create `docker/min.env`.

- [ ] **Step 1:** `docker/Dockerfile`: hai `ARG` trước `FROM`, `FROM ${WPCLI_IMAGE} AS wpcli`; cập nhật comment đầu file.
- [ ] **Step 2:** `compose.yaml`: `build.args` và env theo Contracts; tạo `docker/min.env`.
- [ ] **Step 3:** `docker/setup.sh`: sau `composer install` của plugin, nếu `EINV_WP_PHPUNIT_VERSION` khác rỗng thì `composer require --working-dir=/opt/wp-phpunit --no-interaction --no-progress "wp-phpunit/wp-phpunit:$EINV_WP_PHPUNIT_VERSION"` (idempotent: bỏ qua nếu đã đúng bản). Dòng in cuối thêm `wp-phpunit <dir>`.
- [ ] **Step 4:** Override trong hai bootstrap (Contracts), với `phpcs:ignore` giống dòng `putenv` sẵn có. `scripts/check.sh` thêm `--min` theo Contracts.
- [ ] **Step 5:** Kiểm: `scripts/check.sh` (mới nhất) in `WordPress 7.1.4, WooCommerce 11.2.1, PHP 8.5.x` và `composer check` PASS; `scripts/check.sh --min` in `WordPress 6.6.2, WooCommerce 9.8.7, PHP 8.2.x`, `wp-phpunit: /opt/wp-phpunit/...`; ghi lại kết quả `composer check` của min (có thể đỏ, xử lý ở Task 4); chạy lại `scripts/check.sh` mới nhất để chắc volume không lẫn. Kiểm bootstrap fail-loud: chạy PHPUnit trong stack min với `-e EINV_WP_PHPUNIT_DIR=/nonexistent` → exit 1 và thông báo rõ. Xác nhận wp-phpunit 6.6 chạy được với `phpunit 9.6` và `yoast/phpunit-polyfills 4` trong lock (nếu không → dừng hỏi user).
- [ ] **Step 6:** Commit `build: make the Docker stack select the platform combination`.

## Task 4: Tương thích tổ hợp tối thiểu — implements 3.2, 3.3

**Files:** Theo lỗi phát hiện, trong `src/` và `tests/`.

- [ ] **Step 1:** Chạy `scripts/check.sh --min`, liệt kê từng lỗi (test, file:line, nguyên nhân: API WP/WC thiếu, khác biệt hành vi WC 9.8, deprecation).
- [ ] **Step 2:** Với mỗi lỗi: test đang đỏ trên min là failing test; sửa bằng nhánh tương thích nhỏ (design Decision 7) dùng API có ở cả hai bản; nếu khác biệt nằm ở test fixture (không phải plugin) thì sửa fixture. Nếu không giữ được hành vi → dừng, báo user qua Inbox.
- [ ] **Step 3:** `scripts/check.sh --min` và `scripts/check.sh` đều PASS; nếu lock cần sửa thêm, cập nhật và chạy lại cả hai.
- [ ] **Step 4:** Commit mỗi nhóm sửa: `fix: support WooCommerce 9.8 in <area>` (bỏ qua nếu không có lỗi; ghi "no incompatibility found" vào apply.md).

## Task 5: CI matrix — implements 4.1, 4.2

**Files:** Modify `.github/workflows/php.yml`.

- [ ] **Step 1:** Thêm matrix, bước nạp env và bước `php -l` theo Contracts; `composer check` đã gồm floor guard qua các bước PHPStan hiện có (thêm bước `composer analyse:floor-guard`). Ghi chú trong apply.md: tên check đổi thành `PHP (min)`/`PHP (latest)`, nếu branch protection tham chiếu tên cũ thì user cập nhật.
- [ ] **Step 2:** Kiểm cú pháp: `bunx --bun actionlint` (hoặc `docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:latest`) → không lỗi.
- [ ] **Step 3:** Xác nhận `e2e.yml`, `release.yml`, `scripts/smoke-zip.sh`, `tests/e2e/docker/compose.e2e.yaml` không đặt biến `EINV_*` nên dùng default mới nhất (`grep -rn EINV_WORDPRESS_IMAGE .github scripts tests/e2e`).
- [ ] **Step 4:** Commit `ci: run PHP checks on minimum and latest platforms`.

## Task 6: Tài liệu — implements 5.1, 5.2

**Files:** Modify `README.md` (mục Requirements, CI, "Pinned versions and known limits"), `docs/discovery.md:7`.

- [ ] **Step 1:** README: thêm "Plugin requirements: PHP 8.2+, WordPress 6.6+, WooCommerce 9.8+"; bảng pinned versions hai cột (minimum/latest); cách chạy `scripts/check.sh --min`; dòng `php.yml` ghi matrix; bỏ câu "only the versions above are tested" thay bằng hai tổ hợp.
- [ ] **Step 2:** `docs/discovery.md` dòng target: "Supported: WordPress 6.6+, WooCommerce 9.8+, PHP 8.2+; tested on minimum and latest combinations."
- [ ] **Step 3:** Commit `docs: document supported platform range`.

---

## Scenario → test

- Header and Composer agree → `tests/integration/PlatformRequirementsTest.php` (`test_plugin_header_declares_platform_floor`, `test_composer_declares_php_floor`)
- Minimum platform → `scripts/check.sh --min` (PHPCS, PHPStan, cả hai suite PHPUnit) và job CI `PHP (min)`
- Latest platform → `scripts/check.sh` và job CI `PHP (latest)`
- PHP syntax above the floor → `composer analyse:floor-guard` (PHPStan `phpVersion 80200` báo `json_validate` trong `tests/fixtures/php-floor/Php83Only.php`) + bước `php -l` trên PHP 8.2 trong job CI `PHP (min)`
- Integration test fails only on the minimum platform → matrix `fail-fast: false` trong `php.yml` (Task 5), release gate gọi `php.yml` qua `workflow_call`
