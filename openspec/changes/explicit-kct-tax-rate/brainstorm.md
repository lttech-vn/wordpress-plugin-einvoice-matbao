## Design Summary

**Vì sao:** smoke test cho thấy plugin coi mọi dòng có trạng thái thuế *Không* (`tax_status = none`) là KCT (`TSuat = -1`). Một sản phẩm chưa được cài thuế vì vậy vẫn xuất hóa đơn KCT mà không ai biết. Ngược lại, khi staff đã cài thuế KCT cho sản phẩm (một thuế suất 0% trong WooCommerce), plugin lại xuất `TSuat = 0` vì không phân biệt được KCT với 0%.

**Mục tiêu:**

- KCT và KKKNT là **một thuế được cài cho sản phẩm**, giống 0/5/8/10%. Admin chọn thuế suất WooCommerce nào là KCT, thuế suất nào là KKKNT, **từ danh sách thuế suất có sẵn** trong cài đặt của plugin, không gõ tay.
- **Mọi dòng phải có thuế của chính nó.** Dòng không có thuế suất nào đã lưu là lỗi dữ liệu, kể cả dòng có trạng thái thuế *Không* và sản phẩm 0 đồng (quà tặng).
- Quy tắc áp chung cho sản phẩm, phí và vận chuyển.

**Không làm (non-goals):**

- Không migrate hay giữ hành vi cũ cho đơn đã tạo (plugin đang smoke test).
- Không tự chọn thuế suất cho phí/vận chuyển; thuế suất của chúng do kế toán quyết và cài trong WooCommerce.
- Không hỗ trợ `TSuat = 3.5` ("Khác") của Mắt Bão.

**Anti-goal:** plugin không được đoán thuế. Không suy KCT từ trạng thái *Không*, từ tên thuế hay từ tỷ lệ 0%.

### Requirement hiện có bị thay đổi

- `draft-invoice-creation` → **VAT rate mapping**: hiện "dùng `-1` (KCT) cho dòng không chịu thuế". Đổi thành: `TSuat` lấy từ thuế suất đã lưu trên dòng; thuế suất được chọn là KCT → `-1`, KKKNT → `-2`; dòng không có thuế suất đã lưu (kể cả `tax_status = none`) bị chặn với lỗi dữ liệu mới "Dòng chưa cài thuế".
- `draft-invoice-creation` → **Zero-value lines**: sản phẩm 0 đồng vẫn được gửi, nhưng không còn ngoại lệ "không lưu thuế suất thì dùng 0% hoặc KCT theo trạng thái thuế". Nó phải có đúng một thuế suất như mọi dòng; tiền thuế tự bằng 0. Dòng phí/vận chuyển 0 đồng vẫn bị bỏ khỏi hóa đơn như hiện nay.
- Lỗi `untaxed_line_with_tax` ("Dòng không chịu thuế có tiền thuế") không còn cần, vì dòng *Không* nay bị chặn bằng lỗi chưa cài thuế.
- Cài đặt (tab **Xuất hóa đơn**) có thêm phần chọn thuế suất KCT/KKKNT; error monitoring và cảnh báo nhận thêm mã lỗi mới với hint riêng.

## Alternatives Considered

### Alternative A: Quy ước theo tên thuế
- **Approach**: thuế suất 0% có *Tax name* là `KCT`/`KKKNT` thì xuất mã tương ứng.
- **Pros**: không cần cài đặt thêm.
- **Cons**: gõ sai tên thì dòng âm thầm thành 0%, không có lỗi nào báo.
- **Why not chosen**: vi phạm anti-goal "không đoán thuế"; user muốn chọn từ danh sách để tránh điền sai.

### Alternative B: Quy ước theo lớp thuế (slug `kct`)
- **Approach**: lớp thuế có slug cố định là KCT.
- **Pros**: không cần cài đặt thêm.
- **Cons**: phụ thuộc slug, khó thấy trong admin; cùng rủi ro đặt sai như A.
- **Why not chosen**: cùng lý do với A.

### Alternative C: Giữ `tax_status = none` là KCT, chỉ thêm KKKNT
- **Approach**: thay đổi tối thiểu.
- **Pros**: không đổi hành vi hiện có.
- **Cons**: sản phẩm chưa cài thuế vẫn ra KCT; đây chính là lỗi cần sửa.
- **Why not chosen**: không giải quyết vấn đề gốc.

## Agreed Approach

Admin chọn thuế suất KCT và KKKNT từ danh sách thuế suất WooCommerce trong tab **Xuất hóa đơn**. Plugin nhận diện theo thuế suất đã lưu trên dòng đơn. Mọi dòng cần đúng một thuế suất đã lưu; không có thì chặn với lỗi "Dòng chưa cài thuế". User chọn cách này vì nó không phải đoán và tránh điền sai.

## Key Decisions

- **Chọn từ danh sách:** chọn được nhiều thuế suất cho KCT và cho KKKNT (ví dụ cùng là KCT nhưng ở hai lớp thuế khác nhau). Một thuế suất không được thuộc cả hai nhóm.
- **KCT/KKKNT không có tiền thuế:** dòng dùng thuế suất KCT/KKKNT mà có tiền thuế khác 0 thì chặn. Lý do: không có thuế GTGT. API Mắt Bão không ghi rõ ràng buộc này, đây là suy luận.
- **Quà tặng 0 đồng:** phải có thuế suất của chính nó như mọi dòng (theo user: thuế nhân với 0 vẫn bằng 0). WooCommerce khi tính thuế vẫn lưu thuế suất với tiền thuế 0, nên dòng 0 đồng không có thuế suất nào nghĩa là chưa cài thuế → lỗi.
- **Trạng thái thuế *Không* → lỗi "Dòng chưa cài thuế"**, áp cho sản phẩm, phí và vận chuyển. Shop bán hàng vật lý tự cài thuế cho phí/phương thức vận chuyển; tài liệu hướng dẫn và nhắc hỏi kế toán về thuế suất.
- **KKKNT làm luôn**, cùng cơ chế với KCT (`TSuat = -2`).
- **Đơn cũ:** không xử lý gì thêm.
- **Tài liệu:** `docs/thiet-lap-thue.md`, `docs/huong-dan-su-dung.md`, README cập nhật theo cách cài KCT/KKKNT mới.

## Open Questions

- Không còn câu hỏi mở cho user. Giao diện chọn danh sách, lưu trữ và mã lỗi cụ thể sẽ quyết ở `design.md`.
