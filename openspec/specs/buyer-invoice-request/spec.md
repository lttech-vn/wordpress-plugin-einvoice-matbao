# buyer-invoice-request Specification

## Purpose

Người mua yêu cầu hóa đơn ở checkout Classic và Blocks, kèm validate.

## Requirements

### Requirement: Walk-in buyer by default
Hệ thống SHALL coi người mua là người mua vãng lai khi họ không tick "Yêu cầu xuất hóa đơn" ở checkout (Classic hoặc Blocks): không validate thông tin xuất hóa đơn và không lưu yêu cầu nào trên đơn.
Evidence: src/Checkout/ClassicCheckout.php:114-117,134-137, src/Checkout/BlocksCheckout.php:130-133, tests/integration/ClassicCheckoutBuyerTest.php:31, tests/integration/BlocksCheckoutBuyerTest.php:76,85, tests/e2e/army/checkout-validation.army.ts:92

#### Scenario: Not ticked with billing data
- **WHEN** khách đặt hàng không tick yêu cầu nhưng billing đầy đủ
- **THEN** đơn được tạo và không có yêu cầu xuất hóa đơn nào được lưu

#### Scenario: Blocks without extension data
- **WHEN** Store API checkout được gọi không kèm dữ liệu extension `einvoice-matbao`
- **THEN** đơn được tạo (HTTP 200) và không có yêu cầu nào được lưu

### Requirement: Buyer type selection
Hệ thống SHALL yêu cầu khách đã tick chọn đúng một loại người mua là cá nhân (`individual`) hoặc doanh nghiệp (`business`), và từ chối với thông báo "Vui lòng chọn xuất hóa đơn cho cá nhân hoặc doanh nghiệp." khi thiếu hoặc sai loại.
Evidence: src/Buyer/BuyerRules.php:89-92, tests/integration/ClassicCheckoutBuyerTest.php:212, tests/integration/BlocksCheckoutBuyerTest.php:102,276, tests/e2e/army/checkout-validation.army.ts:40,175

#### Scenario: Ticked without type
- **WHEN** khách tick yêu cầu nhưng không chọn loại
- **THEN** checkout bị từ chối với lỗi chọn loại và không lưu yêu cầu

#### Scenario: Unknown type via Store API
- **WHEN** Store API nhận `buyer_type` = "alien"
- **THEN** phản hồi HTTP 400 và không echo lại giá trị đã gửi

### Requirement: Individual buyer validation
Hệ thống SHALL yêu cầu người mua cá nhân có họ tên billing, địa chỉ billing, số điện thoại Việt Nam hợp lệ và số CCCD gồm đúng 12 chữ số, trong giới hạn độ dài của Mắt Bão; lỗi hiển thị trước khi gửi đơn.

#### Scenario: CCCD not 12 digits
- **WHEN** người mua cá nhân nhập CCCD "123"
- **THEN** checkout báo "Số CCCD phải gồm đúng 12 chữ số." và thông báo không chứa số đã nhập

#### Scenario: International phone form
- **WHEN** số điện thoại billing là "+84901234567"
- **THEN** số được chấp nhận (tiền tố 0, 84, +84 và ký tự phân tách đều hợp lệ)

#### Scenario: Length limits
- **WHEN** họ tên vượt 100 ký tự, địa chỉ billing ghép vượt 400 ký tự hoặc số điện thoại vượt 20 ký tự
- **THEN** checkout bị từ chối với lỗi của trường tương ứng

#### Scenario: Missing billing address
- **WHEN** người mua cá nhân không có địa chỉ billing
- **THEN** checkout bị từ chối với lỗi địa chỉ, nhắc nhập địa chỉ ở phần thanh toán

### Requirement: Business buyer validation
Hệ thống SHALL yêu cầu người mua doanh nghiệp có tên doanh nghiệp, mã số thuế 10 hoặc 13 chữ số, địa chỉ billing, số điện thoại và email billing hợp lệ; không cần tên liên hệ và không cần khóa mã hóa; lỗi hiển thị trước khi gửi đơn.

#### Scenario: Malformed tax code
- **WHEN** doanh nghiệp nhập MST "12"
- **THEN** Classic báo lỗi tại trường MST; Blocks trả HTTP 400 `einvoice_buyer_invalid` với thông báo MST

#### Scenario: Branch tax code
- **WHEN** MST gồm 13 chữ số, có hoặc không có dấu "-" trước 3 số cuối
- **THEN** MST được chấp nhận

#### Scenario: Length limits
- **WHEN** tên doanh nghiệp hoặc địa chỉ billing ghép vượt 400 ký tự, hoặc email vượt 50 ký tự
- **THEN** checkout bị từ chối với lỗi của trường tương ứng

#### Scenario: Missing billing address
- **WHEN** doanh nghiệp không có địa chỉ billing
- **THEN** checkout bị từ chối với lỗi địa chỉ, nhắc nhập địa chỉ ở phần thanh toán

### Requirement: Individual invoice requires encryption key
Hệ thống SHALL không nhận yêu cầu xuất hóa đơn cá nhân khi chưa cấu hình khóa mã hóa (xem credential-encryption): lựa chọn "Cá nhân" bị vô hiệu kèm ghi chú và server từ chối, trong khi doanh nghiệp vẫn dùng được.
Evidence: src/Buyer/BuyerRules.php:106-110, src/Checkout/ClassicCheckout.php:84,91-93, assets/src/checkout-blocks.ts:118-120, tests/integration/ClassicCheckoutBuyerTest.php:126,227,285,294,310, tests/integration/BlocksCheckoutBuyerTest.php:141

#### Scenario: No key
- **WHEN** khóa chưa cấu hình và khách gửi yêu cầu cá nhân hợp lệ
- **THEN** yêu cầu bị từ chối và không có gì được lưu

#### Scenario: Business without key
- **WHEN** khóa chưa cấu hình và khách gửi yêu cầu doanh nghiệp hợp lệ
- **THEN** đơn được tạo và yêu cầu được lưu

### Requirement: Request stored on order with encrypted CCCD
Hệ thống SHALL lưu yêu cầu xuất hóa đơn vào order meta `_einvoice_buyer_request` chỉ gồm các trường đặc thù của loại đã chọn (loại, tên doanh nghiệp, MST, CCCD), không còn ghi địa chỉ riêng; CCCD chỉ được lưu ở dạng mã hóa kèm bản mask lộ 4 số cuối, không bao giờ ở dạng rõ.

#### Scenario: Individual stored
- **WHEN** đơn cá nhân hợp lệ với CCCD 12 số được đặt
- **THEN** meta có mask "********1234", bản mã hóa khác CCCD gốc, và không có tên doanh nghiệp hay MST

#### Scenario: Business ignores individual fields
- **WHEN** form doanh nghiệp vẫn kèm CCCD đã nhập trước đó
- **THEN** CCCD không được lưu

#### Scenario: No address stored
- **WHEN** đơn doanh nghiệp hợp lệ được đặt
- **THEN** meta yêu cầu không chứa địa chỉ mới

### Requirement: Server-side validation is authoritative
Hệ thống SHALL kiểm tra lại yêu cầu ở server lúc tạo đơn và từ chối dữ liệu không hợp lệ, kể cả khi validation trên trình duyệt hoặc bước validate checkout bị bỏ qua.
Evidence: src/Checkout/ClassicCheckout.php:132-147, src/Checkout/BlocksCheckout.php:148-151, tests/integration/ClassicCheckoutBuyerTest.php:218,227, tests/integration/BlocksCheckoutBuyerTest.php:92, tests/e2e/army/checkout-validation.army.ts:78

#### Scenario: Direct Store API call
- **WHEN** Store API được gọi trực tiếp, không qua block, với dữ liệu sai
- **THEN** phản hồi HTTP 400 `einvoice_buyer_invalid` và không lưu yêu cầu

#### Scenario: Classic validation hook skipped
- **WHEN** đơn Classic được tạo mà bước validate checkout không chạy
- **THEN** việc tạo đơn vẫn bị chặn với dữ liệu không hợp lệ

### Requirement: Blocks extension input schema
Hệ thống SHALL khai báo dữ liệu extension `einvoice-matbao` cho endpoint checkout của Store API với kiểu và độ dài ràng buộc, và từ chối giá trị sai kiểu hoặc quá dài bằng HTTP 400 mà không echo lại giá trị.
Evidence: src/Checkout/BlocksCheckout.php:19-24,67-92, tests/integration/BlocksCheckoutBuyerTest.php:148,165,259,283, tests/e2e/army/checkout-validation.army.ts:175,182

#### Scenario: Non-boolean flag
- **WHEN** `request_invoice` là chuỗi "yes"
- **THEN** phản hồi HTTP 400, không âm thầm coi là người mua vãng lai

### Requirement: Blocks data is write-only
Hệ thống SHALL không trả lại hay giữ dữ liệu xuất hóa đơn (kể cả CCCD) trong phản hồi Store API, session, email, trang order-received hay log.
Evidence: src/Checkout/BlocksCheckout.php:67-92, tests/integration/BlocksCheckoutBuyerTest.php:283,331, tests/e2e/army/checkout-validation.army.ts:182

#### Scenario: Response does not echo
- **WHEN** đơn doanh nghiệp hợp lệ được đặt qua Blocks
- **THEN** phản hồi 200 không chứa MST hay CCCD đã gửi

### Requirement: Blocks stores only on place order
Hệ thống SHALL chỉ validate và lưu yêu cầu Blocks khi khách đặt hàng; các lần cập nhật checkout giữa chừng không bị từ chối, không lưu gì và đơn nháp không chứa CCCD.
Evidence: src/Checkout/BlocksCheckout.php:121-126, tests/integration/BlocksCheckoutBuyerTest.php:202,220,238,316

#### Scenario: Partial update while typing
- **WHEN** checkout gửi cập nhật với `request_invoice: true` nhưng chưa chọn loại
- **THEN** phản hồi 200 và không có yêu cầu nào được lưu

#### Scenario: Retry after failed payment
- **WHEN** khách đặt lại sau một lần thanh toán thất bại trên cùng đơn
- **THEN** đơn đặt được và yêu cầu được lưu trên đơn đó

### Requirement: Classic checkout fields
Hệ thống SHALL hiển thị ở checkout Classic ô "Yêu cầu xuất hóa đơn", ẩn các trường cho tới khi tick, hiện trường theo loại người mua, và không bao giờ điền lại CCCD đã gửi.

#### Scenario: Hidden until ticked
- **WHEN** trang checkout tải lần đầu
- **THEN** vùng trường xuất hóa đơn bị ẩn

#### Scenario: Fields per type
- **WHEN** khách chọn doanh nghiệp, rồi chọn cá nhân
- **THEN** doanh nghiệp thấy tên doanh nghiệp và MST; cá nhân thấy CCCD; cả hai không có ô địa chỉ riêng

#### Scenario: Re-rendered after failed submit
- **WHEN** form hiển thị lại sau khi submit lỗi
- **THEN** ô CCCD trống và các giá trị khác được escape

### Requirement: Instant browser feedback
Hệ thống SHALL hiển thị lỗi ngay trên trình duyệt (sau khi rời ô hoặc khi submit) với cùng luật và thông báo tiếng Việt như server, và chặn đặt hàng khi còn lỗi ở cả Classic lẫn Blocks.
Evidence: assets/src/checkout-rules.ts:19-55, assets/src/checkout.ts:103-138, assets/src/checkout-blocks.ts:75-92, assets/src/checkout-rules.test.ts, tests/e2e/army/checkout-validation.army.ts:40,78

#### Scenario: Submit with invalid data
- **WHEN** khách submit khi yêu cầu xuất hóa đơn còn lỗi
- **THEN** mọi lỗi được hiển thị và request không được gửi tới server

### Requirement: Checkout Blocks invoice request block
Hệ thống SHALL cung cấp inner block `einvoice-matbao/invoice-request` trong checkout Blocks, tự chèn sau phần thông tin liên hệ và không cho người dùng xóa.
Evidence: assets/blocks/invoice-request/block.json, src/Checkout/BlocksIntegration.php:43-65, tests/integration/BlocksCheckoutBuyerTest.php:371

#### Scenario: Block placement
- **WHEN** trang checkout dùng block `woocommerce/checkout`
- **THEN** block yêu cầu xuất hóa đơn xuất hiện sau phần thông tin liên hệ và bị khóa không cho xóa

### Requirement: Classic script only on classic checkout
Hệ thống SHALL chỉ nạp script checkout Classic trên trang checkout không dùng block `woocommerce/checkout`.
Evidence: src/Checkout/ClassicCheckout.php:45-59, tests/integration/ClassicCheckoutScriptTest.php:25,33

#### Scenario: Blocks page
- **WHEN** trang checkout chứa block `woocommerce/checkout`
- **THEN** script Classic không được nạp

#### Scenario: Classic page
- **WHEN** trang checkout dùng shortcode Classic
- **THEN** script Classic được nạp

### Requirement: Invoice address from billing
Hệ thống SHALL lấy địa chỉ người mua (`NMua_DChi`) cho mọi loại người mua từ địa chỉ thanh toán (billing) của đơn, ghép theo thứ tự địa chỉ 1, địa chỉ 2, thành phố, tỉnh/thành bằng ", " và bỏ phần trống; hệ thống SHALL không còn ô "Địa chỉ xuất hóa đơn" riêng ở checkout Classic hoặc Blocks, và SHALL bỏ qua giá trị `address` mà script checkout cũ vẫn gửi mà không trả lỗi.

#### Scenario: Address composed from billing
- **WHEN** billing có địa chỉ 1 "12 Nguyễn Huệ", thành phố "Quận 1", tỉnh/thành "TP. Hồ Chí Minh" và địa chỉ 2 trống
- **THEN** địa chỉ người mua là "12 Nguyễn Huệ, Quận 1, TP. Hồ Chí Minh"

#### Scenario: Legacy address field ignored
- **WHEN** Store API nhận extension `einvoice-matbao` kèm `address` từ script đã cache
- **THEN** phản hồi không phải HTTP 400 và giá trị `address` không được dùng

#### Scenario: No separate address field
- **WHEN** khách tick yêu cầu xuất hóa đơn ở Classic hoặc Blocks
- **THEN** không có ô "Địa chỉ xuất hóa đơn", địa chỉ lấy từ phần thanh toán
