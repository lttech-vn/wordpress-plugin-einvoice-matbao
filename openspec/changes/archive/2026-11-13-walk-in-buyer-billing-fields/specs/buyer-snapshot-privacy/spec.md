## ADDED Requirements

### Requirement: Walk-in buyer billing fields
Hệ thống SHALL, với người mua vãng lai, đưa vào snapshot họ tên (`NMua_Ten`, `NMua_HVTNMHang`), địa chỉ (`NMua_DChi`), số điện thoại (`NMua_SDThoai`) và email (`NMua_DCTDTu`) lấy từ billing của đơn tại thời điểm chụp; trường trống hoặc sai định dạng bị bỏ qua, địa chỉ vượt 400 ký tự bị cắt, và `NMua_Ten` dùng tên vãng lai đã cấu hình khi billing không có tên.

#### Scenario: Billing complete
- **WHEN** đơn không có yêu cầu xuất hóa đơn nhưng billing có tên, địa chỉ, số điện thoại hợp lệ và email hợp lệ
- **THEN** payload có `NMua_Ten`, `NMua_HVTNMHang`, `NMua_DChi`, `NMua_SDThoai`, `NMua_DCTDTu` từ billing và không có `NMua_MST` hay `NMua_CCCDan`

#### Scenario: Invalid fields skipped
- **WHEN** billing có số điện thoại "12345" và email sai định dạng
- **THEN** payload không có `NMua_SDThoai` hay `NMua_DCTDTu`, các trường hợp lệ còn lại vẫn được gửi và đơn không bị chặn

#### Scenario: Empty billing
- **WHEN** billing không có thông tin nào
- **THEN** `NMua_Ten` là tên vãng lai đã cấu hình và không có trường `NMua_*` khác

---

## MODIFIED Requirements

### Requirement: Buyer snapshot per buyer type
Hệ thống SHALL chụp snapshot người mua vào order meta `_einvoice_buyer_snapshot` khi đơn completed, và payload hóa đơn chỉ chứa các trường người mua tương ứng với loại người mua; địa chỉ `NMua_DChi` của cá nhân và doanh nghiệp lấy từ địa chỉ billing, và chỉ khi billing không có địa chỉ mới dùng địa chỉ đã lưu trong yêu cầu của đơn đặt trước thay đổi này.

#### Scenario: Walk-in buyer
- **WHEN** đơn không có yêu cầu xuất hóa đơn được gửi
- **THEN** payload có `NMua_Ten` và các trường billing hợp lệ theo yêu cầu "Walk-in buyer billing fields", không có `NMua_MST` hay `NMua_CCCDan`

#### Scenario: Individual buyer
- **WHEN** đơn của người mua cá nhân được gửi
- **THEN** payload có `NMua_Ten`, `NMua_HVTNMHang`, `NMua_DChi` (từ billing), `NMua_CCCDan`, `NMua_SDThoai`, `NMua_DCTDTu` (khi email hợp lệ) và không có `NMua_MST`

#### Scenario: Business buyer
- **WHEN** đơn của người mua doanh nghiệp được gửi
- **THEN** payload có `NMua_Ten` = tên doanh nghiệp, `NMua_MST` và `NMua_DChi` từ billing, không có `NMua_HVTNMHang` hay `NMua_CCCDan`

#### Scenario: Legacy request address fallback
- **WHEN** đơn đặt trước thay đổi này có yêu cầu lưu địa chỉ riêng và billing không có địa chỉ
- **THEN** `NMua_DChi` dùng địa chỉ đã lưu trong yêu cầu

### Requirement: Privacy exporter
Hệ thống SHALL cung cấp WordPress personal-data exporter xuất dữ liệu xuất hóa đơn theo email (billing email hoặc tài khoản cùng email), gồm tên, MST, địa chỉ, điện thoại, email, `MTChieu`, trạng thái và CCCD dạng mask; đơn vãng lai có thông tin billing trong snapshot cũng được xuất.

#### Scenario: Export customer orders
- **WHEN** xuất dữ liệu cho email của một đơn cá nhân
- **THEN** có `CCCD` = `********5678`, không có số đầy đủ hay bản mã hóa, và không có dữ liệu của email khác

#### Scenario: Export walk-in order with billing data
- **WHEN** xuất dữ liệu cho email của đơn vãng lai có snapshot mang thông tin billing
- **THEN** có tên, địa chỉ, điện thoại, email trong snapshot và không có CCCD

#### Scenario: Paged export
- **WHEN** email có hơn 10 đơn
- **THEN** dữ liệu được xuất theo trang 10 đơn, bỏ qua đơn vãng lai không có dữ liệu

### Requirement: Privacy eraser
Hệ thống SHALL cung cấp WordPress personal-data eraser chạy trước eraser của WooCommerce, xóa yêu cầu và snapshot người mua của các đơn thuộc email được yêu cầu, ẩn danh tên người mua và dữ liệu cá nhân trong lý do lỗi và attempt; đơn vãng lai có thông tin billing trong snapshot cũng được xóa snapshot và ẩn danh như đơn cá nhân.

#### Scenario: Erase an order with a draft
- **WHEN** xóa dữ liệu cho email của đơn đã `draft_created`
- **THEN** hai meta bị xóa, tên người mua thành marker ẩn danh và status vẫn `draft_created`

#### Scenario: Walk-in order
- **WHEN** xóa dữ liệu cho đơn vãng lai mà snapshot chỉ có tên vãng lai đã cấu hình
- **THEN** eraser không báo đã ẩn danh và tên người mua vãng lai giữ nguyên

#### Scenario: Walk-in order with billing data
- **WHEN** xóa dữ liệu cho đơn vãng lai mà snapshot mang thông tin billing
- **THEN** snapshot bị xóa, tên người mua thành marker ẩn danh và eraser báo đã ẩn danh
