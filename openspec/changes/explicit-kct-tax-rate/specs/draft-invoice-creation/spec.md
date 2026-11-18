## MODIFIED Requirements

### Requirement: Zero-value lines
Hệ thống SHALL bỏ dòng phí và vận chuyển có tiền hàng 0 đồng và tiền thuế 0 đồng trước mọi kiểm tra thuế suất, và SHALL vẫn gửi dòng sản phẩm 0 đồng trong đơn có tổng khác 0. Dòng sản phẩm 0 đồng SHALL tuân cùng quy tắc thuế suất như mọi dòng (xem VAT rate mapping): `TSuat` lấy từ thuế suất đã lưu trên dòng, không còn được suy từ trạng thái thuế khi dòng không lưu thuế suất.

#### Scenario: Free shipping
- **WHEN** đơn có dòng vận chuyển miễn phí hoặc dòng phí (fee) 0 đồng, kể cả khi dòng đó không lưu thuế suất nào
- **THEN** các dòng đó không có trong `DSHHDVu` và không gây lỗi thuế

#### Scenario: Free product
- **WHEN** đơn có một sản phẩm 0 đồng mang thuế suất 10% đã lưu với tiền thuế 0, cùng sản phẩm có tiền
- **THEN** sản phẩm 0 đồng vẫn có dòng trong payload với `TSuat=10` và `TThue=0`

#### Scenario: Free product without a stored tax rate
- **WHEN** đơn có một sản phẩm 0 đồng không lưu thuế suất nào, cùng sản phẩm có tiền
- **THEN** không gửi create-invoice và hóa đơn dừng với `line_tax_not_configured`

### Requirement: VAT rate mapping
Hệ thống SHALL lấy `TSuat` của mỗi dòng chỉ từ thuế suất WooCommerce đã lưu trên dòng đó. Hệ thống MUST NOT suy thuế suất từ trạng thái thuế hiện tại của sản phẩm, phí hay vận chuyển, và MUST NOT tính lại thuế theo cấu hình hiện tại.

Ánh xạ: thuế suất được admin gán là KCT SHALL cho `TSuat=-1`, thuế suất gán là KKKNT SHALL cho `TSuat=-2`. Thuế suất khác SHALL ánh xạ theo phần trăm đã lưu trên đơn sang `TSuat` thuộc {0, 5, 8, 10}.

Hệ thống SHALL chặn gửi với lỗi dữ liệu, kiểm tra theo đúng thứ tự sau và dừng ở lỗi đầu tiên:
1. dòng không có thuế suất nào đã lưu → `line_tax_not_configured`, kể cả dòng có trạng thái thuế *Không*;
2. dòng có từ hai thuế suất → `line_tax_rate_count`, kể cả khi một trong số đó được gán KCT/KKKNT;
3. dòng mang thuế suất gán KCT hoặc KKKNT nhưng có tiền thuế khác 0 → `untaxed_line_with_tax`;
4. thuế suất chưa gán không có phần trăm lưu trên đơn → `tax_rate_not_stored`;
5. phần trăm đã lưu không thuộc {0, 5, 8, 10} → `tax_rate_unsupported`.

#### Scenario: Mixed rates
- **WHEN** đơn có hàng 10%, 5%, 8%, 0%, hàng mang thuế suất gán KCT và hàng mang thuế suất gán KKKNT
- **THEN** `TSuat` lần lượt 10, 5, 8, 0, -1, -2 và `TgTThue` bằng tổng thuế các dòng

#### Scenario: Several exempt rates
- **WHEN** admin gán hai thuế suất 0% khác nhau (ở lớp Standard và ở một lớp bổ sung) là KCT và đơn có hai sản phẩm mang hai thuế suất đó
- **THEN** cả hai dòng có `TSuat=-1`

#### Scenario: Zero rate not assigned
- **WHEN** dòng mang thuế suất 0% chưa được gán KCT hay KKKNT
- **THEN** `TSuat=0`

#### Scenario: Assignment read when sending
- **WHEN** đơn hoàn thành với dòng thuế suất 0% chưa gán, sau đó admin gán thuế suất đó là KCT trước khi hóa đơn được gửi
- **THEN** dòng có `TSuat=-1`

#### Scenario: Not taxable line is not configured
- **WHEN** đơn có sản phẩm với trạng thái thuế *Không* (dòng không lưu thuế suất nào)
- **THEN** không gửi create-invoice, status `failed`, mã lỗi `line_tax_not_configured`, lý do `Dòng "<tên>" chưa có thuế GTGT.` và không có attempt

#### Scenario: Taxable line without a stored rate
- **WHEN** dòng phí hoặc vận chuyển có tiền nhưng không lưu thuế suất nào
- **THEN** hóa đơn dừng với `line_tax_not_configured`

#### Scenario: Several rates on one line
- **WHEN** dòng có thuế ở hai thuế suất, một trong hai được gán KCT, và có tiền thuế khác 0
- **THEN** hóa đơn dừng với `line_tax_rate_count`

#### Scenario: Exempt rate with tax amount
- **WHEN** dòng mang thuế suất gán KCT hoặc KKKNT nhưng có tiền thuế khác 0
- **THEN** hóa đơn dừng với `untaxed_line_with_tax` và lý do `Dòng "<tên>" dùng thuế KCT/KKKNT nhưng có tiền thuế.`

#### Scenario: Rate percent not stored
- **WHEN** dòng có đúng một thuế suất chưa gán nhưng đơn không lưu phần trăm của thuế suất đó
- **THEN** hóa đơn dừng với `tax_rate_not_stored`

#### Scenario: Unsupported rate
- **WHEN** một dòng có thuế suất 7%
- **THEN** không gửi create-invoice, status `failed`, lý do nêu 7 và không có attempt

#### Scenario: Taxes calculated while tax calculation is off
- **WHEN** WooCommerce tắt tính thuế và đơn được tính tổng lúc đó, nên dòng sản phẩm có tiền không lưu thuế suất nào
- **THEN** hóa đơn dừng với `line_tax_not_configured`

#### Scenario: Stored taxes kept after tax calculation is turned off
- **WHEN** đơn đã lưu thuế suất 10% trên dòng, sau đó WooCommerce tắt tính thuế trước khi hóa đơn được gửi
- **THEN** payload vẫn có `TSuat=10` và tiền thuế đã lưu
