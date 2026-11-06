# matbao-api-client Specification

## Purpose

Giao tiếp với API Mắt Bão: xác thực, token, template, parse và map lỗi.

## Requirements

### Requirement: Base URL resolution
Hệ thống SHALL gọi API Mắt Bão tại URL demo hoặc production theo môi trường đã chọn, và SHALL dùng hằng `EINVOICE_MATBAO_BASE_URL` thay cho mọi môi trường khi hằng là một URL http(s) hợp lệ.
Evidence: src/Matbao/MatbaoCredentials.php:16-73, tests/integration/MatbaoBaseUrlTest.php:17-54

#### Scenario: Environment URL
- **WHEN** không có hằng ghi đè và môi trường là `production`
- **THEN** request đi tới `https://api-hddt.matbao.in:11443`

#### Scenario: Override constant
- **WHEN** hằng là `http://sandbox.example:8080/`
- **THEN** mọi môi trường dùng `http://sandbox.example:8080` (bỏ dấu `/` cuối)

#### Scenario: Invalid override
- **WHEN** hằng rỗng, thiếu scheme, dùng scheme `ftp`, chứa khoảng trắng hoặc không phải chuỗi
- **THEN** hệ thống dùng URL của môi trường, và dùng demo khi môi trường không xác định

### Requirement: Login and encrypted token cache
Hệ thống SHALL đăng nhập Mắt Bão bằng mã số thuế, tên đăng nhập và mật khẩu, cache `accessToken` ở dạng mã hóa theo từng môi trường và tài khoản, và dùng lại token cho các lần gọi sau.
Evidence: src/Matbao/MatbaoClient.php:202-224,287-322, tests/integration/SettingsConnectionRestTest.php:86-96

#### Scenario: Token stored encrypted
- **WHEN** đăng nhập thành công
- **THEN** token được lưu trong transient và không ở dạng plaintext

#### Scenario: Login without accessToken
- **WHEN** đăng nhập trả success nhưng không có `accessToken`
- **THEN** hệ thống báo lỗi và không lưu transient nào

### Requirement: Token lifetime
Hệ thống SHALL cache token không quá 1 giờ và hết hạn sớm 5 phút trước `expiredDate` (giờ Asia/Ho_Chi_Minh), SHALL không cache token đã hoặc sắp hết hạn trong 5 phút, và SHALL theo dõi tối đa 5 transient token.
Evidence: src/Matbao/MatbaoClient.php:20-35,299-322, tests/integration/SettingsFailureModesRestTest.php:106-147

#### Scenario: Expired token
- **WHEN** `expiredDate` nằm trong quá khứ
- **THEN** không có transient nào được lưu và lần gọi sau đăng nhập lại

#### Scenario: Token about to expire
- **WHEN** `expiredDate` chỉ còn 2 phút
- **THEN** token không được cache

### Requirement: Re-login once on 401
Hệ thống SHALL đăng nhập lại đúng một lần và gửi lại request khi Mắt Bão trả 401 (HTTP status hoặc errorCode), và SHALL không lặp thêm.
Evidence: src/Matbao/MatbaoClient.php:169-181, tests/integration/SettingsFailureModesRestTest.php:57-66

#### Scenario: Repeated 401
- **WHEN** request lấy danh sách mẫu luôn trả 401
- **THEN** có đúng 2 request đăng nhập, 2 request danh sách mẫu, và kết quả là lỗi

### Requirement: Response envelope and error kinds
Hệ thống SHALL đọc envelope `{success, errorCode, message, data}` của Mắt Bão và báo lỗi thuộc loại `transport` (request không hoàn tất), `http` (status ngoài 2xx) hoặc `api` (2xx nhưng `success=false` hoặc body không đọc được), kèm mã lỗi và message không chứa credential hay token.
Evidence: src/Matbao/MatbaoClient.php:237-282, src/Matbao/MatbaoError.php, tests/integration/SettingsFailureModesRestTest.php:32-40, tests/integration/InvoiceErrorCodeTest.php:71-87

#### Scenario: Non-JSON body
- **WHEN** Mắt Bão trả 200 với nội dung HTML
- **THEN** client báo lỗi `api` "không đọc được"

#### Scenario: Transport error
- **WHEN** request HTTP không hoàn tất
- **THEN** client báo lỗi `transport` chỉ chứa mã lỗi kỹ thuật, không chứa URL hay secret

### Requirement: Transport and HTTP retryability
Hệ thống SHALL coi lỗi kết nối, HTTP 5xx/408/429 và phản hồi không đọc được là lỗi tạm thời, còn các HTTP 4xx khác, hóa đơn bị từ chối rõ ràng và sai credential là lỗi cần can thiệp.
Evidence: src/Matbao/MatbaoError.php:65-108, tests/integration/InvoiceErrorCodeTest.php:71-147

#### Scenario: HTTP 503
- **WHEN** Mắt Bão trả HTTP 503
- **THEN** lỗi được coi là tạm thời

#### Scenario: Item rejected
- **WHEN** phần tử create-invoice có `success=false` với errorCode 400
- **THEN** lỗi không được coi là tạm thời

### Requirement: Create invoice call
Hệ thống SHALL gửi mỗi hóa đơn dưới dạng danh sách một phần tử tới `/api/invoice/create-invoice`, đọc kết quả của phần tử ngay cả khi envelope báo lỗi, và SHALL báo lỗi khi phần tử bị từ chối hoặc thiếu `maSoHDon`.
Evidence: src/Matbao/MatbaoClient.php:96-122, tests/integration/DraftInvoiceTemplateCacheTest.php:36-48, tests/integration/DraftInvoiceWorkflowTest.php:338

#### Scenario: Success
- **WHEN** phần tử trả `success=true` kèm `data.maSoHDon`
- **THEN** client trả mã hóa đơn đó

#### Scenario: Envelope error with item code
- **WHEN** envelope có `success=false`, errorCode 201 và phần tử có errorCode 305
- **THEN** lỗi mang mã API 305 (trùng tham chiếu)

#### Scenario: Missing invoice ID
- **WHEN** phần tử `success=true` nhưng không có `maSoHDon`
- **THEN** client báo lỗi `api`

### Requirement: Invoice lookup by reference
Hệ thống SHALL tra cứu hóa đơn theo `MTChieu` với mẫu số và ký hiệu qua `/api/invoice/invoice-detail-batch`, trả danh sách rỗng khi Mắt Bão báo không tìm thấy (errorCode 301), và báo lỗi khi phản hồi 200 thiếu danh sách hóa đơn.
Evidence: src/Matbao/MatbaoClient.php:132-160, src/Matbao/MatbaoInvoice.php, docs/discovery.md:94, tests/integration/DraftInvoiceRetryTest.php:55-61,445

#### Scenario: Not found
- **WHEN** tra cứu trả errorCode 301
- **THEN** kết quả là danh sách rỗng

#### Scenario: Unreadable lookup response
- **WHEN** tra cứu trả 200 nhưng không có `data.invoices`
- **THEN** client báo lỗi, không coi là "không tìm thấy"

### Requirement: Invoice and template parsing
Hệ thống SHALL coi hóa đơn là nháp khi `maTThaiHDon` = 1 (hoặc `tenTThaiHDon` = "Nháp" khi thiếu mã), coi mẫu là còn số khi `cLai` > 0, và bỏ qua phần tử không hợp lệ trong danh sách.
Evidence: src/Matbao/MatbaoInvoice.php:51-86, src/Matbao/MatbaoTemplate.php:35-63, tests/integration/SettingsInvoicingRestTest.php:98-157, tests/integration/DraftInvoiceRetryTest.php:753-800

#### Scenario: Draft by name
- **WHEN** hóa đơn không có `maTThaiHDon` và `tenTThaiHDon` là "Nháp"
- **THEN** hóa đơn được coi là nháp

#### Scenario: Template out of numbers
- **WHEN** mẫu có `cLai` = 0
- **THEN** mẫu được coi là hết số

### Requirement: Template cache
Hệ thống SHALL cache danh sách mẫu của một năm cho một tài khoản trong 5 phút, không cache khi gọi lỗi, và xóa cache khi lưu mẫu mặc định hoặc credential, hoặc khi Mắt Bão từ chối mẫu.
Evidence: src/Matbao/TemplateCache.php, tests/integration/DraftInvoiceTemplateCacheTest.php:21-90

#### Scenario: Two drafts in a row
- **WHEN** hai đơn được gửi liên tiếp
- **THEN** chỉ có 1 request lấy danh sách mẫu và 2 request create-invoice

#### Scenario: Template rejected
- **WHEN** create-invoice trả 308 rồi đơn kế tiếp được gửi
- **THEN** cache bị xóa và danh sách mẫu được lấy lại

#### Scenario: Credentials saved
- **WHEN** admin lưu credential Mắt Bão
- **THEN** đơn kế tiếp lấy lại danh sách mẫu

### Requirement: Request transport settings
Hệ thống SHALL gửi mọi request tới Mắt Bão với timeout 30 giây, không theo redirect, header JSON và Bearer token cho mọi endpoint trừ đăng nhập.
Evidence: src/Matbao/MatbaoClient.php:37,237-256
Confidence: inferred

#### Scenario: Authenticated request
- **WHEN** client gọi một endpoint không phải đăng nhập
- **THEN** request có header `Authorization: Bearer <token>`, timeout 30 giây và không theo redirect
