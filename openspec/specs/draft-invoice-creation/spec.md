# draft-invoice-creation Specification

## Purpose

Tạo hóa đơn nháp Mắt Bão khi đơn hàng completed, gồm payload và lịch xử lý nền.

## Requirements

### Requirement: Fixed reference per order
Hệ thống SHALL gán cho mỗi đơn chuyển `completed` một `MTChieu` = prefix cấu hình + `WC` + order ID, chốt tại thời điểm completed và giữ nguyên cho mọi lần gửi sau.
Evidence: src/Invoice/DraftInvoiceWorkflow.php (on_order_completed), tests/integration/DraftInvoiceWorkflowTest.php:89, GLOSSARY.md

#### Scenario: Prefix changed after completion
- **WHEN** đơn completed khi prefix là `SHOP`, rồi prefix đổi thành `OTHER` trước khi tác vụ chạy
- **THEN** payload và bản ghi hóa đơn vẫn dùng `MTChieu` = `SHOPWC<order_id>`

### Requirement: One draft job per completed order
Hệ thống SHALL tạo đúng một bản ghi hóa đơn và xếp tối đa một tác vụ nền tạo nháp cho mỗi đơn, kể cả khi đơn completed nhiều lần hoặc hook completed chạy đồng thời (riêng đơn `stopped` completed lại bắt đầu chu kỳ gửi mới, xem invoice-retry-dedup).
Evidence: src/Invoice/DraftInvoiceWorkflow.php (on_order_completed), tests/integration/DraftInvoiceWorkflowTest.php:103,116

#### Scenario: Completed twice
- **WHEN** đơn được chuyển sang completed lần thứ hai
- **THEN** vẫn chỉ có một bản ghi hóa đơn và không có request create-invoice thứ hai

#### Scenario: Concurrent hooks
- **WHEN** hai hook completed cho cùng đơn chạy cùng lúc
- **THEN** chỉ một bản ghi hóa đơn và một tác vụ được xếp

### Requirement: Reference length limit
Hệ thống SHALL không gửi hóa đơn và SHALL ghi lỗi `reference_too_long` khi `MTChieu` dài hơn 20 ký tự.
Evidence: src/Invoice/DraftInvoiceWorkflow.php:122-129, src/Settings.php:24-32, tests/integration/BlockedDraftInvoiceTest.php:128-139

#### Scenario: Reference too long
- **WHEN** prefix + `WC` + order ID vượt 20 ký tự
- **THEN** bản ghi hóa đơn `failed` với `reference_too_long`, lý do nêu "20 ký tự" và không tác vụ nào được xếp

### Requirement: Skip zero-total orders
Hệ thống SHALL ghi bản ghi hóa đơn `skipped` kèm lý do nêu "0 đồng" và SHALL không gọi Mắt Bão khi tổng đơn bằng 0.
Evidence: src/Invoice/DraftInvoiceWorkflow.php (on_order_completed), tests/integration/DraftInvoiceWorkflowTest.php:148

#### Scenario: Free order
- **WHEN** đơn có tổng 0 chuyển sang completed
- **THEN** status `skipped`, lý do chứa "0 đồng", không có tác vụ và không có request create-invoice

### Requirement: Send once per invoice
Hệ thống SHALL chỉ cho một lần chạy gửi create-invoice cho mỗi bản ghi hóa đơn: chỉ bản ghi `pending` được chuyển sang `sending` và gửi; lần chạy trùng hoặc bản ghi đã `sending` không gửi gì.
Evidence: src/Invoice/DraftInvoiceWorkflow.php (send), tests/integration/DraftInvoiceWorkflowTest.php:135,561, tests/integration/DraftInvoiceRetryTest.php:366-377

#### Scenario: Action runs twice
- **WHEN** tác vụ tạo nháp chạy hai lần cho cùng đơn
- **THEN** chỉ có một request create-invoice và status `draft_created`

#### Scenario: Row already sending
- **WHEN** bản ghi đang `sending` và tác vụ chạy
- **THEN** không có request nào và status vẫn `sending`

### Requirement: Queue failure keeps invoice pending
Hệ thống SHALL giữ bản ghi ở `pending` kèm lý do khi không xếp được tác vụ vào hàng đợi lúc completed, để recovery sweep thử lại.
Evidence: src/Invoice/DraftInvoiceWorkflow.php (enqueue), tests/integration/DraftInvoiceWorkflowTest.php:576, tests/integration/BlockedDraftInvoiceTest.php:377

#### Scenario: Enqueue fails
- **WHEN** Action Scheduler không xếp được tác vụ khi đơn completed
- **THEN** status `pending`, lý do không rỗng và không có alert

### Requirement: Draft payload header
Hệ thống SHALL gửi create-invoice dưới dạng hóa đơn nháp GTGT bằng VND, có `MTChieu` của bản ghi, mẫu số/ký hiệu của mẫu mặc định và ngày lập là ngày gửi theo giờ Việt Nam, và SHALL không gửi `MaTraCuu`.
Evidence: src/Invoice/DraftPayloadBuilder.php (build), tests/integration/DraftInvoiceWorkflowTest.php:24-87 (không assert `HTTToan`, `LoaiTraHang`: xem docs/research/demo-payloads.json)

#### Scenario: Header fields
- **WHEN** tác vụ gửi một đơn hợp lệ
- **THEN** payload có `LoaiHDon=0`, `KTraMTChieuTrung=1`, `TCHDon=0`, `DVTTe=704`, `TGia=1`, `HTTToan="CK"`, `TTCKTMai=0`, `TGTKhac=0`, `KHMSHDon`/`KHHDon` của mẫu mặc định, `NLap` dạng `YYYY-MM-DDT00:00:00` theo Asia/Ho_Chi_Minh, và không có `MaTraCuu`

### Requirement: Invoice lines from stored order amounts
Hệ thống SHALL dựng mỗi dòng hàng từ số tiền và thuế WooCommerce đã lưu (không tính lại), theo giá sau giảm giá và không có dòng chiết khấu; phí và vận chuyển là dòng riêng số lượng 1.
Evidence: src/Invoice/DraftPayloadBuilder.php (line), tests/integration/DraftInvoiceWorkflowTest.php:24-87,162,190,213 (bỏ `MHHDVu` khi SKU rỗng và `SLuong=1` cho phí/vận chuyển: chỉ code, DraftPayloadBuilder.php:147-152,189-191)

#### Scenario: Coupon
- **WHEN** đơn 3 × 200000 áp coupon giảm 10% với thuế 10%
- **THEN** dòng có `DGia=180000`, `ThTien=ThTienChuaCK=540000`, `TLCKhau=0`, `STCKhau=0`, `TThue=54000`, và `TgTTTBSo=594000`

#### Scenario: Line fields
- **WHEN** dòng sản phẩm có SKU
- **THEN** dòng có `TChat=1`, `STT` tuần tự, `MHHDVu` = SKU (bỏ khi SKU rỗng), `THHDVu` = tên, `SLuong`, `DGia` = ThTien/SLuong và `TgTien` = ThTien + TThue

#### Scenario: Fee and shipping
- **WHEN** đơn có sản phẩm, phí 20000 và vận chuyển 30000
- **THEN** payload có 3 dòng theo thứ tự đó và tổng khớp tổng đơn

### Requirement: Zero-value lines
Hệ thống SHALL bỏ dòng phí và vận chuyển 0 đồng, và SHALL vẫn gửi dòng sản phẩm 0 đồng trong đơn có tổng khác 0.
Evidence: src/Invoice/DraftPayloadBuilder.php (line), tests/integration/DraftInvoiceWorkflowTest.php:413,432,449,469

#### Scenario: Free shipping
- **WHEN** đơn có dòng vận chuyển miễn phí hoặc dòng phí (fee) 0 đồng
- **THEN** các dòng đó không có trong `DSHHDVu`

#### Scenario: Free product
- **WHEN** đơn có một sản phẩm 0 đồng cùng sản phẩm có tiền
- **THEN** sản phẩm 0 đồng vẫn có dòng trong payload

### Requirement: VAT rate mapping
Hệ thống SHALL ánh xạ thuế suất đã lưu trên dòng sang `TSuat` thuộc {0, 5, 8, 10}, dùng `-1` (KCT) cho dòng không chịu thuế, và SHALL chặn gửi với lỗi dữ liệu khi gặp thuế suất khác.
Evidence: src/Invoice/DraftPayloadBuilder.php (rate), tests/integration/DraftInvoiceWorkflowTest.php:190,242,497

#### Scenario: Mixed rates
- **WHEN** đơn có hàng 10%, 5%, 8%, 0% và hàng không chịu thuế
- **THEN** `TSuat` lần lượt 10, 5, 8, 0, -1 và `TgTThue` bằng tổng thuế các dòng

#### Scenario: Unsupported rate
- **WHEN** một dòng có thuế suất 7%
- **THEN** không gửi create-invoice, status `failed`, lý do nêu 7 và không có attempt

### Requirement: Payload must match the order
Hệ thống SHALL chặn gửi với lỗi dữ liệu khi đơn không dùng VND, có số tiền không nguyên đồng, không có dòng nào, hoặc tổng các dòng (kể cả thuế) khác tổng đơn.
Evidence: src/Invoice/DraftPayloadBuilder.php (build), tests/integration/DraftInvoiceWorkflowTest.php:256,269,511,542, tests/integration/BlockedDraftInvoiceTest.php:93-127,293

#### Scenario: Non-VND
- **WHEN** đơn dùng USD
- **THEN** không có request create-invoice và status `failed`

#### Scenario: Fractional amounts
- **WHEN** một dòng có tiền hàng hoặc thuế không phải số nguyên đồng
- **THEN** status `failed` và lý do chứa "số nguyên"

#### Scenario: Totals mismatch
- **WHEN** tổng đơn khác tổng các dòng
- **THEN** không gửi và status `failed`

### Requirement: Amount in words
Hệ thống SHALL điền `TgTTTBChu` bằng tổng thanh toán đọc bằng tiếng Việt, viết hoa chữ đầu và kết thúc bằng "đồng".
Evidence: src/Invoice/AmountInWords.php, tests/integration/AmountInWordsTest.php:23-55

#### Scenario: Reading rules
- **WHEN** số tiền là 0, 11, 21, 101, 115000, 2005021 hoặc 2000500000
- **THEN** kết quả lần lượt là "Không đồng", "Mười một đồng", "Hai mươi mốt đồng", "Một trăm linh một đồng", "Một trăm mười lăm nghìn đồng", "Hai triệu không trăm linh năm nghìn không trăm hai mươi mốt đồng", "Hai tỷ năm trăm nghìn đồng"

### Requirement: Unit of measure resolution
Hệ thống SHALL đặt `DVTinh` của dòng sản phẩm theo thứ tự: đơn vị riêng của sản phẩm (biến thể kế thừa sản phẩm cha), rồi đơn vị mặc định của cửa hàng, rồi "Gói"; dòng phí và vận chuyển dùng đơn vị mặc định; giá trị đọc lúc dựng payload.
Evidence: src/Invoice/DraftPayloadBuilder.php (line), tests/integration/DraftInvoiceUnitOfMeasureTest.php:24-135

#### Scenario: Override and default
- **WHEN** mặc định là "Cái", sản phẩm A có đơn vị "Hộp", sản phẩm B không có
- **THEN** `DVTinh` lần lượt là "Hộp" và "Cái"

#### Scenario: Unset default
- **WHEN** cửa hàng chưa cấu hình đơn vị mặc định và sản phẩm không có đơn vị riêng
- **THEN** `DVTinh` là "Gói"

#### Scenario: Changed before send
- **WHEN** đơn vị mặc định đổi sang "Cái" sau khi đơn completed nhưng trước khi tác vụ chạy
- **THEN** payload dùng "Cái"

### Requirement: Configuration required before sending
Hệ thống SHALL không gọi create-invoice và SHALL đặt status `not_configured` khi lúc gửi chưa có credential Mắt Bão, chưa chọn mẫu mặc định, hoặc mẫu mặc định không còn trong danh sách mẫu VAT còn số của năm lập (khi đó chỉ có request lấy danh sách mẫu).
Evidence: src/Invoice/DraftInvoiceWorkflow.php (send_claimed, template_available), tests/integration/DraftInvoiceWorkflowTest.php:282,293, tests/integration/BlockedDraftInvoiceTest.php:141,156,181,192

#### Scenario: Missing configuration
- **WHEN** chưa có credential Mắt Bão hoặc chưa chọn mẫu mặc định
- **THEN** không có HTTP request tới Mắt Bão và status `not_configured`

#### Scenario: Template no longer offered
- **WHEN** mẫu mặc định không có trong danh sách mẫu của năm lập hoặc đã hết số
- **THEN** không có request create-invoice, status `not_configured` với `template_unavailable`, lý do nêu mẫu và không tác vụ nào được xếp

### Requirement: Successful draft recorded
Hệ thống SHALL đặt bản ghi `draft_created` kèm mã hóa đơn Mắt Bão, ngày lập và mẫu đã gửi khi create-invoice thành công; khi thất bại, status theo loại lỗi (xem invoice-retry-dedup).
Evidence: src/Invoice/DraftInvoiceWorkflow.php (mark_created, fail), tests/integration/DraftInvoiceWorkflowTest.php:24-87,305,338 (mẫu lưu lúc thành công: chỉ code mark_created; DraftInvoiceRetryTest.php:672 cho lần gửi đầu)

#### Scenario: Draft created
- **WHEN** Mắt Bão trả `maSoHDon` cho create-invoice
- **THEN** status `draft_created`, `matbao_invoice_id` là mã đó, `issued_on` là ngày gửi, và có một attempt `success`

#### Scenario: Rejected item in success envelope
- **WHEN** envelope `success=true` nhưng phần tử data có errorCode 306
- **THEN** status `failed`, lý do chứa thông điệp của Mắt Bão và attempt có `api_code=306`

### Requirement: Only orders completed while active
Hệ thống SHALL chỉ tạo hóa đơn nháp cho đơn chuyển `completed` trong lúc plugin đang hoạt động, và SHALL không quét đơn cũ khi kích hoạt.
Evidence: src/Invoice/DraftInvoiceWorkflow.php, tests/integration/DraftInvoiceWorkflowTest.php:400

#### Scenario: Activation
- **WHEN** đơn completed trước khi plugin được kích hoạt
- **THEN** không có bản ghi hóa đơn và không có tác vụ nào được xếp
