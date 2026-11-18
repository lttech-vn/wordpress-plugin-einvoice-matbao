## 1. Lưu bảng ánh xạ thuế

Blocked by: none

- [ ] 1.1 `TaxRateMap` (src/Invoice): bảy nhóm cố định, `TSuat` của từng nhóm, kiểm tra khớp `tsuat_for()`; `Settings::tax_rate_map()` / `save_tax_rate_map()` đọc/ghi key `tax_rate_map`, đọc phòng thủ (design D1, D3)
- [ ] 1.2 Test integration cho lưu/đọc bảng và cho `tsuat_for()` của từng nhóm (khớp, lệch, thiếu phần trăm, Khác 3.5)

## 2. Tra bảng khi tạo hóa đơn và mã lỗi

Blocked by: 1

- [ ] 2.1 `InvoiceErrorCode`: thêm `TAX_RATE_MISMATCH`; đổi nhãn `LINE_TAX_NOT_CONFIGURED`, `LINE_TAX_RATE_COUNT`; hint ba mã thuế theo spec failure-alerts (design D4)
- [ ] 2.2 `DraftPayloadBuilder`: kiểm tra theo thứ tự design D3, tra `TSuat` qua `TaxRateMap`, lý do lỗi theo design D4; bỏ phần gán KCT/KKKNT và ánh xạ theo phần trăm
- [ ] 2.3 Cập nhật test integration (workflow, blocked draft, error code, monitoring title, test support) theo bảng ánh xạ; thêm test cho mọi scenario của `specs/draft-invoice-creation`, `specs/failure-alerts`, `specs/error-monitoring`

## 3. Card ánh xạ thuế trong tab Xuất hóa đơn

Blocked by: 2

- [ ] 3.1 `TaxRateCatalog` đọc thuế suất WooCommerce; `TaxRateMapCard` dựng card (badge ×, ô chọn chỉ gồm thuế suất chưa thuộc nhóm, "(đã xóa)", hướng dẫn khi không có thuế suất) (design D2)
- [ ] 3.2 `SettingsController`: route `tax-rate-map/add` và `tax-rate-map/remove`, trả card đã cập nhật, 422 không ghi; xóa route `tax-rates`, `tax-rates/unassign`, `ExemptTaxRateOptions` và card cũ (design D2, D5)
- [ ] 3.3 Test integration `SettingsTaxRateMapRestTest` cho mọi scenario của `specs/admin-settings`; cập nhật `SettingsPageTest`; xóa test của card cũ
- [ ] 3.4 Đổi e2e seam `/settings` sang `tax_map`, rồi xóa phần lưu KCT/KKKNT cũ: `Settings::TAX_RATE_KCT|KKKNT`, `exempt_tax_rates()`, `save_exempt_tax_rates()` và mọi chỗ còn dùng (design D5)

## 4. Browser E2E

Blocked by: 2, 3

- [ ] 4.1 `tests/e2e/setup-site.sh`: thuế suất KCT, KKKNT, 3,5%; `resetWorld` thay toàn bộ bảng bằng bảng mặc định của fixture (test cần bảng trống gửi `tax_map: {}`)
- [ ] 4.2 `tests/e2e/army/failures.army.ts`: `TSuat` 10/5/8/-1/-2/3.5 qua bảng; sản phẩm *Không* dừng `line_tax_not_configured`; thuế suất chưa ánh xạ dừng `tax_rate_mismatch`
- [ ] 4.3 `tests/e2e/army/settings.army.ts`: thêm thuế suất vào hàng KCT và bấm × xóa qua UI; ghi lại cache replay

## 5. Tài liệu

Blocked by: 2, 3

- [ ] 5.1 `docs/thiet-lap-thue.md`: điền bảng ánh xạ cho từng loại thuế (kể cả KCT, KKKNT, Khác), bảng lỗi theo ba mã thuế mới
- [ ] 5.2 `docs/huong-dan-su-dung.md`, `README.md`, `docs/discovery.md` theo bảng ánh xạ
