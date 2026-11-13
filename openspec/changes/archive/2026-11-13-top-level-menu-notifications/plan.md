# Top-level menu và tab Thông báo Implementation Plan

> Executed via `/opsx-apply`, which chooses the executor.
> Spec reference: this change's `specs/` and `design.md`.

**Goal:** Đưa trang "Hóa đơn Mắt Bão" ra menu cấp cao nhất, gộp Lark và Sentry vào tab Thông báo, thêm công tắc bật/tắt cảnh báo Lark.

**Architecture:** Đổi `add_submenu_page` thành `add_menu_page` giữ slug; `AdminTabs` có thêm bảng alias để cả trang và route `tabs/<key>` chuẩn hóa key cũ. Công tắc Lark lưu trong option `einvoice_matbao_settings`, mặc định suy ra từ việc đã có webhook; `InvoiceAlerts::deliver()` kiểm tra công tắc trước webhook.

**Tech Stack:** PHP 8.2, WordPress 7.1, WooCommerce, htmx, PHPUnit 9 (wp-phpunit), phpcs, phpstan, e2e `army` (TypeScript, bun).

## Global Constraints

- Chuỗi UI tiếng Việt, text domain `einvoice-matbao`; code, comment, commit tiếng Anh; docs tiếng Việt.
- Route REST `settings/lark`, `settings/lark/test`, `settings/monitoring`, `settings/monitoring/test` giữ nguyên.
- Không thêm dependency, không đổi DB schema, không ghi option lúc nâng cấp.
- Chạy PHP và composer qua cách repo đã dùng (`composer lint`, `composer analyse`, `composer test:integration`); TypeScript qua `bun`.

## Review Focus

- Admin mở `tab=Lark` (hoa) hoặc `tab=lark1`: vẫn rơi về tab Hóa đơn, không được coi là alias (Task 1).
- Lưu form Lark với công tắc bật nhưng webhook trống và chưa có webhook lưu: bị từ chối, không ghi gì kể cả secret (Task 3).
- Công tắc bật, webhook lưu nhưng không giải mã được (đổi khóa): alert ghi `not_configured`, không ném lỗi (Task 4).
- Công tắc đổi sang tắt giữa lúc xếp hàng và lúc chạy tác vụ: tác vụ ghi `disabled` (Task 4).
- Plugin khác hoặc code còn so khớp `woocommerce_page_einvoice-matbao-settings`: grep không còn chỗ nào dùng (Task 2).

---

## Contracts

| Name | new / modified | Shape | public |
|---|---|---|---|
| `AdminTabs::labels()` | modified | `array<string,string>` đúng 5 key theo thứ tự `invoices`, `connection`, `invoicing`, `notifications`, `advanced`; nhãn `notifications` là "Thông báo" | no |
| `AdminTabs::ALIASES` | new | `public const array ALIASES = ['lark' => 'notifications', 'monitoring' => 'notifications']` | no |
| `AdminTabs::canonical( mixed $tab ): ?string` | new | key tab chuẩn nếu `$tab` là chuỗi khớp chính xác một key hoặc alias, ngược lại `null` | no |
| `AdminTabs::resolve( mixed $tab ): string` | modified | `canonical( $tab ) ?? DEFAULT` | no |
| `SettingsController::show_tab()` | modified | dùng `AdminTabs::canonical`; `null` thì 404 HTML như cũ | no |
| `SettingsPage::TOP_LEVEL_HOOK` | new | `public const string TOP_LEVEL_HOOK = 'toplevel_page_' . self::SLUG` | no |
| `SettingsPage::register_menu()` | modified | `add_menu_page( title, title, CAPABILITY, SLUG, [render], 'dashicons-media-text', 56 )` | no |
| `Settings::lark_enabled( bool $webhook_saved ): bool` | new | khóa `lark_enabled`: `'1'` true, `'0'` false, chưa có thì `$webhook_saved` | no |
| `Settings::save_lark_enabled( bool $enabled ): void` | new | ghi `'1'` hoặc `'0'` vào khóa `lark_enabled` | no |
| Option key `lark_enabled` trong `einvoice_matbao_settings` | new | chuỗi `'1'` hoặc `'0'`, có thể vắng | yes |
| Tham số form `enabled` của `POST settings/lark` | modified | `'1'` là bật, vắng là tắt | yes |
| Trạng thái Lark của alert `disabled` | new | giá trị thứ tư cạnh `sent`, `failed`, `not_configured`, `queue_failed` trong `AlertStore` | yes |
| `AdminAlertNotice::lark_note( 'disabled' )` | modified | `" (Đã tắt cảnh báo Lark nên chưa gửi tin.)"` (bọc ngoặc như các ghi chú khác) | no |
| Tab key `notifications` | new | `tab=notifications` và `GET tabs/notifications`; `tab=lark`, `tab=monitoring` là alias | yes |

---

## Task 1: Tab registry và alias — implements 1.1, 1.2, 1.3

**Files:** `src/Admin/AdminTabs.php`, `src/Admin/SettingsController.php`, `tests/integration/AdminTabsTest.php`, `tests/integration/SettingsTabsRestTest.php`

- [ ] **Step 1:** Trong `AdminTabsTest` đổi `test_tabs_are_ordered_with_invoices_first` thành 5 key; thêm `test_aliases_resolve_to_notifications` (`lark`, `monitoring` → `notifications`) và `test_canonical_returns_null_for_unknown`; giữ data provider `unknown_keys` (`LARK`, `Lark1` vẫn rơi về `invoices`); đổi `test_url_points_to_the_settings_page_tab` sang `notifications`.
- [ ] **Step 2:** Trong `SettingsTabsRestTest` thêm test `GET tabs/lark` và `tabs/monitoring` trả fragment có `data-einv-tab="notifications"`; giữ test `tabs/foo` và `tabs/Lark1` trả 404 HTML.
- [ ] **Step 3:** Chạy `composer test:integration -- --filter '(AdminTabsTest|SettingsTabsRestTest)'`; kỳ vọng FAIL.
- [ ] **Step 4:** Implement theo Contracts (`labels`, `ALIASES`, `canonical`, `resolve`, `show_tab`).
- [ ] **Step 5:** Chạy lại lệnh Step 3; kỳ vọng PASS. Commit `feat: merge lark and monitoring tabs into notifications with aliases`.

## Task 2: Menu cấp cao nhất — implements 2.1, 2.2

**Files:** `src/Admin/SettingsPage.php`, `tests/integration/SettingsPageTest.php`

- [ ] **Step 1:** Trong `SettingsPageTest` sửa test menu: sau `SettingsPage::register_menu()` global `$menu` có mục slug `einvoice-matbao-settings` quyền `manage_options`, `$submenu['woocommerce']` không có slug này; sửa test asset để gọi `enqueue_assets( SettingsPage::TOP_LEVEL_HOOK )` có nạp, `woocommerce_page_…` và `admin_page_…` không nạp. Giữ test redirect `InvoicePage`.
- [ ] **Step 2:** Chạy `composer test:integration -- --filter SettingsPageTest`; kỳ vọng FAIL.
- [ ] **Step 3:** Implement `TOP_LEVEL_HOOK`, `register_menu()` và điều kiện trong `enqueue_assets()` theo Contracts; cập nhật docblock đầu class (không còn "under the WooCommerce menu").
- [ ] **Step 4:** Chạy `rg "woocommerce_page_" src tests`; kỳ vọng chỉ còn trong test negative. Chạy lại Step 2; PASS. Commit `feat: register the settings page as a top-level menu`.

## Task 3: Công tắc Lark — lưu và đọc — implements 3.1, 3.2

**Files:** `src/Settings.php`, `src/Admin/SettingsController.php`, `tests/integration/SettingsFailureModesRestTest.php` (test Lark REST hiện nằm ở đây), `tests/integration/SettingsTest.php` (tạo nếu chưa có)

- [ ] **Step 1:** Viết test `Settings`: `lark_enabled(true)` và `lark_enabled(false)` khi chưa lưu trả lại tham số; sau `save_lark_enabled(false)` trả false dù `$webhook_saved` true; sau `save_lark_enabled(true)` trả true dù `$webhook_saved` false.
- [ ] **Step 2:** Viết test REST `settings/lark`: tắt giữ webhook và secret; bật khi chưa có webhook và ô trống trả lỗi, `lark_enabled` không đổi và secret không bị ghi; bật khi đang nhập webhook hợp lệ thành công; `settings/lark/test` gửi được khi công tắc tắt.
- [ ] **Step 3:** Chạy `composer test:integration -- --filter '(SettingsTest|SettingsFailureModesRestTest)'`; kỳ vọng FAIL.
- [ ] **Step 4:** Implement `Settings` methods theo Contracts. Trong `save_lark()` kiểm tra mọi thứ (webhook hợp lệ, bật mà không có webhook) trước khi ghi bất cứ gì, rồi ghi webhook, secret và công tắc; `test_lark()` không đọc công tắc.
- [ ] **Step 5:** Chạy lại Step 3; PASS. Commit `feat: add Lark alert switch to settings`.

## Task 4: Gửi cảnh báo theo công tắc — implements 3.3, 3.4

**Files:** `src/Alert/InvoiceAlerts.php`, `src/Alert/AdminAlertNotice.php`, `tests/integration/InvoiceAlertTest.php`

- [ ] **Step 1:** Thêm test: công tắc tắt + có webhook → `send()` không có HTTP request, `AlertStore` lark = `disabled`, không có tác vụ retry; công tắc tắt + không webhook → `disabled`; công tắc bật + không webhook → `not_configured` (test cũ); công tắc bật + webhook không giải mã được → `not_configured`, không ném lỗi; bật lại công tắc rồi gọi `send()` cho alert `disabled` cũ không gửi (cần `send()` bỏ qua trạng thái `disabled`), alert mới thì gửi; đổi công tắc sang tắt sau khi xếp hàng, trước `send()` → `disabled`; notice của alert `disabled` có ghi chú "Đã tắt cảnh báo Lark".
- [ ] **Step 2:** Chạy `composer test:integration -- --filter InvoiceAlertTest`; kỳ vọng FAIL.
- [ ] **Step 3:** Implement trong `deliver()`: đọc `Settings::lark_enabled( $store->has_lark() )` trước `lark()`; tắt thì `AlertStore::set( $key, 'lark', 'disabled' )` và return. Trong `send()` thêm `'disabled'` vào điều kiện return sớm cùng `'sent'`. Thêm nhánh `'disabled'` trong `lark_note()`.
- [ ] **Step 4:** Chạy lại Step 2; PASS. Commit `feat: skip Lark alerts when the switch is off`.

## Task 5: Giao diện tab Thông báo — implements 4.1, 4.2, 4.3

**Files:** `src/Admin/SettingsPage.php`, `tests/integration/SettingsPageTest.php`, `tests/integration/SettingsMonitoringRestTest.php`, `tests/integration/Monitoring/SentryDsnTest.php`

- [ ] **Step 1:** Cập nhật `SettingsPageTest`: tab `notifications` có form Lark (checkbox `enabled`, `webhook_url`, nút gửi thử) và form Sentry, mỗi thẻ có `.einv-result` riêng, không có form Mắt Bão; checkbox Lark `checked` cho site có webhook chưa lưu công tắc, không `checked` cho site mới; tab bar 5 tab; `tab=lark` và `tab=monitoring` render `data-einv-tab="notifications"`. Đổi `rustrak.ltt.onl` thành `sentry.example.com` trong `SettingsMonitoringRestTest` và `SentryDsnTest`.
- [ ] **Step 2:** Chạy `composer test:integration -- --filter '(SettingsPageTest|SettingsMonitoringRestTest|SentryDsnTest)'`; kỳ vọng FAIL ở test mới.
- [ ] **Step 3:** Implement `render_tabs()`: `notifications` trả `render_lark_panel() . render_monitoring_panel()`, xóa `lark` và `monitoring` khỏi `match`. Thêm vào `render_lark_panel()` checkbox `name="enabled" value="1"` nhãn "Bật cảnh báo Lark", `checked` theo `Settings::lark_enabled( $store->has_lark() )`, cùng kiểu markup checkbox Sentry.
- [ ] **Step 4:** Chạy lại Step 2; PASS. Commit `feat: render Lark and Sentry cards in the notifications tab`.

## Task 6: e2e, tài liệu và kiểm chứng — implements 5.1, 5.2, 5.3

**Files:** `tests/e2e/army/tabs.army.ts`, `settings.army.ts`, `monitoring.army.ts`, `support.ts`, `tests/e2e/e2e.army.config.ts`, `README.md`, `docs/phat-trien.md`, `docs/huong-dan-su-dung.md`

- [ ] **Step 1:** Cập nhật e2e: `support.ts` trỏ cả hai đường dẫn cũ sang `tab=notifications`; `tabs.army.ts` kiểm tra 5 tab và URL `tab=notifications`; `settings.army.ts`, `monitoring.army.ts` và mô tả giao diện trong `e2e.army.config.ts` (menu cấp cao nhất, tab "Thông báo", checkbox Lark).
- [ ] **Step 2:** Cập nhật ba file tài liệu (tiếng Việt): menu không còn dưới WooCommerce, tab Thông báo, công tắc Lark, alias URL cũ.
- [ ] **Step 3:** Chạy `composer lint`, `composer analyse`, `composer test`; kỳ vọng exit 0. Nếu có môi trường e2e (`docker`), chạy bộ e2e `army`; nếu không, ghi rõ trong `apply.md` rằng chưa chạy được và lý do.
- [ ] **Step 4:** Commit `docs: update e2e and docs for the notifications tab`.

---

## Scenario → test

**admin-settings**
- Administrator sees the menu → `SettingsPageTest` (test menu, Task 2)
- Shop manager denied → `SettingsPageTest` (test quyền, giữ nguyên)
- Old invoice list URL → `SettingsPageTest` (test redirect, giữ nguyên)
- Old invoice list URL without capability → `SettingsPageTest` (giữ nguyên)
- Default tab → `SettingsPageTest` (giữ nguyên)
- Deep link to a tab → `SettingsPageTest` (tab `notifications`, Task 5)
- Legacy tab keys → `AdminTabsTest::test_aliases_resolve_to_notifications` và `SettingsPageTest` (Task 1, 5)
- Notifications tab order → `AdminTabsTest::test_tabs_are_ordered_with_invoices_first` và `SettingsPageTest` (tab bar 5 tab)
- Monitoring tab order → `AdminTabsTest::test_tabs_are_ordered_with_invoices_first` (không có tab `monitoring`)
- Unknown tab → `AdminTabsTest::test_resolve_falls_back_for_unknown_keys`
- Missing encryption key on any tab → `SettingsPageTest` (giữ nguyên)
- Invoicing tab contents → `SettingsPageTest` (thêm assert không có DSN)
- Notifications tab contents → `SettingsPageTest` (Task 5)
- Saving one notification card → `SettingsPageTest` (hai `.einv-result` riêng) và e2e `settings.army.ts`
- Monitoring tab contents → `SettingsPageTest` (`tab=monitoring` render `notifications`)
- Advanced tab contents → `SettingsPageTest` (giữ nguyên)
- Template list stays with credentials → `SettingsConnectionRestTest` (giữ nguyên)
- Switch tab → e2e `tabs.army.ts`
- Legacy tab route → `SettingsTabsRestTest` (Task 1)
- Reload keeps the tab → e2e `tabs.army.ts`
- Back button → e2e `tabs.army.ts`
- Unknown tab route → `SettingsTabsRestTest`
- Malformed tab key → `SettingsTabsRestTest`
- Tab route without capability → `SettingsTabsRestTest` / `SettingsRestAccessTest`

**error-monitoring**
- Save valid settings → `SettingsMonitoringRestTest`
- Form after save → `SettingsMonitoringRestTest` và `SettingsPageTest` (tab `notifications`)
- Invalid DSN → `SettingsMonitoringRestTest`
- Sample rate out of range → `SettingsMonitoringRestTest`
- Blank DSN keeps stored value → `SettingsMonitoringRestTest`

**failure-alerts**
- Existing site keeps alerts → `SettingsTest` và `SettingsPageTest` (checkbox checked) (Task 3, 5)
- New site default → `SettingsTest` và `SettingsPageTest`
- Switch off keeps webhook → `SettingsFailureModesRestTest` (Task 3)
- Enable without webhook → `SettingsFailureModesRestTest`
- Alert while switched off → `InvoiceAlertTest` (Task 4)
- Switch turned on later → `InvoiceAlertTest`
- Test message while switched off → `SettingsFailureModesRestTest`
- No webhook → `InvoiceAlertTest` (test cũ, công tắc bật)
- Switched off without webhook → `InvoiceAlertTest`
- Queue failure → `InvoiceAlertTest` (giữ nguyên)
