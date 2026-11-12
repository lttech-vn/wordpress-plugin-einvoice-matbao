## ADDED Requirements

### Requirement: Resolution hint per error code
Hệ thống SHALL định nghĩa cho mỗi mã lỗi hóa đơn một hướng dẫn xử lý ngắn bằng tiếng Việt theo bảng hướng dẫn trong design của change, không chứa dữ liệu, nêu thao tác cụ thể (màn hình hoặc nút cần dùng), và SHALL dùng cùng hướng dẫn đó ở event giám sát, tin Lark, admin notice, meta box đơn hàng và chi tiết hóa đơn.

#### Scenario: Every code has a hint
- **WHEN** liệt kê mọi mã lỗi hóa đơn
- **THEN** mỗi mã có hướng dẫn không rỗng và đúng với bảng hướng dẫn

#### Scenario: Data error hint
- **WHEN** hóa đơn dừng với `line_tax_rate_count`
- **THEN** hướng dẫn yêu cầu kiểm tra thuế của sản phẩm và dòng trong đơn rồi bấm "Gửi lại"

#### Scenario: Config error hint
- **WHEN** hóa đơn dừng với `credentials_missing`
- **THEN** hướng dẫn chỉ tới tab "Kết nối Mắt Bão" và nút "Kiểm tra kết nối"

#### Scenario: Uncertain send hint
- **WHEN** hóa đơn dừng với `unexpected`
- **THEN** hướng dẫn yêu cầu "Kiểm tra trạng thái" trên Mắt Bão trước khi bấm "Gửi lại"

#### Scenario: Same hint everywhere
- **WHEN** hóa đơn dừng với `line_tax_rate_count`
- **THEN** tin Lark, admin notice, meta box, chi tiết hóa đơn và event giám sát hiện cùng một câu hướng dẫn

## MODIFIED Requirements

### Requirement: Admin notice for administrators
Hệ thống SHALL hiển thị notice lỗi trong wp-admin cho người dùng có quyền `manage_options`, liệt kê tối đa 10 alert chưa ẩn (mới nhất trước) kèm liên kết tới đơn, nhãn lỗi, hướng dẫn xử lý và liên kết "Ẩn".
Evidence: src/Alert/AdminAlertNotice.php:24-65, src/Alert/AlertStore.php:25,98-121, tests/integration/InvoiceAlertTest.php:94-104,250-265,318-334, tests/e2e/army/failures.army.ts:156-170,200-207

#### Scenario: Administrator sees an error
- **WHEN** một hóa đơn dừng ở `failed` và admin mở wp-admin
- **THEN** notice lỗi hiện "#<order>" kèm liên kết sửa đơn và hướng dẫn xử lý của mã lỗi

#### Scenario: User without capability
- **WHEN** người dùng không có `manage_options` mở wp-admin
- **THEN** không có notice

### Requirement: Lark message content and privacy
Hệ thống SHALL gửi tin Lark dạng text chỉ chứa số đơn, nhãn loại lỗi, mã lỗi, hướng dẫn xử lý của mã lỗi và liên kết admin tới đơn; tin Lark và notice MUST NOT chứa dữ liệu cá nhân người mua, URL webhook, secret hay token.
Evidence: src/Alert/InvoiceAlerts.php:142-153, tests/integration/InvoiceAlertTest.php:105-133

#### Scenario: Final error
- **WHEN** một alert mới được gửi
- **THEN** có đúng một request tới webhook với text chứa "#<order>", mã lỗi, hướng dẫn xử lý và liên kết đơn

#### Scenario: No personal data
- **WHEN** đơn có tên, email, địa chỉ và tên công ty người mua
- **THEN** tin Lark và notice không chứa các giá trị đó
