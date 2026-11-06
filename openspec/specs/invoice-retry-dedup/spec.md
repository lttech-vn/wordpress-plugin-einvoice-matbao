# invoice-retry-dedup Specification

## Purpose

Retry, timeout, lookup trước khi gửi lại, dedup bằng MTChieu, draft bị chặn, phân loại lỗi và redaction.

## Requirements

### Requirement: MTChieu is the only dedup key
Hệ thống SHALL dùng cùng một `MTChieu` cho mọi lần gửi của một đơn, gửi kèm yêu cầu Mắt Bão kiểm tra trùng `MTChieu`, và MUST NOT đổi `MTChieu` để né lỗi trùng 305.
Evidence: docs/adr/0001-matbao-reference-as-sole-dedup-key.md, src/Invoice/DraftPayloadBuilder.php:109, tests/integration/DraftInvoiceRetryTest.php:121-180, tests/e2e/army/failures.army.ts:146-154

#### Scenario: Duplicate reference 305
- **WHEN** create-invoice trả lỗi 305 cho `MTChieu` của đơn
- **THEN** hệ thống tra cứu theo `MTChieu` đó trong cùng lần chạy, nhận lại nháp nếu tìm thấy, và mọi request create đều dùng một `MTChieu` duy nhất

#### Scenario: 305 but nothing found
- **WHEN** create trả 305 và tra cứu không thấy hóa đơn
- **THEN** lỗi được xử lý như lỗi tạm thời và lần gửi sau vẫn dùng cùng `MTChieu`

### Requirement: Lookup before resending
Hệ thống SHALL tra cứu hóa đơn theo `MTChieu` (với mẫu số/ký hiệu đã lưu trên bản ghi) trước khi gửi lại bất cứ khi nào một attempt trước có thể đã tới Mắt Bão, và SHALL nhận lại nháp tìm thấy thay vì tạo mới.
Evidence: src/Invoice/DraftInvoiceWorkflow.php:374-382,459-486, tests/integration/DraftInvoiceRetryTest.php:32-62,307-333, tests/e2e/army/failures.army.ts:127-145

#### Scenario: Timeout then draft exists
- **WHEN** create-invoice timeout nhưng Mắt Bão đã lưu nháp, rồi retry chạy
- **THEN** tra cứu tìm thấy nháp, bản ghi thành `draft_created` với mã hóa đơn đó, và chỉ có một request create

#### Scenario: Lookup finds nothing
- **WHEN** tra cứu không thấy hóa đơn nào
- **THEN** hệ thống gửi lại create với cùng `MTChieu`

#### Scenario: Lookup fails
- **WHEN** tra cứu lỗi hoặc trả phản hồi 2xx không đọc được
- **THEN** hệ thống không gửi create và lên lịch thử lại

### Requirement: Skip lookup when nothing reached Mat Bao
Hệ thống SHALL không tra cứu ở lần gửi đầu, và khi mọi attempt trước đều thất bại trước bước create-invoice (ví dụ lỗi đăng nhập hoặc lỗi lấy danh sách mẫu).
Evidence: src/Invoice/DraftInvoiceWorkflow.php:428-433, tests/integration/DraftInvoiceRetryTest.php:32-40,334, tests/integration/BlockedDraftInvoiceTest.php:424

#### Scenario: Login failure then retry
- **WHEN** lần trước lỗi đăng nhập tạm thời
- **THEN** lần retry gửi create ngay, không tra cứu

### Requirement: Automatic retry with backoff
Hệ thống SHALL tự thử lại lỗi tạm thời (lỗi kết nối, HTTP 5xx/408/429, phản hồi không đọc được, trùng tham chiếu 305) tối đa 5 lần với độ trễ 1 phút, 5 phút, 15 phút, 1 giờ, 6 giờ; khi chờ, bản ghi ở `pending` và không alert.
Evidence: src/Invoice/DraftInvoiceWorkflow.php:52,714-765, src/Invoice/InvoiceErrorCode.php (is_auto_retryable), tests/integration/DraftInvoiceRetryTest.php:182-222, tests/integration/BlockedDraftInvoiceTest.php:241-256

#### Scenario: Waiting for retry
- **WHEN** create trả HTTP 500
- **THEN** bản ghi `pending` với `error_code=matbao_http_temporary`, một tác vụ retry được xếp và không có alert

#### Scenario: Backoff schedule
- **WHEN** create liên tục timeout
- **THEN** các lần retry cách nhau 60, 300, 900, 3600 và 21600 giây

### Requirement: Retries exhausted need attention
Hệ thống SHALL đặt bản ghi `needs_attention`, ngừng xếp tác vụ và phát hook `einvoice_matbao_retries_exhausted` (order ID, reference) cùng `einvoice_matbao_invoice_needs_attention` một lần khi lần retry thứ 5 vẫn lỗi.
Evidence: src/Invoice/DraftInvoiceWorkflow.php:743, tests/integration/DraftInvoiceRetryTest.php:182-222, tests/e2e/army/failures.army.ts:156-190

#### Scenario: Six failed attempts
- **WHEN** create timeout 6 lần liên tiếp
- **THEN** có 6 attempt, bản ghi `needs_attention`, hook exhausted phát một lần và không còn tác vụ nào được xếp

### Requirement: Non-retryable errors are final
Hệ thống SHALL không tự thử lại lỗi dữ liệu, lỗi cấu hình, lỗi trạng thái đơn, HTTP 4xx khác, hóa đơn bị Mắt Bão từ chối, mẫu bị từ chối (308/327) và lỗi bất ngờ; các lỗi này cần admin xử lý.
Evidence: src/Invoice/InvoiceErrorCode.php (is_auto_retryable, from_matbao), tests/integration/InvoiceErrorCodeTest.php:111-149, tests/integration/DraftInvoiceRetryTest.php:259-300, tests/integration/BlockedDraftInvoiceTest.php:258-290

#### Scenario: HTTP 400
- **WHEN** create trả HTTP 400
- **THEN** bản ghi `failed` với `matbao_http` và không có tác vụ retry

#### Scenario: Template rejected
- **WHEN** Mắt Bão trả mã 308 hoặc 327, kể cả qua HTTP error
- **THEN** lỗi là `template_rejected` thuộc loại cấu hình và không retry

#### Scenario: Unexpected exception
- **WHEN** quá trình gửi ném exception không lường trước có chứa token và URL nội bộ
- **THEN** bản ghi `failed` với `unexpected`, lý do chung chung không chứa token hay URL, và không retry

### Requirement: Error classification by code and kind
Hệ thống SHALL gán mỗi lỗi một mã ổn định (`error_code`) thuộc một loại (`error_kind`: `data`, `config`, `order`, `matbao`, `system`), và loại quyết định trạng thái: `config` → `not_configured`, `order` → `stopped`, còn lại → `failed`.
Evidence: src/Invoice/InvoiceErrorKind.php, src/Invoice/InvoiceErrorCode.php, tests/integration/InvoiceErrorCodeTest.php:91-125

#### Scenario: Code table complete
- **WHEN** liệt kê mọi mã lỗi
- **THEN** mỗi mã có loại, trạng thái, quyết định retry và nhãn tiếng Việt không rỗng

#### Scenario: Blocked data error
- **WHEN** dữ liệu đơn không dựng được hóa đơn hợp lệ (xem draft-invoice-creation)
- **THEN** bản ghi `failed` với mã loại `data`, không có request create và admin nhận alert

### Requirement: Lookup mismatch is not adopted
Hệ thống SHALL không nhận hóa đơn tìm thấy khi có nhiều hóa đơn, khác `MTChieu`, không ở trạng thái Nháp, tổng khác tổng đơn, hoặc tên/MST người mua khác snapshot; khi đó bản ghi thành `needs_attention` với `matbao_lookup_mismatch`, không retry, không tạo mới.
Evidence: src/Invoice/DraftInvoiceWorkflow.php:553-623, tests/integration/DraftInvoiceRetryTest.php:730-800

#### Scenario: Issued invoice under the reference
- **WHEN** create trả 305 và tra cứu thấy hóa đơn "Đã cấp mã"
- **THEN** bản ghi `needs_attention`, `matbao_invoice_id` rỗng và có một alert `matbao_lookup_mismatch`

#### Scenario: Matching draft adopted
- **WHEN** tra cứu trả đúng một nháp có tổng khớp đơn
- **THEN** bản ghi thành `draft_created` với mã hóa đơn đó

### Requirement: Template changed after uncertain create
Hệ thống SHALL tra cứu dưới mẫu đã lưu trên bản ghi và, nếu không thấy hóa đơn, dừng với `template_changed` (`not_configured`) thay vì tạo dưới mẫu mặc định mới.
Evidence: src/Invoice/DraftInvoiceWorkflow.php:453-486,525-536, tests/integration/DraftInvoiceRetryTest.php:676-722

#### Scenario: Default template changed
- **WHEN** mẫu mặc định đổi sau một create timeout và tra cứu dưới mẫu cũ không thấy gì
- **THEN** bản ghi `not_configured` với `template_changed`, không có create thứ hai và có một alert

### Requirement: Recovery sweep for stuck invoices
Hệ thống SHALL quét mỗi 15 phút và đưa bản ghi `sending` hoặc `pending` không có tác vụ, không đổi quá 15 phút, về `pending`, đóng attempt dở dang là `abandoned`, xếp lại tác vụ và tra cứu trước khi gửi; lần quét không tính vào số lần retry.
Evidence: src/Invoice/DraftInvoiceWorkflow.php:79-90,314-346, tests/integration/DraftInvoiceRetryTest.php:379-402,468-575,650

#### Scenario: Runner died after claim
- **WHEN** bản ghi `sending` đã quá 15 phút
- **THEN** bản ghi về `pending`, tra cứu tìm thấy nháp và nhận lại, không gọi create

#### Scenario: Fresh or scheduled row
- **WHEN** bản ghi `sending` còn mới, hoặc bản ghi `pending` đã có tác vụ retry
- **THEN** lần quét không đụng tới

#### Scenario: Sweep cannot enqueue
- **WHEN** lần quét không xếp được tác vụ
- **THEN** bản ghi dừng với `queue_failed` và cần admin xử lý

### Requirement: New retry cycle on restart
Hệ thống SHALL bắt đầu chu kỳ retry mới (đếm lại 5 lần) khi đơn `stopped` được completed lại hoặc admin gửi lại hóa đơn, vẫn tra cứu trước nếu attempt cũ có thể đã tới Mắt Bão, và chỉ một lần restart đồng thời thắng.
Evidence: src/Invoice/DraftInvoiceWorkflow.php:107-113,185-198, tests/integration/DraftInvoiceRetryTest.php:589-648

#### Scenario: Restart after exhaustion
- **WHEN** admin gửi lại hóa đơn `needs_attention`
- **THEN** hệ thống tra cứu trước, lần retry đầu trễ 60 giây và hook exhausted không phát lại

### Requirement: Error messages stored without citizen ID
Hệ thống SHALL che số CCCD 12 chữ số (chỉ giữ 4 số cuối) trong lý do lỗi và message attempt trước khi lưu, và lý do lỗi người mua chỉ nêu tên trường.
Evidence: src/Invoice/DraftInvoiceWorkflow.php:229-253,715, tests/integration/DraftInvoiceBuyerSnapshotTest.php:410-425

#### Scenario: Mat Bao echoes CCCD
- **WHEN** message lỗi của Mắt Bão chứa CCCD 12 số
- **THEN** `status_reason` và attempt chỉ chứa dạng `********1234`
