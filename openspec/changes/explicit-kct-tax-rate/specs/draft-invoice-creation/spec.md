## MODIFIED Requirements

### Requirement: Zero-value lines
Hệ thống SHALL bỏ dòng phí và vận chuyển có tiền hàng 0 đồng và tiền thuế 0 đồng trước mọi kiểm tra thuế suất, và SHALL vẫn gửi dòng sản phẩm 0 đồng trong đơn có tổng khác 0. Dòng sản phẩm 0 đồng SHALL tuân cùng quy tắc thuế suất như mọi dòng (xem VAT rate mapping).

#### Scenario: Free shipping
- **WHEN** đơn có dòng vận chuyển miễn phí hoặc dòng phí (fee) 0 đồng, kể cả khi dòng đó không lưu thuế suất nào
- **THEN** các dòng đó không có trong `DSHHDVu` và không gây lỗi thuế

#### Scenario: Free product
- **WHEN** đơn có một sản phẩm 0 đồng mang thuế suất 10% đã ánh xạ sang nhóm 10%, với tiền thuế 0, cùng sản phẩm có tiền
- **THEN** sản phẩm 0 đồng vẫn có dòng trong payload với `TSuat=10` và `TThue=0`

#### Scenario: Free product without a stored tax rate
- **WHEN** đơn có một sản phẩm 0 đồng không lưu thuế suất nào, cùng sản phẩm có tiền
- **THEN** không gửi create-invoice và hóa đơn dừng với `line_tax_not_configured`

### Requirement: VAT rate mapping
Hệ thống SHALL lấy `TSuat` của mỗi dòng chỉ từ ID thuế suất WooCommerce đã lưu trên dòng đó và bảng ánh xạ thuế admin khai báo (xem admin-settings). Hệ thống MUST NOT suy thuế từ trạng thái thuế, tên thuế, lớp thuế hay phần trăm, và MUST NOT tính lại thuế.

**Ánh xạ:** nhóm 10%, 8%, 5%, 0%, KCT, KKKNT SHALL cho `TSuat` lần lượt 10, 8, 5, 0, -1, -2. Nhóm Khác SHALL cho `TSuat` bằng phần trăm của thuế suất đã lưu trên đơn.

**Chặn gửi:** hệ thống SHALL chặn gửi với lỗi dữ liệu, kiểm tra theo đúng thứ tự sau và dừng ở lỗi đầu tiên:
1. dòng không có thuế suất nào đã lưu → `line_tax_not_configured`, kể cả dòng có trạng thái thuế *Không*;
2. dòng có từ hai thuế suất → `line_tax_rate_count`;
3. thuế suất của dòng không thuộc nhóm nào trong bảng → `tax_rate_mismatch`;
4. thuế suất không khớp yêu cầu của nhóm → `tax_rate_mismatch`:
   - nhóm 10%, 8%, 5%: phần trăm lưu trên đơn phải bằng giá trị của nhóm;
   - nhóm 0%, KCT, KKKNT: tiền thuế của dòng phải bằng 0;
   - nhóm Khác: phần trăm lưu trên đơn phải lớn hơn 0 và khác 5, 8, 10;
   - nhóm cần phần trăm (10, 8, 5, Khác) mà đơn không lưu phần trăm của thuế suất đó.

#### Scenario: Mixed rates
- **WHEN** bảng ánh xạ có thuế suất 10%, 8%, 5%, 0% vào đúng nhóm, thuế suất 0% tên KCT vào nhóm KCT, thuế suất 0% tên KKKNT vào nhóm KKKNT, thuế suất 3,5% vào nhóm Khác, và đơn có một sản phẩm mỗi loại, giá 100000
- **THEN** `TSuat` lần lượt 10, 8, 5, 0, -1, -2, 3.5 và `TgTThue` bằng tổng thuế các dòng

#### Scenario: Several rates in one group
- **WHEN** hai thuế suất 10% ở lớp Standard và ở một lớp bổ sung cùng thuộc nhóm 10%, và đơn có một sản phẩm mỗi lớp
- **THEN** cả hai dòng có `TSuat=10`

#### Scenario: Empty mapping
- **WHEN** bảng ánh xạ chưa có thuế suất nào và đơn có sản phẩm thuế 10%
- **THEN** không gửi create-invoice, status `failed`, mã lỗi `tax_rate_mismatch` và lý do nêu thuế suất chưa được ánh xạ

#### Scenario: Mapping read when sending
- **WHEN** đơn hoàn thành khi thuế suất 10% chưa được ánh xạ, sau đó admin thêm thuế suất đó vào nhóm 10% trước khi hóa đơn được gửi
- **THEN** dòng có `TSuat=10`

#### Scenario: Not taxable line is not configured
- **WHEN** đơn có sản phẩm với trạng thái thuế *Không* (dòng không lưu thuế suất nào)
- **THEN** không gửi create-invoice, status `failed`, mã lỗi `line_tax_not_configured`, lý do `Dòng "<tên>" chưa có thuế GTGT.` và không có attempt

#### Scenario: Taxable line without a stored rate
- **WHEN** dòng phí hoặc vận chuyển có tiền nhưng không lưu thuế suất nào
- **THEN** hóa đơn dừng với `line_tax_not_configured`

#### Scenario: Several rates on one line
- **WHEN** dòng có thuế ở hai thuế suất đều đã ánh xạ
- **THEN** hóa đơn dừng với `line_tax_rate_count`

#### Scenario: Percent differs from its group
- **WHEN** thuế suất trong nhóm 8% bị sửa thành 10% trong WooCommerce và đơn mới lưu 10% cho dòng
- **THEN** hóa đơn dừng với `tax_rate_mismatch`

#### Scenario: Zero group with tax amount
- **WHEN** dòng mang thuế suất thuộc nhóm KCT (hoặc 0%, KKKNT) nhưng có tiền thuế khác 0
- **THEN** hóa đơn dừng với `tax_rate_mismatch`

#### Scenario: Unsupported rate
- **WHEN** một dòng có thuế suất 7% chưa thuộc nhóm nào trong bảng
- **THEN** không gửi create-invoice, status `failed`, mã lỗi `tax_rate_mismatch`, lý do nêu tên thuế suất và không có attempt

#### Scenario: Other group with a standard percent
- **WHEN** thuế suất 8% được đặt vào nhóm Khác và đơn có dòng mang thuế suất đó
- **THEN** hóa đơn dừng với `tax_rate_mismatch`

#### Scenario: Percent not stored on the order
- **WHEN** dòng mang thuế suất thuộc nhóm 10% nhưng đơn không lưu phần trăm của thuế suất đó
- **THEN** hóa đơn dừng với `tax_rate_mismatch` và lý do hướng dẫn bấm "Tính lại"

#### Scenario: Deleted rate keeps mapping old orders
- **WHEN** thuế suất thuộc nhóm KCT bị xóa khỏi WooCommerce và một đơn cũ đã lưu thuế suất đó với tiền thuế 0 được gửi
- **THEN** dòng có `TSuat=-1`

#### Scenario: Taxes calculated while tax calculation is off
- **WHEN** WooCommerce tắt tính thuế và đơn được tính tổng lúc đó, nên dòng sản phẩm có tiền không lưu thuế suất nào
- **THEN** hóa đơn dừng với `line_tax_not_configured`

#### Scenario: Stored taxes kept after tax calculation is turned off
- **WHEN** đơn đã lưu thuế suất 10% (thuộc nhóm 10%) trên dòng, sau đó WooCommerce tắt tính thuế trước khi hóa đơn được gửi
- **THEN** payload vẫn có `TSuat=10` và tiền thuế đã lưu
