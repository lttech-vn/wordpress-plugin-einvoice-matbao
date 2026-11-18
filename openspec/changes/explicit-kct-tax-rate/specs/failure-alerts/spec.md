## MODIFIED Requirements

### Requirement: Resolution hint per error code
Hệ thống SHALL định nghĩa cho mỗi mã lỗi hóa đơn một hướng dẫn xử lý ngắn bằng tiếng Việt, không chứa dữ liệu, nêu thao tác cụ thể (màn hình hoặc nút cần dùng), và SHALL dùng cùng hướng dẫn đó ở event giám sát, tin Lark, admin notice, meta box đơn hàng và chi tiết hóa đơn. Hướng dẫn của các mã không nêu trong requirement này SHALL giữ nguyên.

Hướng dẫn của các mã thuế SHALL đúng nguyên văn sau:
- `line_tax_not_configured`: `Cài thuế cho sản phẩm (cả biến thể), phí hoặc phương thức vận chuyển của dòng bị nêu: trạng thái thuế "Chịu thuế" và lớp thuế có thuế suất. Hàng KCT/KKKNT dùng thuế suất 0% đã gán ở tab "Xuất hóa đơn". Sau đó bấm "Tính lại" trong đơn rồi bấm "Gửi lại".`
- `line_tax_rate_count`: `Kiểm tra lớp thuế của sản phẩm và thuế trên dòng trong đơn: lớp thuế chỉ được có một thuế suất áp dụng. Sửa rồi bấm "Tính lại" trong đơn và bấm "Gửi lại".`
- `untaxed_line_with_tax`: `Thuế suất gán KCT/KKKNT phải là 0%. Sửa thuế suất trong WooCommerce hoặc gán lại ở tab "Xuất hóa đơn", bấm "Tính lại" trong đơn rồi bấm "Gửi lại".`
- `tax_rate_unsupported`: `Đổi thuế suất của dòng sang 0%, 5%, 8%, 10%, hoặc gán thuế suất 0% là KCT/KKKNT ở tab "Xuất hóa đơn", rồi bấm "Gửi lại".`

#### Scenario: Every code has a hint
- **WHEN** liệt kê mọi mã lỗi hóa đơn
- **THEN** mỗi mã có hướng dẫn không rỗng, và hướng dẫn của bốn mã thuế trên đúng nguyên văn

#### Scenario: Data error hint
- **WHEN** hóa đơn dừng với `line_tax_rate_count`
- **THEN** hướng dẫn yêu cầu kiểm tra thuế của sản phẩm và dòng trong đơn, bấm "Tính lại" rồi bấm "Gửi lại"

#### Scenario: Tax not configured hint
- **WHEN** hóa đơn dừng với `line_tax_not_configured`
- **THEN** hướng dẫn yêu cầu cài thuế cho sản phẩm (cả biến thể), phí hoặc phương thức vận chuyển, nêu cách dùng thuế suất 0% gán KCT/KKKNT ở tab "Xuất hóa đơn", rồi bấm "Tính lại" trong đơn và "Gửi lại"

#### Scenario: Exempt rate with tax hint
- **WHEN** hóa đơn dừng với `untaxed_line_with_tax`
- **THEN** hướng dẫn nêu thuế suất gán KCT/KKKNT phải là 0% và chỉ tới tab "Xuất hóa đơn", nút "Tính lại" và "Gửi lại"

#### Scenario: Unsupported rate hint
- **WHEN** hóa đơn dừng với `tax_rate_unsupported`
- **THEN** hướng dẫn nêu các thuế suất 0%, 5%, 8%, 10% và cách gán KCT/KKKNT ở tab "Xuất hóa đơn"

#### Scenario: Config error hint
- **WHEN** hóa đơn dừng với `credentials_missing`
- **THEN** hướng dẫn chỉ tới tab "Kết nối Mắt Bão" và nút "Kiểm tra kết nối"

#### Scenario: Uncertain send hint
- **WHEN** hóa đơn dừng với `unexpected`
- **THEN** hướng dẫn yêu cầu "Kiểm tra trạng thái" trên Mắt Bão trước khi bấm "Gửi lại"

#### Scenario: Same hint everywhere
- **WHEN** hóa đơn dừng với `line_tax_rate_count`
- **THEN** tin Lark, admin notice, meta box, chi tiết hóa đơn và event giám sát hiện cùng một câu hướng dẫn
