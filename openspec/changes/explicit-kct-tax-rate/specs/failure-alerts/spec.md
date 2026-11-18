## MODIFIED Requirements

### Requirement: Resolution hint per error code
Hệ thống SHALL định nghĩa cho mỗi mã lỗi hóa đơn một hướng dẫn xử lý ngắn bằng tiếng Việt, không chứa dữ liệu, nêu thao tác cụ thể (màn hình hoặc nút cần dùng), và SHALL dùng cùng hướng dẫn đó ở event giám sát, tin Lark, admin notice, meta box đơn hàng và chi tiết hóa đơn. Hướng dẫn của các mã không nêu trong requirement này SHALL giữ nguyên.

Hướng dẫn của các mã thuế SHALL đúng nguyên văn sau:
- `line_tax_not_configured`: `Cài thuế cho sản phẩm (cả biến thể), phí hoặc phương thức vận chuyển của dòng bị nêu: trạng thái thuế "Chịu thuế" và lớp thuế có thuế suất. Sau đó bấm "Tính lại" trong đơn rồi bấm "Gửi lại".`
- `line_tax_rate_count`: `Lớp thuế của sản phẩm chỉ được có một thuế suất áp dụng cho đơn. Sửa lớp thuế, bấm "Tính lại" trong đơn rồi bấm "Gửi lại".`
- `tax_rate_mismatch`: `Mở tab "Xuất hóa đơn", bảng "Ánh xạ thuế Mắt Bão": thêm thuế suất của dòng vào đúng nhóm, hoặc sửa thuế suất trong WooCommerce cho khớp nhóm. Bấm "Tính lại" trong đơn nếu đã sửa thuế, rồi bấm "Gửi lại".`

#### Scenario: Every code has a hint
- **WHEN** liệt kê mọi mã lỗi hóa đơn
- **THEN** mỗi mã có hướng dẫn không rỗng, và hướng dẫn của ba mã thuế trên đúng nguyên văn

#### Scenario: Data error hint
- **WHEN** hóa đơn dừng với `line_tax_rate_count`
- **THEN** hướng dẫn yêu cầu sửa lớp thuế của sản phẩm, bấm "Tính lại" rồi bấm "Gửi lại"

#### Scenario: Tax not configured hint
- **WHEN** hóa đơn dừng với `line_tax_not_configured`
- **THEN** hướng dẫn yêu cầu cài thuế cho sản phẩm (cả biến thể), phí hoặc phương thức vận chuyển, rồi bấm "Tính lại" trong đơn và "Gửi lại"

#### Scenario: Mismatch hint
- **WHEN** hóa đơn dừng với `tax_rate_mismatch`
- **THEN** hướng dẫn chỉ tới tab "Xuất hóa đơn", bảng "Ánh xạ thuế Mắt Bão" và nút "Gửi lại"

#### Scenario: Config error hint
- **WHEN** hóa đơn dừng với `credentials_missing`
- **THEN** hướng dẫn chỉ tới tab "Kết nối Mắt Bão" và nút "Kiểm tra kết nối"

#### Scenario: Uncertain send hint
- **WHEN** hóa đơn dừng với `unexpected`
- **THEN** hướng dẫn yêu cầu "Kiểm tra trạng thái" trên Mắt Bão trước khi bấm "Gửi lại"

#### Scenario: Same hint everywhere
- **WHEN** hóa đơn dừng với `line_tax_rate_count`
- **THEN** tin Lark, admin notice, meta box, chi tiết hóa đơn và event giám sát hiện cùng một câu hướng dẫn
