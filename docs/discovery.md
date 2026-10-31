# Khảo sát plugin hóa đơn Mắt Bão

Ghi nhận quyết định người dùng trong phiên khảo sát. Đây chưa phải spec được duyệt; chưa triển khai code.

## Phạm vi đã chốt

- Target: WordPress 7, WooCommerce 11, PHP 8.5. Chưa xác minh release cụ thể hoặc chạy compatibility test.
- Một đơn vị xuất hóa đơn.
- Mọi đơn chuyển sang `completed` sau khi bật plugin thuộc phạm vi; không tự động quét đơn cũ.
- Chỉ tạo nháp hóa đơn GTGT. Kế toán kiểm tra và ký/phát hành trên Mắt Bão.
- Dịch vụ/sản phẩm số, VND.
- Dùng tiền và thuế WooCommerce đã lưu trên từng dòng đơn, không tính lại theo cấu hình hiện tại.
- Billing cho cá nhân; bổ sung trường hóa đơn doanh nghiệp ở checkout và trang admin đơn.
- Thiếu hoặc sai dữ liệu: chặn tạo nháp, báo admin; không đổi trạng thái completed.
- Một hóa đơn gốc mỗi đơn; đổi trạng thái rồi completed lại không tạo thêm.
- Refund: báo kế toán, xử lý tay; không tự hủy hoặc điều chỉnh.
- Retry background có giới hạn cho lỗi tạm thời; tra cứu trước khi gửi lại nếu kết quả chưa rõ. Lỗi dữ liệu không retry tự động.
- Trang chi tiết đơn hiển thị trạng thái, mã hóa đơn, lỗi, nút gửi lại; danh sách đơn có bộ lọc lỗi.
- Bản đầu không hiển thị hoặc gửi nháp cho khách.
- Đơn 0 đồng chuyển admin kiểm tra, không tự tạo nháp.
- Sửa tiền hoặc thông tin người mua sau khi có nháp: cảnh báo, kế toán xử lý tay; không tự cập nhật/tạo lại.
- Trước khi background job gửi, đơn rời completed hoặc bị hủy/refund: dừng và báo admin.
- Cảnh báo vận hành qua admin và Lark; không chọn email. Lark dùng custom bot webhook gửi vào group chỉ định.
- Webhook URL/secret là credential: không yêu cầu gửi trong chat, không ghi vào tài liệu hoặc log. Nội dung cảnh báo mặc định chỉ gồm mã đơn, loại cảnh báo và link admin; không chứa thông tin cá nhân người mua.
- Lỗi gửi cảnh báo Lark không được kích hoạt lại việc tạo hóa đơn.
- Hỗ trợ cả checkout Classic và Checkout Blocks.
- Đơn vị tính có giá trị mặc định toàn cửa hàng và override từng sản phẩm.
- Một mẫu/ký hiệu hóa đơn GTGT mặc định do admin chọn từ Mắt Bão.
- Chỉ Administrator được sửa thông tin hóa đơn, gửi lại và quản lý cấu hình/credential.

## Checkout tối giản và kết quả khảo sát field người mua

- Form cơ bản chỉ yêu cầu tên, số điện thoại và email.
- Checkbox yêu cầu xuất hóa đơn mở lựa chọn cá nhân/doanh nghiệp; không chọn thì xử lý vãng lai.
- Cá nhân tái sử dụng tên, số điện thoại và email; chỉ nhập thêm địa chỉ hóa đơn và CCCD theo yêu cầu nghiệp vụ đã chốt.
- Doanh nghiệp tái sử dụng số điện thoại và email; chỉ nhập thêm tên doanh nghiệp, MST và địa chỉ hóa đơn. Không bắt nhập lại tên người liên hệ.
- Các field cấu hình hóa đơn và dữ liệu tiền/thuế/dòng dịch vụ không trở thành field checkout.
- [Khảo sát tài liệu người mua](research/matbao-buyer-fields.md): bảy field người mua khảo sát không được đánh dấu Bắt buộc trong bảng định nghĩa create-invoice. Tài liệu chưa có required matrix cá nhân/doanh nghiệp/vãng lai; không đủ để khẳng định backend chấp nhận việc bỏ trống hoặc xác nhận mapping vãng lai.
- Parent đã kiểm tra trực tiếp các ô bảng trong XML của cả ba DOCX bằng parser chạy qua uv: bảy field người mua có ô Ràng buộc trống. Không suy rộng hàng địa chỉ nhập kho PXK sang người mua GTGT; không dùng example chứng từ TNCN để xác nhận mapping cá nhân GTGT.
- Đã thực hiện request demo ngày 14/10/2026: login/templates thành công; payload cá nhân và doanh nghiệp tạo được nháp, được tra cứu xác nhận trạng thái Nháp. Payload thiếu toàn bộ buyer fields có HTTPError chưa giữ status/body, hai lần tra cứu notFound; không retry POST này. Follow-up bằng hai fixture độc lập: thiếu buyer fields nhận HTTP 400 yêu cầu NMua_Ten; chỉ NMua_Ten tạo được nháp và tra cứu xác nhận Nháp. Demo cho phép bỏ MST/địa chỉ/CCCD/điện thoại/email/HVT trong payload này. Chưa xác nhận nhãn/hiển thị vãng lai hoặc điều kiện phát hành/production. Các yêu cầu CCCD/địa chỉ/MST đã chốt vẫn là yêu cầu nghiệp vụ; không tự bỏ chỉ vì bảng API không đánh dấu bắt buộc.
- Người dùng cho phép kiểm tra API và thử request nếu Mắt Bão cung cấp sandbox endpoint. Tài liệu có endpoint demo; cần xác minh tính chất môi trường trước khi thử tạo nháp. Không cho phép gọi production hoặc ký/phát hành.

## Evidence API đã đọc

Nguồn: `OneDrive_1_10-13-2026/HoaDonBanRa/API-Proxy-HDDT-v1.0.1.docx`, chuyển sang text bằng `textutil` để đọc.

- Mục I: có `create-invoice`, `sign-invoice`, `invoice-detail-batch` và callback hóa đơn.
- Mục II, tạo mới: `LoaiHDon=0` tạo nháp, `1` tạo và phát hành; có `KTraMTChieuTrung`; `MTChieu` tối đa 20 ký tự.
- Mục II, trạng thái: nháp, lỗi ký, chưa gửi CQT, đã cấp mã/tiếp nhận, CQT chưa phản hồi, không đủ điều kiện cấp mã/tiếp nhận.
- Mục III, mã lỗi: `300` nghĩa là nháp đã tạo nhưng ký thất bại; `304` trùng mã tra cứu; `305` trùng mã tham chiếu.
- Ví dụ request tạo mới không liệt kê đầy đủ các field được mô tả ở bảng định nghĩa. Cần đối chiếu Postman và các phiên bản tài liệu trước khi chốt API contract.
- Khảo sát tài liệu ban đầu không gọi API. Sau khi được người dùng cho phép, đã dùng credential demo trong process để kiểm tra login/templates và tạo nháp test; không lưu token/credential vào báo cáo, không gọi production, không ký/phát hành.

## Yêu cầu bổ sung về chống trùng và người mua

Các yêu cầu sau bổ sung hoặc điều chỉnh chính sách dữ liệu đã ghi ở trên; ranh giới nhóm người mua đang được làm rõ. “Xuất hóa đơn” vẫn nằm trong phạm vi tạo nháp, không tự ký/phát hành.

- Một order không được tạo hai hóa đơn gốc, kể cả retry, gửi lại thủ công, job đồng thời hoặc nhiều lần chuyển completed.
- Các attempt dùng cùng định danh nghiệp vụ; không đổi mã tham chiếu để né lỗi trùng. Timeout hoặc response chưa rõ phải đối soát, không tạo lại mù. Bảo đảm atomic chống trùng phía Mắt Bão chưa được xác minh; lookup notFound đơn lẻ không chứng minh request trước đã kết thúc.
- Khách không cung cấp thông tin xuất hóa đơn được xử lý dưới dạng người mua vãng lai. Điều này điều chỉnh chính sách chặn mọi trường hợp thiếu dữ liệu trước đó; cách phân biệt thiếu toàn bộ và nhập dở còn mở.
- Hỗ trợ người mua doanh nghiệp và người mua cá nhân có thông tin. Cá nhân có thông tin phải cung cấp số căn cước công dân theo yêu cầu nghiệp vụ người dùng.
- CCCD không xuất hiện trong Lark, log, order notes hoặc fixture dùng dữ liệu thật. Quyền truy cập, masking và retention cần chốt.
- Khách không chọn yêu cầu xuất hóa đơn được coi là không cung cấp thông tin xuất hóa đơn, dù có điền tên hoặc có billing. Nhóm này vẫn được tạo nháp vãng lai theo phạm vi mọi đơn completed đã chốt; checkbox không quyết định có tạo nháp hay không.
- Khách chọn yêu cầu xuất hóa đơn phải chọn cá nhân hoặc doanh nghiệp. Cá nhân bắt buộc họ tên, địa chỉ, CCCD và số điện thoại.
- Doanh nghiệp bắt buộc tên doanh nghiệp, MST, địa chỉ hóa đơn, số điện thoại và email. Tên người liên hệ không thuộc bộ trường bắt buộc đã chốt.
- Kiểm tra cấu trúc dữ liệu bằng JavaScript tại checkout; chọn yêu cầu xuất hóa đơn nhưng dữ liệu không hợp lệ thì báo lỗi ngay cho khách. Cần validation server-side tương ứng để ngăn bypass và xử lý đơn qua admin/API; đây là yêu cầu kỹ thuật bảo vệ validation, không phải xác minh danh tính thực tế.
- Chưa chốt giá trị payload cho người mua vãng lai; không dùng CCCD hoặc MST giả.

## Quyết định còn mở

- Xử lý đơn 0 đồng, dòng không chịu thuế, phí và coupon; đơn vị tính dịch vụ và quy tắc làm tròn.
- Sửa đơn sau khi đã tạo nháp; nháp bị xóa hoặc phát hành trực tiếp trên Mắt Bão.
- Lịch retry, điều kiện dừng và cách cảnh báo kế toán/admin.
- Dữ liệu cá nhân/doanh nghiệp bắt buộc, checkout classic/Blocks, HPOS.
- Chọn mẫu/ký hiệu hóa đơn, đổi năm, ngày lập khi retry qua ngày.
- Quyền thao tác, lưu credential, log đã che dữ liệu nhạy cảm, retention.
- Môi trường demo và dữ liệu thử; compatibility/E2E trên target stack.

Chỉ lập spec triển khai sau khi giải quyết các quyết định còn mở và người dùng xác nhận hiểu biết chung.

## Quyết định vòng grill 2 (chốt)

### Dữ liệu hóa đơn
- Người mua vãng lai: `NMua_Ten` = "Khách hàng không lấy hóa đơn" (admin sửa được), field khác để trống. Đã xác nhận hiển thị trên demo ngày 31/10/2026 (xem [kết quả](research/e2e-demo-results.md)).
- Tiền, thuế, fee, coupon lấy từ số liệu WooCommerce đã lưu trên từng dòng. Mỗi dòng gửi giá sau giảm; không tạo dòng chiết khấu (`ThTienChuaCK = ThTien`, `TLCKhau = 0`, `STCKhau = 0`). Fee là dòng riêng.
- `TSuat` suy từ rate WC đã lưu: 0, 5, 8, 10. `tax_status = none` thì `-1` (KCT). Rate khác (ví dụ 7%, 3.5%) thì chặn nháp và báo admin.
- Tổng dòng phải bằng `TgThTien`, `TgTThue`, `TgTTTBSo` và `order total` WC; lệch thì chặn nháp. Không tính lại hay điều chỉnh.
- Đơn 0 đồng: đánh dấu bỏ qua có lý do, không có nút tạo.
- Snapshot người mua chụp khi `completed`, gửi từ snapshot. Admin sửa trước khi nháp thành công thì chụp lại.
- Ngày lập là ngày gửi thành công. Mẫu/ký hiệu hết hiệu lực (ví dụ qua năm) thì chặn, admin chọn lại.

### Chống trùng và retry
- `MTChieu` = prefix cấu hình + `WC` + order ID, lưu cố định, ≤ 20 ký tự; vượt thì chặn.
- Timeout hoặc kết quả chưa rõ: tra cứu theo `MTChieu` trước, gửi lại cùng mã với `KTraMTChieuTrung=1`. Lỗi 305 thì tra cứu lại, không đổi mã.
- Retry 5 lần, backoff 1p/5p/15p/1h/6h, qua Action Scheduler của WooCommerce.
- Hết retry: báo admin và Lark một lần cho mỗi đơn và loại lỗi; admin gửi lại thủ công.
- Nháp bị xóa/phát hành trên Mắt Bão: nút "Kiểm tra trạng thái" cho Administrator, không đồng bộ tự động, không tự tạo lại.
- Đã xác minh ngày 31/10/2026 trên demo: Mắt Bão KHÔNG chặn trùng nguyên tử (5 request song song cùng MTChieu/mẫu tạo 2 nháp, 3 trả 305). Chống trùng do plugin (`UNIQUE(order_id)`, claim `pending -> sending`, tra cứu trước retry). Chi tiết: [kết quả](research/e2e-demo-results.md).

### Dữ liệu nhạy cảm
- CCCD trong order meta, mã hóa, mask 4 số cuối ở danh sách và notes, không vào log/Lark/fixture; có WP privacy exporter/eraser; giữ theo vòng đời đơn. Bảng hóa đơn chỉ giữ bản mask.
- Credential Mắt Bão và Lark lưu DB mã hóa. Khóa là constant trong `wp-config.php`; thiếu khóa thì từ chối lưu và báo admin.

### Tech stack và giao diện
- Trang quản lý và cài đặt: menu con WooCommerce, htmx + Tailwind scoped trong `#einv-app`, tắt preflight toàn cục.
- htmx gọi REST namespace riêng trả HTML fragment; `permission_callback` kiểm tra Administrator; nonce qua `X-WP-Nonce`.
- Tooling: bun + Tailwind CLI, htmx vendored (không CDN), Composer PSR-4, PHPUnit, PHPStan, PHPCS WordPress.
- Màn v1: danh sách hóa đơn (lọc, tìm theo đơn/`MTChieu`), chi tiết hóa đơn (trạng thái, attempt, lỗi), cài đặt, dashboard.
- Design: Stripe `DESIGN.md` (awesome-design-md) làm token gốc.
- Lưu trữ: bảng riêng `invoice` và `attempt` liên kết order; Action Scheduler dùng bản kèm WooCommerce.
- Uninstall: giữ dữ liệu hóa đơn mặc định; credential luôn xóa; có tùy chọn xóa hết cần xác nhận.
- UI tiếng Việt mặc định, có text domain.
- Verify: khai báo HPOS và Cart/Checkout Blocks, chỉ dùng WC CRUD; E2E Classic và Blocks với API demo trên WP 7 / WC 11 / PHP 8.5.

### Còn mở
- Chặn trùng nguyên tử phía Mắt Bão.
- Hiển thị người mua vãng lai trên Mắt Bão.
- `ThTienChuaCK`/`TLCKhau`/`STCKhau` với giá sau giảm: xác nhận trên API demo.
