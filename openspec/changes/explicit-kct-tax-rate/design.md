## Context

**WooCommerce lưu thuế thế nào:**
- Thuế suất đã lưu trên dòng nằm ở `get_taxes()['total']`, dạng `rate_id => amount`.
- Khi tính thuế, WooCommerce lưu mọi rate khớp vào dòng, kể cả khi tiền thuế bằng 0. Vì vậy sản phẩm 0 đồng và rate 0% vẫn có rate ID.
- Dòng được tính lúc có trạng thái *Không*, đơn miễn VAT, hoặc site đang tắt tính thuế: nhận `set_taxes( false )`, tức không có rate nào.
- Phần trăm của từng rate lấy từ tax item của đơn (`stored_rate_percents()`); đơn trước WooCommerce 3.7 không lưu phần trăm.

**Nhánh hiện tại đã code bản cũ, nay bị thay:**
- Gán KCT/KKKNT cho rate 0%: `Settings::exempt_tax_rates()`, `ExemptTaxRateOptions`.
- Route `settings/tax-rates` và `settings/tax-rates/unassign`.
- Ánh xạ 10/8/5/0 theo phần trăm.

**Hạ tầng dùng lại được:**
- Settings lưu trong option `einvoice_matbao_settings`.
- Form admin gửi qua htmx tới REST `einvoice-matbao/v1/settings/*`; route trả fragment HTML qua `Fragment`.
- Lỗi 4xx cũng được swap.

## Goals / Non-Goals

**Goals:**
- `TSuat` chỉ suy từ rate ID đã lưu trên dòng và bảng ánh xạ admin khai báo.
- Kiểm tra đúng yêu cầu Mắt Bão trước khi gửi; mọi sai lệch thành lỗi dữ liệu có hướng dẫn.

**Non-Goals:**
- Không đọc `tax_status`, tên thuế, tên lớp để quyết thuế.
- Không tính lại thuế; không đổi DB schema; không migrate hay tự điền bảng.

## Decisions

**D1. Lưu bảng ánh xạ.**
- Key mới `tax_rate_map` trong `einvoice_matbao_settings`, dạng `array<string, list<int>>`, khóa là mã nhóm cố định: `vat10`, `vat8`, `vat5`, `vat0`, `kct`, `kkknt`, `other`.
- Đặt trong lớp mới `TaxRateMap` (`src/Invoice/TaxRateMap.php`). Nó sở hữu danh sách nhóm, mã `TSuat` của từng nhóm và quy tắc kiểm tra khớp, nên builder và admin dùng chung một nguồn.
- `Settings` chỉ đọc/ghi mảng thô, qua `tax_rate_map()` / `save_tax_rate_map()`.
- Đọc phòng thủ:
  - bỏ khóa nhóm lạ;
  - ID dùng `Settings::parse_rate_id()`;
  - ID trùng giữa hai nhóm thì giữ nhóm xuất hiện trước theo thứ tự cố định, để kết quả luôn xác định.
- Xóa các key cũ `kct_tax_rate_ids`, `kkknt_tax_rate_ids` cùng `exempt_tax_rates()` / `save_exempt_tax_rates()`. Plugin đang smoke test, không migrate, và các key này chưa phát hành.

**D2. Bảng trong tab Xuất hóa đơn, thêm/xóa từng thuế suất.**
- Card "Ánh xạ thuế Mắt Bão" có bảy hàng: nhãn hàng (10%, 8%, 5%, 0%, KCT, KKKNT, Khác) và một dòng mô tả ngắn.
  - Mỗi badge là một rate đã chọn, hiển thị `tên · lớp · quốc gia`; rate đã xóa ghi "(đã xóa)".
  - Mỗi badge có nút × (`aria-label` "Bỏ <tên> khỏi <nhóm>"). Nút gửi `POST einvoice-matbao/v1/settings/tax-rate-map/remove` với `group`, `rate_id`.
  - Mỗi hàng có `<select>` có label, chỉ liệt kê rate **chưa thuộc nhóm nào**, mọi lớp và mọi phần trăm, hiển thị `tên · lớp · quốc gia · phần trăm%`. Nút **Thêm** gửi `POST einvoice-matbao/v1/settings/tax-rate-map/add` với `group`, `rate_id`.
- Kết quả của cả hai route là **card đã cập nhật**: route trả lại toàn bộ card, htmx swap `outerHTML` của card. Badge và các danh sách chọn luôn khớp với dữ liệu, không cần tải lại trang. Thông báo thành công/lỗi nằm trong card.
- Kiểm tra ở trust boundary:
  - `group` phải là một trong bảy mã.
  - `rate_id` qua `parse_rate_id()`.
  - Add: rate phải còn tồn tại trong WooCommerce (`WC_Tax::_get_tax_rate`) và chưa thuộc nhóm nào.
  - Remove: rate phải đang thuộc đúng `group`; được phép kể cả rate đã xóa.
  - Sai thì 422, card giữ nguyên và kèm thông báo lỗi, không ghi gì.
- Add không kiểm tra phần trăm của rate so với nhóm, vì phần trăm trên đơn mới là thứ được kiểm tra (D3). Danh sách chọn hiện phần trăm để admin tự đối chiếu.
- Truy vấn WooCommerce gom trong `TaxRateCatalog` (`src/Admin/TaxRateCatalog.php`):
  - `all_rates(): list<array{id, name, class, country, percent}>`, gồm lớp Standard và mọi lớp bổ sung;
  - `find( int $id ): ?array`.
- Card do `TaxRateMapCard::render( ?string $notice_html ): string` dựng, để `SettingsPage` và controller dùng chung.

**D3. Thứ tự kiểm tra một dòng trong `DraftPayloadBuilder`.**
1. Như cũ: loại dòng, `whole_vnd(net)`, số lượng/số tiền âm, `whole_vnd(tax)`.
2. Phí/vận chuyển có net = 0 và tax = 0 → bỏ dòng. Sản phẩm 0 đồng không còn ngoại lệ.
3. Không có rate nào trên dòng → `line_tax_not_configured`.
4. Từ hai rate trở lên → `line_tax_rate_count`.
5. Rate ID không thuộc bảng → `tax_rate_mismatch`.
6. Theo nhóm của rate, kiểm tra khớp rồi trả `TSuat`; sai → `tax_rate_mismatch`:
   - `vat10` / `vat8` / `vat5`: phần trăm lưu trên đơn phải tồn tại và bằng 10 / 8 / 5 → `TSuat` 10 / 8 / 5.
   - `vat0`, `kct`, `kkknt`: tiền thuế của dòng phải bằng 0 → `TSuat` 0 / -1 / -2. Không cần phần trăm lưu trên đơn: tiền thuế 0 đã là điều Mắt Bão cần.
   - `other`: phần trăm lưu trên đơn phải tồn tại, lớn hơn 0 và khác 5, 8, 10 → `TSuat` = phần trăm đó, gửi dạng số (ví dụ `3.5`, `7`).

- Builder không đọc `get_tax_status()`. Dòng *Không* rơi vào bước 3.
- Bảng ánh xạ đọc từ `Settings` một lần mỗi payload, như `default_unit()`.
- Kiểm tra tổng (`totals_mismatch`) giữ nguyên.
- `TaxRateMap::tsuat_for( string $group, ?string $stored_percent, int $tax ): int|float|null` trả `null` khi không khớp. Builder chỉ quyết thứ tự và ném lỗi.

**D4. Mã lỗi.**
- Thêm `LINE_TAX_NOT_CONFIGURED = 'line_tax_not_configured'` và `TAX_RATE_MISMATCH = 'tax_rate_mismatch'`. Cả hai kind `data`, status `failed`, không tự retry.
- Nhãn và lý do:

  | Mã | Nhãn | Lý do lưu |
  |---|---|---|
  | `line_tax_not_configured` | `Sản phẩm chưa được cài đặt thuế` | `Dòng "<tên>" chưa có thuế GTGT.` |
  | `line_tax_rate_count` (giữ mã, đổi nhãn) | `Sản phẩm có quá nhiều dòng thuế` | giữ `Dòng "<tên>" cần đúng một thuế suất GTGT.` |
  | `tax_rate_mismatch` | `Thuế không khớp với thông tin thuế gửi tới Mắt Bão` | xem dưới |

- Lý do của `tax_rate_mismatch` theo trường hợp:
  - Không thuộc bảng: `Thuế suất "<tên thuế>" của dòng "<tên>" chưa được ánh xạ sang thuế Mắt Bão.`
  - 10/8/5% lệch: `Thuế suất của dòng "<tên>" là <p>% nhưng được ánh xạ sang <nhóm>.`
  - 0%/KCT/KKKNT có tiền thuế: `Dòng "<tên>" được ánh xạ sang <nhóm> nhưng có tiền thuế.`
  - Khác không hợp lệ: `Thuế suất của dòng "<tên>" là <p>%, không dùng được cho nhóm Khác.`
  - Thiếu phần trăm đã lưu: `Không tìm thấy phần trăm thuế đã lưu của dòng "<tên>". Bấm "Tính lại" trong đơn.`

  Tên thuế lấy từ tax item của đơn; vắng thì dùng `#<rate_id>`.
- Giữ `TAX_RATE_NOT_STORED`, `TAX_RATE_UNSUPPORTED`, `UNTAXED_LINE_WITH_TAX` trong enum với nhãn/hint hiện có, vì hóa đơn đã lỗi trước đó vẫn hiển thị chúng. Builder không phát các mã này nữa.
- Hint dùng chung cho Lark, notice, meta box, chi tiết và Sentry:
  - `line_tax_not_configured`: `Cài thuế cho sản phẩm (cả biến thể), phí hoặc phương thức vận chuyển của dòng bị nêu: trạng thái thuế "Chịu thuế" và lớp thuế có thuế suất. Sau đó bấm "Tính lại" trong đơn rồi bấm "Gửi lại".`
  - `line_tax_rate_count`: `Lớp thuế của sản phẩm chỉ được có một thuế suất áp dụng cho đơn. Sửa lớp thuế, bấm "Tính lại" trong đơn rồi bấm "Gửi lại".`
  - `tax_rate_mismatch`: `Mở tab "Xuất hóa đơn", bảng "Ánh xạ thuế Mắt Bão": thêm thuế suất của dòng vào đúng nhóm, hoặc sửa thuế suất trong WooCommerce cho khớp nhóm. Bấm "Tính lại" trong đơn nếu đã sửa thuế, rồi bấm "Gửi lại".`

**D5. Thay code nhánh cũ.**
- Xóa:
  - `ExemptTaxRateOptions`;
  - route `settings/tax-rates` và `settings/tax-rates/unassign`;
  - card "Thuế KCT/KKKNT";
  - `Settings::TAX_RATE_KCT|KKKNT`, `exempt_tax_rates()`, `save_exempt_tax_rates()`;
  - các test của chúng.
- Giữ `Settings::parse_rate_id()`, phần không đọc `tax_status`, mã `line_tax_not_configured`, e2e seam và cấu trúc test workflow. Sửa test và seam theo bảng mới.

## Risks / Trade-offs

- **Nâng cấp lên thì mọi đơn có thuế bị chặn cho tới khi điền bảng** → chấp nhận (smoke test); lỗi `tax_rate_mismatch` hướng dẫn mở bảng.
- **Bảng đọc lúc gửi, không lưu trên đơn:** đổi bảng sau khi đơn hoàn thành sẽ đổi kết quả của lần gửi sau → chấp nhận; sửa bảng rồi Gửi lại là cách sửa mong muốn.
- **Rate trong nhóm 8% bị sửa thành 10% trong WooCommerce:** đơn mới bị chặn `tax_rate_mismatch` (phần trăm lệch); đơn cũ đã lưu 8% vẫn gửi 8% → đúng ý.
- **Hàng Khác có thể nhận rate 3.5% lẫn 7%:** mỗi dòng gửi phần trăm của chính nó → đúng ý "dùng giá trị của thuế đang chọn".
- **Mắt Bão chưa được thử với `-2` và giá trị Khác** → thử một nháp trên Demo trước khi phát hành (ghi ở verify/finalize).
- **Card swap `outerHTML` cả card:** card lớn hơn một notice; số rate nhỏ nên chấp nhận.

## Migration Plan

- Không migrate; key `tax_rate_map` mặc định rỗng.
- Hai key cũ `kct_tax_rate_ids`/`kkknt_tax_rate_ids` chưa từng phát hành nên không cần dọn.
- Rollback: cài lại bản trước; key mới bị bỏ qua.

## Open Questions

- Không còn.
