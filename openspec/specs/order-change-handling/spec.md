# order-change-handling Specification

## Purpose

Xử lý khi đơn hàng thay đổi sau khi completed hoặc đã có draft.

## Requirements

### Requirement: Post-draft order change only warns
Hệ thống SHALL chỉ cảnh báo khi đơn đã có hóa đơn nháp bị đổi dòng hàng, tổng tiền, thuế, phí, giảm giá, người mua hoặc bị hoàn tiền: ghi mã cảnh báo loại `order` và giữ status `draft_created`; hệ thống MUST NOT cập nhật, hủy hay tạo lại nháp trên Mắt Bão.
Evidence: src/Invoice/OrderChangeWatcher.php:120-121,153-178, tests/integration/PostDraftOrderChangeTest.php:106-129, tests/e2e/army/order-changes.army.ts:16-31, docs/phat-trien.md:156-158

#### Scenario: Amounts edited after draft
- **WHEN** admin đổi tổng tiền đơn sau khi hóa đơn đã `draft_created`
- **THEN** hóa đơn có `error_code` = `order_amounts_changed`, status vẫn `draft_created`, mã hóa đơn Mắt Bão không đổi và không có request cập nhật, hủy hay tạo nào

### Requirement: Warning code priority
Hệ thống SHALL chọn đúng một mã cảnh báo cho mỗi thay đổi theo thứ tự ưu tiên: hoàn tiền (`order_refunded_after_draft`), rồi số tiền (`order_amounts_changed`), rồi người mua (`order_buyer_changed`).
Evidence: src/Invoice/OrderChangeWatcher.php:154-163, tests/integration/PostDraftOrderChangeTest.php:160-201

#### Scenario: Refund after draft
- **WHEN** đơn có nháp bị hoàn tiền một phần hoặc toàn bộ
- **THEN** có đúng một cảnh báo `order_refunded_after_draft` và status vẫn `draft_created`

#### Scenario: Buyer edit after draft
- **WHEN** billing của đơn bị sửa sau khi có nháp
- **THEN** có cảnh báo `order_buyer_changed` và snapshot người mua không đổi

### Requirement: Warning notifies once per change without personal data
Hệ thống SHALL thêm một ghi chú đơn không chứa dữ liệu cá nhân và phát hook `einvoice_matbao_invoice_needs_attention` đúng một lần cho mỗi thay đổi; lưu lại cùng thay đổi MUST NOT cảnh báo lại, còn thay đổi mới SHALL cảnh báo lại.
Evidence: src/Invoice/OrderChangeWatcher.php:169-177,226-231, tests/integration/PostDraftOrderChangeTest.php:106-157, tests/e2e/army/order-changes.army.ts:33-45

#### Scenario: Same change saved twice
- **WHEN** đơn được lưu lại nhiều lần mà không đổi thêm gì
- **THEN** hook chỉ phát một lần

#### Scenario: Second change
- **WHEN** đơn thay đổi lần nữa sau cảnh báo đầu
- **THEN** hook phát lần nữa với mã tương ứng

#### Scenario: Note without buyer data
- **WHEN** cảnh báo được ghi
- **THEN** ghi chú đơn nói hệ thống "không tự cập nhật hay hủy hóa đơn" và không chứa email hay tên người mua

### Requirement: Insignificant saves do not warn
Hệ thống SHALL NOT cảnh báo khi đơn được lưu mà số tiền, người mua và số tiền hoàn không đổi; đổi thứ tự dòng hoặc thêm khoảng trắng trong tên không tính là thay đổi, còn đổi tên dòng thật sự thì có.
Evidence: src/Invoice/OrderState.php:43-44,136-143, tests/integration/PostDraftOrderChangeTest.php:131-141,433-444

#### Scenario: Reorder lines
- **WHEN** các dòng hàng được sắp xếp lại hoặc tên dòng có thêm khoảng trắng
- **THEN** không có cảnh báo

#### Scenario: Line renamed
- **WHEN** tên một dòng hàng bị đổi thật sự
- **THEN** có cảnh báo thay đổi

### Requirement: Remembered order state is not readable
Hệ thống SHALL ghi nhớ trạng thái đơn chỉ dưới dạng digest HMAC không đọc ngược được cho số tiền và người mua, cùng tổng số tiền đã hoàn.
Evidence: src/Invoice/OrderState.php:14-18,67,132-134, docs/phat-trien.md:156-158, tests/integration/BuyerPersonalDataPrivacyTest.php:140-160

#### Scenario: Stored state
- **WHEN** trạng thái đơn được ghi nhớ trên bản ghi hóa đơn
- **THEN** giá trị lưu không chứa email, tên hay số điện thoại người mua

### Requirement: Changes checked once per request
Hệ thống SHALL kiểm tra thay đổi của mỗi đơn tối đa một lần ở cuối request khi đơn được lưu, sửa dòng hàng hoặc hoàn tiền; lỗi trong lúc kiểm tra MUST NOT làm hỏng việc lưu đơn.
Evidence: src/Invoice/OrderChangeWatcher.php:49-95, tests/e2e/army/order-changes.army.ts:47-58

#### Scenario: Several saves in one request
- **WHEN** một request lưu đơn nhiều lần
- **THEN** chỉ có một lần so sánh và tối đa một cảnh báo

#### Scenario: Check throws
- **WHEN** việc kiểm tra gặp exception
- **THEN** chỉ ghi error log tên lỗi và việc lưu đơn vẫn thành công

### Requirement: In-flight or legacy invoices are not warned
Hệ thống SHALL bỏ qua đơn chưa có hóa đơn hoặc hóa đơn đang `sending`, và với hóa đơn cũ chưa có trạng thái đơn ghi nhớ SHALL ghi nhớ trạng thái hiện tại mà không cảnh báo.
Evidence: src/Invoice/OrderChangeWatcher.php:102-118
Confidence: inferred

#### Scenario: Legacy invoice
- **WHEN** hóa đơn cũ chưa có trạng thái đơn ghi nhớ và đơn được lưu
- **THEN** trạng thái được ghi nhớ và không có cảnh báo

### Requirement: Changes during creation are warned after the draft exists
Hệ thống SHALL so sánh đơn với trạng thái đã nhớ ngay sau khi nháp được tạo hoặc nhận lại, và cảnh báo nếu đơn đã đổi trong lúc gọi tạo hoặc kể từ attempt trước.
Evidence: src/Invoice/OrderChangeWatcher.php:134-143, src/Invoice/DraftInvoiceWorkflow.php:664-678, tests/integration/PostDraftOrderChangeTest.php:223-262,391-430

#### Scenario: Refund while create in flight
- **WHEN** đơn bị hoàn tiền trong lúc request tạo nháp đang chạy
- **THEN** sau khi nháp có, cảnh báo `order_refunded_after_draft` được phát

#### Scenario: Customer edits buyer before draft
- **WHEN** khách sửa người mua trước khi nháp được tạo và nháp mang người mua cũ
- **THEN** nháp được tạo với người mua cũ và cảnh báo `order_buyer_changed` một lần

### Requirement: Admin buyer edit before draft updates the snapshot
Hệ thống SHALL chụp lại snapshot người mua (xem buyer-snapshot-privacy) khi Administrator sửa người mua trước khi có nháp và chưa attempt nào có thể đã tới Mắt Bão; sửa bởi người khác, hoặc sau khi attempt có thể đã tới Mắt Bão, SHALL giữ snapshot cũ.
Evidence: src/Invoice/OrderChangeWatcher.php:190-207, tests/integration/PostDraftOrderChangeTest.php:264-282,302-340, tests/e2e/army/order-changes.army.ts:60-76

#### Scenario: Admin edits before send
- **WHEN** admin sửa người mua khi tác vụ chưa gửi
- **THEN** lần gửi dùng người mua mới và không có cảnh báo

#### Scenario: Non-admin edit
- **WHEN** người không phải Administrator sửa người mua trước khi gửi
- **THEN** snapshot giữ nguyên

#### Scenario: Earlier attempt may have reached Mat Bao
- **WHEN** admin sửa người mua sau khi một attempt có thể đã tới Mắt Bão, rồi nháp được nhận lại
- **THEN** snapshot giữ nguyên và có cảnh báo `order_buyer_changed`

### Requirement: Invalid buyer edit before draft blocks the send
Hệ thống SHALL giữ snapshot cũ, đặt lỗi `buyer_invalid` cho hóa đơn đang chờ, phát hook một lần và không gọi create khi người mua sau khi sửa không còn hợp lệ.
Evidence: src/Invoice/OrderChangeWatcher.php:202-217, tests/integration/PostDraftOrderChangeTest.php:285-300

#### Scenario: Invalid buyer
- **WHEN** admin sửa người mua thành dữ liệu không hợp lệ trước khi gửi
- **THEN** hóa đơn có `buyer_invalid`, không có request create và snapshot cũ còn nguyên

### Requirement: Order leaves completed or is refunded before sending
Hệ thống SHALL dừng gửi với status `stopped` khi lúc gửi đơn không còn `completed` (`order_not_completed`, không alert) hoặc đã bị hoàn tiền (`order_refunded`, có alert); đơn completed lại bắt đầu chu kỳ gửi mới.
Evidence: src/Invoice/DraftInvoiceWorkflow.php:392-398, tests/integration/PostDraftOrderChangeTest.php:204-221, tests/integration/DraftInvoiceWorkflowTest.php:282,293, tests/integration/BlockedDraftInvoiceTest.php:205-240, tests/integration/DraftInvoiceRetryTest.php:589-648

#### Scenario: Cancelled before job runs
- **WHEN** đơn completed rồi chuyển `cancelled` trước khi tác vụ chạy
- **THEN** không có request create-invoice, status `stopped` và không có alert

#### Scenario: Partial refund before job runs
- **WHEN** đơn completed bị hoàn một phần trước khi tác vụ chạy
- **THEN** hóa đơn `stopped` với `order_refunded`, không gọi create và có alert

#### Scenario: Full refund before job runs
- **WHEN** đơn chuyển `refunded` trước khi tác vụ chạy
- **THEN** hóa đơn `stopped` và không có hóa đơn nào được gửi
