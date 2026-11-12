# Hướng dẫn sử dụng

Tài liệu này dành cho **chủ cửa hàng, quản trị viên WordPress và kế toán**. Nội dung gồm cài đặt, cấu hình kết nối Mắt Bão, quy trình hóa đơn hằng ngày và cách xử lý lỗi.

- **Cần biết trước:** quản trị WordPress/WooCommerce cơ bản, có tài khoản hóa đơn điện tử Mắt Bão với thông tin đăng nhập API.
- **Không bao gồm:** môi trường phát triển, kiểm thử, phát hành. Xem [Tài liệu phát triển](phat-trien.md).

> **Plugin chỉ tạo hóa đơn nháp.** Kế toán luôn kiểm tra rồi ký/phát hành trên trang quản lý của Mắt Bão. Plugin không bao giờ ký, phát hành, sửa hay hủy hóa đơn.

## Plugin làm gì

Khi một đơn WooCommerce chuyển sang **Hoàn thành** (`completed`), plugin tự tạo một **hóa đơn GTGT nháp** trên Mắt Bão từ số tiền và thuế đã lưu trên đơn.

```text
Khách đặt hàng ──> Đơn "Hoàn thành" ──> Plugin tạo nháp trên Mắt Bão ──> Kế toán kiểm tra, ký, phát hành trên Mắt Bão
  (có/không yêu cầu     (thủ công hoặc       (nền, tự thử lại khi
   xuất hóa đơn)         tự động)             Mắt Bão lỗi tạm thời)
```

Các điểm chính:

- **Mỗi đơn tối đa một hóa đơn gốc.** Thử lại, gửi lại hay chuyển trạng thái nhiều lần đều không tạo thêm hóa đơn.
- **Mọi đơn hoàn thành sau khi bật plugin đều được xử lý**, kể cả khách không yêu cầu xuất hóa đơn (gọi là *người mua vãng lai*). Đơn cũ trước khi bật plugin không được quét.
- **Trạng thái đơn không bao giờ bị đổi.** Lỗi hóa đơn chỉ hiện trên trang quản lý hóa đơn và cảnh báo.
- **Một đơn vị xuất hóa đơn, tiền VND**, phù hợp dịch vụ/sản phẩm số.

## Yêu cầu hệ thống

| Thành phần | Phiên bản tối thiểu | Đã kiểm thử đến |
|---|---|---|
| PHP | 8.2 | 8.5 |
| WordPress | 6.6 | 7.1 |
| WooCommerce | 9.8 | 11.2 |

Thêm vào đó:

- Đơn vị tiền tệ của cửa hàng là **VND**, số tiền nguyên đồng.
- **WP-Cron** (hoặc cron hệ thống gọi `wp-cron.php`) phải chạy, vì việc tạo nháp và thử lại chạy nền qua Action Scheduler.
- Hỗ trợ cả HPOS và bảng đơn cũ, cả checkout cổ điển (Classic) và Checkout Blocks.

## Cài đặt

1. Tải `einvoice-matbao-X.Y.Z.zip` ở trang [Releases](https://github.com/lttech-vn/wordpress-plugin-einvoice-matbao/releases). File zip đã gồm đủ thư viện, không cần Composer hay bun.
2. (Tùy chọn) Kiểm tra file tải về:

   ```sh
   shasum -a 256 -c einvoice-matbao-X.Y.Z.zip.sha256
   # hoặc
   gh attestation verify einvoice-matbao-X.Y.Z.zip --repo lttech-vn/wordpress-plugin-einvoice-matbao
   ```

3. Trong wp-admin, mở **Plugin → Cài mới → Tải plugin lên** (*Plugins → Add New → Upload Plugin*), chọn file zip rồi **Kích hoạt**. WooCommerce phải đang hoạt động; nếu không, WordPress từ chối kích hoạt.
4. Thêm khóa mã hóa vào `wp-config.php` (mục tiếp theo) **trước khi** nhập thông tin đăng nhập.

**Cập nhật:** làm thủ công, tải bản zip mới lên và chọn thay thế plugin hiện có. Dữ liệu và cài đặt được giữ nguyên.

## Bước 1: thêm khóa mã hóa

Mật khẩu Mắt Bão, webhook Lark và số CCCD của khách được mã hóa bằng khóa đặt trong `wp-config.php`. Thêm dòng sau, phía trên dòng `/* That's all, stop editing! */`:

```php
define( 'EINVOICE_MATBAO_ENCRYPTION_KEY', '<chuỗi ngẫu nhiên, tối thiểu 32 ký tự>' );
```

Tạo chuỗi ngẫu nhiên bằng một trong các lệnh:

```sh
openssl rand -base64 48
wp eval 'echo wp_generate_password( 64, false );'
```

**Lưu ý:**

- Thiếu khóa thì plugin **không lưu** thông tin đăng nhập và hiện thông báo trong trang cài đặt. Lựa chọn xuất hóa đơn **cá nhân** ở checkout cũng bị tắt, vì không thể lưu CCCD an toàn.
- **Sao lưu khóa cùng với cơ sở dữ liệu.** Đổi hoặc mất khóa thì thông tin đăng nhập đã lưu và CCCD đã mã hóa không đọc được nữa: phải nhập lại thông tin đăng nhập, và các đơn chưa tạo nháp có CCCD sẽ báo lỗi.
- Không đưa khóa vào git, ảnh chụp màn hình hay tin nhắn.

## Bước 2: cấu hình trên trang cài đặt

Mở **WooCommerce → Hóa đơn Mắt Bão**. Chỉ Administrator (quyền `manage_options`) thấy và dùng được trang này. Trang chia thành các tab:

| Tab | Nội dung |
|---|---|
| **Hóa đơn** (mở mặc định) | Tổng quan, danh sách hóa đơn, chi tiết, gửi lại |
| **Kết nối Mắt Bão** | Thông tin đăng nhập API và mẫu hóa đơn mặc định |
| **Xuất hóa đơn** | Mã tham chiếu, người mua vãng lai, đơn vị tính |
| **Cảnh báo Lark** | Webhook nhận cảnh báo |
| **Nâng cao** | Gỡ plugin |

Chuyển tab không tải lại trang; mỗi tab có đường dẫn riêng (`&tab=connection`, …) nên có thể đánh dấu trang. Mỗi phần có nút **Lưu** riêng.

### Kết nối Mắt Bão (tab Kết nối Mắt Bão)

| Trường | Nhập gì |
|---|---|
| Môi trường | **Demo (thử nghiệm)** để chạy thử, **Production (thật)** khi dùng chính thức |
| Mã số thuế | MST của đơn vị xuất hóa đơn |
| Tên đăng nhập, Mật khẩu | Thông tin đăng nhập API, lấy trong mục cấu hình API của trang quản lý hóa đơn Mắt Bão |

Bấm **Lưu kết nối**, rồi **Kiểm tra kết nối**. Mật khẩu đã lưu không hiển thị lại; để trống ô mật khẩu nếu không muốn đổi.

### Mẫu hóa đơn mặc định (tab Kết nối Mắt Bão)

Sau khi kết nối thành công, danh sách mẫu GTGT của năm hiện tại được tải từ Mắt Bão (kèm số hóa đơn còn lại). Chọn một **mẫu số / ký hiệu** rồi bấm **Lưu mẫu mặc định**.

**Qua năm mới:** mẫu của năm cũ không còn dùng được. Đơn hoàn thành sau đó báo lỗi *Mẫu hóa đơn hết hiệu lực* cho đến khi bạn chọn mẫu của năm mới. Hãy chọn mẫu mới ngay đầu năm.

### Mã tham chiếu và người mua vãng lai (tab Xuất hóa đơn)

- **Prefix mã tham chiếu (MTChieu):** chữ cái không dấu hoặc chữ số. Mã tham chiếu của mỗi hóa đơn là `prefix + WC + mã đơn`, tối đa 20 ký tự, ví dụ `SHOPWC1234`. Đặt prefix ngắn để còn chỗ cho mã đơn.
- **Tên người mua vãng lai (NMua_Ten):** ghi lên hóa đơn của khách không yêu cầu xuất hóa đơn. Để trống thì dùng *Khách hàng không lấy hóa đơn*.

### Đơn vị tính (tab Xuất hóa đơn)

**Đơn vị tính mặc định (DVTinh)**, tối đa 50 ký tự, mặc định là *Gói*. Phí và phí vận chuyển luôn dùng đơn vị mặc định.

Muốn đặt riêng cho một sản phẩm, mở trang sửa sản phẩm, điền ô **Đơn vị tính hóa đơn**. Biến thể không đặt riêng thì dùng của sản phẩm cha. Sửa nhanh, sửa hàng loạt và nhập CSV chưa hỗ trợ trường này (giá trị đã có được giữ nguyên).

### Cảnh báo Lark (tab Cảnh báo Lark, tùy chọn)

Tạo **custom bot** trong group Lark nhận cảnh báo, dán **Webhook URL** và **Secret ký** (nếu bot bật ký), bấm **Lưu webhook** rồi **Gửi tin nhắn kiểm tra**. Webhook và secret được lưu mã hóa.

Tin Lark chỉ gồm mã đơn, loại lỗi, **Cách xử lý** (hướng dẫn cụ thể cho loại lỗi đó) và đường dẫn tới đơn trong wp-admin, không có thông tin cá nhân người mua.

### Chạy thử trước khi dùng thật

1. Chọn môi trường **Demo**, nhập thông tin đăng nhập demo của Mắt Bão, chọn mẫu demo.
2. Đặt một đơn thử và chuyển sang **Hoàn thành**.
3. Sau khoảng một phút (khi WP-Cron chạy), mở đơn và xem hộp **Hóa đơn Mắt Bão** báo *Đã tạo nháp*.
4. Kiểm tra nháp trên trang demo của Mắt Bão, rồi chuyển sang **Production** và chọn mẫu thật.

## Checkout: khách yêu cầu xuất hóa đơn

Dưới phần thông tin thanh toán có ô **Yêu cầu xuất hóa đơn**. Không tick thì đơn vẫn được tạo nháp với **người mua vãng lai**, kể cả khi khách đã điền tên trong thông tin thanh toán.

Khi tick, khách chọn **Cá nhân** hoặc **Doanh nghiệp**:

| Loại | Cần có |
|---|---|
| Cá nhân | Họ tên và số điện thoại (lấy từ thông tin thanh toán), **địa chỉ xuất hóa đơn**, **số CCCD** (đúng 12 chữ số) |
| Doanh nghiệp | **Tên doanh nghiệp**, **mã số thuế** (10 hoặc 13 chữ số, có thể có dấu `-` trước 3 số cuối), **địa chỉ xuất hóa đơn**, số điện thoại và email (lấy từ thông tin thanh toán) |

Dữ liệu sai được báo ngay trên trình duyệt và kiểm tra lại ở máy chủ. Plugin chỉ kiểm tra **định dạng**, không xác minh danh tính hay tra cứu MST.

**Checkout Blocks:** plugin có block *Yêu cầu xuất hóa đơn*, tự chèn sau phần thông tin liên hệ. Nếu trang Thanh toán đã được tùy biến trước khi bật plugin, hoặc block không hiện ở trang ngoài, hãy mở trang Thanh toán trong trình soạn thảo, thêm block **Yêu cầu xuất hóa đơn** rồi lưu.

## Quy trình hằng ngày

### Plugin tạo nháp thế nào

Khi đơn chuyển sang **Hoàn thành**, plugin:

1. Chụp lại thông tin người mua tại thời điểm đó (*snapshot*). Mọi lần gửi đều dùng bản chụp này.
2. Kiểm tra dữ liệu: tiền VND nguyên đồng, thuế suất hợp lệ, tổng các dòng khớp tổng đơn, mẫu hóa đơn còn hiệu lực.
3. Gửi tạo nháp lên Mắt Bão ở chế độ nền.

Nội dung hóa đơn:

- Mỗi sản phẩm là một dòng, với **giá sau giảm** (hóa đơn không hiện giá gốc hay % chiết khấu). Phí và vận chuyển là dòng riêng; dòng phí 0 đồng bị bỏ.
- Thuế suất lấy từ thuế WooCommerce đã lưu trên từng dòng: **0%, 5%, 8%, 10%**, hoặc **KCT** (không chịu thuế) khi sản phẩm có trạng thái thuế *Không*. Thuế suất khác (ví dụ 7%) bị chặn.
- **Ngày lập** là ngày gửi thành công lên Mắt Bão.
- **Đơn 0 đồng** không được tạo nháp; hóa đơn hiện trạng thái *Bỏ qua* để admin kiểm tra.

### Xem trạng thái hóa đơn

- **Trên trang đơn hàng:** hộp **Hóa đơn Mắt Bão** hiện trạng thái, mã tham chiếu, mã hóa đơn Mắt Bão, mẫu/ký hiệu, loại lỗi và lý do.
- **Danh sách đơn hàng:** bộ lọc **Hóa đơn** để lọc đơn theo trạng thái hóa đơn.
- **WooCommerce → Hóa đơn Mắt Bão, tab Hóa đơn** (đường dẫn cũ *Danh sách hóa đơn* tự chuyển về đây):
  - Ô tổng quan: *Lỗi / cần xử lý*, *Đang chờ gửi*, *Đã tạo nháp* (tổng và hôm nay).
  - Lọc theo trạng thái, tìm theo mã đơn hoặc đầu mã tham chiếu.
  - Trang **Chi tiết**: lịch sử từng lần gửi, kết quả, thông điệp từ Mắt Bão. Số CCCD luôn được che, chỉ hiện 4 số cuối.

| Trạng thái | Ý nghĩa | Cần làm |
|---|---|---|
| Chờ gửi / Đang gửi | Đang xếp hàng hoặc đang gọi Mắt Bão; lỗi tạm thời đang chờ thử lại | Không cần, trừ khi kéo dài nhiều giờ (kiểm tra WP-Cron) |
| Đã tạo nháp | Nháp đã có trên Mắt Bão | Kế toán kiểm tra, ký, phát hành trên Mắt Bão |
| Lỗi | Dữ liệu sai hoặc Mắt Bão từ chối; không tự thử lại | Sửa nguyên nhân rồi **Gửi lại** |
| Lỗi cần xử lý | Hết lượt thử lại, hoặc tìm thấy hóa đơn không khớp trên Mắt Bão | Kiểm tra trên Mắt Bão, rồi **Gửi lại** nếu phù hợp |
| Chưa cấu hình | Thiếu kết nối, thiếu mẫu, mẫu hết hiệu lực hoặc bị từ chối | Sửa trang cài đặt rồi **Gửi lại** |
| Đã dừng | Đơn rời *Hoàn thành* hoặc được hoàn tiền trước khi gửi | Xem lại đơn; chuyển lại *Hoàn thành* để tạo tiếp |
| Bỏ qua | Đơn 0 đồng | Kiểm tra tay nếu cần hóa đơn |

### Tự động thử lại

Khi Mắt Bão không phản hồi, lỗi máy chủ hoặc báo trùng mã tham chiếu, plugin tự thử lại sau **1 phút, 5 phút, 15 phút, 1 giờ và 6 giờ**. Không có cảnh báo trong lúc chờ. Sau lần thứ năm vẫn lỗi, hóa đơn chuyển sang **Lỗi cần xử lý** và gửi cảnh báo.

Trước mỗi lần gửi lại sau một kết quả không rõ, plugin **tra cứu Mắt Bão theo mã tham chiếu**. Nếu nháp đã được tạo thì dùng nháp đó, không tạo thêm. Khoảng thời gian chờ là tối thiểu: WP-Cron chậm thì thử lại cũng chậm.

Mỗi 15 phút, plugin cũng tự gửi tiếp các hóa đơn bị kẹt (ví dụ máy chủ khởi động lại khi đang gửi).

### Gửi lại và kiểm tra trạng thái

Trên trang chi tiết hóa đơn, Administrator có:

- **Kiểm tra trạng thái:** tra cứu nháp trên Mắt Bão. Kết quả cho biết nháp còn, đã bị xóa, hoặc không còn ở trạng thái Nháp (đã phát hành hay xử lý trên Mắt Bão). Plugin không đồng bộ tự động.
- **Gửi lại:** dùng cho trạng thái *Lỗi*, *Lỗi cần xử lý*, *Chưa cấu hình*, *Đã dừng*. Gửi lại luôn tra cứu trước nên không tạo trùng.

Một số trường hợp cần xác nhận thêm:

- **Nháp đã bị xóa trên Mắt Bão:** plugin không tự tạo lại. Sau khi *Kiểm tra trạng thái* xác nhận nháp không còn, tick ô xác nhận để tạo lại.
- **Đã đổi mẫu mặc định khi một lần gửi trước chưa rõ kết quả** (lỗi *Mẫu hóa đơn đã đổi giữa các lần gửi*): kiểm tra trên Mắt Bão rằng **chưa có** hóa đơn nào với mẫu cũ, nhập lại mã tham chiếu để xác nhận, rồi gửi lại bằng mẫu hiện tại.
- **Thông tin người mua sai:** sửa thông tin trên đơn trước, gửi lại sẽ kiểm tra lại. Nếu vẫn sai, plugin báo tên trường cần sửa.

### Sửa đơn hoặc hoàn tiền

| Thời điểm | Plugin làm gì |
|---|---|
| **Trước khi có nháp**, Administrator sửa thông tin người mua | Chụp lại thông tin người mua (nếu hợp lệ) |
| **Trước khi gửi**, đơn rời *Hoàn thành*, bị hủy hoặc hoàn tiền toàn phần | Dừng, không tạo nháp |
| **Trước khi gửi**, hoàn tiền một phần | Dừng và cảnh báo |
| **Sau khi có nháp**, đổi số tiền, người mua hoặc hoàn tiền | Chỉ cảnh báo (*Số tiền đơn hàng đổi sau khi có nháp*, *Người mua đổi sau khi có nháp*, *Đơn hàng hoàn tiền sau khi có nháp*) |

**Plugin không bao giờ sửa, hủy hay tạo lại nháp đã có.** Kế toán tự điều chỉnh hoặc hủy trên Mắt Bão. Sửa dữ liệu trực tiếp trong cơ sở dữ liệu (không qua WooCommerce) thì plugin không phát hiện được.

## Cảnh báo

Khi một hóa đơn cần người xử lý, plugin:

- Hiện **thông báo trong wp-admin** cho Administrator (tối đa 10 cảnh báo, có đường dẫn tới đơn, bấm **Ẩn** để tắt).
- Gửi **tin Lark** nếu đã cấu hình. Lark lỗi mạng thì gửi lại tối đa 3 lần; lỗi Lark không ảnh hưởng việc tạo hóa đơn.

Mỗi lỗi chỉ cảnh báo **một lần** cho mỗi đơn. Sau mỗi lần bạn bấm *Gửi lại*, lỗi đó có thể cảnh báo lại.

Thông báo trong wp-admin cũng hiện dòng **Cách xử lý** cho từng đơn. Hướng dẫn này trùng với hướng dẫn trong hộp **Hóa đơn Mắt Bão** trên đơn và trang chi tiết hóa đơn, nên bạn có thể làm theo cùng một cách ở mọi nơi.

## Xử lý lỗi thường gặp

Cách xử lý đầy đủ của mỗi lỗi giống nhau ở mọi nơi: hộp **Hóa đơn Mắt Bão** trên đơn, trang chi tiết hóa đơn, tin Lark và Sentry (context `invoice.hint`). Bảng dưới đây tóm tắt.

| Lỗi hiển thị | Nguyên nhân | Cách xử lý |
|---|---|---|
| Chưa cấu hình kết nối Mắt Bão | Chưa lưu thông tin đăng nhập | Nhập ở trang cài đặt, rồi gửi lại |
| Chưa chọn mẫu hóa đơn | Chưa có mẫu mặc định | Chọn mẫu, rồi gửi lại |
| Mẫu hóa đơn hết hiệu lực | Mẫu của năm cũ hoặc hết số | Chọn mẫu khác của năm hiện tại, rồi gửi lại |
| Mắt Bão từ chối mẫu hóa đơn | Mẫu/ký hiệu không hợp lệ phía Mắt Bão | Kiểm tra mẫu trên Mắt Bão, chọn lại |
| Tiền tệ không phải VND | Cửa hàng hoặc đơn dùng tiền tệ khác | Plugin chỉ hỗ trợ VND |
| Số tiền không nguyên đồng | Có dòng tiền/thuế lẻ đồng | Sửa giá hoặc cấu hình thuế để ra số nguyên |
| Thuế suất không hỗ trợ / Thiếu thuế suất đã lưu | Thuế khác 0/5/8/10%, hoặc dòng không lưu thuế | Đổi thuế suất của dòng sang 0%, 5%, 8% hoặc 10%, rồi gửi lại. Nếu dòng chưa lưu thuế, bấm **Tính lại** trong đơn trước |
| Dòng hàng có hai thuế suất | Lớp thuế của sản phẩm sinh ra hai thuế suất cho một dòng | Kiểm tra lớp thuế của sản phẩm và thuế trên dòng trong đơn, sửa cho còn đúng một thuế suất, rồi gửi lại |
| Tổng tiền lệch tổng đơn hàng | Tổng các dòng không khớp tổng đơn | Kiểm tra phí, giảm giá, làm tròn của đơn |
| Mã tham chiếu quá dài | Prefix + `WC` + mã đơn vượt 20 ký tự. Mã của đơn này đã cố định nên không gửi lại được | Xuất hóa đơn tay trên Mắt Bão. Rút ngắn prefix ở tab **Xuất hóa đơn** để các đơn sau không bị lỗi này |
| Thông tin người mua không hợp lệ | Thông tin trên đơn sai định dạng khi hoàn thành | Sửa trên đơn, rồi gửi lại |
| Thiếu khóa mã hóa để đọc CCCD / Không giải mã được CCCD | Thiếu hoặc đã đổi `EINVOICE_MATBAO_ENCRYPTION_KEY` | Khôi phục đúng khóa cũ trong `wp-config.php` |
| Không kết nối được Mắt Bão / Mắt Bão tạm thời lỗi | Mạng hoặc Mắt Bão gián đoạn | Plugin tự thử lại; hết lượt thì gửi lại thủ công |
| Mắt Bão từ chối hóa đơn | Mắt Bão trả lỗi nghiệp vụ hoặc đăng nhập | Xem thông điệp ở trang chi tiết, sửa rồi gửi lại |
| Hóa đơn tìm thấy không khớp đơn hàng | Trên Mắt Bão có hóa đơn cùng mã tham chiếu nhưng khác trạng thái/tổng tiền, hoặc có nhiều hóa đơn | Kiểm tra trực tiếp trên Mắt Bão, xử lý tay trước khi gửi lại |
| Không xếp được hàng đợi | Action Scheduler lỗi | Kiểm tra WP-Cron và hành động đã lên lịch (xem ngay dưới bảng) |

**Hóa đơn đứng mãi ở *Chờ gửi*:** kiểm tra WP-Cron có chạy không và mục **WooCommerce → Trạng thái → Hành động đã lên lịch** (*Status → Scheduled Actions*, nhóm `einvoice-matbao`) có hành động bị lỗi không.

## Dữ liệu cá nhân

- **CCCD** chỉ lưu dạng mã hóa trong dữ liệu đơn hàng. Danh sách, ghi chú đơn, nhật ký và cảnh báo chỉ hiện 4 số cuối (`********1234`). CCCD không bao giờ xuất hiện trong phản hồi REST API của WooCommerce.
- **Xuất dữ liệu cá nhân** (Công cụ → Xuất dữ liệu cá nhân): gồm loại người mua, tên, MST, địa chỉ, CCCD đã che, điện thoại, email, mã tham chiếu và trạng thái hóa đơn. File xuất không bao giờ có CCCD đầy đủ.
- **Xóa dữ liệu cá nhân** (Công cụ → Xóa dữ liệu cá nhân): xóa thông tin xuất hóa đơn trên đơn và tên người mua trong bảng hóa đơn. Bản ghi hóa đơn, mã tham chiếu, trạng thái và mã hóa đơn Mắt Bão được giữ cho kế toán. **Hóa đơn đã tạo trên Mắt Bão nằm ngoài plugin**, phải xử lý trên Mắt Bão. Nếu xóa khi hóa đơn còn chờ gửi, lần gửi sau sẽ lỗi thiếu thông tin người mua.

## Tắt và gỡ plugin

- **Tắt plugin:** hủy mọi tác vụ nền đang chờ; dữ liệu giữ nguyên. Bật lại thì hoạt động tiếp.
- **Gỡ (xóa) plugin:** luôn xóa thông tin đăng nhập Mắt Bão/Lark, bộ nhớ đệm và cảnh báo. Mặc định **giữ** bảng hóa đơn, lịch sử gửi, thông tin người mua trên đơn và cài đặt.
- **Xóa toàn bộ dữ liệu:** ở phần **Gỡ plugin** trong tab **Nâng cao**, bật tùy chọn và gõ `XOA HET DU LIEU` để xác nhận. Khi đó, xóa plugin sẽ xóa vĩnh viễn cả bảng hóa đơn, thông tin người mua trên đơn, đơn vị tính sản phẩm và cài đặt. Đơn hàng, sản phẩm WooCommerce và hóa đơn trên Mắt Bão không bị ảnh hưởng.

Nếu WooCommerce bị tắt hoặc gỡ, plugin tự tắt ở lần mở wp-admin tiếp theo và hiện thông báo.

## Giám sát lỗi (Sentry)

Tính năng này là tùy chọn, **mặc định tắt**. Khi bật, plugin gửi tới máy chủ **Sentry** do bạn chọn (tự host hoặc dịch vụ Sentry):

- **Lỗi (issues):** các exception mà plugin tự bắt được (plugin không cài trình xử lý warning/fatal PHP của site nên không gửi lỗi PHP khác), cùng cảnh báo "hóa đơn cần xử lý" (mức warning, mỗi đơn và mã lỗi một lần mỗi chu kỳ).
- **Hiệu năng (performance):** thời gian tạo nháp, gọi API Mắt Bão và tác vụ nền.
- **Log:** ví dụ mỗi lần đổi trạng thái hóa đơn.
- **Cron monitor:** báo khi tác vụ phục hồi hóa đơn kẹt không chạy đúng lịch.

### Bật giám sát

1. Lấy DSN của project Sentry: có dạng `https://<public_key>@<host>/<project_id>`.
2. Mở **WooCommerce → Hóa đơn Mắt Bão**, chọn tab **Giám sát lỗi**.
3. Bật ô **Bật gửi lỗi, hiệu năng và log lên máy chủ Sentry**, nhập **DSN** (ô này hiển thị dạng mật khẩu và không hiện lại DSN đã lưu; để trống khi muốn giữ DSN cũ).
4. Chỉnh **Tỉ lệ lấy mẫu hiệu năng** từ 0 đến 1 (mặc định 1 = ghi lại mọi giao dịch; site đông đơn nên giảm, ví dụ 0.2).
5. Bấm **Lưu cấu hình**, rồi bấm **Gửi event thử**. Thành công sẽ hiện ID event; trên Sentry, event có nội dung "einvoice-matbao test event". Thất bại thì kiểm tra lại DSN và kết nối mạng từ máy chủ WordPress.

DSN được lưu mã hóa bằng khóa `EINVOICE_MATBAO_ENCRYPTION_KEY`. Nếu đổi khóa, DSN đã lưu không đọc được và form sẽ yêu cầu nhập lại.

### Cấu hình qua wp-config.php (tùy chọn)

Hai hằng sau ghi đè giá trị trên trang cài đặt; khi có hằng, ô tương ứng trên trang chuyển sang chỉ đọc với nhãn "Đặt trong wp-config":

```php
define( 'EINVOICE_MATBAO_SENTRY_DSN', 'https://<public_key>@<host>/<project_id>' );
define( 'EINVOICE_MATBAO_SENTRY_ENABLED', true );
```

- `EINVOICE_MATBAO_SENTRY_DSN` chỉ được dùng khi là DSN hợp lệ; DSN sai bị bỏ qua và trang cài đặt hiện cảnh báo.
- **Đặt DSN trong hằng không tự bật giám sát.** Phải đặt `EINVOICE_MATBAO_SENTRY_ENABLED` là `true` hoặc bật công tắc trên trang.
- `EINVOICE_MATBAO_SENTRY_ENABLED` đặt là `false` sẽ tắt giám sát, dù công tắc trên trang đang bật.
- Giám sát cần extension PHP `mbstring`. Thiếu extension này thì giám sát tự tắt.

### Dữ liệu được gửi và không gửi

**Không bao giờ gửi:**

- Thông tin người mua: tên, công ty, email, điện thoại, địa chỉ, MST, CCCD. Giá trị người mua đã biết trong đơn được che trước khi gửi.
- Thông tin đăng nhập và token Mắt Bão, webhook Lark, DSN.
- Biến của stacktrace, cookie, header, request body, tên máy chủ.

**Event gửi lên Sentry cho cảnh báo hóa đơn** có tiêu đề `Hóa đơn cần xử lý: <nhãn> – Đơn #<mã đơn>` và các ngữ cảnh:

- `invoice`: mã đơn, ID hóa đơn, mã tham chiếu, trạng thái, mã lỗi, nhãn, cách xử lý, lý do lưu trên hóa đơn và đường dẫn admin tới đơn (chỉ path, không có host, nonce hay fragment);
- `line` (chỉ khi lỗi theo dòng): dòng hàng gây lỗi gồm **tên sản phẩm**, SKU, mã sản phẩm và mã thuế. Tên sản phẩm đi qua bộ lọc như mọi dữ liệu khác, nên tên trùng tên người mua bị che;
- `matbao` (chỉ khi Mắt Bão trả lỗi trong cùng request): loại lỗi, HTTP status, mã lỗi API và việc đã gửi tới Mắt Bão hay chưa.

**Được gửi (đã che theo mẫu):** email, URL (chỉ giữ host của Mắt Bão), dãy 12 chữ số (CCCD), MST (10 chữ số, có thể kèm `-XXX`), `Bearer <token>`, và chuỗi liền từ 32 ký tự trở lên có chữ số (gồm `A-Z a-z 0-9 + = _ -`) được thay bằng `[filtered]`. Tên hook, tên route và mã lỗi không bị che vì không chứa chữ số kiểu này.

**Tag được gửi (danh sách cố định):** `site`, `order_id`, `invoice.id`, `invoice.status`, `error_code`, `error_kind`, `matbao.env`, `component`, `product_id` (khi lỗi ở dòng sản phẩm), `matbao.api_code` (khi Mắt Bão trả mã lỗi). Tag khác bị bỏ.

### Nhiều cửa hàng dùng chung một project Sentry

Mỗi site đăng ký monitor riêng với slug `einvoice-matbao-recovery-<host16>-<hash8>`, trong đó `<host16>` là phần đầu host viết thường (tối đa 16 ký tự) và `<hash8>` là 8 ký tự băm của host. Mọi event có tag `site` là host của site, nên có thể lọc theo từng cửa hàng trên cùng một project.

### Khuyến nghị: dùng system cron thay WP-Cron

Cron monitor chỉ đúng khi tác vụ phục hồi chạy đúng 15 phút một lần. WP-Cron chạy theo lượt truy cập, nên trên site ít khách có thể bị báo "missed check-in" giả. Trên site ít truy cập, hãy tắt WP-Cron tự động (`define( 'DISABLE_WP_CRON', true );`) và đặt cron thật của hệ thống gọi `wp-cron.php` mỗi 5 đến 15 phút.

### Khi máy chủ Sentry không hoạt động

Plugin chờ tối đa 3 giây cho mỗi lần gửi (2 giây để kết nối). Nếu gửi lỗi, timeout hoặc Sentry trả lỗi HTTP, plugin ngừng gửi trong 60 giây; nếu Sentry trả `429` có `Retry-After`, plugin chờ theo giá trị đó, tối đa 600 giây. Trong thời gian ngừng, dữ liệu giám sát bị bỏ, không xếp hàng. Luồng tạo hóa đơn vẫn chạy bình thường.

## Câu hỏi thường gặp

**Plugin có tự ký và phát hành hóa đơn không?**
Không. Plugin chỉ tạo nháp; ký và phát hành luôn do kế toán làm trên Mắt Bão.

**Đơn cũ trước khi cài plugin có được tạo hóa đơn không?**
Không. Chỉ đơn chuyển sang *Hoàn thành* sau khi bật plugin.

**Chuyển đơn khỏi *Hoàn thành* rồi chuyển lại có tạo hóa đơn thứ hai không?**
Không. Mỗi đơn chỉ có một hóa đơn gốc. Nếu trước đó hóa đơn *Đã dừng*, plugin gửi tiếp chính hóa đơn đó.

**Khách gửi thông tin hóa đơn sai sau khi đã có nháp thì sao?**
Plugin chỉ cảnh báo. Kế toán sửa trên Mắt Bão.

**Có gửi hóa đơn cho khách không?**
Không. Bản hiện tại không hiển thị hay gửi nháp cho khách.

**Ai được thao tác?**
Chỉ Administrator được xem trang **Hóa đơn Mắt Bão** (cả danh sách hóa đơn lẫn cài đặt), gửi lại và kiểm tra trạng thái.
