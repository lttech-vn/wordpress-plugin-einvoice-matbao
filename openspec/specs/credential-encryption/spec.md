# credential-encryption Specification

## Purpose

Mã hóa secret bằng key trong wp-config.

## Requirements

### Requirement: Secrets encrypted with a wp-config key
Hệ thống SHALL mã hóa secret bằng libsodium với nonce ngẫu nhiên cho mỗi lần mã hóa và khóa lấy từ hằng `EINVOICE_MATBAO_ENCRYPTION_KEY` trong `wp-config.php`.
Evidence: src/Encryption.php:41-50,99, tests/integration/EncryptionTest.php:28-32, README.md:30-34

#### Scenario: Round trip
- **WHEN** một chuỗi được mã hóa rồi giải mã với cùng khóa
- **THEN** kết quả đúng bằng chuỗi gốc

### Requirement: Minimum key length
Hệ thống SHALL coi khóa mã hóa là chưa cấu hình khi hằng không được định nghĩa, không phải chuỗi, hoặc ngắn hơn 32 ký tự.
Evidence: src/Encryption.php:21,95-97, README.md:33

#### Scenario: Key too short
- **WHEN** hằng `EINVOICE_MATBAO_ENCRYPTION_KEY` có dưới 32 ký tự
- **THEN** hệ thống coi như thiếu khóa và từ chối mã hóa

### Requirement: Key override only outside production
Hệ thống SHALL chỉ cho filter `einvoice_matbao_encryption_key` thay khóa trong môi trường `local` hoặc `development`, hoặc khi chạy test suite, để plugin khác không đổi được khóa trên production.
Evidence: src/Encryption.php:84-92,105-107
Confidence: inferred

#### Scenario: Production ignores filter
- **WHEN** môi trường là production và một filter trả khóa khác
- **THEN** khóa vẫn lấy từ hằng trong `wp-config.php`

### Requirement: Damaged ciphertext is rejected
Hệ thống SHALL coi bản mã là không đọc được khi bản mã sai định dạng, bị cắt, bị sửa, rỗng, được mã hóa bằng khóa khác, hoặc khi không có khóa.
Evidence: src/Encryption.php:58-76, tests/integration/EncryptionTest.php:34-53, tests/integration/SettingsCredentialsRestTest.php:295-303

#### Scenario: Tampered ciphertext
- **WHEN** một ký tự của bản mã bị đổi
- **THEN** giải mã không trả về dữ liệu

#### Scenario: Different key
- **WHEN** credential đã lưu và khóa sau đó bị đổi
- **THEN** credential Mắt Bão không đọc được

### Requirement: Credentials stored only encrypted
Hệ thống SHALL lưu credential Mắt Bão (môi trường, mã số thuế, tên đăng nhập, mật khẩu) và webhook Lark (URL, secret) chỉ dưới dạng mã hóa trong option không autoload, không bao giờ ghi bản rõ.
Evidence: src/CredentialStore.php:15-23,118-123, tests/integration/SettingsCredentialsRestTest.php:59-89,160-184

#### Scenario: Saved credentials
- **WHEN** admin lưu credential Mắt Bão khi có khóa hợp lệ
- **THEN** các option của plugin trong DB không chứa mật khẩu hay tên đăng nhập dạng rõ, và plugin đọc lại đúng giá trị

### Requirement: Refuse saving without encryption key
Hệ thống SHALL từ chối lưu credential Mắt Bão và webhook Lark khi thiếu khóa mã hóa, phản hồi HTTP 422 với thông báo nêu hằng `EINVOICE_MATBAO_ENCRYPTION_KEY` và `wp-config.php`, và không ghi gì vào DB.
Evidence: src/Admin/SettingsController.php:133-135,312-314,433-441, tests/integration/SettingsCredentialsRestTest.php:23-57

#### Scenario: Save Mat Bao credential without key
- **WHEN** admin lưu credential Mắt Bão khi chưa có khóa
- **THEN** phản hồi 422 với notice lỗi nêu tên hằng và `wp-config.php`, và option credential không tồn tại

#### Scenario: Save Lark webhook without key
- **WHEN** admin lưu webhook Lark khi chưa có khóa
- **THEN** phản hồi 422 và không có gì được lưu

### Requirement: Missing key notice on settings page
Hệ thống SHALL hiển thị cảnh báo trên trang settings khi chưa cấu hình khóa mã hóa, nói rõ plugin chưa thể lưu credential Mắt Bão và Lark.
Evidence: src/Admin/SettingsPage.php:79-107
Confidence: inferred

#### Scenario: Settings page without key
- **WHEN** admin mở trang settings khi chưa có khóa
- **THEN** trang có banner `role="alert"` nêu hằng `EINVOICE_MATBAO_ENCRYPTION_KEY`

### Requirement: Secrets are never rendered back
Hệ thống SHALL không hiển thị lại mật khẩu Mắt Bão, URL webhook Lark hay secret Lark trong phản hồi hoặc trang settings; ô nhập để trống và chỉ hiện trạng thái đã lưu.
Evidence: src/Admin/SettingsPage.php:129-130,209-214,283, tests/integration/SettingsCredentialsRestTest.php:59-99,160-184, README.md:37

#### Scenario: Page after save
- **WHEN** admin mở trang settings sau khi lưu credential
- **THEN** HTML không chứa mật khẩu và có chữ "Đã lưu"

### Requirement: Undecryptable credentials need re-entry
Hệ thống SHALL coi credential đã lưu mà không giải mã được (thiếu hoặc đổi khóa) là không dùng được, vẫn cho biết có bản đã lưu, và yêu cầu admin nhập lại.
Evidence: src/CredentialStore.php:33-45,131-145, src/Admin/SettingsController.php:346,404,446-453, README.md:36-37, tests/integration/SettingsCredentialsRestTest.php:295-303

#### Scenario: Key changed
- **WHEN** khóa bị đổi sau khi credential đã được lưu
- **THEN** credential không dùng được và admin được yêu cầu nhập lại thông tin

### Requirement: Secrets redacted in debug output
Hệ thống SHALL che mật khẩu Mắt Bão bằng `[REDACTED]` khi đối tượng credential được dump để debug, và thông báo lỗi thiếu khóa SHALL không chứa secret.
Evidence: src/Matbao/MatbaoCredentials.php:45,80-87, src/MissingEncryptionKey.php:20-22
Confidence: inferred

#### Scenario: Dump credentials
- **WHEN** đối tượng credential Mắt Bão bị dump bằng `var_dump` hoặc `print_r`
- **THEN** trường mật khẩu hiển thị `[REDACTED]`
