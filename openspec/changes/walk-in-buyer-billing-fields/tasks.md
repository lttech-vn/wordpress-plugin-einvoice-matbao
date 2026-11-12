## 1. Billing address helper và BuyerRules

Blocked by: none

- [x] 1.1 Thêm `BillingAddress` ghép địa chỉ billing (địa chỉ 1, địa chỉ 2, thành phố, tỉnh/thành) cho mảng posted và cho `WC_Order`
- [x] 1.2 Đổi `BuyerRules::validate()` bắt buộc địa chỉ billing (trống hoặc >400 ký tự báo lỗi) và đổi thông báo `address` nhắc nhập ở phần thanh toán
- [x] 1.3 Đổi `BuyerInput` / `BuyerRequestStore::from_input()` thôi ghi địa chỉ riêng (ghi chuỗi rỗng) nhưng `load()` vẫn đọc `address` cũ

## 2. Snapshot đơn vãng lai và địa chỉ billing

Blocked by: 1

- [ ] 2.1 `BuyerSnapshotter::build()` cho vãng lai: lọc từng trường billing (tên, địa chỉ cắt 400, SĐT, email), `NMua_Ten` dự phòng tên cấu hình
- [ ] 2.2 `BuyerSnapshotter::build()` cá nhân/doanh nghiệp lấy `NMua_DChi` từ billing, dự phòng `address` cũ của yêu cầu; `validate()` dùng cùng thứ tự
- [ ] 2.3 Thêm cờ `personal` vào `BuyerSnapshot` (`to_array`/`from_array`, mặc định false cho snapshot cũ)

## 3. Checkout Classic và Blocks

Blocked by: 1

- [ ] 3.1 Classic: bỏ ô `einv_address`, `input()` lấy địa chỉ từ billing posted, validate/save dùng `BuyerRules`
- [ ] 3.2 Blocks: giữ `address` trong schema nhưng bỏ qua giá trị, `save_to_order` lấy địa chỉ từ billing của đơn
- [ ] 3.3 `checkout-rules.ts`, `checkout.ts`, `checkout-blocks.ts`: bỏ ô địa chỉ, validate địa chỉ billing (Classic từ `billing_address_1`..., Blocks từ `billingAddress`), build lại `assets/dist`

## 4. Privacy exporter và eraser

Blocked by: 2

- [ ] 4.1 `BuyerPersonalData`: `is_identified_buyer` và `personal_values` dùng cờ `personal` / loại cá nhân-doanh nghiệp thay cho `type !== WALK_IN`; exporter xuất tên, địa chỉ, SĐT, email của snapshot vãng lai có dữ liệu

## 5. Test, tài liệu

Blocked by: 2, 3, 4

- [ ] 5.1 Cập nhật/ thêm integration test (Classic, Blocks, snapshot, privacy) và unit test `checkout-rules.test.ts`; chạy `composer test`, `bun test assets/src`, phpcs, phpstan
- [ ] 5.2 Cập nhật e2e `tests/e2e/army/checkout-validation.army.ts`, `docs/huong-dan-su-dung.md`, `docs/discovery.md`
