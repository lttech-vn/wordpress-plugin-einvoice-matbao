# Explicit KCT/KKKNT Tax Rate Implementation Plan

> Executed via `/opsx-apply`, which chooses the executor.
> Spec reference: this change's `specs/` and `design.md`.

**Goal:** `TSuat` chỉ lấy từ thuế suất đã lưu trên từng dòng đơn. KCT/KKKNT là thuế suất 0% do admin chọn từ danh sách. Dòng không lưu thuế suất nào bị chặn.

**Architecture:**
- `Settings` lưu hai list rate ID của WooCommerce: KCT và KKKNT.
- `DraftPayloadBuilder` đọc map này một lần mỗi payload và quyết từng dòng chỉ từ `get_taxes()['total']` đã lưu, không đọc `tax_status`.
- Card htmx mới trong tab Xuất hóa đơn gửi tới route REST mới. Route kiểm rate ID với WooCommerce trước khi lưu và giữ gán của rate đã xóa (orphan).

**Tech Stack:** PHP 8.2+, WordPress REST + fragment htmx, WooCommerce `WC_Tax`, PHPUnit (wp-phpunit) qua `einv composer test:integration`, e2e Army (`*.army.ts`).

## Global Constraints

- **Ngôn ngữ:**
  - Comment, docblock, identifier, commit: tiếng Anh.
  - Chuỗi UI: tiếng Việt qua text domain `einvoice-matbao`.
  - Tài liệu (`docs/`, README): tiếng Việt.
- **`InvoiceErrorCode`:** giá trị enum ổn định; chỉ thêm case, không đổi tên.
- **Không** thêm dependency, không đổi DB schema, không migrate đơn cũ.
- **Coding standards:**
  - Theo `~/.agents/skills/coding-standards/SKILL.md`.
  - Không làm `DraftPayloadBuilder::line()` dài thêm: quyết định thuế nằm trong method riêng.
  - Truy vấn WooCommerce cho form nằm trong class riêng, không nhét vào `SettingsController`/`SettingsPage`.
- **Kiểm tra trong dev container:** `einv composer test:integration`, `einv composer lint`, `einv composer analyse` (xem `docs/phat-trien.md`).

## Review Focus

- **Rate đã gán KCT bị sửa thành 8% trong WooCommerce.**
  - Dòng mang rate KCT có tiền thuế → `untaxed_line_with_tax`, không bao giờ ra `TSuat=-1` kèm tiền thuế.
  - Test ở Task 2: `test_exempt_rate_with_tax_amount_is_blocked`.
- **Rate đã gán rồi bị xóa khỏi WooCommerce.**
  - Trang cài đặt không lỗi; gán vẫn còn sau lần lưu khác; đơn cũ vẫn ra `TSuat=-1`.
  - Test ở Task 3: `test_deleted_rate_keeps_its_assignment_when_another_is_saved`, `test_old_order_with_deleted_kct_rate_still_maps`.
- **Biến thể có sản phẩm cha để *Không*.**
  - Biến thể kế thừa `tax_status` của cha → không có rate → `line_tax_not_configured`.
  - Cùng đường với test sản phẩm *Không* ở Task 2; không cần test riêng.
- **Đơn miễn VAT (`is_vat_exempt`).**
  - WooCommerce không lưu rate → `line_tax_not_configured`.
  - Test ở Task 2: `test_vat_exempt_order_is_blocked`.
- **Request `tax_rate_kind` sai kiểu** (chuỗi thay vì mảng, giá trị lồng).
  - Phải 422 và không ghi gì; khác với trường hợp thiếu field (= xóa gán).
  - Test ở Task 3: `test_malformed_field_is_rejected`.

---

## Contracts

| Name | new / modified | Shape | public |
|---|---|---|---|
| Option keys `kct_tax_rate_ids`, `kkknt_tax_rate_ids` trong `einvoice_matbao_settings` | new | `list<int>` rate ID WooCommerce nguyên dương; vắng = rỗng | no |
| `Settings::TAX_RATE_KCT = -1`, `Settings::TAX_RATE_KKKNT = -2` | new | hằng int (mã `TSuat`) | no |
| `Settings::exempt_tax_rates(): array` | new | trả `array<int, int>`: `tax_rate_id => TSuat` (`-1`/`-2`). Chỉ nhận số nguyên dương: `int > 0` hoặc chuỗi khớp `/^[1-9][0-9]{0,18}$/` và `<= PHP_INT_MAX`. Bỏ phân số, số mũ, số âm, 0, tràn số. ID có ở cả hai list → KKKNT thắng | no |
| `Settings::save_exempt_tax_rates( array $kct, array $kkknt ): void` | new | `list<int>`, `list<int>`; ghi đè cả hai list như được truyền. Caller bảo đảm hai list rời nhau, ID hợp lệ và đã gộp orphan | no |
| `DraftPayloadBuilder::TAX_RATE_NOT_TAXABLE` | modified | xóa (thay bằng hằng của `Settings`) | no |
| `DraftPayloadBuilder::line( WC_Order_Item $item, int $index, array $rates, string $default_unit, array $exempt ): ?array` | modified | thêm `$exempt` (`array<int, int>` từ `exempt_tax_rates()`) | no |
| `DraftPayloadBuilder::tax_rate( LineFailure $line, array $line_taxes, int $tax, array $rates, array $exempt ): int` | new (thay `rate()`) | trả `TSuat`; ném `InvoiceDataError` theo thứ tự spec *VAT rate mapping* | no |
| `InvoiceErrorCode::LINE_TAX_NOT_CONFIGURED` | new | `'line_tax_not_configured'`, kind `DATA`, status `failed`, không tự retry, cần admin xử lý | yes (cột `error_code`, tag Sentry, Lark) |
| `InvoiceErrorCode::UNTAXED_LINE_WITH_TAX` | modified | giữ giá trị; nghĩa mới "dòng KCT/KKKNT có tiền thuế"; label/hint theo spec failure-alerts | yes |
| `ExemptTaxRateOptions` (`src/Admin/ExemptTaxRateOptions.php`) | new | final class, static: `zero_rates(): list<array{id: int, name: string, class: string, country: string}>`; `zero_rate_ids(): list<int>` (internal; `is_zero_rate`/`exists` removed: controller computes the zero-rate ID set once per request and keeps assignments of every non-0% ID) | no |
| REST `POST einvoice-matbao/v1/settings/tax-rates` | new | body `tax_rate_kind[<rate_id>] = vat0\|kct\|kkknt`. Field vắng → mọi rate 0% hiện có là `vat0`. Field có mặt mà không phải mảng phẳng chuỗi → 422. Gán của rate ID không còn tồn tại được giữ. 200 fragment success, 422 fragment error, 403 như route khác | yes (route admin) |
| `SettingsRestTestCase::htmx()` param `$params` | modified (test support) | phpdoc `array<string, mixed>` để gửi mảng lồng | no |
| `InvoiceWorkflowTestCase`: `CLASS_KCT = 'einv-kct'`, `CLASS_KKKNT = 'einv-kkknt'`, `rate_id_of( string $class_slug ): int` | new (test support) | mỗi lớp có một rate 0% tạo trong `set_up()`; `rate_id_of` trả `tax_rate_id` đầu tiên của lớp | no |
| `ExemptTaxRateOptions::stale_assignments( array $assigned ): list<array{id: int, name: string, class: string, percent: string, code: int}>` | new | từ map `exempt_tax_rates()`, trả các rate còn tồn tại (`WC_Tax::_get_tax_rate`) có tỷ lệ khác 0; `percent` là tỷ lệ đã định dạng, `code` là -1/-2 | no |
| REST `POST einvoice-matbao/v1/settings/tax-rates/unassign` | new | body `rate_id` (số nguyên dương theo `Settings::parse_rate_id()`); chỉ nhận rate còn tồn tại, đã gán, khác 0%; xóa đúng phép gán đó qua `save_exempt_tax_rates()`; 200 fragment success chỉ gồm thông báo (yêu cầu tải lại trang để cập nhật danh sách; deviation: bản đầu ghi "kèm card đã cập nhật", đổi vì không cần OOB swap — ruling Task 6), 422 fragment error không ghi gì, 403 như route khác | yes (route admin) |
| e2e seam `POST einv-e2e/v1/settings` | modified (test only) | nhận `exempt: { kct: string[], kkknt: string[] }` (tên rate) | no |

---

## Task 1: Lưu gán thuế suất KCT/KKKNT — implements 1

**Covers:** 1.1, 1.2
**Difficulty:** Easy
**Blocked by:** none

**Files:**
- Modify: `src/Settings.php`
- Create: `tests/integration/SettingsExemptTaxRatesTest.php`

**Interfaces:**
- Produces: `Settings::exempt_tax_rates()`, `Settings::save_exempt_tax_rates()`, `Settings::TAX_RATE_KCT`, `Settings::TAX_RATE_KKKNT` (xem Contracts).

- [ ] **Step 1: Viết test thất bại** trong `SettingsExemptTaxRatesTest` (extends `WP_UnitTestCase`):
  - `test_nothing_is_exempt_before_saving`: `assertSame( array(), ( new Settings() )->exempt_tax_rates() )`.
  - `test_saved_rates_map_to_tsuat`: `save_exempt_tax_rates( array( 12, 13 ), array( 20 ) )` → `array( 12 => -1, 13 => -1, 20 => -2 )`.
  - `test_saving_replaces_previous_assignment`: lưu `( [12], [20] )` rồi `( [], [12] )` → `array( 12 => -2 )`.
  - `test_only_positive_integer_ids_are_read`:
    - `update_option( Settings::OPTION, array( 'kct_tax_rate_ids' => array( 7, '9', '7.5', 0.5, '1e3', -3, 0, 'x', '99999999999999999999' ), 'kkknt_tax_rate_ids' => 'oops' ) )`
    - → `array( 7 => -1, 9 => -1 )`.
  - `test_id_in_both_lists_reads_as_kkknt`: option `kct = [5]`, `kkknt = [5]` → `array( 5 => -2 )`.
- [ ] **Step 2: Chạy** `einv composer test:integration -- --filter SettingsExemptTaxRatesTest`. Kỳ vọng: FAIL (method chưa có).
- [ ] **Step 3: Cài đặt trong `src/Settings.php`:**
  - Hai hằng.
  - `exempt_tax_rates()`, đọc qua private `positive_ids( string $key ): array` theo quy tắc trong Contracts; ghi KCT trước, KKKNT sau.
  - `save_exempt_tax_rates()` qua `update()`.
  - Mở rộng phpdoc của `update()` thành `array<string, mixed>`; cập nhật docblock lớp.
- [ ] **Step 4: Chạy** lại filter. Kỳ vọng: PASS.
- [ ] **Step 5: Commit** `feat(settings): store KCT and KKKNT tax rate assignment`.

---

## Task 2: Ánh xạ thuế suất và dòng chưa cài thuế — implements 2

**Covers:** 2.1, 2.2, 2.3
**Difficulty:** Hard
**Blocked by:** Task 1

**Files:**
- Modify: `src/Invoice/InvoiceErrorCode.php`, `src/Invoice/DraftPayloadBuilder.php`
- Modify test:
  - `tests/integration/InvoiceErrorCodeTest.php`
  - `tests/integration/DraftInvoiceWorkflowTest.php`
  - `tests/integration/BlockedDraftInvoiceTest.php`
  - `tests/integration/Support/InvoiceWorkflowTestCase.php`
- Verify (không đổi nếu xanh): `tests/integration/Monitoring/WorkflowFailureDetailTest.php`, `tests/integration/DraftPayloadLineFailureTest.php`, `tests/integration/Monitoring/SameHintEverywhereTest.php`, `tests/integration/Monitoring/NeedsAttentionEventTest.php`, `tests/integration/OrderInvoiceMetaBoxTest.php`

**Interfaces:**
- Consumes: `Settings::exempt_tax_rates()`, `Settings::save_exempt_tax_rates()` (Task 1).
- Produces: `InvoiceErrorCode::LINE_TAX_NOT_CONFIGURED`; test support `CLASS_KCT`, `CLASS_KKKNT`, `rate_id_of()` (Contracts).

- [ ] **Step 1: Test mã lỗi** trong `InvoiceErrorCodeTest`:
  - thêm dòng data `'line_tax_not_configured' => array( 'line_tax_not_configured', 'data', 'failed', false )`;
  - `test_the_table_lists_every_code`: đổi `assertCount( 34, … )` thành `35`;
  - `test_tax_hints_match_the_design`:
    - hint của `LINE_TAX_NOT_CONFIGURED`, `LINE_TAX_RATE_COUNT`, `UNTAXED_LINE_WITH_TAX`, `TAX_RATE_UNSUPPORTED` đúng nguyên văn trong `specs/failure-alerts/spec.md`;
    - label `'Dòng chưa cài thuế'` và `'Dòng KCT/KKKNT có tiền thuế'`;
  - `test_data_error_hint` (đã có): thêm assert hint chứa `"Tính lại"`.
- [ ] **Step 2: Chạy** `--filter InvoiceErrorCodeTest`. Kỳ vọng: FAIL.
- [ ] **Step 3: Cài đặt mã lỗi:**
  - Case `LINE_TAX_NOT_CONFIGURED` đặt cạnh `LINE_TAX_RATE_COUNT`, thêm vào nhánh `DATA` của `kind()`, có label và hint.
  - Đổi hint ba mã còn lại và label `UNTAXED_LINE_WITH_TAX` theo nguyên văn spec.
- [ ] **Step 4: Chạy** `--filter 'InvoiceErrorCodeTest|SameHintEverywhereTest|NeedsAttentionEventTest|OrderInvoiceMetaBoxTest'`. Kỳ vọng: PASS. Commit `feat(invoice): add line_tax_not_configured error code`.
- [ ] **Step 5: Test support:**
  - Thêm `CLASS_KCT`, `CLASS_KKKNT` vào `InvoiceWorkflowTestCase`, mỗi lớp `create_tax_rate( …, 0 )` trong `set_up()`.
  - Thêm `rate_id_of( string $class_slug ): int`, đọc `WC_Tax::get_rates_for_tax_class( $slug )` và trả `tax_rate_id` đầu tiên.
- [ ] **Step 6: Test ánh xạ (thất bại)** trong `DraftInvoiceWorkflowTest`:
  - Thay `test_rates_map_to_tsuat_and_untaxed_products_are_kct` bằng `test_rates_map_to_tsuat_including_assigned_kct_and_kkknt`:
    - gán `( [ rate_id_of( CLASS_KCT ) ], [ rate_id_of( CLASS_KKKNT ) ] )`;
    - sản phẩm 10%, 5%, 8%, 0%, KCT, KKKNT, mỗi cái 100000;
    - → `TSuat` `[10, 5, 8, 0, -1, -2]`, `TThue` `[10000, 5000, 8000, 0, 0, 0]`, `TgTThue` 23000.
  - `test_standard_and_additional_zero_rates_assigned_kct_both_map`:
    - trong test, xóa rate 10% chuẩn rồi tạo rate 0% cho lớp Standard (`create_tax_rate( '', 0 )`);
    - gán KCT cho rate đó và cho `rate_id_of( CLASS_KCT )`;
    - một sản phẩm lớp Standard, một sản phẩm `CLASS_KCT` → `TSuat` `[-1, -1]`.
  - `test_zero_rate_not_assigned_stays_zero`: sản phẩm `CLASS_ZERO`, chưa gán → `TSuat` 0.
  - `test_assignment_is_read_when_sending`:
    - đơn có sản phẩm `CLASS_ZERO`; `$order->update_status( 'completed' )` (chưa chạy queue);
    - gán KCT cho `rate_id_of( CLASS_ZERO )`;
    - `$this->run_queued_draft_actions()` → `TSuat` -1.
  - Thay `test_free_product_in_a_paid_order_is_sent_with_rate_by_tax_status` bằng `test_free_product_is_sent_with_its_stored_rate`: `A` 100000 và `Tặng kèm` 0 (Standard) → dòng hai `TSuat` 10, `TThue` 0.
  - Thay `test_free_product_without_a_stored_rate_uses_the_rate_for_its_tax_status` bằng `test_free_product_without_a_stored_rate_is_blocked`: setup cũ làm rỗng taxes của dòng 0 đồng → `failed`, `error_code` `line_tax_not_configured`, không có request create.
  - Sửa `test_shop_without_tax_calculation_is_failed_instead_of_guessing_a_rate`: assert `error_code` `line_tax_not_configured` và reason chứa `'chưa có thuế GTGT'`, thay cho `'thuế suất'`.
  - `test_stored_taxes_are_kept_after_tax_calculation_is_turned_off`: tạo đơn (10%), rồi `update_option( 'woocommerce_calc_taxes', 'no' )`, complete → `TSuat` 10, `TThue` 10000.
  - `test_vat_exempt_order_is_blocked`:
    - đơn có sản phẩm 10%; `$order->update_meta_data( 'is_vat_exempt', 'yes' )`, `calculate_totals()`, `save()`;
    - assert dòng không có rate → `line_tax_not_configured`.
- [ ] **Step 7: Test chặn (thất bại)** trong `BlockedDraftInvoiceTest`:
  - `test_not_taxable_product_is_blocked_as_not_configured`: sản phẩm `'KCT cũ'` trạng thái `none` → `assert_blocked( $order, LINE_TAX_NOT_CONFIGURED, 'data', FAILED, 'chưa có thuế GTGT' )`.
  - `test_fee_without_a_stored_rate_is_blocked`:
    - thêm fee 10000 `taxable`, `set_taxes( false )`, `calculate_totals( false )`;
    - → `LINE_TAX_NOT_CONFIGURED`, lý do nêu tên fee.
  - `test_shipping_without_a_stored_rate_is_blocked`:
    - thêm `WC_Order_Item_Shipping` tên `'Giao hàng'`, total 30000, `set_taxes( false )`, `calculate_totals( false )`;
    - → `LINE_TAX_NOT_CONFIGURED`, lý do nêu `'Giao hàng'`.
  - `test_free_fee_and_shipping_without_rate_are_left_off`:
    - thêm fee 0 và shipping 0, cả hai `set_taxes( false )`;
    - → nháp được gửi, không dòng nào trong `DSHHDVu`.
  - Thay `test_untaxed_line_with_tax_blocks_the_draft` bằng `test_exempt_rate_with_tax_amount_is_blocked`:
    - gán rate 10% chuẩn là KCT trực tiếp qua `Settings`, giả lập rate bị sửa sau khi gán;
    - sản phẩm 100000 Standard → `UNTAXED_LINE_WITH_TAX`, lý do chứa `'dùng thuế KCT/KKKNT nhưng có tiền thuế'`.
  - `test_kkknt_rate_with_tax_amount_is_blocked`: như trên nhưng gán KKKNT → `UNTAXED_LINE_WITH_TAX`.
  - `test_several_rates_including_kct_are_blocked_as_rate_count`:
    - dựng như `test_line_with_several_tax_rates_blocks_the_draft`: lớp `einv-double`, `_insert_tax_rate` rate 0% priority 1 và rate 5% priority 2, cùng country/state rỗng;
    - gán rate 0% là KCT; sản phẩm 100000;
    - trước khi chạy workflow, assert dòng có đúng 2 rate ID và tổng thuế 5000;
    - → `LINE_TAX_RATE_COUNT`.
  - Giữ nguyên `test_line_with_several_tax_rates_blocks_the_draft`, `test_unusual_tax_rate_blocks_the_draft`, `test_line_whose_rate_percent_is_not_stored_blocks_the_draft`; chúng phải vẫn xanh.
- [ ] **Step 8: Chạy** `--filter 'DraftInvoiceWorkflowTest|BlockedDraftInvoiceTest'`. Kỳ vọng: test mới/đổi FAIL, test còn lại PASS.
- [ ] **Step 9: Cài đặt trong `DraftPayloadBuilder`:**
  - `build()`: đọc `$exempt = ( new Settings() )->exempt_tax_rates()` một lần, cạnh `$default_unit`, truyền vào `line()`.
  - `line()`:
    - bỏ `$taxable` và `get_tax_status()` ở cả hai nhánh;
    - sau `whole_vnd(tax)`, chỉ phí/vận chuyển có net 0 và tax 0 mới `return null`; sản phẩm không còn ngoại lệ;
    - sau đó `$rate = $this->tax_rate( $failure, $line_taxes, $tax, $rates, $exempt )`;
    - xóa kiểm tra `UNTAXED_LINE_WITH_TAX` dựa trên `$taxable`.
  - `tax_rate()` thay `rate()`, theo thứ tự spec:
    1. 0 rate → `LINE_TAX_NOT_CONFIGURED` với lý do `sprintf( __( 'Dòng "%s" chưa có thuế GTGT.', 'einvoice-matbao' ), $name )`;
    2. >1 rate → `LINE_TAX_RATE_COUNT` (giữ lý do cũ);
    3. rate trong `$exempt` → `0 !== $tax` thì `UNTAXED_LINE_WITH_TAX` với `'Dòng "%s" dùng thuế KCT/KKKNT nhưng có tiền thuế.'`, ngược lại trả mã đã gán;
    4. còn lại → logic percent cũ (`TAX_RATE_NOT_STORED`, `TAX_RATE_UNSUPPORTED`).
  - Xóa `TAX_RATE_NOT_TAXABLE`; cập nhật docblock lớp và các dòng `@throws`.
- [ ] **Step 10: Chạy** toàn bộ `einv composer test:integration`. Kỳ vọng: PASS, 0 lỗi.
  - Test khác giả định `none` → `-1` hoặc dòng không rate → 0%: sửa bằng cách cho sản phẩm/fee một rate đã lưu.
  - Không nới assertion.
- [ ] **Step 11: Chạy** `einv composer lint` và `einv composer analyse`. Kỳ vọng: sạch.
- [ ] **Step 12: Commit** `feat(invoice): map KCT/KKKNT from assigned rates and block lines without tax`.

---

## Task 3: Form gán KCT/KKKNT trong tab Xuất hóa đơn — implements 3

**Covers:** 3.1, 3.2, 3.3
**Difficulty:** Normal
**Blocked by:** Task 1

**Files:**
- Create: `src/Admin/ExemptTaxRateOptions.php`, `tests/integration/SettingsTaxRatesRestTest.php`
- Modify: `src/Admin/SettingsController.php`, `src/Admin/SettingsPage.php`
- Modify test: `tests/integration/SettingsPageTest.php`, `tests/integration/Support/SettingsRestTestCase.php` (chỉ phpdoc `$params`)
- Note: `test_old_order_with_deleted_kct_rate_still_maps` dùng workflow; đặt trong `tests/integration/DraftInvoiceWorkflowTest.php` và chạy sau khi Task 2 đã merge (xem Step 8).

**Interfaces:**
- Consumes: `Settings::exempt_tax_rates()`, `Settings::save_exempt_tax_rates()`, `Settings::TAX_RATE_KCT|KKKNT` (Task 1).
- Produces: `ExemptTaxRateOptions` (Contracts).
  - `zero_rates()`: rate có `(float) tax_rate === 0.0` của slug `''` (Standard, không có trong `get_tax_class_slugs()`) và từng slug của `WC_Tax::get_tax_class_slugs()`, qua `WC_Tax::get_rates_for_tax_class()`.
  - `class` là tên hiển thị (`'Tiêu chuẩn'` cho `''`, các lớp khác lấy từ `WC_Tax::get_tax_classes()`); `country` là mã quốc gia hoặc `'*'`.

- [ ] **Step 1: Viết test thất bại** trong `SettingsTaxRatesRestTest`. Lớp extends `SettingsRestTestCase`, đăng nhập administrator; `set_up()` tạo lớp `kct`, `kkknt` (mỗi lớp một rate 0%) và một rate 10% Standard.
  - `test_not_configured_shows_vat0_for_every_zero_rate`: chưa lưu → HTML tab `invoicing` có `<option value="vat0" selected` cho cả hai rate 0%.
  - `test_saves_kct_and_kkknt`: POST `tax_rate_kind = [ kctId => 'kct', kkkntId => 'kkknt' ]` → 200, fragment success, `exempt_tax_rates() == [ kctId => -1, kkkntId => -2 ]`.
  - `test_standard_and_additional_zero_rates_in_one_group`: thêm rate 0% Standard; POST rate đó và kctId là `kct` → cả hai -1.
  - `test_vat0_clears_an_assignment`: đã gán kct, POST `[ kctId => 'vat0' ]` → `[]`.
  - `test_missing_field_clears_the_assignment`: đã gán, POST không param → 200, `[]`.
  - `test_malformed_field_is_rejected`: đã gán kct; POST `tax_rate_kind = 'oops'`, rồi `[ kctId => [ 'kct' ] ]`, rồi request JSON `{"tax_rate_kind": null}` (`WP_REST_Request` với `set_body( wp_json_encode( … ) )` và `Content-Type: application/json`) → mỗi lần 422, gán không đổi.
  - `test_mixed_valid_and_invalid_entries_write_nothing`: POST `[ kctId => 'kct', tenId => 'kct' ]` → 422, `[]`.
  - `test_non_zero_rate_is_rejected`: `[ tenId => 'kct' ]` → 422, gán không đổi.
  - `test_unknown_rate_is_rejected`: `[ 999999 => 'kct' ]` → 422, không đổi.
  - `test_unknown_kind_is_rejected`: `[ kctId => 'exempt' ]` → 422, không đổi.
  - `test_non_administrator_cannot_save` (role `shop_manager` → 403) và `test_invalid_nonce_cannot_save` (→ 403), gán không đổi.
  - `test_form_lists_only_zero_rates_with_current_choice`. Đã gán kct; HTML tab `invoicing`:
    - có `name="tax_rate_kind[<kctId>]"` với option `kct` selected;
    - có `tax_rate_kind[<kkkntId>]`, không có `tax_rate_kind[<tenId>]`;
    - có `hx-post` tới `settings/tax-rates`;
    - mỗi `<select id="einv-tax-rate-<id>">` có `<label for="einv-tax-rate-<id>">` chứa tên rate, lớp và quốc gia.
  - `test_result_goes_to_the_card_result_area`:
    - form tax-rates có `hx-target="next .einv-result"`, tab có 3 `class="einv-result"`;
    - POST lưu chỉ trả fragment notice (không chứa `name="default_unit"` hay `name="reference_prefix"`).
  - `test_no_zero_rate_shows_guidance`: xóa cả hai rate 0% → HTML có `'Chưa có thuế suất 0%'`, không có `settings/tax-rates`.
  - `test_deleted_rate_is_not_listed_and_does_not_break_the_page`: gán kct, xóa rate đó (`WC_Tax::_delete_tax_rate`) → trang render, không có `tax_rate_kind[<kctId>]`, `exempt_tax_rates()` vẫn có kctId.
  - `test_deleted_rate_keeps_its_assignment_when_another_is_saved`: gán kct cho A=kctId, xóa A, POST `[ kkkntId => 'kkknt' ]` → `[ A => -1, kkkntId => -2 ]`.
- [ ] **Step 2: Chạy** `--filter SettingsTaxRatesRestTest`. Kỳ vọng: FAIL.
- [ ] **Step 3: Cài đặt** `ExemptTaxRateOptions` theo Interfaces.
- [ ] **Step 4: Cài đặt `SettingsController::save_tax_rates( WP_REST_Request $request ): WP_REST_Response`**, đăng ký `'/settings/tax-rates' => array( WP_REST_Server::CREATABLE, 'save_tax_rates' )`.
  - **Đọc request:**
    - `! $request->has_param( 'tax_rate_kind' )` (field vắng) → mảng rỗng;
    - field có mặt mà giá trị không phải mảng (kể cả `null` gửi tường minh), hoặc có value không phải chuỗi → lỗi.
    - Mọi key phải là số nguyên dương và `is_zero_rate()`; mọi value thuộc `vat0|kct|kkknt`.
  - **Lỗi:** `Fragment::error( __( 'Lựa chọn thuế không hợp lệ. Tải lại trang rồi thử lại.', 'einvoice-matbao' ) )`, trả trước mọi ghi.
  - **Dựng list mới:**
    - giữ gán hiện tại của ID không còn `exists()` (orphan);
    - thêm ID gửi lên là `kct`/`kkknt`;
    - gọi `save_exempt_tax_rates()`.
  - **Thành công:** `Fragment::success( sprintf( __( 'Đã lưu. %1$d thuế suất KCT, %2$d thuế suất KKKNT.', 'einvoice-matbao' ), … ) )`, đếm cả orphan.
- [ ] **Step 5: Card mới trong `SettingsPage::render_invoicing_panel()`**, đặt sau card đơn vị tính:
  - **Tiêu đề:** `Thuế KCT/KKKNT`.
  - **Help:** `'Chọn thuế suất 0% nào là KCT (không chịu thuế) hoặc KKKNT (không kê khai, nộp thuế). Thuế suất để "0%" xuất hóa đơn thuế suất 0%.'`
  - **Mỗi rate của `zero_rates()`:**
    - `<label for="einv-tax-rate-<id>">` hiển thị `name · class · country`;
    - `<select id="einv-tax-rate-<id>" name="tax_rate_kind[<id>]">` với option `vat0`/`kct`/`kkknt`, nhãn `0%`/`KCT`/`KKKNT`, chọn sẵn theo `exempt_tax_rates()`.
  - Nút `Lưu`, vùng `.einv-result` riêng, form `hx-target="next .einv-result"`.
  - **Không có rate 0%:** đoạn `'Chưa có thuế suất 0% nào. Tạo thuế suất 0% trong WooCommerce → Cài đặt → Thuế, rồi tải lại trang.'`, không có form.
  - Escape mọi giá trị.
- [ ] **Step 6: Cập nhật test có sẵn:**
  - `SettingsRestTestCase::htmx()`: phpdoc `$params` thành `array<string, mixed>`.
  - `SettingsPageTest::test_tabs_group_the_existing_forms`:
    - đầu test tạo một rate 0% (`WC_Tax::_insert_tax_rate`, `tax_rate` `'0'`, lớp `''`);
    - assert `settings/tax-rates` có trong `invoicing`, không có trong `connection` và `notifications`.
- [ ] **Step 7: Chạy** `--filter 'SettingsTaxRatesRestTest|SettingsPageTest|SettingsRestAccessTest'`. Kỳ vọng: PASS.
- [ ] **Step 8: Test đơn cũ với rate đã xóa** (cần Task 2):
  - Thêm `DraftInvoiceWorkflowTest::test_old_order_with_deleted_kct_rate_still_maps`:
    - gán KCT cho `rate_id_of( CLASS_KCT )`, tạo đơn với sản phẩm `CLASS_KCT`;
    - `WC_Tax::_delete_tax_rate()` rate đó; complete → `TSuat` -1.
  - Nếu Task 2 chưa merge khi làm Task 3, dời bước này vào cuối Task 2.
- [ ] **Step 9: Chạy** toàn bộ `einv composer test:integration`, `einv composer lint`, `einv composer analyse`. Kỳ vọng: PASS/sạch.
- [ ] **Step 10: Commit** `feat(admin-settings): assign KCT and KKKNT tax rates from a list`.

---

## Task 4: Browser E2E — implements 4

**Covers:** 4.1, 4.2, 4.3
**Difficulty:** Normal
**Blocked by:** Task 2, Task 3

**Files:**
- Modify: `tests/e2e/setup-site.sh`, `tests/e2e/wordpress/einv-e2e-http.php`, `tests/e2e/army/failures.army.ts`, `tests/e2e/army/settings.army.ts`, các file cache replay runner sinh ra
- Đọc trước: skill `e2e-army-testing`, `e2e`; `tests/e2e/README.md`

**Interfaces:**
- Consumes: route và form của Task 3; mã lỗi của Task 2.

- [ ] **Step 1: `setup-site.sh`:**
  - Thêm lớp `kct` ("KCT") và `kkknt` ("KKKNT"), mỗi lớp một rate 0% (`tax_rate_name` `KCT`/`KKKNT`).
  - Thêm sản phẩm `E2E-KCT`, `E2E-KKKNT` (taxable, lớp tương ứng, 100000).
  - Giữ `E2E-NOTAX` (trạng thái `none`) làm fixture chưa cài thuế.
  - Idempotent như vòng lặp có sẵn.
- [ ] **Step 2: Seam `/settings`:** nhận `exempt`, đổi tên rate thành ID qua bảng `woocommerce_tax_rates`, gọi `save_exempt_tax_rates()`.
- [ ] **Step 3: `failures.army.ts`:**
  - Đổi test thuế suất thành:
    - đặt `exempt: { kct: ['KCT'], kkknt: ['KKKNT'] }` qua seam;
    - đơn `E2E-SVC`, `E2E-VAT5`, `E2E-VAT8`, `E2E-KCT`, `E2E-KKKNT` → `TSuat` 10, 5, 8, -1, -2.
  - Thêm test "a not-taxable product is blocked until its tax is configured":
    - `E2E-NOTAX` → `failed`, `error_code` `line_tax_not_configured`, 0 request create;
    - trang đơn có `[data-einv-error-code='line_tax_not_configured']` và chữ `Dòng chưa cài thuế`.
  - Fixture fee gửi `taxable: false` (nếu có test dùng) chuyển sang taxable.
- [ ] **Step 4: `settings.army.ts`:** thêm test "admin assigns KCT from the tax rate list".
  - Mở tab Xuất hóa đơn; `agent.act` chọn `KCT` cho rate `KCT` trong card `Thuế KCT/KKKNT` rồi bấm `Lưu`.
  - Assert thông báo thành công; tải lại thì select hiện `KCT`.
- [ ] **Step 5: Record và chạy** theo skill `e2e-army-testing` (record cache, rồi replay strict). Kỳ vọng: mọi army test PASS ở replay.
- [ ] **Step 6: Commit** `test(e2e): cover KCT/KKKNT mapping and not-configured lines`.

---

## Task 5: Tài liệu — implements 5

**Covers:** 5.1, 5.2
**Difficulty:** Easy
**Blocked by:** Task 2, Task 3

**Files:**
- Modify: `docs/thiet-lap-thue.md`, `docs/huong-dan-su-dung.md`, `README.md`

- [ ] **Step 1: `docs/thiet-lap-thue.md`:**
  - Bảng "Quy tắc plugin kiểm tra":
    - không rate → *Dòng chưa cài thuế*;
    - thêm dòng KCT/KKKNT;
    - trạng thái *Không* → lỗi.
  - Bước 2: thêm lớp `KCT`, `KKKNT`, mỗi lớp một rate 0%.
  - Bước mới: gán chúng ở tab **Xuất hóa đơn** → card **Thuế KCT/KKKNT**.
  - Bước 3: sản phẩm KCT/KKKNT dùng các lớp đó, không dùng trạng thái *Không*.
  - Phí/vận chuyển cần có thuế: trạng thái thuế của phương thức vận chuyển là *Chịu thuế*, rate có tick *Vận chuyển*. Hỏi kế toán về thuế suất.
  - Bỏ ngoại lệ "sản phẩm 0 đồng không rate → 0%".
  - Mục sửa đơn bị chặn nêu `line_tax_not_configured`.
- [ ] **Step 2: `docs/huong-dan-su-dung.md`:**
  - Dòng "Thuế suất lấy từ…": KCT/KKKNT nay lấy từ rate đã gán.
  - Danh sách tab Xuất hóa đơn có card mới.
  - Bảng lỗi: thêm *Dòng chưa cài thuế*; đổi *Dòng không chịu thuế có tiền thuế* → *Dòng KCT/KKKNT có tiền thuế*.
- [ ] **Step 3: `README.md`:** dòng tính năng `(0%, 5%, 8%, 10%, KCT, KKKNT)`.
- [ ] **Step 4: Kiểm link/anchor:** `rg -n "thiet-lap-thue.md#" docs README.md`; mỗi anchor có heading tương ứng. Kỳ vọng: không anchor hỏng.
- [ ] **Step 5: Commit** `docs: describe KCT/KKKNT tax setup and not-configured lines`.

---

## Task 6: Bỏ gán thuế suất KCT/KKKNT không còn 0% — implements 6

**Covers:** 6.1, 6.2, 6.3
**Difficulty:** Normal
**Blocked by:** Task 3

**Files:**
- Modify: `src/Admin/ExemptTaxRateOptions.php`, `src/Admin/SettingsController.php`, `src/Admin/SettingsPage.php`, `docs/thiet-lap-thue.md`
- Modify test: `tests/integration/SettingsTaxRatesRestTest.php`

**Interfaces:**
- Consumes: `Settings::exempt_tax_rates()`, `Settings::save_exempt_tax_rates()`, `Settings::parse_rate_id()`, `ExemptTaxRateOptions::zero_rates()`.
- Produces: `ExemptTaxRateOptions::stale_assignments()`, route `/settings/tax-rates/unassign` (Contracts).

- [ ] **Step 1: Viết test thất bại** trong `SettingsTaxRatesRestTest` (A = rate 0% lớp `kct` đã gán KCT, sau đó `WC_Tax::_update_tax_rate( A, array( 'tax_rate' => '10' ) )`):
  - `test_stale_assignment_is_listed_with_warning_and_unassign_button`: HTML tab `invoicing` có khối `data-einv-stale-rates`, trong đó có tên A, lớp, `10%`, `KCT`, câu cảnh báo `'Đơn mới dùng thuế suất này sẽ bị chặn'`, form `hx-post` tới `settings/tax-rates/unassign` với input ẩn `rate_id` = A; và không có `tax_rate_kind[<A>]`.
  - `test_unassign_removes_only_that_assignment`: đã có thêm B (0%) gán KKKNT và orphan C (đã xóa) gán KCT; POST `/settings/tax-rates/unassign` `rate_id=A` → 200 success, `exempt_tax_rates()` == `[ B => -2, C => -1 ]`.
  - `test_unassign_rejects_invalid_targets`: lần lượt `rate_id` = B (0%), một rate 10% chưa gán, `999999`, `abc`, `0`, thiếu field → mỗi lần 422 và gán không đổi (assert sau từng request).
  - `test_unassign_requires_permission_and_nonce`: `shop_manager` → 403; nonce sai → 403; gán không đổi.
  - `test_no_stale_block_when_nothing_is_stale`: chỉ có gán của rate 0% và orphan đã xóa → HTML không có `data-einv-stale-rates`.
  - `test_unassigned_stale_rate_maps_by_its_stored_percent`: trong `DraftInvoiceWorkflowTest` — gán KCT cho `rate_id_of( CLASS_KCT )`, đổi rate đó sang 10%, bỏ gán qua `Settings::save_exempt_tax_rates( [], [] )`, đơn sản phẩm `CLASS_KCT` 100000 → `TSuat` 10, `TThue` 10000.
- [ ] **Step 2: Chạy** `--filter 'SettingsTaxRatesRestTest|DraftInvoiceWorkflowTest'`. Kỳ vọng: test mới FAIL.
- [ ] **Step 3: Cài đặt `ExemptTaxRateOptions::stale_assignments()`** theo Contracts; tên lớp lấy như `zero_rates()` (tách helper riêng nếu cần để không lặp code); bỏ qua ID không tồn tại.
- [ ] **Step 4: Cài đặt route** `'/settings/tax-rates/unassign' => array( WP_REST_Server::CREATABLE, 'unassign_tax_rate' )` trong `SettingsController`:
  - `rate_id` qua `Settings::parse_rate_id()`; phải thuộc ID của `stale_assignments( exempt_tax_rates() )`; sai → `Fragment::error( __( 'Thuế suất này không cần bỏ gán. Tải lại trang rồi thử lại.', 'einvoice-matbao' ) )`, không ghi.
  - Hợp lệ → bỏ ID khỏi đúng nhóm, `save_exempt_tax_rates()`, trả `Fragment::success( sprintf( __( 'Đã bỏ gán thuế suất "%s".', 'einvoice-matbao' ), $name ) )`.
- [ ] **Step 5: Card** trong `SettingsPage::render_invoicing_panel()`, sau danh sách chọn (vẫn hiện cả khi không có rate 0%):
  - Khi `stale_assignments()` không rỗng: khối `<div data-einv-stale-rates>` với tiêu đề `Đã gán nhưng không còn 0%`, help `'Đơn mới dùng thuế suất này sẽ bị chặn. Bỏ gán nếu thuế suất đã đổi hẳn; đơn cũ đã lưu thuế 0% sẽ xuất theo phần trăm đã lưu sau khi bỏ gán.'`.
  - Mỗi rate một dòng `name · class · percent% · KCT|KKKNT` và một form nhỏ `hx-post` tới `settings/tax-rates/unassign`, `hx-target` vùng kết quả của card, input ẩn `rate_id`, nút `Bỏ gán` có `aria-label` chứa tên rate.
  - Escape mọi giá trị.
- [ ] **Step 6: Tài liệu** `docs/thiet-lap-thue.md`, mục "Khi đổi thuế suất": nêu thuế suất đã gán KCT/KKKNT mà đổi khỏi 0% sẽ hiện ở "Đã gán nhưng không còn 0%" trong card, đơn mới dùng nó bị chặn cho tới khi bấm **Bỏ gán**.
- [ ] **Step 7: Chạy** focused rồi toàn bộ `einv composer test:integration`, `einv composer lint`, `einv composer analyse`. Kỳ vọng: PASS/sạch.
- [ ] **Step 8: Commit** `feat(admin-settings): unassign KCT/KKKNT rates that are no longer 0%`.

---

## Scenario → test

**draft-invoice-creation**
- Free shipping → giữ `DraftInvoiceWorkflowTest::test_free_shipping_in_a_paid_order_is_left_off_the_invoice`, `DraftInvoiceWorkflowTest::test_zero_fee_in_a_paid_order_is_left_off_the_invoice` (chạy ở Task 2 Step 10); mới `BlockedDraftInvoiceTest::test_free_fee_and_shipping_without_rate_are_left_off` (Task 2 Step 7)
- Free product → `DraftInvoiceWorkflowTest::test_free_product_is_sent_with_its_stored_rate` (Task 2 Step 6)
- Free product without a stored tax rate → `DraftInvoiceWorkflowTest::test_free_product_without_a_stored_rate_is_blocked` (Task 2 Step 6)
- Mixed rates → `DraftInvoiceWorkflowTest::test_rates_map_to_tsuat_including_assigned_kct_and_kkknt` (Task 2 Step 6); e2e `failures.army.ts` test thuế suất (Task 4 Step 3)
- Several exempt rates → `DraftInvoiceWorkflowTest::test_standard_and_additional_zero_rates_assigned_kct_both_map` (Task 2 Step 6)
- Zero rate not assigned → `DraftInvoiceWorkflowTest::test_zero_rate_not_assigned_stays_zero` (Task 2 Step 6)
- Assignment read when sending → `DraftInvoiceWorkflowTest::test_assignment_is_read_when_sending` (Task 2 Step 6)
- Not taxable line is not configured → `BlockedDraftInvoiceTest::test_not_taxable_product_is_blocked_as_not_configured` (Task 2 Step 7); e2e "a not-taxable product is blocked until its tax is configured" (Task 4 Step 3)
- Taxable line without a stored rate → `BlockedDraftInvoiceTest::test_fee_without_a_stored_rate_is_blocked`, `BlockedDraftInvoiceTest::test_shipping_without_a_stored_rate_is_blocked` (Task 2 Step 7)
- Several rates on one line → `BlockedDraftInvoiceTest::test_several_rates_including_kct_are_blocked_as_rate_count` (Task 2 Step 7); giữ `BlockedDraftInvoiceTest::test_line_with_several_tax_rates_blocks_the_draft`
- Exempt rate with tax amount → `BlockedDraftInvoiceTest::test_exempt_rate_with_tax_amount_is_blocked`, `BlockedDraftInvoiceTest::test_kkknt_rate_with_tax_amount_is_blocked` (Task 2 Step 7)
- Rate percent not stored → giữ `BlockedDraftInvoiceTest::test_line_whose_rate_percent_is_not_stored_blocks_the_draft` (Task 2 Step 7/10)
- Unsupported rate → giữ `BlockedDraftInvoiceTest::test_unusual_tax_rate_blocks_the_draft`, `DraftInvoiceWorkflowTest::test_unusual_tax_rate_is_not_sent_and_marks_the_invoice_failed` (Task 2 Step 10)
- Taxes calculated while tax calculation is off → `DraftInvoiceWorkflowTest::test_shop_without_tax_calculation_is_failed_instead_of_guessing_a_rate` (Task 2 Step 6)
- Stored taxes kept after tax calculation is turned off → `DraftInvoiceWorkflowTest::test_stored_taxes_are_kept_after_tax_calculation_is_turned_off` (Task 2 Step 6)

**admin-settings**
- Not configured → `SettingsTaxRatesRestTest::test_not_configured_shows_vat0_for_every_zero_rate` (Task 3 Step 1), `SettingsExemptTaxRatesTest::test_nothing_is_exempt_before_saving` (Task 1 Step 1)
- Assign exempt rates → `SettingsTaxRatesRestTest::test_saves_kct_and_kkknt`, `SettingsTaxRatesRestTest::test_form_lists_only_zero_rates_with_current_choice` (Task 3 Step 1); mapping qua `DraftInvoiceWorkflowTest::test_rates_map_to_tsuat_including_assigned_kct_and_kkknt`; e2e "admin assigns KCT from the tax rate list" (Task 4 Step 4)
- Several rates in one group → `SettingsTaxRatesRestTest::test_standard_and_additional_zero_rates_in_one_group` (Task 3 Step 1)
- Only zero rates listed → `SettingsTaxRatesRestTest::test_form_lists_only_zero_rates_with_current_choice` (Task 3 Step 1)
- No zero rate → `SettingsTaxRatesRestTest::test_no_zero_rate_shows_guidance` (Task 3 Step 1)
- Missing field clears the assignment → `SettingsTaxRatesRestTest::test_missing_field_clears_the_assignment` (Task 3 Step 1)
- Deleted rate keeps its assignment → `SettingsTaxRatesRestTest::test_deleted_rate_keeps_its_assignment_when_another_is_saved`, `SettingsTaxRatesRestTest::test_deleted_rate_is_not_listed_and_does_not_break_the_page` (Task 3 Step 1); `DraftInvoiceWorkflowTest::test_old_order_with_deleted_kct_rate_still_maps` (Task 3 Step 8)
- Non-zero rate rejected → `SettingsTaxRatesRestTest::test_non_zero_rate_is_rejected`, `SettingsTaxRatesRestTest::test_mixed_valid_and_invalid_entries_write_nothing` (Task 3 Step 1)
- Unknown rate or kind rejected → `SettingsTaxRatesRestTest::test_unknown_rate_is_rejected`, `SettingsTaxRatesRestTest::test_unknown_kind_is_rejected`, `SettingsTaxRatesRestTest::test_malformed_field_is_rejected` (Task 3 Step 1)
- Result area per card → `SettingsTaxRatesRestTest::test_result_goes_to_the_card_result_area` (Task 3 Step 1)
- Rate changed away from zero keeps its assignment → `SettingsTaxRatesRestTest::test_rate_edited_away_from_zero_keeps_its_assignment` (fix wave sau final review, commit 144df5d)
- Stale assignment listed → `SettingsTaxRatesRestTest::test_stale_assignment_is_listed_with_warning_and_unassign_button` (Task 6 Step 1)
- Unassign a stale rate → `SettingsTaxRatesRestTest::test_unassign_removes_only_that_assignment`, `DraftInvoiceWorkflowTest::test_unassigned_stale_rate_maps_by_its_stored_percent` (Task 6 Step 1)
- Unassign rejected → `SettingsTaxRatesRestTest::test_unassign_rejects_invalid_targets` (Task 6 Step 1)
- Unassign access control → `SettingsTaxRatesRestTest::test_unassign_requires_permission_and_nonce` (Task 6 Step 1)
- No stale assignment → `SettingsTaxRatesRestTest::test_no_stale_block_when_nothing_is_stale` (Task 6 Step 1)
- Invoicing tab contents → `SettingsPageTest::test_tabs_group_the_existing_forms` (Task 3 Step 6)
- Notifications tab contents → giữ `SettingsPageTest::test_notifications_tab_contents` (Task 3 Step 7)
- Saving one notification card → giữ `SettingsPageTest::test_notifications_tab_contents` (mỗi card một `einv-result`); không đổi hành vi, chạy ở Task 3 Step 9
- Monitoring tab contents → giữ `SettingsPageTest::test_deep_link_renders_the_notifications_tab` (Task 3 Step 9)
- Advanced tab contents → giữ `SettingsPageTest::test_tabs_group_the_existing_forms` (phần `advanced`) (Task 3 Step 6)
- Template list stays with credentials → giữ `SettingsPageTest::test_tabs_group_the_existing_forms` (assert `einv-matbao-saved from:body`), e2e `tabs.army.ts` "switching to the connection tab loads the template list" (Task 4 Step 5)

**failure-alerts**
- Every code has a hint → giữ `InvoiceErrorCodeTest::test_every_code_has_a_hint`; `InvoiceErrorCodeTest::test_tax_hints_match_the_design` (Task 2 Step 1)
- Data error hint → `InvoiceErrorCodeTest::test_data_error_hint` (thêm assert "Tính lại", Task 2 Step 1)
- Tax not configured hint → `InvoiceErrorCodeTest::test_tax_hints_match_the_design` (Task 2 Step 1)
- Exempt rate with tax hint → `InvoiceErrorCodeTest::test_tax_hints_match_the_design` (Task 2 Step 1)
- Unsupported rate hint → `InvoiceErrorCodeTest::test_tax_hints_match_the_design` (Task 2 Step 1)
- Config error hint → giữ `InvoiceErrorCodeTest::test_config_error_hint` (Task 2 Step 4)
- Uncertain send hint → giữ `InvoiceErrorCodeTest::test_uncertain_send_hint` (Task 2 Step 4)
- Same hint everywhere → giữ `Monitoring/SameHintEverywhereTest::test_same_hint_everywhere` (Task 2 Step 4)
