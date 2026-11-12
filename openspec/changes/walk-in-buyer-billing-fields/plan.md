# Walk-in Buyer Billing Fields Implementation Plan

> Executed via `/opsx-apply`, which chooses the executor.
> Spec reference: this change's `specs/` and `design.md`.

**Goal:** Lấy mọi thông tin liên hệ người mua (kể cả địa chỉ) từ billing và đưa thông tin billing của khách vãng lai vào hóa đơn nháp.

**Architecture:** Một helper `BillingAddress` ghép `NMua_DChi` từ billing cho Classic, Blocks, snapshot. `BuyerSnapshotter::build()` lọc từng trường billing cho vãng lai, lấy địa chỉ billing cho cá nhân/doanh nghiệp (dự phòng `address` cũ). `BuyerSnapshot` có cờ `personal` để exporter/eraser nhận ra snapshot vãng lai mang dữ liệu cá nhân.

**Tech Stack:** PHP 8.2 / WooCommerce, PHPUnit integration (`composer test:integration`), TypeScript + bun (`bun test assets/src`, `bun run build`), phpcs, phpstan.

---

## Contracts

| Name | new / modified | Shape | public |
|---|---|---|---|
| `BillingAddress::from_array(array $data, string $prefix = 'billing_'): string` | new | ghép `{prefix}address_1`, `address_2`, `city`, tên tỉnh/thành của `{prefix}state` + `{prefix}country`; bỏ trống; nối ", "; không cắt | no |
| `BillingAddress::from_order(WC_Order $order): string` | new | như trên từ `get_billing_address_1/2`, `get_billing_city`, `get_billing_state`, `get_billing_country` | no |
| `BuyerRules::MAX_ADDRESS` / `validate()` | modified | `BuyerInput::$address` nay mang địa chỉ billing ghép; luật không đổi (không rỗng, ≤ 400); key lỗi `address` giữ nguyên, thông báo nhắc địa chỉ ở phần thanh toán | no |
| `BuyerRequestStore::from_input()` | modified | khóa `address` luôn ghi `''`; `load()` vẫn trả `address` đọc từ meta | no |
| `BuyerSnapshot` | modified | thêm tham số `bool $personal = false` (cuối); `to_array()` có khóa `personal: bool`; `from_array()` đọc `personal`, thiếu thì `false` | no |
| `BuyerSnapshotter::build()` | modified | vãng lai: `type=WALK_IN`, `fields` gồm `NMua_Ten`, `NMua_HVTNMHang`, `NMua_DChi`, `NMua_SDThoai`, `NMua_DCTDTu` chỉ khi hợp lệ/không trống, `personal=true` nếu có ít nhất một trường ngoài `NMua_Ten`-mặc-định; cá nhân/doanh nghiệp: `NMua_DChi` = billing nếu có, ngược lại `request['address']`, `personal=true` | no |
| Store API extension `einvoice-matbao.address` | modified | vẫn khai báo `string|null`, `maxLength 400`; giá trị bị bỏ qua | **public** (REST schema; giữ để script cũ không nhận 400) |
| Classic POST `einv_address` | modified | không còn render hay đọc | no |

---

## Task 1: Billing address helper và BuyerRules — implements 1

**Covers:** 1.1, 1.2, 1.3
**Difficulty:** Normal
**Blocked by:** none

Files: create `src/Buyer/BillingAddress.php`, `tests/integration/BillingAddressTest.php`; modify `src/Buyer/BuyerRules.php`, `src/Buyer/BuyerRequestStore.php`, `tests/integration/BuyerRequestStoreTest.php`.

- [ ] **Step 1:** Viết `BillingAddressTest`: ghép đủ 4 phần, bỏ phần trống, tên tỉnh/thành từ mã (`VN` + state), mã state lạ giữ nguyên, tất cả trống trả `''` (cả `from_array` và `from_order`); chạy thấy FAIL.
- [ ] **Step 2:** Viết `BillingAddress` (final, hai static method, không trạng thái); chạy test PASS.
- [ ] **Step 3:** Cập nhật `BuyerRules` thông báo `address` ("Vui lòng nhập địa chỉ thanh toán của bạn (tối đa 400 ký tự)."), giữ luật; sửa test liên quan ở `BuyerRequestStoreTest` cho `from_input` ghi `address` rỗng và `load` vẫn đọc `address` cũ; chạy PASS.
- [ ] **Step 4:** `composer lint` và `composer analyse` các file đã đổi; commit "Add billing address helper and rules".

---

## Task 2: Snapshot vãng lai và địa chỉ billing — implements 2

**Covers:** 2.1, 2.2, 2.3
**Difficulty:** Hard
**Blocked by:** Task 1

Files: modify `src/Buyer/BuyerSnapshotter.php`, `src/Buyer/BuyerSnapshot.php`, `tests/integration/DraftInvoiceBuyerSnapshotTest.php`.

- [ ] **Step 1:** Thêm test vãng lai (billing đủ → có `NMua_Ten/HVTNMHang/DChi/SDThoai/DCTDTu`, không `MST`/`CCCDan`; SĐT `12345` và email sai bị bỏ; billing trống → chỉ tên cấu hình; địa chỉ 450 ký tự bị cắt 400; tên billing >100 ký tự bỏ `NMua_HVTNMHang` nhưng vẫn gửi). Chạy FAIL.
- [ ] **Step 2:** Thêm test cá nhân/doanh nghiệp: `NMua_DChi` từ billing; billing trống + yêu cầu cũ có `address` → dùng `address` cũ; `validate()` không lỗi khi chỉ có `address` cũ. Chạy FAIL.
- [ ] **Step 3:** Thêm `personal` vào `BuyerSnapshot` (constructor, `to_array`, `from_array`); test round-trip và snapshot cũ thiếu khóa → `false`.
- [ ] **Step 4:** Triển khai `build()` và `validate()` theo contract; chạy toàn bộ `DraftInvoiceBuyerSnapshotTest` và `DraftInvoiceWorkflowTest` PASS.
- [ ] **Step 5:** `composer lint`, `composer analyse`; commit "Send billing data for walk-in buyers".

---

## Task 3: Checkout Classic và Blocks — implements 3

**Covers:** 3.1, 3.2, 3.3
**Difficulty:** Hard
**Blocked by:** Task 1

Files: modify `src/Checkout/ClassicCheckout.php`, `src/Checkout/BlocksCheckout.php`, `assets/src/checkout-rules.ts`, `assets/src/checkout-rules.test.ts`, `assets/src/checkout.ts`, `assets/src/checkout-blocks.ts`, `tests/integration/ClassicCheckoutBuyerTest.php`, `tests/integration/BlocksCheckoutBuyerTest.php`; rebuild `assets/dist` (nếu được commit).

- [ ] **Step 1:** Sửa/Thêm test Classic: `individual_post()`/`business_post()` bỏ `einv_address`, lỗi `einvoice_buyer_address` khi billing không có địa chỉ, địa chỉ billing >400 ký tự báo lỗi, `save_to_order` lưu meta không có địa chỉ. Chạy FAIL.
- [ ] **Step 2:** Sửa `ClassicCheckout`: bỏ `text_field('einv_address')`, `input()` dùng `BillingAddress::from_array($data)`; PASS.
- [ ] **Step 3:** Sửa test Blocks: payload không `address`, vẫn gửi `address` thì HTTP 200 và bị bỏ qua, billing không địa chỉ → HTTP 400 `einvoice_buyer_invalid`. Sửa `BlocksCheckout::save_to_order` dùng `BillingAddress::from_order($order)`; PASS.
- [ ] **Step 4:** Test `checkout-rules.test.ts` trước (bỏ `einv_address`, thêm `billing_address_1` bắt buộc cùng `billing_address_2/city/state` ghép ≤ 400; lỗi key `billing_address_1`); sửa `checkout-rules.ts`; `bun test assets/src` PASS.
- [ ] **Step 5:** Sửa `checkout.ts` (danh sách input kích hoạt kiểm tra thêm `billing_address_1/2`, `billing_city`, `billing_state`) và `checkout-blocks.ts` (bỏ field/`values.address`, lấy từ `billingAddress`, hiện lỗi địa chỉ billing); `bun run build`; commit "Use billing address for invoice buyer".

---

## Task 4: Privacy exporter và eraser — implements 4

**Covers:** 4.1
**Difficulty:** Normal
**Blocked by:** Task 2

Files: modify `src/Privacy/BuyerPersonalData.php`, `tests/integration/BuyerPersonalDataPrivacyTest.php`.

- [ ] **Step 1:** Thêm test: đơn vãng lai có snapshot `personal=true` được export (tên, địa chỉ, SĐT, email, không CCCD), eraser xóa 2 meta và ẩn danh; đơn vãng lai chỉ tên mặc định (`personal=false`) vẫn không báo ẩn danh. Chạy FAIL.
- [ ] **Step 2:** Sửa `is_identified_buyer` (thêm snapshot có `personal`), `export_fields` (nhãn loại người mua: vãng lai → "Khách vãng lai"), `personal_values` (dùng `personal` thay `type !== WALK_IN`); PASS cùng `OrderRestBuyerPrivacyTest`.
- [ ] **Step 3:** `composer lint`, `composer analyse`; commit "Cover walk-in billing data in privacy tools".

---

## Task 5: Test tổng, e2e và tài liệu — implements 5

**Covers:** 5.1, 5.2
**Difficulty:** Normal
**Blocked by:** Task 2, Task 3, Task 4

Files: modify `tests/e2e/army/checkout-validation.army.ts`, `docs/huong-dan-su-dung.md`, `docs/discovery.md`.

- [ ] **Step 1:** Cập nhật e2e: bỏ ô địa chỉ riêng, nhập địa chỉ billing, kiểm lỗi địa chỉ billing trống; (chạy khi môi trường e2e sẵn sàng, ghi giới hạn nếu không).
- [ ] **Step 2:** Cập nhật `docs/huong-dan-su-dung.md` (địa chỉ lấy từ billing, doanh nghiệp điền địa chỉ đăng ký vào billing, vãng lai có thông tin thì gửi, shop cần bật địa chỉ thanh toán) và `docs/discovery.md` (ghi quyết định thay thế dòng 35–36, 84).
- [ ] **Step 3:** Chạy `composer test`, `bun test assets/src`, `composer lint`, `composer analyse`; sửa lỗi còn lại; commit "Update docs and e2e for billing address".

---

## Scenario → test

- Address composed from billing → `BillingAddressTest::test_composes_all_parts`
- Legacy address field ignored → `BlocksCheckoutBuyerTest::test_legacy_address_field_is_ignored`
- No separate address field → `ClassicCheckoutBuyerTest::test_classic_form_has_no_address_field` + e2e `checkout-validation.army.ts`
- CCCD not 12 digits → `ClassicCheckoutBuyerTest::test_individual_rejects_an_id_number_that_is_not_twelve_digits`
- International phone form → `ClassicCheckoutBuyerTest::test_individual_rejects_an_invalid_phone_and_accepts_international_form`
- Length limits (cá nhân) → `ClassicCheckoutBuyerTest::test_individual_rejects_a_billing_address_longer_than_the_mat_bao_limit`
- Missing billing address (cá nhân) → `ClassicCheckoutBuyerTest::test_individual_requires_a_billing_address` + `checkout-rules.test.ts`
- Malformed tax code → `ClassicCheckoutBuyerTest` và `BlocksCheckoutBuyerTest` (test MST hiện có)
- Branch tax code → test MST 13 chữ số hiện có (`ClassicCheckoutBuyerTest`)
- Length limits (doanh nghiệp) → `ClassicCheckoutBuyerTest::test_business_rejects_a_billing_address_longer_than_the_mat_bao_limit`
- Missing billing address (doanh nghiệp) → `ClassicCheckoutBuyerTest::test_business_requires_a_billing_address`
- Individual stored → `BuyerRequestStoreTest::test_individual_is_stored_with_encrypted_id`
- Business ignores individual fields → `BuyerRequestStoreTest::test_business_ignores_individual_fields`
- No address stored → `BuyerRequestStoreTest::test_request_has_no_new_address`
- Hidden until ticked → e2e `checkout-validation.army.ts`
- Fields per type → e2e `checkout-validation.army.ts`
- Re-rendered after failed submit → `ClassicCheckoutBuyerTest` (test render CCCD hiện có)
- Billing complete → `DraftInvoiceBuyerSnapshotTest::test_walk_in_draft_carries_billing_fields`
- Invalid fields skipped → `DraftInvoiceBuyerSnapshotTest::test_walk_in_skips_invalid_phone_and_email`
- Empty billing → `DraftInvoiceBuyerSnapshotTest::test_walk_in_with_empty_billing_sends_only_the_configured_name`
- Walk-in buyer / Individual buyer / Business buyer → `DraftInvoiceBuyerSnapshotTest` (3 test cập nhật)
- Legacy request address fallback → `DraftInvoiceBuyerSnapshotTest::test_legacy_request_address_is_the_fallback`
- Export customer orders → `BuyerPersonalDataPrivacyTest` (test export hiện có)
- Export walk-in order with billing data → `BuyerPersonalDataPrivacyTest::test_exports_walk_in_order_with_billing_data`
- Paged export → `BuyerPersonalDataPrivacyTest` (test phân trang hiện có)
- Erase an order with a draft → `BuyerPersonalDataPrivacyTest` (test hiện có)
- Walk-in order → `BuyerPersonalDataPrivacyTest::test_walk_in_order_without_personal_data_is_not_anonymized`
- Walk-in order with billing data → `BuyerPersonalDataPrivacyTest::test_erases_walk_in_order_with_billing_data`
