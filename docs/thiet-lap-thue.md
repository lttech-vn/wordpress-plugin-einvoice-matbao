# Thiết lập thuế GTGT cho sản phẩm

Tài liệu này hướng dẫn cấu hình thuế WooCommerce và bảng **Ánh xạ thuế Mắt Bão** để mỗi dòng đơn hàng có đúng một thuế suất mà Mắt Bão chấp nhận: **10%, 8%, 5%, 0%, KCT** (không chịu thuế), **KKKNT** (không kê khai, nộp thuế) hoặc **Khác** (thuế suất khác, ví dụ 3,5%).

- **Cần biết trước:** quản trị WooCommerce cơ bản; biết sản phẩm nào chịu thuế suất nào (hỏi kế toán).
- **Không bao gồm:** chọn thuế suất đúng luật cho từng mặt hàng. Đó là quyết định của kế toán.

> **Plugin không tính lại thuế.** Hóa đơn nháp lấy đúng thuế WooCommerce **đã lưu trên từng dòng đơn tại lúc tạo/tính lại đơn**. Đổi cấu hình thuế hay lớp thuế của sản phẩm chỉ có tác dụng với đơn mới. Đơn cũ phải bấm **Tính lại** (*Recalculate*) trong trang sửa đơn.

## Quy tắc plugin kiểm tra

Với mỗi dòng sản phẩm, phí và vận chuyển, plugin đối chiếu thuế lưu trên dòng với bảng ánh xạ:

| Thuế lưu trên dòng | Kết quả trên hóa đơn |
|---|---|
| **Không có** thuế suất nào (kể cả trạng thái *Không*) | Lỗi *Sản phẩm chưa được cài đặt thuế* |
| **Hai** thuế suất trở lên | Lỗi *Sản phẩm có quá nhiều dòng thuế* |
| Một thuế suất **không thuộc** hàng nào trong bảng (ví dụ 7%) | Lỗi *Thuế không khớp với thông tin thuế gửi tới Mắt Bão* |
| Thuế suất thuộc hàng 10%, 8% hoặc 5% | `TSuat` = 10, 8 hoặc 5. Phần trăm lưu trên đơn phải đúng bằng hàng; sai thì lỗi *Thuế không khớp* |
| Thuế suất thuộc hàng 0%, KCT hoặc KKKNT | `TSuat` = 0, -1 hoặc -2. Tiền thuế của dòng phải bằng 0; khác 0 thì lỗi *Thuế không khớp* |
| Thuế suất thuộc hàng Khác | `TSuat` = phần trăm lưu trên đơn (ví dụ 3,5). Phần trăm phải lớn hơn 0 và khác 5, 8, 10; không thì lỗi *Thuế không khớp* |
| Hàng 10%, 8%, 5% hoặc Khác mà đơn **không lưu phần trăm** của thuế suất | Lỗi *Thuế không khớp*; bấm **Tính lại** |

Ngoại lệ: dòng phí/vận chuyển **0 đồng** (tiền hàng và tiền thuế đều 0) bị bỏ khỏi hóa đơn, kể cả khi không có thuế suất. Dòng sản phẩm 0 đồng vẫn cần thuế suất như mọi dòng khác.

## Bước 1: cấu hình chung của WooCommerce

Mở **WooCommerce → Cài đặt → Chung** (*Settings → General*):

| Tùy chọn | Giá trị |
|---|---|
| Đơn vị tiền tệ | Đồng Việt Nam (VND) |
| Số chữ số thập phân | `0` |
| Bật thuế và tính thuế (*Enable tax rates and calculations*) | **Bật** |

Tắt tính thuế thì đơn không lưu thuế nào và mọi dòng chịu thuế đều bị chặn với *Sản phẩm chưa được cài đặt thuế*.

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
| `KCT` | `0` | `KCT` |
| `KKKNT` | `0` | `KKKNT` |

Các cột còn lại của mỗi dòng:

- **Mã quốc gia, Bang, Mã bưu chính, Thành phố:** để trống (áp cho mọi địa chỉ). Nếu muốn giới hạn `VN`, phải chọn *Tính thuế dựa trên = Địa chỉ cửa hàng*. Nếu không, đơn thiếu quốc gia hoặc khách nước ngoài sẽ không có thuế suất nào.
- **Độ ưu tiên (*Priority*):** `1`.
- **Thuế kép (*Compound*):** **bỏ chọn**.
- **Vận chuyển (*Shipping*):** **chọn** nếu lớp này dùng cho vận chuyển. Nếu bỏ chọn, dòng vận chuyển chịu thuế sẽ không có thuế suất và bị chặn.

**Lưu ý:**

- **Không thêm dòng thứ hai** trong cùng lớp với độ ưu tiên khác. WooCommerce sẽ cộng cả hai vào một dòng đơn → lỗi *Sản phẩm có quá nhiều dòng thuế*.
- **Thuế 0% vẫn phải có dòng thuế suất `0`.** Lớp thuế rỗng (không có dòng nào) không ra 0%; dòng đơn sẽ không có thuế suất và bị chặn.
- **Lớp `KCT`, `KKKNT` chỉ chứa thuế suất 0%** để chọn vào hàng KCT/KKKNT ở Bước 3. Sản phẩm KCT/KKKNT vẫn ở trạng thái *Chịu thuế* (Bước 4).

## Bước 3: điền bảng Ánh xạ thuế Mắt Bão

Mở **Hóa đơn Mắt Bão → Xuất hóa đơn**, card **Ánh xạ thuế Mắt Bão**. Card có bảy hàng theo thứ tự **10%, 8%, 5%, 0%, KCT, KKKNT, Khác**. Mỗi hàng là một loại thuế Mắt Bão và nhận các thuế suất WooCommerce tương ứng:

| Hàng | Thuế suất WooCommerce nên chọn |
|---|---|
| 10%, 8%, 5%, 0% | Thuế suất có đúng phần trăm của hàng (ví dụ lớp `Giam 8` → hàng 8%) |
| KCT | Thuế suất 0% của lớp `KCT` |
| KKKNT | Thuế suất 0% của lớp `KKKNT` |
| Khác | Thuế suất khác 0/5/8/10% (ví dụ 3,5%); hóa đơn gửi đúng phần trăm đó |

**Thao tác:**

1. Chọn thuế suất trong ô chọn của hàng, bấm **Thêm**. Badge của thuế suất hiện trong hàng.
2. Bấm **×** trên badge để bỏ thuế suất khỏi hàng.

Cả hai thao tác lưu ngay, không cần nút Lưu.

**Quy tắc:**

- Mỗi thuế suất WooCommerce chỉ thuộc **một** hàng. Ô chọn chỉ liệt kê thuế suất chưa có trong bảng.
- Hàng **Khác** không dùng cho 5%, 8%, 10%: các mức này phải dùng hàng riêng.
- Thuế suất đã xóa khỏi WooCommerce vẫn hiện badge kèm **(đã xóa)**, để đơn cũ tiếp tục gửi đúng. Bấm **×** để bỏ khi không còn cần.
- Bảng trống (ví dụ ngay sau khi nâng cấp plugin): mọi dòng có thuế bị chặn với *Thuế không khớp*. Điền bảng trước khi chạy thử.
- Bảng được đọc lúc gửi hóa đơn: thêm thuế suất trước khi hóa đơn được gửi là đủ, không cần đặt lại đơn.
- Chưa có thuế suất nào trong WooCommerce: card hướng dẫn tạo ở **WooCommerce → Cài đặt → Thuế** và không có nút **Thêm**.

## Bước 4: gán thuế cho từng loại sản phẩm

Mở sản phẩm → **Dữ liệu sản phẩm → Chung** (*Product data → General*):

| Loại hàng | Trạng thái thuế (*Tax status*) | Lớp thuế (*Tax class*) |
|---|---|---|
| Thuế 10% | Chịu thuế (*Taxable*) | Tiêu chuẩn (*Standard*) |
| Giảm thuế còn 8% | Chịu thuế | `Giam 8` |
| Thuế 5% | Chịu thuế | `Thue 5` |
| Thuế 0% | Chịu thuế | `Thue 0` |
| Không chịu thuế (KCT) | Chịu thuế | `KCT` |
| Không kê khai, nộp thuế (KKKNT) | Chịu thuế | `KKKNT` |

**Sản phẩm có biến thể:** mỗi biến thể có ô **Lớp thuế** riêng, mặc định *Giống sản phẩm cha* (*Same as parent*). Nếu biến thể đã đặt lớp khác, sửa lớp ở **từng biến thể** (*Variations*); đổi lớp ở sản phẩm cha không đè lên lớp biến thể đã chọn.

**Đổi hàng loạt:** ở danh sách **Sản phẩm**, chọn nhiều sản phẩm → **Sửa** (*Bulk actions → Edit*) → đặt **Lớp thuế**. Sau đó mở vài sản phẩm có biến thể để chắc biến thể vẫn *Giống sản phẩm cha* hoặc đúng lớp mới.

**KCT/KKKNT:** trạng thái *Không* không còn nghĩa là KCT. Sản phẩm có trạng thái *Không* bị chặn với *Sản phẩm chưa được cài đặt thuế*.

**Phí (fee):** phí do plugin khác thêm vào dùng lớp thuế mà plugin đó chọn. Phí chịu thuế cũng phải ra đúng một thuế suất.

**Vận chuyển (shipping):** mỗi phương thức vận chuyển (WooCommerce → Cài đặt → Vận chuyển) đặt trạng thái thuế *Chịu thuế* và tick **Vận chuyển** ở rate dùng cho vận chuyển. Chọn thuế suất theo ý kiến kế toán. Vận chuyển miễn phí (0 đồng) không cần thuế.

## Bước 5: kiểm tra bằng đơn thử

1. Tạo đơn thử (môi trường **Demo**) với ít nhất một sản phẩm mỗi loại thuế và có vận chuyển.
2. Mở đơn trong wp-admin. Mỗi dòng sản phẩm và vận chuyển phải có **đúng một cột thuế có số tiền**, đúng tên thuế mong đợi (ví dụ `GTGT 8%`). Dòng KCT/KKKNT không có tiền thuế.
3. Chuyển đơn sang **Hoàn thành**, rồi xem `TSuat` của từng dòng trong hóa đơn nháp trên Mắt Bão.

**Khuyến nghị:** trước khi dùng thật, tạo thêm một nháp thử trên Demo có dòng **KKKNT** (kiểm `TSuat = -2`) và dòng **Khác** (ví dụ 3,5%), vì hai nhóm này cần kiểm trên Mắt Bão.

## Khi đổi thuế suất (ví dụ hết thời gian giảm thuế 8%)

1. Đổi lớp thuế của sản phẩm (và biến thể) sang lớp mới, hoặc sửa tỷ lệ của dòng thuế suất trong lớp `Giam 8`. Không thêm dòng thứ hai vào lớp này.
2. Nếu chỉ sửa phần trăm mà thuế suất vẫn nằm ở hàng cũ (ví dụ hàng 8% nhưng thuế suất đã thành 10%), đơn mới bị chặn với *Thuế không khớp*. Chuyển thuế suất sang hàng đúng: ở card **Ánh xạ thuế Mắt Bão**, bấm **×** ở hàng cũ, rồi chọn lại thuế suất và bấm **Thêm** ở hàng mới (ví dụ hàng 10%).
3. Đơn **mới** dùng thuế suất mới. Đơn **cũ** giữ thuế đã lưu. Chỉ bấm **Tính lại** ở đơn cũ khi kế toán xác nhận đơn đó phải theo thuế suất mới.
4. Thuế suất đã xóa khỏi WooCommerce vẫn giữ ánh xạ (badge *(đã xóa)*) để đơn cũ gửi đúng. Chỉ bấm **×** khi chắc không còn đơn nào dùng thuế suất đó.

## Sửa đơn bị chặn vì thuế

Khi hóa đơn báo *Sản phẩm chưa được cài đặt thuế*, *Sản phẩm có quá nhiều dòng thuế* hoặc *Thuế không khớp với thông tin thuế gửi tới Mắt Bão*:

1. Với *chưa được cài đặt thuế* hoặc *quá nhiều dòng thuế*: mở sản phẩm/biến thể, phí hoặc phương thức vận chuyển có tên trong lỗi, kiểm tra theo [Bước 4](#bước-4-gán-thuế-cho-từng-loại-sản-phẩm). Với *Thuế không khớp*: mở card **Ánh xạ thuế Mắt Bão** theo [Bước 3](#bước-3-điền-bảng-ánh-xạ-thuế-mắt-bão), thêm thuế suất vào đúng hàng, hoặc sửa thuế suất trong WooCommerce cho khớp hàng.
2. Mở đơn, bấm **Tính lại** (*Recalculate*). Kiểm tra dòng đã có đúng một thuế suất khớp bảng.
3. Trong hộp **Hóa đơn Mắt Bão** trên đơn, bấm **Gửi lại**.

**Hóa đơn lỗi từ trước khi có bảng ánh xạ:** có thể còn hiện mã cũ *Thiếu thuế suất đã lưu*, *Thuế suất không hỗ trợ* hoặc *Dòng KCT/KKKNT có tiền thuế*. Gợi ý của các mã này cũng trỏ tới card **Ánh xạ thuế Mắt Bão**; xử lý như mục trên.

**Mẹo đọc lỗi:** trong Sentry, `contexts.line.tax_rate_ids` là danh sách thuế suất lưu trên dòng. `[]` nghĩa là dòng **không có thuế suất nào**: sản phẩm chưa đúng lớp lúc tạo đơn, hoặc thuế suất không khớp địa chỉ. Hai ID trở lên nghĩa là lớp thuế có nhiều dòng thuế suất.

**Bấm Tính lại mà dòng vẫn không có thuế:** nguyên nhân gần như luôn là thuế suất không khớp địa chỉ của đơn (Bước 2), hoặc biến thể vẫn dùng lớp cũ (Bước 4).
