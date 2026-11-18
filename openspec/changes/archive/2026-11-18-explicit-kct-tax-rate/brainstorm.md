## Design Summary

**Vì sao:** smoke test cho thấy hai vấn đề:
- Plugin coi mọi dòng có trạng thái thuế *Không* (`tax_status = none`) là KCT. Sản phẩm chưa cài thuế vẫn ra hóa đơn KCT mà không ai biết.
- Plugin suy `TSuat` từ phần trăm thuế. Thuế KCT staff đã cài (thuế suất 0% trong WooCommerce) vì vậy ra `TSuat = 0`, không phân biệt được KCT với 0%.

Cả hai đều là plugin **đoán** thuế. Cách mới: admin tự khai báo thuế suất WooCommerce nào ứng với giá trị thuế nào của Mắt Bão. Plugin chỉ tra theo ID thuế suất và kiểm tra khớp.

**Bảng ánh xạ thuế** (tab **Xuất hóa đơn**):
- Mỗi hàng là một giá trị `TSuat` Mắt Bão chấp nhận: **10%, 8%, 5%, 0%, KCT, KKKNT, Khác**.
- Hàng **Khác** dành cho thuế suất GTGT ngoài 0/5/8/10 (ví dụ 3,5%). Không phải tự điền số: thuế suất chọn ở hàng này gửi `TSuat` bằng chính phần trăm của thuế suất đó đã lưu trên đơn.
- Mỗi hàng chứa các thuế suất WooCommerce đã chọn, hiện dạng **badge kèm dấu ×**. Một hàng có thể có nhiều thuế suất (ví dụ 10% ở lớp Standard và ở lớp khác, hoặc nhiều quốc gia).
- Thêm: chọn thuế suất từ danh sách thuế suất hiện có của WooCommerce rồi bấm **Thêm**. Danh sách chỉ gồm thuế suất **chưa thuộc hàng nào**, nên mỗi thuế suất chỉ ứng với một giá trị Mắt Bão.
- Bỏ: bấm **×** trên badge thì xóa ngay ánh xạ đó.
- Hàng không có badge nghĩa là không dùng giá trị đó (thay cho lựa chọn "bỏ qua").

```text
Thuế Mắt Bão   Thuế suất WooCommerce đã chọn                       Thêm
10%            [VAT 10 · Tiêu chuẩn · VN ×] [VAT 10 · Dịch vụ ×]   [chọn thuế suất ▾] [Thêm]
8%             [Giảm 8 · Giam 8 ×]                                 [chọn thuế suất ▾] [Thêm]
5%             (trống)                                             [chọn thuế suất ▾] [Thêm]
0%             (trống)                                             [chọn thuế suất ▾] [Thêm]
KCT            [KCT · KCT ×]                                       [chọn thuế suất ▾] [Thêm]
KKKNT          [KKKNT · KKKNT (đã xóa) ×]                          [chọn thuế suất ▾] [Thêm]
Khác           [VAT 3,5 · Giảm 3,5 ×]                              [chọn thuế suất ▾] [Thêm]
```

**Khi tạo hóa đơn**, với mỗi dòng (sản phẩm kể cả 0 đồng, phí, vận chuyển có tiền):
1. Không có thuế suất nào lưu trên dòng → lỗi **"Sản phẩm chưa được cài đặt thuế"**, kể cả dòng có trạng thái thuế *Không*.
2. Có từ hai thuế suất → lỗi **"Sản phẩm có quá nhiều dòng thuế"**.

Hai tên lỗi này dùng chung cho dòng phí và vận chuyển; lý do lỗi kèm theo nêu tên dòng cụ thể.
3. ID thuế suất không có trong bảng (kể cả khi bảng còn trống) → lỗi mới **"Thuế không khớp với thông tin thuế gửi tới Mắt Bão"**.
4. ID có trong bảng → `TSuat` lấy theo hàng chứa ID đó (hàng **Khác**: bằng phần trăm đã lưu trên đơn), rồi kiểm tra đúng yêu cầu Mắt Bão. Sai thì chặn với cùng lỗi "không khớp":
   - hàng 10%, 8%, 5%: phần trăm lưu trên đơn phải bằng giá trị của hàng;
   - hàng 0%, KCT, KKKNT: tiền thuế của dòng phải bằng 0;
   - hàng Khác: phần trăm lưu trên đơn phải lớn hơn 0 và khác 5, 8, 10 (các giá trị này dùng hàng riêng).

Phí và vận chuyển 0 đồng vẫn bị bỏ khỏi hóa đơn như hiện nay.

**Mục tiêu:**
- `TSuat` chỉ đến từ ID thuế suất đã lưu trên dòng đơn và bảng ánh xạ do admin chọn.
- Mọi dòng phải có đúng một thuế suất, và thuế suất đó phải nằm trong bảng.
- Hỗ trợ đủ bảy giá trị Mắt Bão: 10, 8, 5, 0, KCT (`-1`), KKKNT (`-2`), Khác (phần trăm của thuế suất).
- Quy tắc áp chung cho sản phẩm, phí và vận chuyển.

**Không làm (non-goals):**
- Không migrate hay tự điền bảng khi nâng cấp; plugin đang smoke test.
- Không tự chọn thuế suất cho phí/vận chuyển; kế toán quyết và cài trong WooCommerce.
- Không tính lại thuế của đơn.

**Anti-goal:** plugin không được đoán thuế. Không suy từ trạng thái thuế, tên thuế, tên lớp hay phần trăm.

### Requirement hiện có bị thay đổi

- `draft-invoice-creation` → **VAT rate mapping**:
  - hiện: ánh xạ theo phần trăm đã lưu, dòng *Không* là KCT;
  - đổi thành: tra `TSuat` theo bảng ánh xạ, kiểm tra khớp, chặn dòng chưa cài thuế và dòng có thuế suất không thuộc bảng.
- `draft-invoice-creation` → **Zero-value lines**: sản phẩm 0 đồng không còn ngoại lệ "không lưu thuế suất thì dùng 0% hoặc KCT theo trạng thái thuế". Nó tuân cùng quy tắc như mọi dòng.
- `admin-settings`: thêm bảng ánh xạ thuế vào tab `invoicing`.
- `failure-alerts` → **Resolution hint per error code**: thêm hint cho lỗi mới; hint các lỗi thuế cũ trỏ tới bảng ánh xạ.

### So với bản đang code trên nhánh

- **Bỏ:** card "Thuế KCT/KKKNT" (chỉ gán thuế suất 0% là KCT/KKKNT), route lưu toàn bộ phép gán, route "Bỏ gán", mục "Đã gán nhưng không còn 0%".
- **Thay bằng:** bảng ánh xạ đủ bảy giá trị Mắt Bão (thêm **Khác**), thêm/xóa từng thuế suất.
- **Thay lỗi:** các lỗi "Thuế suất không hỗ trợ", "Thiếu thuế suất đã lưu", "Dòng KCT/KKKNT có tiền thuế" được thay bằng lỗi "không khớp". Mã cũ vẫn được giữ trong danh mục, vì hóa đơn đã ghi lỗi trước đó còn hiển thị chúng.
- **Giữ:** hai lỗi chặn dòng thiếu thuế và dòng nhiều thuế (đổi tên thành "Sản phẩm chưa được cài đặt thuế" và "Sản phẩm có quá nhiều dòng thuế"; mã lỗi giữ nguyên), việc không đọc trạng thái thuế, phần e2e và tài liệu (sẽ cập nhật theo bảng mới).

## Alternatives Considered

### Alternative A: Gán KCT/KKKNT cho thuế suất 0%, phần còn lại suy theo phần trăm
- **Approach**: bản đang code trên nhánh. Admin chỉ gán thuế suất 0% là KCT hoặc KKKNT; 10/8/5/0 vẫn suy từ phần trăm đã lưu.
- **Pros**: ít cấu hình; site chưa có KCT không phải làm gì.
- **Cons**: vẫn đoán theo phần trăm. Phải xử lý riêng thuế suất đã gán rồi bị sửa khỏi 0% (cần thêm mục "Bỏ gán"). Hai cơ chế song song khó giải thích.
- **Why not chosen**: user muốn khai báo tường minh cho mọi giá trị, không đoán.

### Alternative B: Mỗi thuế suất WooCommerce một ô chọn giá trị Mắt Bão
- **Approach**: liệt kê mọi thuế suất WooCommerce, mỗi thuế suất chọn một trong 10/8/5/0/KCT/KKKNT hoặc "bỏ qua".
- **Pros**: cùng dữ liệu với phương án được chọn; dễ thấy thuế suất nào chưa ánh xạ.
- **Cons**: danh sách dài khi site có nhiều quốc gia/lớp; khó nhìn nhanh giá trị Mắt Bão nào đang dùng thuế suất nào.
- **Why not chosen**: user muốn nhìn theo giá trị Mắt Bão, chọn nhiều thuế suất dạng badge.

### Alternative C: Quy ước theo tên thuế hoặc tên lớp
- **Approach**: thuế suất tên `KCT`/`KKKNT` hoặc lớp có slug cố định thì ra mã tương ứng.
- **Pros**: không cần cài đặt.
- **Cons**: gõ sai tên thì âm thầm ra sai mã.
- **Why not chosen**: vi phạm anti-goal "không đoán thuế".

## Agreed Approach

Bảng ánh xạ theo giá trị Mắt Bão, mỗi hàng chọn nhiều thuế suất WooCommerce dạng badge có dấu ×. Hóa đơn tra `TSuat` theo ID thuế suất đã lưu trên dòng, kiểm tra khớp yêu cầu Mắt Bão, và chặn mọi dòng không khớp. User chọn cách này vì nó tường minh, không đoán, và một nơi cấu hình cho cả bảy giá trị.

## Key Decisions

- **Chọn nhiều, mỗi thuế suất một hàng:** một giá trị Mắt Bão có thể gồm nhiều thuế suất WooCommerce. Danh sách thêm chỉ hiện thuế suất chưa được chọn ở hàng nào.
- **Thêm/xóa lưu ngay:** bấm **Thêm** hoặc **×** là lưu, không có nút Lưu chung cho bảng.
- **Thuế suất đã xóa khỏi WooCommerce:** giữ ánh xạ theo ID để đơn cũ vẫn gửi đúng; badge ghi "(đã xóa)", admin bấm × nếu muốn bỏ.
- **Bảng trống sau khi nâng cấp:** mọi dòng có thuế bị chặn với lỗi "không khớp" cho tới khi admin điền bảng. Không tự điền, không đoán.
- **Kiểm tra khớp yêu cầu Mắt Bão:** 10/8/5% so phần trăm lưu trên đơn; 0%/KCT/KKKNT yêu cầu tiền thuế bằng 0; Khác yêu cầu phần trăm lớn hơn 0 và không phải 5/8/10. Sai thì chặn với lỗi "không khớp".
- **Hàng Khác dùng giá trị của thuế suất:** admin chỉ chọn thuế suất, không nhập số; `TSuat` là phần trăm thuế suất đó đã lưu trên đơn (ví dụ `3.5`).
- **Trạng thái thuế *Không*** không còn là KCT. Dòng như vậy không có thuế suất nên báo "Sản phẩm chưa được cài đặt thuế".
- **Quà tặng 0 đồng:** có thuế suất của chính nó như mọi sản phẩm; tiền thuế tự bằng 0.
- **KKKNT** làm luôn, cùng cơ chế.
- **Đơn cũ:** không xử lý gì thêm.

## Open Questions

- Không còn câu hỏi mở cho user. Lưu trữ bảng, route, mã lỗi cụ thể và giao diện htmx sẽ quyết ở `design.md`.
- Cần thử trên Mắt Bão Demo rằng `TSuat = -2` (KKKNT) và `TSuat` của hàng Khác (ví dụ `3.5`) được chấp nhận trước khi phát hành.
