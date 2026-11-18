## Why

Plugin đang đoán thuế: coi mọi dòng có trạng thái thuế *Không* là KCT, và suy `TSuat` từ phần trăm thuế. Hệ quả: sản phẩm chưa cài thuế vẫn ra hóa đơn KCT, còn thuế KCT staff đã cài (thuế suất 0%) lại ra `TSuat = 0`. Smoke test cần sửa trước khi dùng thật, để mọi dòng hóa đơn mang đúng thuế đã khai báo, không đoán.

## What Changes

**Bảng ánh xạ thuế**
- From: KCT = dòng `tax_status = none`; 10/8/5/0 suy từ phần trăm đã lưu; không hỗ trợ KKKNT và "Khác".
- To: tab **Xuất hóa đơn** có bảng ánh xạ bảy giá trị Mắt Bão (10%, 8%, 5%, 0%, KCT, KKKNT, Khác).
  - Mỗi giá trị chứa nhiều thuế suất WooCommerce dạng badge có dấu ×.
  - Thêm/xóa lưu ngay.
  - Mỗi thuế suất chỉ thuộc một giá trị.
  - Hàng Khác gửi `TSuat` bằng phần trăm đã lưu của thuế suất.
- Reason: khai báo tường minh, không đoán.
- Impact: breaking. Sau khi nâng cấp, bảng trống nên mọi dòng có thuế bị chặn cho tới khi admin điền bảng. Plugin đang smoke test nên không migrate.

**Kiểm tra dòng**
- From: dòng *Không* → KCT; sản phẩm 0 đồng không có thuế suất → 0% hoặc KCT; thuế suất lạ → `tax_rate_unsupported`.
- To: mọi dòng (sản phẩm kể cả 0 đồng, phí, vận chuyển có tiền) phải có đúng một thuế suất đã lưu, nằm trong bảng và khớp yêu cầu Mắt Bão.
  - Không có thuế suất → "Sản phẩm chưa được cài đặt thuế" (mã mới `line_tax_not_configured`).
  - Nhiều thuế suất → "Sản phẩm có quá nhiều dòng thuế" (mã `line_tax_rate_count`, đổi nhãn).
  - Không thuộc bảng hoặc không khớp → "Thuế không khớp với thông tin thuế gửi tới Mắt Bão" (mã mới `tax_rate_mismatch`).
- Reason: thiếu thuế hay khai báo sai là lỗi cài đặt cần báo, không tự sửa.
- Impact: các mã `tax_rate_not_stored`, `tax_rate_unsupported`, `untaxed_line_with_tax` không còn được phát nhưng vẫn giữ trong danh mục, vì hóa đơn đã lỗi trước đó còn hiển thị chúng.

**Tài liệu:** `docs/thiet-lap-thue.md`, `docs/huong-dan-su-dung.md`, README, `docs/discovery.md` hướng dẫn điền bảng ánh xạ.

## Capabilities

### New Capabilities
- (không có)

### Modified Capabilities
- `draft-invoice-creation`: *VAT rate mapping* tra `TSuat` theo bảng ánh xạ và kiểm tra khớp; *Zero-value lines* bỏ ngoại lệ thuế của sản phẩm 0 đồng.
- `admin-settings`: thêm requirement bảng ánh xạ thuế và thao tác thêm/xóa; *Settings grouped by tab* thêm bảng vào tab `invoicing`.
- `failure-alerts`: *Resolution hint per error code* thêm hint cho hai mã mới, đổi hint `line_tax_rate_count`.
- `error-monitoring`: scenario *Readable title* theo nhãn mới của `line_tax_rate_count`.

## Impact

- Code:
  - `src/Invoice/DraftPayloadBuilder.php`: tra bảng, kiểm tra khớp.
  - `src/Invoice/InvoiceErrorCode.php`: hai mã mới, nhãn/hint.
  - `src/Settings.php`: lưu bảng ánh xạ.
  - `src/Admin/TaxRateMapping*.php` (mới): đọc thuế suất WooCommerce, dựng bảng.
  - `src/Admin/SettingsController.php`, `src/Admin/SettingsPage.php`: hai route thêm/xóa, bảng htmx.
  - Thay phần KCT/KKKNT đã code trên nhánh (card, route lưu/bỏ gán, `ExemptTaxRateOptions`, `exempt_tax_rates()`).
- API Mắt Bão: bắt đầu gửi `TSuat = -2` (KKKNT) và phần trăm "Khác" (ví dụ `3.5`).
- Test: integration (workflow, blocked draft, settings REST, error code), e2e (`setup-site.sh`, `failures.army.ts`, `settings.army.ts`).
- Không thêm dependency, không đổi DB schema.
