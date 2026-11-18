## Why

Plugin đang coi mọi dòng có trạng thái thuế *Không* là KCT, nên sản phẩm chưa cài thuế vẫn xuất hóa đơn KCT mà không ai biết. Ngược lại, thuế KCT staff đã cài trong WooCommerce (một thuế suất 0%) lại ra `TSuat = 0`, vì plugin không phân biệt được KCT với 0%. Smoke test cần sửa trước khi dùng thật, để hóa đơn luôn mang đúng thuế đã cài cho từng sản phẩm.

## What Changes

**Nhận diện KCT/KKKNT**
- From: KCT = dòng có `tax_status = none`; không hỗ trợ KKKNT.
- To: admin gán thuế suất 0% của WooCommerce là KCT (`TSuat = -1`) hoặc KKKNT (`TSuat = -2`) trong tab **Xuất hóa đơn**, chọn từ danh sách thuế suất có sẵn. Dòng mang thuế suất đó xuất mã tương ứng.
- Reason: KCT/KKKNT là thuế của sản phẩm; plugin không được đoán.
- Impact: breaking với site đang dùng trạng thái *Không* cho hàng KCT. Phải cài thuế KCT rồi tính lại đơn. Plugin đang smoke test nên không migrate.

**Dòng chưa cài thuế**
- From: dòng `tax_status = none` → KCT; dòng chịu thuế không có thuế suất → `line_tax_rate_count`; sản phẩm 0 đồng không có thuế suất → 0% hoặc KCT theo trạng thái thuế.
- To: mọi dòng (sản phẩm kể cả 0 đồng, phí và vận chuyển có tiền) phải có đúng một thuế suất đã lưu; phí/vận chuyển 0 đồng vẫn bị bỏ khỏi hóa đơn. Không có thuế suất nào → lỗi dữ liệu mới `line_tax_not_configured`. `line_tax_rate_count` chỉ còn cho dòng có từ hai thuế suất.
- Reason: mỗi sản phẩm có thuế của chính nó; thiếu thuế là lỗi cài đặt cần báo.
- Impact: breaking với đơn có dòng *Không* hoặc dòng không lưu thuế.

**Dòng KCT/KKKNT có tiền thuế**
- From: `untaxed_line_with_tax` cho dòng *Không* có tiền thuế.
- To: `untaxed_line_with_tax` cho dòng mang thuế suất KCT/KKKNT mà có tiền thuế khác 0.
- Impact: mã lỗi giữ nguyên, hint đổi theo.

**Tài liệu:** `docs/thiet-lap-thue.md`, `docs/huong-dan-su-dung.md` và README hướng dẫn cài KCT/KKKNT, cài thuế cho phí/vận chuyển.

## Capabilities

### New Capabilities
- (không có)

### Modified Capabilities
- `draft-invoice-creation`: *VAT rate mapping* nhận diện KCT/KKKNT theo thuế suất được gán và chặn dòng chưa cài thuế; *Zero-value lines* bỏ ngoại lệ thuế của sản phẩm 0 đồng.
- `admin-settings`: thêm requirement gán và lưu thuế suất KCT/KKKNT; *Settings grouped by tab* thêm form này vào tab `invoicing`.
- `failure-alerts`: *Resolution hint per error code* thêm hint cho `line_tax_not_configured` và đổi hint `untaxed_line_with_tax`, `line_tax_rate_count`.

## Impact

- Code:
  - `src/Invoice/DraftPayloadBuilder.php`: ánh xạ thuế suất, kiểm tra dòng.
  - `src/Invoice/InvoiceErrorCode.php`: mã mới, label/hint.
  - `src/Settings.php`: lưu ID thuế suất KCT/KKKNT.
  - `src/Admin/SettingsController.php`, `src/Admin/SettingsPage.php`: route và form mới.
- API Mắt Bão: bắt đầu gửi `TSuat = -2` (KKKNT); `-1` chỉ còn từ thuế suất được gán.
- Test: integration (`DraftInvoiceWorkflowTest`, `BlockedDraftInvoiceTest`, settings REST test, `InvoiceErrorCodeTest`), e2e (`tests/e2e/setup-site.sh`, `tests/e2e/army/failures.army.ts`).
- Không thêm dependency, không đổi DB schema.
