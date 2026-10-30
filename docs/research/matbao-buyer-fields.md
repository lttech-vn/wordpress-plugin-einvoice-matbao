# Khảo sát field người mua của `create-invoice`

## Phạm vi

Đối chiếu 3 DOCX và 3 Postman collection trong `OneDrive_1_10-13-2026/HoaDonBanRa/`. DOCX được chuyển bằng `textutil -convert txt` sang `/tmp/matbao101.txt` và `/tmp/matbao102.txt`; không dump toàn văn ra stdout. Không gọi API, không dùng credential. Đây là mô tả tài liệu tích hợp, không phải kết luận pháp lý.

## Constraint trong DOCX

DOCX v1.0.1, mục II «Thông tin định nghĩa dữ liệu», mục «Thông tin API tạo mới hóa đơn: api/Invoice/create-invoice», bảng field người mua khoảng dòng 729–764. v1.0.2 có bảng tương ứng khoảng dòng 694–730. Bảy field dưới đây không có chữ “Bắt buộc” ở cột ràng buộc (khác các field có đánh dấu rõ ở bảng). Kiểu chuỗi.

| Field | Ý nghĩa | Tối đa | Ràng buộc/điều kiện đối tượng |
|---|---|---:|---|
| `NMua_Ten` | Tên khách hàng hoặc tên đơn vị mua hàng | 400 | Không đánh dấu bắt buộc; không có phân nhánh cá nhân/doanh nghiệp/vãng lai. |
| `NMua_HVTNMHang` | Họ và tên người mua hàng | 100 | Không đánh dấu; không thấy quy tắc cá nhân/vãng lai. |
| `NMua_MST` | Mã số thuế người mua hàng | 14 | Không đánh dấu; không có điều kiện rõ. |
| `NMua_DChi` | Địa chỉ người mua hàng | 400 | Không đánh dấu; không có điều kiện rõ. |
| `NMua_CCCDan` | Căn cước công dân (Số CC/CCCD/số định danh) | 12 | Không đánh dấu; không quy định cá nhân phải cung cấp. |
| `NMua_SDThoai` | Số điện thoại người mua hàng | 20 | Không đánh dấu; không có điều kiện rõ. |
| `NMua_DCTDTu` | Địa chỉ thư điện tử | 50 | Không đánh dấu; không có điều kiện rõ. |

DOCX v1.0.1: các định nghĩa/độ dài ở khoảng dòng 729–764; v1.0.2 tương ứng khoảng 694 trở đi. Riêng bảng v1.0.1 có đoạn trình bày lặp/ngắt dòng ở hậu tố (điện thoại/email/HVT/CCCD); v1.0.2 cùng schema cho các độ dài nêu trên. Không dùng các dòng schema JSON ban đầu chỉ có kiểu `string` làm bằng chứng bắt buộc.

## Request examples Postman (đối chiếu riêng constraint)

| Collection | Request/heading tiêu biểu | Những field buyer thể hiện và tín hiệu từ payload |
|---|---|---|
| `API HDDT - PROXY - DEMO.postman_collection.json` (thư mục gốc) | `create-invoice-dieu-chinh`, `create-invoice-thay-the` | Các field tên, MST, địa chỉ, điện thoại, email, HVT có giá trị ví dụ. Không kết luận required; request điều chỉnh/thay thế, không phải luồng tạo mới cơ bản. |
| `Demo/Demo - ToKhai01/API HDDT - PROXY - DEMO.postman_collection.json` | `create-invoice` | Các field tên/MST/địa chỉ/điện thoại/email/HVT có ví dụ; CCCD không có trong mẫu thường. `create-invoice (pxk-nội bộ)` và `(pxk-đại lý)` để MST, điện thoại, email, HVT là chuỗi rỗng; CCCD không gửi. `create-invoice (thu nhập cá nhân)` để `NMua_Ten` và MST rỗng nhưng có HVT, địa chỉ, CCCD, điện thoại, email. |
| `Demo/Demo - VeMayBay - HNH/API HDDT - PROXY.postman_collection_demo.json` | `create-invoice` | Mẫu chung có buyer fields phổ biến với placeholder. Các request PXK có các chuỗi rỗng như trên; TNCN có `NMua_Ten`/MST rỗng, HVT/CCCD có dữ liệu. Vé máy bay để MST và HVT rỗng, tên/địa chỉ/điện thoại/email có dữ liệu. |

Evidence path chính xác: hai DOCX ở `OneDrive_1_10-13-2026/HoaDonBanRa/API-Proxy-HDDT-v1.0.1.docx` và `.../Demo/Demo - ToKhai01/API-Proxy-HDDT-v1.0.2.docx`; DOCX thứ ba `.../Demo/Demo - VeMayBay - HNH/API-Proxy-HDDT-v1.0.1-demo.docx` là bản demo v1.0.1 và không cung cấp một bảng constraint khác được xác nhận. Ba Postman path đúng như bảng trên.

Collection gốc có các request named `create-invoice` không phải luồng chuẩn duy nhất; các request được dò có thể chứa ví dụ hoặc biến thể. Hai collection demo cùng lặp các nhóm request vé xe, PXK và chứng từ TNCN. Không thấy request ví dụ nào khẳng định required theo schema; payload có field không đồng nghĩa bắt buộc. Mẫu PXK và TNCN là loại chứng từ đặc thù, không dùng để suy rộng cho hóa đơn bán hàng thông thường.

## Kết luận cho form tối giản đề xuất

- **Base: tên + điện thoại + email:** phù hợp với phạm vi đề xuất của form, nhưng các tài liệu đã khảo sát **không xác nhận ba field này là bắt buộc**, cũng không nói rằng chúng đủ để tạo/phát hành hóa đơn.
- **Cá nhân bổ sung địa chỉ + CCCD:** ví dụ `create-invoice (thu nhập cá nhân)` có địa chỉ và CCCD; ví dụ vé máy bay có địa chỉ nhưng không gửi CCCD. Chưa có constraint xác nhận đây là điều kiện áp dụng chung cho cá nhân.
- **Doanh nghiệp bổ sung tên doanh nghiệp + MST + địa chỉ:** ý nghĩa `NMua_Ten` cho phép “tên đơn vị mua hàng”; ví dụ phổ biến có MST và địa chỉ. Không có điều kiện bắt buộc/validation theo loại doanh nghiệp được ghi nhận.
- **Vãng lai:** không tìm thấy hướng dẫn ánh xạ rõ ràng “vãng lai” sang field nào, cũng không có quy tắc bỏ trống/giá trị mặc định. Không thể xác nhận mapping vãng lai từ các tài liệu này.

## Mâu thuẫn, khoảng trống, rủi ro

1. Tên `NMua_Ten` và `NMua_HVTNMHang` có thể cùng chứa tên trong một số ví dụ, nhưng tài liệu chỉ định nghĩa riêng; không hướng dẫn cách chọn khi cá nhân, không quy tắc ưu tiên/đồng bộ.
2. Một số mẫu đặc thù để MST/điện thoại/email/HVT trống; mẫu vé máy bay để MST/HVT trống; điều này cho thấy payload examples không nhất quán theo loại chứng từ, không phải bằng chứng validate chấp nhận chung.
3. Không có required matrix theo loại người mua (cá nhân/doanh nghiệp/vãng lai), không có quy tắc CCCD-vs-hộ chiếu, không có validator/HTTP response hoặc API behavior được thử nghiệm.
4. Không khẳng định các trường rỗng được chấp nhận ở luồng create-invoice thông thường; chỉ ghi nhận chúng xuất hiện trong các ví dụ xác định.

## Kiểm chứng bảng DOCX gốc bởi parent

Parent dùng parser XML qua `uv run python` để đọc các hàng liên quan trong `word/document.xml` của cả ba DOCX. Lý do dùng parser: cần giữ ranh giới ô bảng mà `textutil` làm phẳng; chỉ xuất bảy field mục tiêu, không xuất credential.

Trong cả ba DOCX, hàng định nghĩa người mua của bảy field đều có ô Ràng buộc trống. Có một hàng `NMua_DChi` khác mang nghĩa “Nhập kho tại” với ràng buộc `(PXK)`; không áp dụng hàng đó cho địa chỉ người mua hóa đơn GTGT.

Citation chính: đường dẫn DOCX được liệt kê ở trên → heading «Thông tin định nghĩa dữ liệu» → «Thông tin API tạo mới hóa đơn» → hàng mang tên field. Số dòng text chuyển đổi chỉ là locator phụ. Kiểm chứng này xác nhận nội dung bảng, không xác nhận hành vi runtime API.

## Runtime demo ngày 14/10/2026

- Endpoint: `https://demo-api-hddt.matbao.in:11443`; TLS verification bật, redirect bị chặn. Không gọi production hoặc ký/phát hành.
- Login HTTP 200, `success=true`, `errorCode=200`. Response thực tế dùng camelCase; token ở `data.accessToken`, không phải chuỗi `Data` như mô tả tổng quát.
- Templates năm 2026 HTTP 200; mẫu GTGT `1 / C26TAT` có số lượng còn lại và khớp Postman tạo nháp.
- Postman `Demo/Demo - ToKhai01/API HDDT - PROXY - DEMO.postman_collection.json` → `create-invoice`: body array, `LoaiHDon=0` trong từng object; body có comment nên không phải JSON chuẩn.
- Payload trước gửi lưu ở `demo-payloads.json`, dùng dữ liệu test, ba mã tham chiếu riêng và `KTraMTChieuTrung=1`.
- Chỉ gửi case A không có buyer fields, mã `PB261014110755A`, một lần. POST phát sinh `HTTPError`; script ban đầu chưa giữ HTTP code/body lỗi nên chưa xác định lý do bị từ chối. Không gửi lại POST.
- Đối soát `invoice-detail-batch` cùng mã: HTTP 200, `success=false`, `errorCode=301`, `invoices=[]`, `notFound` chứa mã test. Một lookup notFound không đủ chứng minh POST không còn xử lý.
- Follow-up tra cứu A vẫn HTTP 200, errorCode 301/notFound; không gửi lại A.
- Case B cá nhân `PB261014110755B`: create HTTP 200; envelope và phần tử đều success=true/errorCode=200. Lookup xác nhận đúng mã, mẫu `1/C26TAT`, `maLoaiHDon=1`, `maTThaiHDon=1`, tên trạng thái Nháp, số hóa đơn 0, chưa có MCCQT.
- Case C doanh nghiệp `PB261014110755C`: kết quả tương tự B; lookup xác nhận Nháp. MST dùng tài khoản demo, không dùng dữ liệu khách thật.
- B/C chứng minh payload đầy đủ được demo chấp nhận; không chứng minh từng field là bắt buộc. Chưa xác nhận bộ field tối thiểu, mapping vãng lai hoặc chống trùng atomic. Không ký/phát hành, không xóa nháp.
- Kết quả lưu `demo-results.json`, download URLs được che. Payload test lưu `demo-payloads.json`; script follow-up lưu `demo-followup.py`, ghi attempted_at trước POST và không gửi lại mã đã attempted.
- Script probe cần cải thiện ghi HTTP error code/body đã lọc trước lần khảo sát tiếp; không được chạy lại tự động vì có plan/results guard.

## Kiểm tra tối thiểu field người mua trên demo

Hai fixture độc lập, không retry case A:

| Case | Mã tham chiếu | Kết quả |
|---|---|---|
| Không gửi buyer fields | `PB261014041950D` | HTTP 400; `errors["[0].NMua_Ten"]`: `The NMua_Ten field is required.` Lookup 301/notFound. |
| Chỉ gửi `NMua_Ten` | `PB261014042005D` | HTTP 200; envelope và phần tử success=true/errorCode=200. Lookup đúng mã và mẫu, maLoaiHDon=1/maTThaiHDon=1, trạng thái Nháp, shDon=0. |

**Runtime demo yêu cầu NMua_Ten**, dù bảng DOCX không đánh dấu. Trong payload GTGT nháp đã thử, MST, địa chỉ, CCCD, điện thoại, email và HVT được bỏ hoàn toàn mà vẫn tạo nháp thành công. Không suy ra quy tắc production/pháp lý hoặc điều kiện phát hành. Tên test chỉ dùng thử nghiệm, không phải nhãn vãng lai chính thức.

Lookup không trả chi tiết người mua nên chưa xác nhận cách hiển thị vãng lai trên PDF. Không tải PDF qua link bên thứ ba, không ký/phát hành hoặc xóa nháp. Các case không kiểm tra chống trùng atomic.

## Tổng kết

Trong các tài liệu được cung cấp, không field nào trong bảy field có dấu “Bắt buộc” tại bảng create-invoice. Độ dài/ý nghĩa có tài liệu; quy tắc điều kiện theo loại người mua và mapping vãng lai chưa xác nhận. Form tối giản nêu trong yêu cầu có thể là quyết định UX/product, nhưng chưa được chứng minh là contract API bởi nguồn đã khảo sát.
