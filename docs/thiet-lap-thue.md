# Thiết lập thuế GTGT cho sản phẩm

Tài liệu này hướng dẫn cấu hình thuế WooCommerce để mỗi dòng đơn hàng có đúng một thuế suất GTGT mà Mắt Bão chấp nhận: **0%, 5%, 8%, 10% hoặc KCT** (không chịu thuế).

- **Cần biết trước:** quản trị WooCommerce cơ bản; biết sản phẩm nào chịu thuế suất nào (hỏi kế toán).
- **Không bao gồm:** chọn thuế suất đúng luật cho từng mặt hàng. Đó là quyết định của kế toán.

> **Plugin không tính lại thuế.** Hóa đơn nháp lấy đúng thuế WooCommerce **đã lưu trên từng dòng đơn tại lúc tạo/tính lại đơn**. Đổi cấu hình thuế hay lớp thuế của sản phẩm chỉ có tác dụng với đơn mới. Đơn cũ phải bấm **Tính lại** (*Recalculate*) trong trang sửa đơn.

## Quy tắc plugin kiểm tra

Với mỗi dòng sản phẩm, phí và vận chuyển:

| Trạng thái thuế của dòng | Thuế lưu trên dòng | Kết quả trên hóa đơn |
|---|---|---|
| Chịu thuế (*Taxable*) | Đúng **một** thuế suất 0, 5, 8 hoặc 10% | `TSuat` = thuế suất đó |
| Chịu thuế | **Không có** thuế suất nào | Lỗi *Dòng không có đúng một thuế suất* |
| Chịu thuế | **Hai** thuế suất trở lên | Lỗi *Dòng không có đúng một thuế suất* |
| Chịu thuế | Thuế suất khác 0/5/8/10% (ví dụ 7%) | Lỗi *Thuế suất không hỗ trợ* |
| Không chịu thuế (*None*) | Không có thuế | KCT (`TSuat = -1`) |

Ngoại lệ: dòng sản phẩm 0 đồng chịu thuế mà không lưu thuế suất được gửi với 0%. Dòng phí/vận chuyển 0 đồng bị bỏ khỏi hóa đơn.

## Bước 1: cấu hình chung của WooCommerce

Mở **WooCommerce → Cài đặt → Chung** (*Settings → General*):

| Tùy chọn | Giá trị |
|---|---|
| Đơn vị tiền tệ | Đồng Việt Nam (VND) |
| Số chữ số thập phân | `0` |
| Bật thuế và tính thuế (*Enable tax rates and calculations*) | **Bật** |

Tắt tính thuế thì đơn không lưu thuế nào và mọi dòng chịu thuế đều bị chặn.

Mở tab **Thuế** (*Tax*), phần **Tùy chọn thuế** (*Tax options*):

| Tùy chọn | Khuyến nghị | Lý do |
|---|---|---|
| Giá nhập đã gồm thuế (*Prices entered with tax*) | **Không, giá chưa gồm thuế** | Cấu hình đã kiểm thử; giá gồm thuế dễ sinh tiền thuế lẻ đồng → lỗi *Số tiền không nguyên đồng* |
| Tính thuế dựa trên (*Calculate tax based on*) | **Địa chỉ cửa hàng** (*Shop base address*) | Thuế không phụ thuộc địa chỉ khách, nên mọi đơn đều khớp thuế suất (xem [Bước 2](#bước-2-tạo-lớp-thuế-và-thuế-suất)) |
| Lớp thuế vận chuyển (*Shipping tax class*) | Theo lớp thuế cụ thể kế toán chọn, hoặc *Dựa trên sản phẩm trong giỏ* | Dòng vận chuyển cũng cần đúng một thuế suất |
| Làm tròn thuế ở tổng phụ (*Round tax at subtotal level*) | **Không** | Thuế làm tròn theo từng dòng, khớp từng dòng hóa đơn |
| Lớp thuế bổ sung (*Additional tax classes*) | Thêm `Giam 8`, `Thue 5`, `Thue 0` (mỗi dòng một tên) | Mỗi thuế suất GTGT một lớp riêng |

## Bước 2: tạo lớp thuế và thuế suất

Mỗi lớp thuế cần **đúng một dòng thuế suất** áp cho mọi đơn. Vào tab **Thuế**, mở từng lớp và thêm một dòng:

| Lớp thuế | Tỷ lệ (*Rate %*) | Tên thuế (*Tax name*) gợi ý |
|---|---|---|
| Thuế suất tiêu chuẩn (*Standard rates*) | `10` | `GTGT 10%` |
| `Giam 8` | `8` | `GTGT 8%` |
| `Thue 5` | `5` | `GTGT 5%` |
| `Thue 0` | `0` | `GTGT 0%` |

Các cột còn lại của mỗi dòng:

- **Mã quốc gia, Bang, Mã bưu chính, Thành phố:** để trống (áp cho mọi địa chỉ). Nếu muốn giới hạn `VN`, phải chọn *Tính thuế dựa trên = Địa chỉ cửa hàng*. Nếu không, đơn thiếu quốc gia hoặc khách nước ngoài sẽ không có thuế suất nào.
- **Độ ưu tiên (*Priority*):** `1`.
- **Thuế kép (*Compound*):** **bỏ chọn**.
- **Vận chuyển (*Shipping*):** **chọn** nếu lớp này dùng cho vận chuyển. Nếu bỏ chọn, dòng vận chuyển chịu thuế sẽ không có thuế suất và bị chặn.

**Lưu ý:**

- **Không thêm dòng thứ hai** trong cùng lớp với độ ưu tiên khác. WooCommerce sẽ cộng cả hai vào một dòng đơn → lỗi *Dòng không có đúng một thuế suất*.
- **Thuế 0% vẫn phải có dòng thuế suất `0`.** Lớp thuế rỗng (không có dòng nào) không ra 0%; dòng đơn sẽ không có thuế suất và bị chặn.
- **KCT không dùng lớp thuế.** Đặt trạng thái thuế của sản phẩm là *Không* (Bước 3).

## Bước 3: gán thuế cho từng loại sản phẩm

Mở sản phẩm → **Dữ liệu sản phẩm → Chung** (*Product data → General*):

| Loại hàng | Trạng thái thuế (*Tax status*) | Lớp thuế (*Tax class*) |
|---|---|---|
| Thuế 10% | Chịu thuế (*Taxable*) | Tiêu chuẩn (*Standard*) |
| Giảm thuế còn 8% | Chịu thuế | `Giam 8` |
| Thuế 5% | Chịu thuế | `Thue 5` |
| Thuế 0% | Chịu thuế | `Thue 0` |
| Không chịu thuế (KCT) | Không (*None*) | (bỏ qua) |

**Sản phẩm có biến thể:** mỗi biến thể có ô **Lớp thuế** riêng, mặc định *Giống sản phẩm cha* (*Same as parent*). Nếu biến thể đã đặt lớp khác, sửa lớp ở **từng biến thể** (*Variations*); đổi lớp ở sản phẩm cha không đè lên lớp biến thể đã chọn.

**Đổi hàng loạt:** ở danh sách **Sản phẩm**, chọn nhiều sản phẩm → **Sửa** (*Bulk actions → Edit*) → đặt **Lớp thuế**. Sau đó mở vài sản phẩm có biến thể để chắc biến thể vẫn *Giống sản phẩm cha* hoặc đúng lớp mới.

**Phí (fee):** phí do plugin khác thêm vào dùng lớp thuế mà plugin đó chọn. Phí chịu thuế cũng phải ra đúng một thuế suất.

## Bước 4: kiểm tra bằng đơn thử

1. Tạo đơn thử (môi trường **Demo**) với ít nhất một sản phẩm mỗi loại thuế và có vận chuyển.
2. Mở đơn trong wp-admin. Mỗi dòng sản phẩm và vận chuyển phải có **đúng một cột thuế có số tiền**, đúng tên thuế mong đợi (ví dụ `GTGT 8%`). Dòng KCT không có thuế.
3. Chuyển đơn sang **Hoàn thành**, rồi xem `TSuat` của từng dòng trong hóa đơn nháp trên Mắt Bão.

## Khi đổi thuế suất (ví dụ hết thời gian giảm thuế 8%)

1. Đổi lớp thuế của sản phẩm (và biến thể) sang lớp mới, hoặc sửa tỷ lệ của dòng thuế suất trong lớp `Giam 8`. Không thêm dòng thứ hai vào lớp này.
2. Đơn **mới** dùng thuế suất mới. Đơn **cũ** giữ thuế đã lưu. Chỉ bấm **Tính lại** ở đơn cũ khi kế toán xác nhận đơn đó phải theo thuế suất mới.

## Sửa đơn bị chặn vì thuế

Khi hóa đơn báo *Dòng không có đúng một thuế suất*, *Thuế suất không hỗ trợ* hoặc *Thiếu thuế suất đã lưu*:

1. Mở sản phẩm/biến thể có tên trong lỗi. Kiểm tra trạng thái thuế và lớp thuế theo [Bước 3](#bước-3-gán-thuế-cho-từng-loại-sản-phẩm).
2. Kiểm tra lớp thuế đó có đúng một dòng thuế suất áp được cho địa chỉ của đơn, theo [Bước 2](#bước-2-tạo-lớp-thuế-và-thuế-suất).
3. Mở đơn, bấm **Tính lại** (*Recalculate*). Kiểm tra dòng đã có đúng một thuế suất.
4. Trong hộp **Hóa đơn Mắt Bão** trên đơn, bấm **Gửi lại**.

**Mẹo đọc lỗi:** trong Sentry, `contexts.line.tax_rate_ids` là danh sách thuế suất lưu trên dòng. `[]` nghĩa là dòng **không có thuế suất nào**: sản phẩm chưa đúng lớp lúc tạo đơn, hoặc thuế suất không khớp địa chỉ. Hai ID trở lên nghĩa là lớp thuế có nhiều dòng thuế suất.

**Bấm Tính lại mà dòng vẫn không có thuế:** nguyên nhân gần như luôn là thuế suất không khớp địa chỉ của đơn (Bước 2), hoặc biến thể vẫn dùng lớp cũ (Bước 3).
