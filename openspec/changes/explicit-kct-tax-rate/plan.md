# Mat Bao Tax Mapping Table Implementation Plan

> Executed via `/opsx-apply`, which chooses the executor.
> Spec reference: this change's `specs/` and `design.md`.

**Goal:** `TSuat` của mỗi dòng chỉ lấy từ ID thuế suất đã lưu trên dòng và bảng ánh xạ bảy giá trị Mắt Bão do admin khai báo; dòng thiếu thuế, nhiều thuế hoặc không khớp bị chặn.

**Architecture:**
- `TaxRateMap` (src/Invoice) sở hữu bảy nhóm, mã `TSuat` và quy tắc khớp; `Settings` chỉ lưu mảng thô. `DraftPayloadBuilder` dùng `TaxRateMap` theo thứ tự kiểm tra cố định.
- Admin: `TaxRateCatalog` đọc thuế suất WooCommerce, `TaxRateMapCard` dựng card. Hai route htmx thêm/xóa trả lại card đã cập nhật.
- Bản KCT/KKKNT cũ trên nhánh (gán rate 0%, route lưu/bỏ gán) bị thay thế và xóa.

**Tech Stack:** PHP 8.2+, WordPress REST + fragment htmx, WooCommerce `WC_Tax`, PHPUnit (wp-phpunit) qua `einv composer test:integration`, e2e Army (`*.army.ts`).

## Global Constraints

- **Ngôn ngữ:** comment, docblock, identifier, commit bằng tiếng Anh; chuỗi UI tiếng Việt qua text domain `einvoice-matbao`; tài liệu (`docs/`, README) tiếng Việt.
- **`InvoiceErrorCode`:** giá trị enum ổn định. Chỉ thêm case, không đổi tên hay xóa (`TAX_RATE_NOT_STORED`, `TAX_RATE_UNSUPPORTED`, `UNTAXED_LINE_WITH_TAX` ở lại).
- **Không** thêm dependency, không đổi DB schema, không migrate.
- **Coding standards** theo `~/.agents/skills/coding-standards/SKILL.md`:
  - quy tắc nhóm/khớp chỉ nằm trong `TaxRateMap`;
  - truy vấn WooCommerce chỉ nằm trong `TaxRateCatalog`;
  - markup card chỉ nằm trong `TaxRateMapCard`;
  - `DraftPayloadBuilder::line()` không dài thêm.
- **Kiểm tra trong dev container:** prefix `DOCKER_CONTEXT=kiri`, `docker compose exec -T -w /var/www/html/wp-content/plugins/einvoice-matbao wordpress composer <script>`, script `test:integration`, `lint`, `analyse`.

## Review Focus

- **Bảng trống sau nâng cấp.** Mọi dòng có thuế dừng `tax_rate_mismatch` với lý do "chưa được ánh xạ", không gửi gì. Test: Task 2 `test_empty_mapping_blocks_every_taxed_line`.
- **Thuế suất trong nhóm 8% bị sửa thành 10%.** Đơn mới lưu 10% → `tax_rate_mismatch`, không gửi 8% kèm tiền thuế 10%. Test: Task 2 `test_percent_differing_from_its_group_is_blocked`.
- **Request add/remove sai kiểu** (mảng, chuỗi rỗng, nhóm lạ, JSON số nguyên hợp lệ). Lỗi → 422 không ghi; số nguyên JSON hợp lệ được chấp nhận. Test: Task 3 `test_add_rejects_invalid_input`, `test_add_accepts_json_integer_rate_id`.
- **Thuế suất đã xóa khỏi WooCommerce.** Badge "(đã xóa)" vẫn hiện và xóa được; đơn cũ vẫn ra mã đúng. Test: Task 3 `test_deleted_rate_badge_can_be_removed`, Task 2 `test_deleted_rate_keeps_mapping_old_orders`.
- **Biến thể có cha để *Không*.** Kế thừa → không có rate → `line_tax_not_configured`. Cùng đường test sản phẩm *Không*; không test riêng.

---

## Contracts

| Name | new / modified | Shape | public |
|---|---|---|---|
| `TaxRateMap` (`src/Invoice/TaxRateMap.php`) | new | `final class TaxRateMap`. Hằng nhóm `GROUP_VAT10='vat10'`, `GROUP_VAT8='vat8'`, `GROUP_VAT5='vat5'`, `GROUP_VAT0='vat0'`, `GROUP_KCT='kct'`, `GROUP_KKKNT='kkknt'`, `GROUP_OTHER='other'`. `public static function groups(): array` → `list<string>` theo thứ tự trên. `public static function label( string $group ): string` → `10%`,`8%`,`5%`,`0%`,`KCT`,`KKKNT`,`Khác`. `public function __construct( array $map )` với `array<string, list<int>>` đã chuẩn hóa. `public function group_of( int $rate_id ): ?string`. `public static function tsuat_for( string $group, ?string $stored_percent, int $tax ): int\|float\|null` theo design D3 bước 6 (null = không khớp; `other` trả int khi phần trăm nguyên, float khi lẻ) | no |
| `Settings::tax_rate_map(): array` | new | `array<string, list<int>>`: chỉ khóa thuộc `TaxRateMap::groups()`, ID qua `parse_rate_id()`; ID trùng giữ nhóm đứng trước theo `groups()`; thiếu key → mảng rỗng | no |
| `Settings::save_tax_rate_map( array $map ): void` | new | ghi key `tax_rate_map` như được truyền; caller bảo đảm hợp lệ, rời nhau | no |
| Option key `tax_rate_map` trong `einvoice_matbao_settings` | new | `array<string, list<int>>` | no |
| `Settings::exempt_tax_rates()`, `save_exempt_tax_rates()`, `TAX_RATE_KCT`, `TAX_RATE_KKKNT`, keys `kct_tax_rate_ids`/`kkknt_tax_rate_ids` | removed | thay bằng bảng ánh xạ; chưa từng phát hành | no |
| `Settings::parse_rate_id( mixed ): ?int` | kept | không đổi | no |
| `InvoiceErrorCode::TAX_RATE_MISMATCH` | new | `'tax_rate_mismatch'`, kind `DATA`, status `failed`, không tự retry, cần admin xử lý; nhãn `Thuế không khớp với thông tin thuế gửi tới Mắt Bão` | yes (cột `error_code`, tag Sentry, Lark) |
| `InvoiceErrorCode::LINE_TAX_NOT_CONFIGURED` | modified | giữ giá trị `'line_tax_not_configured'`; nhãn `Sản phẩm chưa được cài đặt thuế`; hint theo spec | yes |
| `InvoiceErrorCode::LINE_TAX_RATE_COUNT` | modified | giữ giá trị; nhãn `Sản phẩm có quá nhiều dòng thuế`; hint theo spec | yes |
| `DraftPayloadBuilder::line( WC_Order_Item, int, array $rates, string $default_unit, TaxRateMap $map, array $rate_names ): ?array` | modified | `$rate_names` = `array<int, string>` tên thuế suất từ tax item của đơn | no |
| `DraftPayloadBuilder::tax_rate( LineFailure, array $line_taxes, int $tax, array $rates, TaxRateMap $map, array $rate_names ): int\|float` | modified | thay bản dùng `$exempt`; ném lỗi theo design D3/D4 | no |
| `DraftPayloadBuilder::TAX_RATE_NOT_TAXABLE`, `ALLOWED_TAX_RATES` | removed (nếu không còn dùng) | quy tắc chuyển vào `TaxRateMap` | no |
| `TaxRateCatalog` (`src/Admin/TaxRateCatalog.php`) | new | `final class`, static: `all_rates(): list<array{id: int, name: string, class: string, country: string, percent: string}>` (lớp Standard tên `Tiêu chuẩn` + mọi lớp bổ sung; country `*` khi trống; percent dạng hiển thị, ví dụ `3,5`); `find( int $id ): ?array` cùng shape, null khi rate không tồn tại | no |
| `TaxRateMapCard` (`src/Admin/TaxRateMapCard.php`) | new | `final class`, static `render( string $notice_html = '' ): string` → toàn bộ `<section id="einv-tax-rate-map" …>`; `''` = không có notice | no |
| `ExemptTaxRateOptions`, route `settings/tax-rates`, `settings/tax-rates/unassign`, card "Thuế KCT/KKKNT" | removed | thay bằng card và route mới | yes → removed (route admin chưa phát hành) |
| REST `POST einvoice-matbao/v1/settings/tax-rate-map/add` | new | body `group` (một trong `TaxRateMap::groups()`), `rate_id` (qua `parse_rate_id`, nhận cả số nguyên JSON); rate phải tồn tại (`TaxRateCatalog::find`) và chưa thuộc nhóm nào. 200: `Fragment::response( TaxRateMapCard::render( success notice ) )`. 422: card hiện tại kèm error notice, không ghi. 403 như route khác | yes (route admin) |
| REST `POST einvoice-matbao/v1/settings/tax-rate-map/remove` | new | body `group`, `rate_id`; rate phải đang thuộc đúng `group` (kể cả rate đã xóa khỏi WooCommerce). 200/422/403 như trên | yes (route admin) |
| `InvoiceWorkflowTestCase` (test support) | modified | `set_up()` ánh xạ sẵn: rate chuẩn 10% → `vat10`, `CLASS_FIVE` → `vat5`, `CLASS_EIGHT` → `vat8`, `CLASS_ZERO` → `vat0`, `CLASS_KCT` → `kct`, `CLASS_KKKNT` → `kkknt`, `CLASS_OTHER='einv-other'` (3,5%) → `other`; `CLASS_SEVEN` không ánh xạ. Thêm `map_rates( array $map ): void`, `rate_id_of( string $class_slug ): int` (giữ) | no |
| e2e seam `POST einv-e2e/v1/settings` | modified (test only) | thay `exempt` bằng `tax_map: { <group>: string[] }` (tên thuế suất) | no |

---

## Task 1: Lưu bảng ánh xạ thuế — implements 1

**Covers:** 1.1, 1.2
**Difficulty:** Normal
**Blocked by:** none

**Files:**
- Create: `src/Invoice/TaxRateMap.php`, `tests/integration/TaxRateMapTest.php`, `tests/integration/SettingsTaxRateMapTest.php`
- Modify: `src/Settings.php`
- Delete: `tests/integration/SettingsExemptTaxRatesTest.php`; chuyển test `parse_rate_id` của nó (PHP_INT_MAX, tràn số, số 0 đầu) sang `SettingsTaxRateMapTest`.
- Note: builder, admin và e2e seam còn gọi `exempt_tax_rates()`/`save_exempt_tax_rates()`. Để suite và `analyse` xanh, Task 1 giữ chúng cùng hằng KCT/KKKNT, đánh dấu `@deprecated Removed by task 3`. Task 3 (sau Task 2) đổi consumer cuối cùng rồi xóa (checkbox 3.4).

**Interfaces:**
- Produces: `TaxRateMap`, `Settings::tax_rate_map()`, `Settings::save_tax_rate_map()` (Contracts).

- [ ] **Step 1: Test thất bại** `TaxRateMapTest` (`WP_UnitTestCase`):
  - `test_groups_and_labels`: `groups()` = `['vat10','vat8','vat5','vat0','kct','kkknt','other']`; nhãn tương ứng `10%`,`8%`,`5%`,`0%`,`KCT`,`KKKNT`,`Khác`.
  - `test_group_of`: map `['vat10'=>[3,4],'kct'=>[9]]` → `group_of(4)='vat10'`, `group_of(9)='kct'`, `group_of(5)=null`.
  - `test_tsuat_for_percent_groups`:
    - `('vat10','10.0000',1000)` → 10; `('vat8','8',800)` → 8; `('vat5','5.0000',500)` → 5;
    - `('vat8','10.0000',1000)` → null; `('vat10',null,1000)` → null; `('vat10','',1000)` → null.
  - `test_tsuat_for_zero_groups`:
    - `('vat0','0.0000',0)` → 0; `('kct',null,0)` → -1; `('kkknt','0',0)` → -2;
    - `('kct','0.0000',100)` → null; `('vat0',null,0)` → 0.
  - `test_tsuat_for_other`:
    - `('other','3.5000',3500)` → `3.5` (float); `('other','7.0000',7000)` → `7` (int);
    - `('other','5.0000',500)`, `('other','8.0000',8000)`, `('other','10.0000',1000)`, `('other','0',0)`, `('other','-3',0)`, `('other',null,100)`, `('other','',100)` → đều null.
  - `test_unknown_group`: `tsuat_for('vat7','7',700)` → null.
- [ ] **Step 2: Test thất bại** `SettingsTaxRateMapTest`:
  - `test_empty_before_saving`: `tax_rate_map()` = `[]`.
  - `test_round_trip`: lưu `['vat10'=>[3],'other'=>[11]]` → đọc lại y hệt.
  - `test_defensive_read`:
    - option `tax_rate_map` = `['vat10'=>['3',3,'x',0,-1,'0009'],'bogus'=>[5],'kct'=>'oops','vat8'=>[3,6]]`
    - → `['vat10'=>[3],'vat8'=>[6]]` (trùng bỏ ở nhóm sau, khóa lạ và giá trị hỏng bị bỏ; nhóm rỗng không xuất hiện).
  - Chuyển ba test `parse_rate_id` từ file cũ: chấp nhận `(string) PHP_INT_MAX`, từ chối `'9223372036854775808'`, `'0009'`.
- [ ] **Step 3: Chạy** `--filter 'TaxRateMapTest|SettingsTaxRateMapTest'`. Kỳ vọng: FAIL.
- [ ] **Step 4: Cài đặt** `TaxRateMap` theo Contracts. So phần trăm bằng sai số `0.000001` như `DraftPayloadBuilder::same()`. Thêm `Settings::tax_rate_map()`/`save_tax_rate_map()`. Đánh dấu deprecated phần exempt như Note. Xóa `SettingsExemptTaxRatesTest.php`.
- [ ] **Step 5: Chạy** filter rồi toàn bộ `test:integration`, `lint`, `analyse`. Kỳ vọng: PASS/sạch.
- [ ] **Step 6: Commit** `feat(settings): store the Mat Bao tax mapping table`.

---

## Task 2: Tra bảng khi tạo hóa đơn và mã lỗi — implements 2

**Covers:** 2.1, 2.2, 2.3
**Difficulty:** Hard
**Blocked by:** Task 1

**Files:**
- Modify: `src/Invoice/InvoiceErrorCode.php`, `src/Invoice/DraftPayloadBuilder.php`
- Modify test:
  - `tests/integration/Support/InvoiceWorkflowTestCase.php`
  - `tests/integration/InvoiceErrorCodeTest.php`
  - `tests/integration/DraftInvoiceWorkflowTest.php`
  - `tests/integration/BlockedDraftInvoiceTest.php`
  - `tests/integration/Monitoring/NeedsAttentionEventTest.php` (nhãn mới)
- Verify (đổi chỉ khi đỏ vì giả định cũ): các test kế thừa `InvoiceWorkflowTestCase` khác.

**Interfaces:**
- Consumes: `TaxRateMap`, `Settings::tax_rate_map()`, `save_tax_rate_map()` (Task 1).
- Produces: `InvoiceErrorCode::TAX_RATE_MISMATCH`; test support `map_rates()`, `CLASS_OTHER`.

- [ ] **Step 1: Test mã lỗi (thất bại)** trong `InvoiceErrorCodeTest`:
  - thêm dòng `'tax_rate_mismatch' => ['tax_rate_mismatch','data','failed',false]`; đổi `assertCount( 35 → 36 )`;
  - `test_tax_hints_match_the_design`: viết lại cho ba hint nguyên văn trong `specs/failure-alerts/spec.md`; nhãn `Sản phẩm chưa được cài đặt thuế`, `Sản phẩm có quá nhiều dòng thuế`, `Thuế không khớp với thông tin thuế gửi tới Mắt Bão`;
  - `test_data_error_hint`: hint `line_tax_rate_count` chứa `"Tính lại"` và `"Gửi lại"`;
  - giữ assert nhãn ba mã cũ không đổi; hint của `tax_rate_not_stored`, `tax_rate_unsupported`, `untaxed_line_with_tax` bằng nguyên văn hint mã cũ trong spec (`test_legacy_tax_codes_point_to_the_mapping_table`).
- [ ] **Step 2: Cài đặt** enum theo Contracts/spec. Chạy `--filter 'InvoiceErrorCodeTest'`. Kỳ vọng: PASS. Sửa `NeedsAttentionEventTest` message thành `Hóa đơn cần xử lý: Sản phẩm có quá nhiều dòng thuế – Đơn #…`. Commit `feat(invoice): add tax_rate_mismatch and rename tax error labels`.
- [ ] **Step 3: Test support:**
  - thêm `CLASS_OTHER='einv-other'` (rate 3,5%);
  - `map_rates( array $map ): void` (lưu qua `Settings::save_tax_rate_map`);
  - `set_up()` ánh xạ sẵn như Contracts.
- [ ] **Step 4: Test (thất bại)** trong `DraftInvoiceWorkflowTest`:
  - `test_mapped_rates_send_their_tsuat`:
    - sản phẩm 10%, 8%, 5%, 0%, KCT, KKKNT, Khác 3,5%, mỗi cái 100000;
    - → `TSuat` `[10, 8, 5, 0, -1, -2, 3.5]`, `TThue` `[10000, 8000, 5000, 0, 0, 0, 3500]`, `TgTThue` 26500.
  - `test_several_rates_in_one_group`: thêm lớp `einv-ten2` có rate 10%; `map_rates` thêm nó vào `vat10` → hai sản phẩm đều `TSuat` 10.
  - `test_empty_mapping_blocks_every_taxed_line`: `map_rates([])`, sản phẩm 10% → `failed`, `tax_rate_mismatch`, lý do chứa `'chưa được ánh xạ'`, 0 request create.
  - `test_mapping_read_when_sending`:
    - bỏ rate 10% khỏi bảng, `update_status('completed')` (chưa chạy queue);
    - `map_rates` thêm lại, `run_queued_draft_actions()` → `TSuat` 10.
  - `test_free_product_is_sent_with_its_stored_rate`: giữ, kỳ vọng `TSuat` 10.
  - `test_free_product_without_a_stored_rate_is_blocked`: giữ, `line_tax_not_configured`.
  - `test_shop_without_tax_calculation_is_failed_instead_of_guessing_a_rate`: giữ, `line_tax_not_configured`.
  - `test_stored_taxes_are_kept_after_tax_calculation_is_turned_off`: giữ, `TSuat` 10.
  - `test_vat_exempt_order_is_blocked`: giữ.
  - `test_deleted_rate_keeps_mapping_old_orders`: đơn sản phẩm `CLASS_KCT`; `WC_Tax::_delete_tax_rate( rate_id_of(CLASS_KCT) )`; complete → `TSuat` -1.
  - Xóa các test gán exempt cũ: `test_rates_map_to_tsuat_including_assigned_kct_and_kkknt`, `test_standard_and_additional_zero_rates_assigned_kct_both_map`, `test_zero_rate_not_assigned_stays_zero`, `test_assignment_is_read_when_sending`, `test_old_order_with_deleted_kct_rate_still_maps`, `test_unassigned_stale_rate_maps_by_its_stored_percent`.
  - Thay `test_unusual_tax_rate_is_not_sent_and_marks_the_invoice_failed`: `CLASS_SEVEN` không ánh xạ → `tax_rate_mismatch`.
- [ ] **Step 5: Test chặn (thất bại)** trong `BlockedDraftInvoiceTest`:
  - `test_not_taxable_product_is_blocked_as_not_configured`: giữ (`line_tax_not_configured`, `'chưa có thuế GTGT'`).
  - `test_fee_without_a_stored_rate_is_blocked`, `test_shipping_without_a_stored_rate_is_blocked`, `test_free_fee_and_shipping_without_rate_are_left_off`: giữ.
  - `test_line_with_several_tax_rates_blocks_the_draft`: giữ, ánh xạ cả hai rate của `einv-double` vào `vat5` → `LINE_TAX_RATE_COUNT`.
  - `test_several_rates_with_an_unmapped_one_are_blocked_as_rate_count`: như trên nhưng chỉ ánh xạ rate đầu → vẫn `LINE_TAX_RATE_COUNT` (đếm rate trước khi tra bảng).
  - `test_unusual_tax_rate_blocks_the_draft`: `CLASS_SEVEN` không ánh xạ → `TAX_RATE_MISMATCH`, lý do chứa `'VAT 7'` và `'chưa được ánh xạ'`.
  - `test_percent_differing_from_its_group_is_blocked`: `map_rates` đặt rate 10% chuẩn vào `vat8` → `TAX_RATE_MISMATCH`, lý do chứa `'10%'` và `'8%'`.
  - `test_zero_group_with_tax_amount_is_blocked` (data provider `vat0`, `kct`, `kkknt`): `map_rates` đặt rate 10% chuẩn vào nhóm → `TAX_RATE_MISMATCH`, lý do chứa nhãn nhóm (`0%`/`KCT`/`KKKNT`) và `'có tiền thuế'`.
  - `test_other_group_with_a_standard_percent_is_blocked`: đặt `CLASS_EIGHT` vào `other` → `TAX_RATE_MISMATCH`, lý do chứa `'nhóm Khác'`.
  - `test_percent_not_stored_is_blocked`: đổi từ `test_line_whose_rate_percent_is_not_stored_blocks_the_draft` (xóa tax item của đơn) → `TAX_RATE_MISMATCH`, lý do chứa `'Tính lại'`.
  - Xóa `test_exempt_rate_with_tax_amount_is_blocked`, `test_kkknt_rate_with_tax_amount_is_blocked`, `test_several_rates_including_kct_are_blocked_as_rate_count`.
- [ ] **Step 6: Chạy** `--filter 'DraftInvoiceWorkflowTest|BlockedDraftInvoiceTest'`. Kỳ vọng: test mới/đổi FAIL.
- [ ] **Step 7: Cài đặt** `DraftPayloadBuilder`:
  - `build()`: `$map = new TaxRateMap( $settings->tax_rate_map() )`; `$rate_names` từ `$order->get_taxes()`: `get_rate_id()` → `get_label( 'edit' )` (không dùng `'view'`, vì nó trả `"Tax"` khi rỗng); chuỗi rỗng hoặc không có tax item thì lý do dùng `#<rate_id>`. Test trong `BlockedDraftInvoiceTest::test_unusual_tax_rate_blocks_the_draft` biến thể: label rỗng → lý do chứa `#<id>`.
  - `tax_rate()`: thứ tự design D3 bước 3–6, lý do design D4. Nhóm in trong lý do dùng `TaxRateMap::label()`. Không nhóm → `'Thuế suất "%1$s" của dòng "%2$s" chưa được ánh xạ sang thuế Mắt Bão.'` với tên thuế hoặc `#<id>`.
  - Bỏ `$exempt` và ánh xạ theo phần trăm. Xóa `ALLOWED_TAX_RATES` nếu không còn dùng.
  - `TSuat` kiểu `int|float` trong payload; `number()` đã xử lý.
- [ ] **Step 8: Chạy** toàn bộ `test:integration`, `lint`, `analyse`. Kỳ vọng: PASS. Test khác đỏ vì giả định cũ: sửa fixture (ánh xạ hoặc rate), không nới assertion, liệt kê trong report.
- [ ] **Step 9: Commit** `feat(invoice): send TSuat from the Mat Bao tax mapping table`.

---

## Task 3: Card ánh xạ thuế — implements 3

**Covers:** 3.1, 3.2, 3.3, 3.4
**Difficulty:** Normal
**Blocked by:** Task 2 (Task 2 bỏ consumer `exempt_tax_rates()` trong builder; Task 3 xóa API đó)

**Files:**
- Create:
  - `src/Admin/TaxRateCatalog.php`, `src/Admin/TaxRateMapCard.php`
  - `tests/integration/SettingsTaxRateMapRestTest.php`
- Modify:
  - `src/Admin/SettingsController.php`, `src/Admin/SettingsPage.php`, `src/Settings.php` (xóa phần exempt deprecated)
  - `tests/integration/SettingsPageTest.php`, `tests/integration/BlockedDraftInvoiceTest.php` (xóa helper exempt nếu còn)
  - `tests/e2e/wordpress/einv-e2e-http.php`, `tests/e2e/army/support.ts`, `tests/e2e/army/failures.army.ts` (chỉ đổi `exempt` → `tax_map` trong seam và lời gọi; ghi cache/record để Task 4)
- Delete: `src/Admin/ExemptTaxRateOptions.php`, `tests/integration/SettingsTaxRatesRestTest.php`

**Interfaces:**
- Consumes: `TaxRateMap`, `Settings::tax_rate_map()`, `save_tax_rate_map()`, `parse_rate_id()`.
- Produces: `TaxRateCatalog`, `TaxRateMapCard`, hai route (Contracts).

- [ ] **Step 1: Test thất bại** `SettingsTaxRateMapRestTest` (extends `SettingsRestTestCase`, administrator). `set_up()` tạo rate 10% Standard (`VAT 10`, VN), lớp `kct` có rate 0% tên `KCT`, lớp `other` có rate 3,5% tên `VAT 3,5`.
  - `test_empty_table`: HTML tab `invoicing` có `id="einv-tax-rate-map"`, bảy hàng nhãn `10%`…`Khác` theo thứ tự, không badge. Mỗi `<select name="rate_id">` liệt kê cả ba rate với `tên · lớp · quốc gia · phần trăm%` (ví dụ `VAT 10 · Tiêu chuẩn · VN · 10%`) và có `<label for>`.
  - `test_badges_per_group`:
    - thêm rate 10% thứ hai `VAT 10 B` ở lớp `kct`;
    - `save_tax_rate_map(['vat10'=>[ten, ten_b],'kct'=>[kct]])`;
    - hàng 10% có **hai** badge `VAT 10 · Tiêu chuẩn · VN` và `VAT 10 B · …`, mỗi badge một nút × (`aria-label="Bỏ VAT 10 khỏi 10%"`, `aria-label="Bỏ VAT 10 B khỏi 10%"`); hàng KCT có một badge `KCT`.
  - `test_mapped_rate_leaves_the_pickers`: ten trong `vat8` → không `<option value="<ten>"` nào trong mọi select.
  - `test_deleted_rate_badge`: map kkknt=[A], `WC_Tax::_delete_tax_rate(A)` → badge chứa `(đã xóa)` và nút ×.
  - `test_no_woocommerce_rate`: xóa mọi rate, bảng trống → HTML có `'Chưa có thuế suất nào'`, không có nút `Thêm`.
  - `test_add_a_rate`:
    - POST `/settings/tax-rate-map/add` `group=vat10&rate_id=<ten>` → 200;
    - body chứa `id="einv-tax-rate-map"`, notice success `Đã thêm "VAT 10" vào 10%.`, badge `VAT 10` trong hàng 10% và **không** có `<option value="<ten>"` nào;
    - `tax_rate_map()` = `['vat10'=>[ten]]`.
  - `test_add_accepts_json_integer_rate_id`: JSON body `{"group":"kct","rate_id":<kct int>}` → 200, map có kct.
  - `test_add_rejects_invalid_input`: lần lượt `group=vat7`, `group=''`, `group[]=vat10` (mảng), thiếu `group`, `rate_id=abc`, `rate_id=''`, `rate_id[]=1` (mảng), `rate_id=0`, `rate_id=-1`, `rate_id=1.5`, thiếu `rate_id`, `rate_id=999999`, rate đã thuộc nhóm khác → mỗi lần 422, body có card và notice lỗi, map không đổi (assert sau từng request).
  - `test_remove_a_rate`:
    - map `vat10=[ten]`, `kct=[kct]`; POST remove `group=kct&rate_id=<kct>` → 200;
    - response: notice `Đã bỏ "KCT" khỏi KCT.`, hàng KCT không còn badge, `<option value="<kct>"` có lại trong các select;
    - map `['vat10'=>[ten]]`.
  - `test_deleted_rate_badge_can_be_removed`: badge đã xóa A trong `kkknt` → remove 200, map rỗng.
  - `test_remove_rejects_invalid_input`: rate không thuộc `group`, `group` lạ/rỗng/mảng/thiếu, `rate_id` `abc`/rỗng/mảng/âm/thiếu → mỗi lần 422, map không đổi (assert sau từng request).
  - `test_access_control`: `shop_manager` → 403 cho add và remove; nonce sai → 403; map không đổi.
  - `test_label_escapes_rate_name`: rate tên `<b>x</b>` → hiện dạng escape trong option và badge.
  - `test_added_rate_reaches_the_invoice` (trong `DraftInvoiceWorkflowTest`, vì cần workflow): `map_rates([])`, POST add `group=vat10&rate_id=<rate chuẩn 10%>` qua REST (đăng nhập admin), rồi đơn sản phẩm 10% → `TSuat` 10.
  - `test_saving_lark_leaves_the_monitoring_card` (trong `SettingsPageTest`): POST `/settings/lark` hợp lệ, response chỉ là notice (không chứa `einv-sentry-dsn`), và tab `notifications` render lại vẫn có form giám sát lỗi với giá trị không đổi.
- [ ] **Step 2: Chạy** `--filter SettingsTaxRateMapRestTest`. Kỳ vọng: FAIL.
- [ ] **Step 3: Cài đặt** `TaxRateCatalog` (mọi rate của `''` + `WC_Tax::get_tax_class_slugs()`; tên lớp như `ExemptTaxRateOptions::class_names()` hiện có; percent định dạng `wc_format_localized_decimal` bỏ số 0 thừa).
- [ ] **Step 4: Cài đặt** `TaxRateMapCard::render()`:
  - `<section id="einv-tax-rate-map" class="CARD">`, tiêu đề `Ánh xạ thuế Mắt Bão`.
  - Help: `'Chọn thuế suất WooCommerce ứng với từng loại thuế Mắt Bão. Hóa đơn chỉ gửi được dòng có thuế suất nằm trong bảng; hàng Khác gửi đúng phần trăm của thuế suất.'`
  - Mỗi hàng: nhãn nhóm; badge (`<span>` + `<form hx-post=remove hx-target="#einv-tax-rate-map" hx-swap="outerHTML">` hidden `group`, `rate_id`, nút `×` có `aria-label`); `<form hx-post=add …>` hidden `group`, `<select name="rate_id" id="einv-map-<group>">` có `<label for>` (ẩn trực quan) với option chưa thuộc nhóm, nút `Thêm`.
  - Không có rate WooCommerce nào: đoạn `'Chưa có thuế suất nào trong WooCommerce. Tạo thuế suất ở WooCommerce → Cài đặt → Thuế rồi tải lại trang.'`, ẩn ô chọn và nút Thêm.
  - Notice (nếu có) nằm trong card, `aria-live="polite"`. Escape mọi giá trị.
- [ ] **Step 5: Cài đặt** route trong `SettingsController` (`'/settings/tax-rate-map/add'`, `'/settings/tax-rate-map/remove'`) theo Contracts.
  - Lỗi: `Fragment::response( TaxRateMapCard::render( Fragment::notice( error, msg ) ), 422 )`.
  - Thông điệp lỗi: `'Không thêm được thuế suất. Tải lại trang rồi thử lại.'` / `'Không bỏ được thuế suất. Tải lại trang rồi thử lại.'`.
  - Thành công: notice `Đã thêm "%1$s" vào %2$s.` / `Đã bỏ "%1$s" khỏi %2$s.`; tên rate đã xóa dùng `#<id>`.
- [ ] **Step 6: Thay** card "Thuế KCT/KKKNT" trong `SettingsPage::render_invoicing_panel()` bằng `TaxRateMapCard::render()`. Xóa:
  - route `tax-rates`, `tax-rates/unassign` và các method riêng của chúng;
  - `ExemptTaxRateOptions`, `SettingsTaxRatesRestTest.php`.

  Phần exempt deprecated trong `Settings` chỉ xóa ở cuối Step 6b, sau khi đổi consumer e2e.

  Cập nhật `SettingsPageTest::test_tabs_group_the_existing_forms`: `invoicing` chứa `settings/tax-rate-map/add`, không chứa `settings/tax-rates`.
- [ ] **Step 6b: Đổi e2e seam** `/settings`: `exempt` → `tax_map` (`{ <group>: string[] }` tên thuế suất → ID, `save_tax_rate_map()`); `support.ts` `resetWorld` gửi bảng mặc định của fixture (Task 4 Step 2); `failures.army.ts` lời gọi `exempt` → `tax_map`. Chưa record cache (Task 4). Sau đó xóa `Settings::exempt_tax_rates()`, `save_exempt_tax_rates()`, `TAX_RATE_KCT|KKKNT` và helper exempt trong `BlockedDraftInvoiceTest`.
- [ ] **Step 7: Chạy** filter rồi toàn bộ `test:integration`, `lint`, `analyse`; `rg -n "exempt_tax_rates|save_exempt_tax_rates|ExemptTaxRateOptions|tax-rates/unassign|TAX_RATE_KCT|TAX_RATE_KKKNT" src tests` rỗng. Kỳ vọng: PASS/sạch.
- [ ] **Step 8: Commit** `feat(admin-settings): Mat Bao tax mapping card replaces the KCT/KKKNT card`.

---

## Task 4: Browser E2E — implements 4

**Covers:** 4.1, 4.2, 4.3
**Difficulty:** Normal
**Blocked by:** Task 2, Task 3

**Files:**
- Modify: `tests/e2e/setup-site.sh`, `tests/e2e/wordpress/einv-e2e-http.php`, `tests/e2e/army/support.ts`, `tests/e2e/army/failures.army.ts`, `tests/e2e/army/settings.army.ts`, cache trong `tests/e2e/army-cache/`
- Đọc trước: skill `e2e-army-testing`, `tests/e2e/README.md`

- [ ] **Step 1:** `setup-site.sh` thêm lớp `other` có rate 3,5% (`VAT 3,5`) và sản phẩm `E2E-OTHER` (100000). Giữ `vat-7` (không ánh xạ), `E2E-NOTAX`, `E2E-KCT`, `E2E-KKKNT`.
- [ ] **Step 2:** Seam `/settings` đã nhận `tax_map` từ Task 3 Step 6b. `resetWorld` **thay toàn bộ** bảng bằng bảng mặc định của fixture `{vat10:["VAT 10"], vat8:["VAT 8"], vat5:["VAT 5"], kct:["KCT"], kkknt:["KKKNT"], other:["VAT 3,5"]}` để các test cũ vẫn gửi được; test cần bảng trống gửi `tax_map: {}` tường minh.
- [ ] **Step 3:** `failures.army.ts`:
  - test thuế suất: `E2E-SVC`, `E2E-VAT5`, `E2E-VAT8`, `E2E-KCT`, `E2E-KKKNT`, `E2E-OTHER` → `TSuat` 10, 5, 8, -1, -2, 3.5;
  - test `E2E-NOTAX` → `line_tax_not_configured`, nhãn `Sản phẩm chưa được cài đặt thuế`;
  - test `vat-7` (đang kỳ vọng `tax_rate_unsupported`) đổi thành `tax_rate_mismatch` và nhãn `Thuế không khớp với thông tin thuế gửi tới Mắt Bão`.
- [ ] **Step 4:** `settings.army.ts` thay test KCT cũ bằng "admin maps a tax rate to KCT and removes it":
  - seam bảng trống cho `kct`;
  - `agent.act` chọn rate `KCT` trong ô chọn hàng KCT (không bấm); bấm `Thêm` của hàng KCT bằng locator chính xác trong `#einv-tax-rate-map`;
  - assert badge `KCT` và notice; bấm × (`getByRole('button', { name: 'Bỏ KCT khỏi KCT' })`) → badge biến mất.
- [ ] **Step 5:** Record (`E2E_TEXT_*` từ `E2E_MODEL_*`) rồi replay không credential theo README; xóa cache không còn dùng; review cache không có credential/dữ liệu cá nhân. Kỳ vọng: replay `run.summary` passed = executed, failed 0.
- [ ] **Step 6: Commit** `test(e2e): cover the Mat Bao tax mapping table`.

---

## Task 5: Tài liệu — implements 5

**Covers:** 5.1, 5.2
**Difficulty:** Easy
**Blocked by:** Task 2, Task 3

**Files:**
- Modify: `docs/thiet-lap-thue.md`, `docs/huong-dan-su-dung.md`, `README.md`, `docs/discovery.md`

- [ ] **Step 1: `docs/thiet-lap-thue.md`:**
  - Viết lại phần KCT/KKKNT thành "Điền bảng Ánh xạ thuế Mắt Bão": mỗi loại thuế (10/8/5/0/KCT/KKKNT/Khác) chọn thuế suất nào; badge và nút ×; "(đã xóa)".
  - Bảng "Quy tắc plugin kiểm tra" theo spec VAT rate mapping.
  - Bảng lỗi ba mã: `Sản phẩm chưa được cài đặt thuế`, `Sản phẩm có quá nhiều dòng thuế`, `Thuế không khớp với thông tin thuế gửi tới Mắt Bão`.
  - Mục đổi thuế suất: sửa phần trăm trong WooCommerce mà không đổi nhóm sẽ bị chặn; chuyển rate sang nhóm khác bằng × rồi Thêm.
- [ ] **Step 2: Các file còn lại:**
  - `docs/huong-dan-su-dung.md`: mục cấu hình nhắc bảng ánh xạ; bảng lỗi thay các dòng thuế cũ bằng ba mã; ghi chú hóa đơn cũ có thể còn hiện mã cũ.
  - `README.md`: tính năng `(0%, 5%, 8%, 10%, KCT, KKKNT, Khác)` qua bảng ánh xạ.
  - `docs/discovery.md`: ghi chú superseded trỏ bảng ánh xạ.
- [ ] **Step 3: Kiểm link/anchor** `thiet-lap-thue.md#…` trong `docs/` và `README.md`. Kỳ vọng: không anchor hỏng.
- [ ] **Step 4: Commit** `docs: describe the Mat Bao tax mapping table`.

---

## Scenario → test

**draft-invoice-creation**
- Free shipping → `DraftInvoiceWorkflowTest::test_free_shipping_in_a_paid_order_is_left_off_the_invoice`, `::test_zero_fee_in_a_paid_order_is_left_off_the_invoice`, `BlockedDraftInvoiceTest::test_free_fee_and_shipping_without_rate_are_left_off` (Task 2)
- Free product → `DraftInvoiceWorkflowTest::test_free_product_is_sent_with_its_stored_rate` (Task 2 Step 4)
- Free product without a stored tax rate → `DraftInvoiceWorkflowTest::test_free_product_without_a_stored_rate_is_blocked` (Task 2 Step 4)
- Mixed rates → `DraftInvoiceWorkflowTest::test_mapped_rates_send_their_tsuat` (Task 2 Step 4); e2e test thuế suất `failures.army.ts` (Task 4 Step 3)
- Several rates in one group → `DraftInvoiceWorkflowTest::test_several_rates_in_one_group` (Task 2 Step 4)
- Empty mapping → `DraftInvoiceWorkflowTest::test_empty_mapping_blocks_every_taxed_line` (Task 2 Step 4)
- Mapping read when sending → `DraftInvoiceWorkflowTest::test_mapping_read_when_sending` (Task 2 Step 4)
- Not taxable line is not configured → `BlockedDraftInvoiceTest::test_not_taxable_product_is_blocked_as_not_configured` (Task 2 Step 5); e2e `E2E-NOTAX` (Task 4 Step 3)
- Taxable line without a stored rate → `BlockedDraftInvoiceTest::test_fee_without_a_stored_rate_is_blocked`, `::test_shipping_without_a_stored_rate_is_blocked` (Task 2 Step 5)
- Several rates on one line → `BlockedDraftInvoiceTest::test_line_with_several_tax_rates_blocks_the_draft`, `::test_several_rates_with_an_unmapped_one_are_blocked_as_rate_count` (Task 2 Step 5)
- Percent differs from its group → `BlockedDraftInvoiceTest::test_percent_differing_from_its_group_is_blocked` (Task 2 Step 5)
- Zero group with tax amount → `BlockedDraftInvoiceTest::test_zero_group_with_tax_amount_is_blocked` (Task 2 Step 5)
- Unsupported rate → `BlockedDraftInvoiceTest::test_unusual_tax_rate_blocks_the_draft`, `DraftInvoiceWorkflowTest::test_unusual_tax_rate_is_not_sent_and_marks_the_invoice_failed` (Task 2); e2e `vat-7` (Task 4 Step 3)
- Other group with a standard percent → `BlockedDraftInvoiceTest::test_other_group_with_a_standard_percent_is_blocked` (Task 2 Step 5)
- Percent not stored on the order → `BlockedDraftInvoiceTest::test_percent_not_stored_is_blocked` (Task 2 Step 5)
- Deleted rate keeps mapping old orders → `DraftInvoiceWorkflowTest::test_deleted_rate_keeps_mapping_old_orders` (Task 2 Step 4)
- Taxes calculated while tax calculation is off → `DraftInvoiceWorkflowTest::test_shop_without_tax_calculation_is_failed_instead_of_guessing_a_rate` (Task 2 Step 4)
- Stored taxes kept after tax calculation is turned off → `DraftInvoiceWorkflowTest::test_stored_taxes_are_kept_after_tax_calculation_is_turned_off` (Task 2 Step 4)

**admin-settings**
- Empty table → `SettingsTaxRateMapRestTest::test_empty_table` (Task 3 Step 1)
- Badges per group → `SettingsTaxRateMapRestTest::test_badges_per_group` (Task 3 Step 1)
- Mapped rate leaves the pickers → `SettingsTaxRateMapRestTest::test_mapped_rate_leaves_the_pickers` (Task 3 Step 1)
- Deleted rate badge → `SettingsTaxRateMapRestTest::test_deleted_rate_badge` (Task 3 Step 1)
- No WooCommerce rate → `SettingsTaxRateMapRestTest::test_no_woocommerce_rate` (Task 3 Step 1)
- Add a rate → `SettingsTaxRateMapRestTest::test_add_a_rate`, `::test_add_accepts_json_integer_rate_id` (Task 3 Step 1); `DraftInvoiceWorkflowTest::test_added_rate_reaches_the_invoice` (Task 3 Step 1); e2e "admin maps a tax rate to KCT and removes it" (Task 4 Step 4)
- Remove a rate → `SettingsTaxRateMapRestTest::test_remove_a_rate` (Task 3 Step 1); e2e như trên
- Remove a deleted rate → `SettingsTaxRateMapRestTest::test_deleted_rate_badge_can_be_removed` (Task 3 Step 1)
- Add rejected → `SettingsTaxRateMapRestTest::test_add_rejects_invalid_input` (Task 3 Step 1)
- Remove rejected → `SettingsTaxRateMapRestTest::test_remove_rejects_invalid_input` (Task 3 Step 1)
- Access control → `SettingsTaxRateMapRestTest::test_access_control` (Task 3 Step 1)
- Invoicing tab contents → `SettingsPageTest::test_tabs_group_the_existing_forms` (Task 3 Step 6)
- Notifications tab contents → giữ `SettingsPageTest::test_notifications_tab_contents` (Task 3 Step 7)
- Saving one notification card → `SettingsPageTest::test_saving_lark_leaves_the_monitoring_card` (Task 3 Step 1)
- Monitoring tab contents → giữ `SettingsPageTest::test_deep_link_renders_the_notifications_tab` (Task 3 Step 7)
- Advanced tab contents → giữ `SettingsPageTest::test_tabs_group_the_existing_forms` (Task 3 Step 6)
- Template list stays with credentials → giữ `SettingsPageTest::test_tabs_group_the_existing_forms`; e2e `tabs.army.ts` "switching to the connection tab loads the template list" (Task 4 Step 5)

**failure-alerts**
- Every code has a hint → giữ `InvoiceErrorCodeTest::test_every_code_has_a_hint`; `::test_tax_hints_match_the_design` (Task 2 Step 1)
- Data error hint → `InvoiceErrorCodeTest::test_data_error_hint` (Task 2 Step 1)
- Tax not configured hint → `InvoiceErrorCodeTest::test_tax_hints_match_the_design` (Task 2 Step 1)
- Mismatch hint → `InvoiceErrorCodeTest::test_tax_hints_match_the_design` (Task 2 Step 1)
- Legacy tax code hint → `InvoiceErrorCodeTest::test_legacy_tax_codes_point_to_the_mapping_table` (Task 2 Step 1)
- Config error hint → giữ `InvoiceErrorCodeTest::test_config_error_hint` (Task 2 Step 2)
- Uncertain send hint → giữ `InvoiceErrorCodeTest::test_uncertain_send_hint` (Task 2 Step 2)
- Same hint everywhere → giữ `Monitoring/SameHintEverywhereTest::test_same_hint_everywhere` (Task 2 Step 8)

**error-monitoring**
- Readable title → `Monitoring/NeedsAttentionEventTest` (message mới, Task 2 Step 2)
- Unexpected error while sending / Action Scheduler action throws / Action Scheduler exception echoes the buyer / Action without an order / Needs attention once per alert / Same error code on two orders → giữ các test hiện có trong `tests/integration/Monitoring/WorkflowMonitoringTest.php`, `ExceptionContextTest.php`, `NeedsAttentionEventTest.php` (chạy ở Task 2 Step 8)
