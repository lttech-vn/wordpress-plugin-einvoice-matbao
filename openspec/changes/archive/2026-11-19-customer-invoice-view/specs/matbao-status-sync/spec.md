## ADDED Requirements

### Requirement: Customer download check
Lần tra cứu lại khi khách tải PDF SHALL dùng cùng cách ghép dòng, ghi kết quả và alert trạng thái bất thường như nút "Kiểm tra trạng thái", và được đối xử với thời gian tạm dừng như kiểm tra thủ công: lần này MUST NOT bị chặn bởi thời gian tạm dừng, khi thành công SHALL xóa chuỗi lỗi, thời gian tạm dừng và lượt thử lại đang chờ, và khi lỗi MUST NOT tăng chuỗi lỗi hay đổi thời gian tạm dừng. Lần này SHALL chạy cả với hóa đơn đã ở "Đã cấp mã/tiếp nhận", vốn không còn được đồng bộ hằng giờ.

#### Scenario: Issued invoice rechecked
- **WHEN** khách tải PDF của hóa đơn đã ở "Đã cấp mã/tiếp nhận" và Mắt Bão trả dòng khớp với loại "Hóa đơn bị thay thế"
- **THEN** bản ghi lưu loại "Hóa đơn bị thay thế", không có alert (trạng thái vẫn là "Đã cấp mã/tiếp nhận", không bất thường) và đồng bộ hằng giờ vẫn không tra cứu hóa đơn đó

#### Scenario: Download check during the pause
- **WHEN** đồng bộ tự động đang tạm dừng và khách tải PDF, tra cứu lại thành công
- **THEN** trạng thái được ghi, chuỗi lỗi và thời gian tạm dừng bị xóa, và không còn lượt thử lại chờ

#### Scenario: Download check fails
- **WHEN** khách tải PDF và tra cứu lại trả HTTP 503
- **THEN** chuỗi lỗi và thời gian tạm dừng không đổi
