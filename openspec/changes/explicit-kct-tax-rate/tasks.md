## 1. Lưu gán thuế suất KCT/KKKNT

Blocked by: none

- [x] 1.1 `Settings`: đọc gán `kct_tax_rate_ids`/`kkknt_tax_rate_ids` thành map `rate_id => TSuat` (-1/-2), lưu thay toàn bộ, bỏ giá trị không phải số nguyên dương (design D1)
- [x] 1.2 Test integration cho đọc/ghi gán (chưa lưu → rỗng; lưu rồi đọc lại; một rate không thuộc hai nhóm)

## 2. Ánh xạ thuế suất và lỗi dòng chưa cài thuế

Blocked by: 1

- [x] 2.1 `InvoiceErrorCode`: thêm `LINE_TAX_NOT_CONFIGURED` (kind `data`), label và hint; đổi label/hint `UNTAXED_LINE_WITH_TAX`, hint `LINE_TAX_RATE_COUNT` và `TAX_RATE_UNSUPPORTED` theo design D4
- [x] 2.2 `DraftPayloadBuilder::line()`/`rate()`: bỏ đọc `tax_status`, kiểm tra theo thứ tự design D3, ánh xạ KCT/KKKNT từ gán trong `Settings`
- [x] 2.3 Cập nhật test integration hiện có dựa vào `tax_status = none` → KCT (`DraftInvoiceWorkflowTest`, `BlockedDraftInvoiceTest`, `Monitoring/WorkflowFailureDetailTest`, `InvoiceErrorCodeTest`) và thêm test cho mọi scenario của `specs/draft-invoice-creation` cùng các scenario hint mới của `specs/failure-alerts`

## 3. Form gán KCT/KKKNT trong tab Xuất hóa đơn

Blocked by: 1

- [x] 3.1 `SettingsController`: route `POST /settings/tax-rates`, kiểm tra lựa chọn và rate ID (tồn tại, tỷ lệ 0%), lưu qua `Settings` (design D2)
- [x] 3.2 `SettingsPage::render_invoicing_panel()`: card "Thuế KCT/KKKNT" liệt kê rate 0% kèm tên, lớp, quốc gia và `<select>` có label; hướng dẫn khi không có rate 0% (design D5)
- [x] 3.3 Test integration `SettingsTaxRatesRestTest` (lưu, 422, quyền, nonce, chỉ liệt kê rate 0%, không có rate 0%) và cập nhật `SettingsPageTest` cho nội dung tab Xuất hóa đơn

## 4. Browser E2E

Blocked by: 2, 3

- [x] 4.1 `tests/e2e/setup-site.sh`: thêm lớp/rate 0% cho KCT và KKKNT, sản phẩm KCT/KKKNT; gán qua settings; giữ một sản phẩm trạng thái *Không* để kiểm lỗi
- [x] 4.2 `tests/e2e/army/failures.army.ts`: `TSuat` -1/-2 cho sản phẩm KCT/KKKNT, sản phẩm *Không* dừng với `line_tax_not_configured`; cập nhật fee fixture không chịu thuế nếu có
- [x] 4.3 `tests/e2e/army/settings.army.ts`: gán KCT trong tab Xuất hóa đơn qua UI; ghi lại cache replay

## 5. Tài liệu

Blocked by: 2, 3

- [x] 5.1 `docs/thiet-lap-thue.md`: KCT/KKKNT là thuế suất 0% được gán ở tab Xuất hóa đơn; trạng thái *Không* là lỗi; cài thuế cho phí/vận chuyển; bảng lỗi
- [x] 5.2 `docs/huong-dan-su-dung.md` (mục cấu hình, bảng lỗi) và `README.md` (tính năng) theo hành vi mới

## 6. Bỏ gán thuế suất KCT/KKKNT không còn 0%

Blocked by: 3

- [ ] 6.1 `ExemptTaxRateOptions` liệt kê rate đã gán, còn tồn tại, khác 0%; route `POST /settings/tax-rates/unassign` xóa đúng phép gán đó (design D2)
- [ ] 6.2 Card "Thuế KCT/KKKNT" hiện danh sách "đã gán nhưng không còn 0%" kèm cảnh báo và nút bỏ gán; test integration cho mọi scenario của requirement "Stale exempt assignment removal" và "Rate changed away from zero keeps its assignment"
- [ ] 6.3 Tài liệu `docs/thiet-lap-thue.md` mục đổi thuế suất: cách bỏ gán thuế suất KCT/KKKNT đã đổi khỏi 0%
