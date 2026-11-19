## ADDED Requirements

### Requirement: Invoice section on the customer order page
Hệ thống SHALL hiện khối "Hóa đơn điện tử" trong trang chi tiết đơn của My Account (endpoint `view-order`) cho mọi đơn có bản ghi hóa đơn, bất kể người mua có yêu cầu xuất hóa đơn hay không, khi người xem đã đăng nhập và là khách hàng của đơn. Hệ thống MUST NOT hiện khối khi đơn chưa có bản ghi hóa đơn, khi người xem không phải chủ đơn (kể cả đơn guest có customer ID 0), và MUST NOT hiện khối ở trang order-received hay email.

#### Scenario: Walk-in buyer order
- **WHEN** khách đăng nhập mở chi tiết một đơn không yêu cầu xuất hóa đơn, đơn đã có bản ghi hóa đơn `pending`
- **THEN** trang hiện khối "Hóa đơn điện tử" với trạng thái "Đang xuất hóa đơn"

#### Scenario: Order without invoice record
- **WHEN** khách mở chi tiết một đơn `processing` chưa có bản ghi hóa đơn
- **THEN** trang không có khối "Hóa đơn điện tử"

#### Scenario: Order received page
- **WHEN** trang order-received của một đơn có bản ghi hóa đơn được hiển thị
- **THEN** trang không có khối "Hóa đơn điện tử"

#### Scenario: Viewer is not the owner
- **WHEN** khối được render cho một đơn trong khi user hiện tại khác customer ID của đơn, hoặc đơn là đơn guest có customer ID 0
- **THEN** không có khối "Hóa đơn điện tử" và không có dữ liệu hóa đơn nào trong HTML

### Requirement: Customer-facing invoice status
Hệ thống SHALL hiển thị cho khách đúng một trạng thái thân thiện cho mỗi hóa đơn. Status plugin `skipped` SHALL hiện "Đơn hàng không cần xuất hóa đơn"; `pending` và `sending` SHALL hiện "Đang xuất hóa đơn"; mọi status còn lại ngoài `skipped`, `pending`, `sending` và `draft_created` (gồm `failed`, `needs_attention`, `not_configured`, `stopped` và giá trị lạ) SHALL hiện "Đang xử lý, cửa hàng sẽ liên hệ nếu cần". Hóa đơn `draft_created` theo requirement *Customer status of a created draft*.

#### Scenario: Skipped zero-total order
- **WHEN** hóa đơn của đơn có status `skipped`
- **THEN** khối hiện "Đơn hàng không cần xuất hóa đơn" và không có nút tải

#### Scenario: Failed invoice
- **WHEN** hóa đơn có status `needs_attention`
- **THEN** khối hiện "Đang xử lý, cửa hàng sẽ liên hệ nếu cần" và không có nút tải

#### Scenario: Every plugin status
- **WHEN** hóa đơn lần lượt có status `pending`, `sending`, `failed`, `needs_attention`, `not_configured`, `stopped`, `skipped` và một giá trị lạ
- **THEN** khối hiện lần lượt "Đang xuất hóa đơn", "Đang xuất hóa đơn", rồi "Đang xử lý, cửa hàng sẽ liên hệ nếu cần" cho bốn status kế tiếp, "Đơn hàng không cần xuất hóa đơn" và "Đang xử lý, cửa hàng sẽ liên hệ nếu cần", và không trường hợp nào có nút tải

### Requirement: Customer status of a created draft
Với hóa đơn `draft_created`, hệ thống SHALL hiện "Đang xử lý, cửa hàng sẽ liên hệ nếu cần" khi trạng thái Mắt Bão bất thường; "Hóa đơn đã được điều chỉnh, vui lòng liên hệ cửa hàng" khi ở "Đã cấp mã/tiếp nhận" với loại xóa bỏ (3) hoặc bị thay thế (5); "Đã xuất hóa đơn" khi có mã hóa đơn Mắt Bão và ở "Đã cấp mã/tiếp nhận" với loại khác hoặc chưa biết; và "Đang xuất hóa đơn" trong mọi trường hợp còn lại.

#### Scenario: Draft not yet issued
- **WHEN** hóa đơn `draft_created` có trạng thái Mắt Bão Nháp
- **THEN** khối hiện "Đang xuất hóa đơn" và không có nút tải

#### Scenario: Draft awaiting tax authority
- **WHEN** hóa đơn `draft_created` có trạng thái Mắt Bão "CQT chưa phản hồi"
- **THEN** khối hiện "Đang xuất hóa đơn"

#### Scenario: Sign error on Mat Bao
- **WHEN** hóa đơn `draft_created` có trạng thái Mắt Bão Lỗi ký
- **THEN** khối hiện "Đang xử lý, cửa hàng sẽ liên hệ nếu cần"

#### Scenario: Issued invoice
- **WHEN** hóa đơn `draft_created` có mã hóa đơn Mắt Bão, trạng thái Mắt Bão "Đã cấp mã/tiếp nhận" và loại "Hóa đơn mới"
- **THEN** khối hiện "Đã xuất hóa đơn" và nút tải PDF

#### Scenario: Cancelled invoice
- **WHEN** hóa đơn `draft_created` có trạng thái Mắt Bão "Đã cấp mã/tiếp nhận" và loại "Hóa đơn xóa bỏ"
- **THEN** khối hiện "Hóa đơn đã được điều chỉnh, vui lòng liên hệ cửa hàng" và không có nút tải

#### Scenario: Replaced invoice
- **WHEN** hóa đơn `draft_created` có trạng thái Mắt Bão "Đã cấp mã/tiếp nhận" và loại "Hóa đơn bị thay thế"
- **THEN** khối hiện "Hóa đơn đã được điều chỉnh, vui lòng liên hệ cửa hàng" và không có nút tải

#### Scenario: Every Mat Bao status of a created draft
- **WHEN** hóa đơn `draft_created` có mã hóa đơn Mắt Bão và trạng thái Mắt Bão lần lượt là chưa có, 0, 1, 2, 3, 5, 6, 7, 8, 9 và 12
- **THEN** khối hiện "Đang xử lý, cửa hàng sẽ liên hệ nếu cần" cho 0, 2 và 6, "Đang xuất hóa đơn" cho các giá trị còn lại, và không trường hợp nào có nút tải

#### Scenario: Issued with unknown type
- **WHEN** hóa đơn `draft_created` có mã hóa đơn Mắt Bão, ở "Đã cấp mã/tiếp nhận", chưa biết loại và chưa có mã tra cứu
- **THEN** khối hiện "Đã xuất hóa đơn" và nút tải PDF

#### Scenario: Issued without Mat Bao invoice ID
- **WHEN** hóa đơn `draft_created` ở "Đã cấp mã/tiếp nhận" loại "Hóa đơn mới" nhưng không có mã hóa đơn Mắt Bão
- **THEN** khối hiện "Đang xuất hóa đơn" và không có nút tải

### Requirement: Customer view hides staff details
Khối hóa đơn của khách MUST NOT chứa mã lỗi, lý do lỗi, `MTChieu`, mã tra cứu, mã hóa đơn Mắt Bão, MCCQT, ghi chú CQT, tên trạng thái do Mắt Bão trả, lịch sử attempt hay nút thao tác của staff. Khi trạng thái là "Đã xuất hóa đơn", khối SHALL hiện số hóa đơn, mẫu số và ký hiệu, và ngày lập khi có.

#### Scenario: Error details hidden
- **WHEN** hóa đơn `needs_attention` có lý do "Thiếu mã số thuế người mua" và mã lỗi `buyer_tax_code_missing`
- **THEN** khối không chứa lý do, mã lỗi hay `MTChieu` của hóa đơn

#### Scenario: Issued details shown
- **WHEN** hóa đơn đã xuất có số 125, mẫu `1`, ký hiệu `C26TAT`, ngày lập 2026-11-19
- **THEN** khối hiện số 125, ký hiệu `1C26TAT` và ngày lập, và không chứa MCCQT hay mã tra cứu

### Requirement: PDF download only by the order owner
Hệ thống SHALL chỉ trả PDF khi người yêu cầu đã đăng nhập, là khách hàng của đơn (customer ID của đơn bằng user hiện tại) và gửi nonce hợp lệ cho đơn đó. Mọi yêu cầu khác MUST NOT gọi Mắt Bão và SHALL nhận cùng một thông báo lỗi chung, không cho biết đơn có tồn tại hay không.

#### Scenario: Owner downloads
- **WHEN** chủ đơn đã đăng nhập bấm "Tải hóa đơn (PDF)" của hóa đơn đã xuất
- **THEN** trình duyệt nhận file PDF

#### Scenario: Another customer
- **WHEN** một khách đăng nhập khác mở link tải của đơn không thuộc họ
- **THEN** không có request nào tới Mắt Bão, không có file, và khách thấy thông báo lỗi chung

#### Scenario: Logged out
- **WHEN** người chưa đăng nhập mở link tải
- **THEN** không có request nào tới Mắt Bão và người đó được chuyển tới trang đăng nhập My Account

#### Scenario: Missing nonce
- **WHEN** chủ đơn mở link tải không có nonce hoặc nonce sai
- **THEN** không có request nào tới Mắt Bão và khách thấy thông báo lỗi chung

#### Scenario: Nonce of another order
- **WHEN** chủ của hai đơn A và B dùng nonce của đơn A trên link tải của đơn B
- **THEN** không có request nào tới Mắt Bão và khách thấy thông báo lỗi chung

#### Scenario: Error returns to a clean order page
- **WHEN** một yêu cầu tải của chủ đơn đã đăng nhập thất bại vì bất kỳ lý do nào
- **THEN** khách được chuyển về trang chi tiết đơn không còn tham số tải hay nonce trên URL, kèm thông báo lỗi chung

### Requirement: PDF only for a still-valid issued invoice
Trước mọi lời gọi Mắt Bão, hệ thống MUST từ chối khi trạng thái cho khách không phải "Đã xuất hóa đơn". Sau đó hệ thống SHALL tra cứu lại một lần, ghi kết quả như kiểm tra trạng thái, và chỉ lấy PDF khi tra cứu xác nhận dòng khớp mã hóa đơn đã lưu và bản ghi đọc lại vẫn "Đã xuất hóa đơn", cùng mã hóa đơn, có mã tra cứu. Trạng thái đã lưu MUST NOT thay cho xác nhận đó.

#### Scenario: Cancelled after issue
- **WHEN** hóa đơn đã lưu ở "Đã cấp mã/tiếp nhận" loại "Hóa đơn mới", và tra cứu lại lúc tải trả loại "Hóa đơn xóa bỏ"
- **THEN** không có request `download-invoice`, khách thấy thông báo lỗi chung, và bản ghi lưu loại "Hóa đơn xóa bỏ"

#### Scenario: Lookup fails at download
- **WHEN** tra cứu lại lúc tải trả HTTP 503
- **THEN** không có request `download-invoice` và khách thấy thông báo lỗi chung

#### Scenario: Lookup does not confirm the invoice
- **WHEN** tra cứu lại lúc tải trả thành công nhưng lần lượt: không liệt kê `MTChieu` của hóa đơn ở đâu cả, trả dòng cùng `MTChieu` với `maSoHDon` khác, hoặc trả dòng không đọc được
- **THEN** trong cả ba trường hợp không có request `download-invoice`, không có file và khách thấy thông báo lỗi chung

#### Scenario: Deleted on Mat Bao at download
- **WHEN** tra cứu lại lúc tải trả errorCode 301
- **THEN** không có request `download-invoice`, bản ghi có trạng thái Mắt Bão "Đã xóa trên Mắt Bão" và không còn mã tra cứu

#### Scenario: Issued before this feature
- **WHEN** hóa đơn đã xuất chưa có mã tra cứu lưu sẵn và tra cứu lại trả mã tra cứu "FM80520C49"
- **THEN** request `download-invoice` gửi `MaTraCuu` "FM80520C49" cùng mã hóa đơn Mắt Bão đã lưu

#### Scenario: Draft link forged
- **WHEN** chủ đơn gửi yêu cầu tải với nonce hợp lệ cho một hóa đơn đang ở Nháp
- **THEN** không có request nào tới Mắt Bão và khách thấy thông báo lỗi chung

#### Scenario: Non-issued invoices never reach Mat Bao
- **WHEN** chủ đơn gửi yêu cầu tải với nonce hợp lệ cho hóa đơn lần lượt `pending`, `sending`, `needs_attention`, `stopped`, `skipped`, và `draft_created` đã điều chỉnh (Đã cấp mã, loại xóa bỏ)
- **THEN** không trường hợp nào có request tới Mắt Bão và khách thấy thông báo lỗi chung

#### Scenario: Still no lookup code after the check
- **WHEN** tra cứu lại lúc tải xác nhận dòng khớp "Đã cấp mã/tiếp nhận" nhưng dòng không có `maTraCuu`
- **THEN** không có request `download-invoice` và khách thấy thông báo lỗi chung

#### Scenario: Invoice ID changed during the check
- **WHEN** trong lúc tra cứu lại, bản ghi được ghi một mã hóa đơn Mắt Bão khác
- **THEN** không có request `download-invoice` và khách thấy thông báo lỗi chung

### Requirement: PDF served without storing it
Hệ thống SHALL trả PDF dạng file đính kèm (`application/pdf`, tên file an toàn chứa ký hiệu và số hóa đơn, không cache, `nosniff`, `Content-Length` đúng). Hệ thống MUST NOT lưu PDF vào file, database hay cache bền vững, và MUST NOT ghi PDF, base64, mã tra cứu hay body phản hồi Mắt Bão vào log hoặc monitoring. Khi Mắt Bão lỗi hoặc trả nội dung không phải PDF, hệ thống SHALL hiện thông báo lỗi chung và ghi log cảnh báo.

#### Scenario: Served headers
- **WHEN** chủ đơn tải hóa đơn số 125 ký hiệu `1C26TAT`
- **THEN** phản hồi có `Content-Type: application/pdf`, `Content-Disposition` attachment với tên file chứa `1C26TAT` và `125`, header chống cache, `X-Content-Type-Options: nosniff` và `Content-Length` bằng số byte của file

#### Scenario: Unsafe characters in the file name
- **WHEN** ký hiệu hóa đơn Mắt Bão trả về chứa `"`, `/` hoặc ký tự có dấu
- **THEN** tên file trong `Content-Disposition` chỉ còn chữ, số ASCII và `-`

#### Scenario: Not a PDF
- **WHEN** `download-invoice` trả thành công nhưng `data_PDF_Base64` không giải mã ra file PDF
- **THEN** khách thấy thông báo lỗi chung, không có file, và một log cảnh báo được ghi

#### Scenario: Nothing stored
- **WHEN** chủ đơn tải PDF thành công
- **THEN** không có file mới trong thư mục uploads hay thư mục tạm, không có option hay transient mới chứa nội dung PDF, và bản ghi hóa đơn không chứa nội dung PDF

#### Scenario: Nothing sensitive logged
- **WHEN** một lần tải thành công và một lần tải bị Mắt Bão từ chối được thực hiện với monitoring bật
- **THEN** không log hay span nào chứa nội dung PDF, chuỗi base64, mã tra cứu hay body phản hồi Mắt Bão
