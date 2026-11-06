## Why

Plugin đã có code, test và ADR nhưng `openspec/specs/` còn trống. Vì vậy các thay đổi sau này không có baseline hành vi để đối chiếu. Sinh bộ spec đầu tiên từ code, test và tài liệu sẽ tạo nguồn sự thật chung cho mọi change OpenSpec tiếp theo.

## What Changes

- Thêm 12 spec capability mô tả hành vi hiện tại của plugin; mỗi requirement có `Evidence:` và đánh dấu `Confidence: inferred` khi chỉ dựa trên code.
- No product code changes; tasks verify each spec against the code and its evidence.
- Loại khỏi phạm vi: `vendor/`, `node_modules/`, `assets/dist/`, `OneDrive_1_10-13-2026/`, `openspec/`, cùng build/CI/dev tooling (`scripts/`, `.github/workflows/`, `docker/`, hạ tầng e2e).

## Capabilities

### New Capabilities
- `draft-invoice-creation`: tạo hóa đơn nháp Mắt Bão khi đơn hàng completed, gồm payload và lịch xử lý nền.
- `invoice-retry-dedup`: retry, timeout, lookup trước khi gửi lại, dedup bằng MTChieu, draft bị chặn, phân loại lỗi và redaction.
- `invoice-storage-schema`: bảng invoice/attempt, nâng cấp schema và truy vấn dữ liệu hóa đơn.
- `matbao-api-client`: giao tiếp với API Mắt Bão: xác thực, token, template, parse và map lỗi.
- `buyer-invoice-request`: người mua yêu cầu hóa đơn ở checkout Classic và Blocks, kèm validate.
- `buyer-snapshot-privacy`: snapshot người mua, bảo vệ CCCD, privacy exporter/eraser và che dữ liệu trong REST.
- `order-change-handling`: xử lý khi đơn hàng thay đổi sau khi completed hoặc đã có draft.
- `admin-settings`: trang cấu hình admin (credential, kết nối, invoicing, đơn vị tính, failure modes, uninstall).
- `admin-invoice-management`: quản lý hóa đơn trong admin, gồm danh sách, dashboard, thao tác, meta box, filter và đơn vị tính sản phẩm.
- `credential-encryption`: mã hóa secret bằng key trong wp-config.
- `failure-alerts`: cảnh báo lỗi hóa đơn qua admin notice và Lark webhook.
- `plugin-lifecycle`: bootstrap, phụ thuộc WooCommerce, activation và uninstall.

### Modified Capabilities

## Impact

Chỉ thêm tài liệu trong `openspec/changes/bootstrap-specs/`; không đổi code, API, dependency hay dữ liệu.
