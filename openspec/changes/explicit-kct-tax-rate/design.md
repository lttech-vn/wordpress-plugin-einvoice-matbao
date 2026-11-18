## Context

`DraftPayloadBuilder::line()` hiện quyết `TSuat` theo `tax_status` của dòng (`'none'` → `-1`). `WC_Order_Item_Product::get_tax_status()` đọc từ sản phẩm **hiện tại**, không lưu trên đơn. Thuế suất đã lưu trên dòng nằm ở `get_taxes()['total']`, dạng `rate_id => amount`. Phần trăm của từng rate lấy từ tax item của đơn (`stored_rate_percents()`).

Khi tính thuế, WooCommerce lưu mọi rate khớp vào dòng, kể cả khi tiền thuế bằng 0. Cụ thể `WC_Tax::calc_exclusive_tax` ghi `taxes[rate_id] = 0.0`, nên sản phẩm 0 đồng và rate 0% vẫn có rate ID trên dòng. Khi đơn được tính thuế lúc dòng có trạng thái *Không*, đơn miễn VAT, hoặc site đang tắt tính thuế, dòng nhận `set_taxes( false )`, tức không có rate nào. Đơn đã lưu rate từ trước thì giữ rate đó, dù sau đó tắt tính thuế.

Settings của plugin nằm trong option `einvoice_matbao_settings`, đọc/ghi qua `Settings`. Form admin gửi qua htmx tới route REST `einvoice-matbao/v1/settings/*`; route trả HTML fragment qua `Fragment::success|error`.

## Goals / Non-Goals

**Goals:**
- `TSuat` chỉ suy từ thuế suất đã lưu trên dòng; KCT/KKKNT do admin gán theo rate ID.
- Không còn đường nào gán KCT ngầm.

**Non-Goals:**
- Không đọc `tax_status` để quyết thuế.
- Không hỗ trợ `TSuat = 3.5`.
- Không đổi DB schema.
- Không migrate đơn cũ.

## Decisions

**D1. Lưu gán KCT/KKKNT theo rate ID trong option settings.**
- Hai key mới `kct_tax_rate_ids`, `kkknt_tax_rate_ids`, mỗi key là list số nguyên dương. `Settings` đọc chúng qua một accessor trả `array<int, int>` dạng `rate_id => TSuat` (`-1`/`-2`).
- So theo ID vì rate ID là khóa trong `get_taxes()` của dòng: đổi tên hay sắp xếp lại rate không làm sai.
- Bỏ phương án theo tên/slug, vì gõ sai thì không có lỗi nào báo (brainstorm).

**D2. Chỉ cho gán rate có tỷ lệ 0%.**
- Form chỉ liệt kê rate WooCommerce có `tax_rate = 0`, của mọi lớp (Standard và lớp bổ sung), lấy qua `WC_Tax::get_rates_for_tax_class()` cho slug `''` (Standard, không có trong `get_tax_class_slugs()`) và từng slug của `WC_Tax::get_tax_class_slugs()`.
- Mỗi rate có một `<select>` ba giá trị: `0%` (mặc định), `KCT`, `KKKNT`. Mỗi rate chỉ thuộc một nhóm, và admin không thể chọn rate 5/8/10%.
- Route mới `POST einvoice-matbao/v1/settings/tax-rates` nhận `tax_rate_kind[<rate_id>] = vat0|kct|kkknt`.
- Server kiểm lại mọi ID còn tồn tại và có tỷ lệ 0, vì đây là trust boundary. Sai một ID thì từ chối 422 và không ghi gì.
- Mỗi lần lưu thay phép gán của các rate 0% **đang tồn tại** theo request; rate có mặt trong form mà không có trong request được coi là `vat0`. Gán của rate đã bị xóa khỏi WooCommerce (orphan) được giữ nguyên trong option, để đơn cũ mang rate đó vẫn ánh xạ đúng. Không đơn mới nào mang rate đã xóa, nên giữ lại không gây hại.
- Gán của rate **còn tồn tại nhưng đã đổi khỏi 0%** cũng được giữ, để đơn cũ đã lưu rate đó ở 0% vẫn ra KCT/KKKNT. Rate như vậy không còn trong danh sách chọn, nên card liệt kê riêng chúng (tên, lớp, tỷ lệ hiện tại, nhóm đã gán, cảnh báo đơn mới sẽ bị chặn) kèm nút bỏ gán. Nút gửi `POST einvoice-matbao/v1/settings/tax-rates/unassign` với `rate_id`; route chỉ nhận rate còn tồn tại, đã gán và khác 0%, xóa đúng phép gán đó, sai thì 422 không ghi gì. Route riêng giữ route lưu chính đơn giản: không trộn thao tác xóa vào phép thay toàn bộ (quyết định của user, phương án 2 sau final review).
- Trang hiển thị kèm tên rate, lớp thuế và quốc gia để admin phân biệt. Không có rate 0% nào thì card hiện hướng dẫn tạo rate 0% trong WooCommerce.

**D3. Thứ tự kiểm tra một dòng trong `DraftPayloadBuilder::line()`.**
1. Như cũ: loại dòng, `whole_vnd(net)`, số lượng/số tiền âm, `whole_vnd(tax)`.
2. Phí/vận chuyển có net = 0 và tax = 0 → bỏ dòng, như cũ. Sản phẩm 0 đồng không còn ngoại lệ.
3. Không có rate nào trên dòng → `line_tax_not_configured`.
4. Từ hai rate trở lên → `line_tax_rate_count`, kể cả khi có rate KCT/KKKNT.
5. Rate thuộc nhóm KCT/KKKNT → `TSuat = -1`/`-2`; tiền thuế khác 0 thì → `untaxed_line_with_tax`. Không cần percent lưu trên đơn.
6. Ngược lại: percent đã lưu → `tax_rate_not_stored` / `tax_rate_unsupported` như cũ.

- Builder không còn đọc `get_tax_status()`. Dòng *Không* rơi vào bước 3 một cách tự nhiên, vì WooCommerce không lưu rate cho nó.
- Gán KCT/KKKNT đọc từ `Settings` một lần mỗi payload, giống `default_unit()`. Đây là snapshot của settings lúc gửi.

**D4. Mã lỗi.**
- Thêm `LINE_TAX_NOT_CONFIGURED = 'line_tax_not_configured'`, kind `data`, status `failed`, không tự retry.
  - Label "Dòng chưa cài thuế".
  - Lý do: `Dòng "<tên>" chưa có thuế GTGT.`
- Giữ `untaxed_line_with_tax` (giá trị enum ổn định), đổi nghĩa thành "dòng KCT/KKKNT có tiền thuế".
  - Label "Dòng KCT/KKKNT có tiền thuế".
  - Lý do: `Dòng "<tên>" dùng thuế KCT/KKKNT nhưng có tiền thuế.`
- `line_tax_rate_count` giữ nguyên lý do `Dòng "<tên>" cần đúng một thuế suất GTGT.` (spec *Line details on line data errors* chốt câu này); nay chỉ phát cho dòng có từ hai thuế suất.
- Hint (dùng chung cho Lark, notice, meta box, chi tiết, Sentry):
  - `line_tax_not_configured`: `Cài thuế cho sản phẩm (cả biến thể), phí hoặc phương thức vận chuyển của dòng bị nêu: trạng thái thuế "Chịu thuế" và lớp thuế có thuế suất. Hàng KCT/KKKNT dùng thuế suất 0% đã gán ở tab "Xuất hóa đơn". Sau đó bấm "Tính lại" trong đơn rồi bấm "Gửi lại".`
  - `line_tax_rate_count`: `Kiểm tra lớp thuế của sản phẩm và thuế trên dòng trong đơn: lớp thuế chỉ được có một thuế suất áp dụng. Sửa rồi bấm "Tính lại" trong đơn và bấm "Gửi lại".`
  - `untaxed_line_with_tax`: `Thuế suất gán KCT/KKKNT phải là 0%. Sửa thuế suất trong WooCommerce hoặc gán lại ở tab "Xuất hóa đơn", bấm "Tính lại" trong đơn rồi bấm "Gửi lại".`
  - `tax_rate_unsupported`: `Đổi thuế suất của dòng sang 0%, 5%, 8%, 10%, hoặc gán thuế suất 0% là KCT/KKKNT ở tab "Xuất hóa đơn", rồi bấm "Gửi lại".`

**D5. UI.**
- Card mới "Thuế KCT/KKKNT" trong tab `invoicing`, sau card đơn vị tính, có vùng kết quả riêng.
- Dùng các class `CARD/TITLE/LABEL/FIELD/HELP/BUTTON` sẵn có.
- Mỗi `<select>` có `<label>` gắn với `id` riêng, để đảm bảo accessibility.

## Risks / Trade-offs

- **[Đơn cũ có dòng *Không* bị chặn khi Gửi lại]** → chấp nhận, vì đang smoke test. Tài liệu hướng dẫn cài thuế và bấm Tính lại.
- **[Gán đọc lúc gửi chứ không lưu trên đơn]** Đổi gán sau khi đơn hoàn thành sẽ đổi `TSuat` của lần gửi sau → chấp nhận. Gán sai là lỗi cấu hình; sửa gán rồi Gửi lại là cách sửa mong muốn.
- **[Site tắt tính thuế]** Đơn được tính tổng lúc đang tắt không có rate → `line_tax_not_configured`, đúng ý đồ "không đoán thuế"; hint nêu cách cài. Đơn đã lưu rate trước đó vẫn gửi theo rate đã lưu.
- **[Rate 0% bị sửa thành tỷ lệ khác sau khi gán KCT]** Đơn mới có tiền thuế → `untaxed_line_with_tax` chặn; trang settings không còn liệt kê rate đó. Admin thấy qua lỗi.
- **[Plugin thuế bên thứ ba ghi taxes không theo rate ID]** Dòng có thể không có rate → bị chặn thay vì đoán. Chấp nhận.

## Migration Plan

- Không migrate dữ liệu; option mới mặc định rỗng (không rate nào là KCT/KKKNT).
- Rollback: cài lại bản trước. Hai key mới trong option bị bỏ qua.

## Open Questions

- Không còn.
