## ADDED Requirements

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

---

## MODIFIED Requirements

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
