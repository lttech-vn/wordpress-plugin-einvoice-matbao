## ADDED Requirements

### Requirement: Line details on line data errors
Khi chặn gửi vì lỗi dữ liệu của một dòng (các mã `line_*`, `amount_not_whole_vnd`, `tax_rate_*`, `untaxed_line_with_tax`), hệ thống SHALL kèm theo lỗi thông tin của dòng đó: line item ID, loại dòng, product ID, variation ID, SKU, tên và ID các thuế suất trên dòng. Lỗi dữ liệu không gắn với một dòng MUST NOT mang thông tin dòng. Lý do lưu và mã lỗi SHALL giữ nguyên.

#### Scenario: Two tax rates on a product line
- **WHEN** dòng sản phẩm (line item 12, product 55, variation 0, SKU `AO-01`) có thuế ở hai thuế suất 3 và 7
- **THEN** lỗi `line_tax_rate_count` mang line item 12, loại `line_item`, product 55, SKU `AO-01` và thuế suất [3, 7], và lý do lưu vẫn là `Dòng "<tên>" cần đúng một thuế suất GTGT.`

#### Scenario: Shipping line with fractional amount
- **WHEN** dòng vận chuyển có số tiền không nguyên đồng
- **THEN** lỗi `amount_not_whole_vnd` mang line item ID và loại `shipping`, với product ID và SKU để trống

#### Scenario: Totals mismatch
- **WHEN** tổng các dòng lệch tổng đơn
- **THEN** lỗi `totals_mismatch` không mang thông tin dòng
