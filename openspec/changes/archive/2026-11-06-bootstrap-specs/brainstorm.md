## Design Summary

Sinh bộ spec đầu tiên (draft) cho plugin WooCommerce xuất hóa đơn điện tử nháp qua Mắt Bão, dựa trên code, test và tài liệu hiện có. `openspec/specs/` đang trống nên mọi capability đều là mới. No product code changes; tasks verify each spec against the code and its evidence.

Kết quả map codebase (map scout) — 12 capability đã được user xác nhận, trong đó `plugin-lifecycle-dependencies` đổi tên thành `plugin-lifecycle` và loại bỏ build/CI/dev tooling:

| Capability | Mục đích | Phạm vi chính |
|---|---|---|
| `draft-invoice-creation` | Khi đơn chuyển `completed`, giữ chỗ một bản ghi invoice, xếp job Action Scheduler gọi create-invoice tạo hóa đơn nháp, ghi attempt | `src/Invoice/DraftInvoiceWorkflow.php`, `DraftPayloadBuilder.php`, `AmountInWords.php`, `InvoiceRecord.php`, `InvoiceStatus.php` |
| `invoice-retry-dedup` | Retry, timeout, lookup trước khi gửi lại, MTChieu là dedup key duy nhất, draft bị chặn, phân loại lỗi, redaction | `src/Invoice/InvoiceError*.php`, `InvoiceDataError.php`, `Redactor.php`; ADR 0001 |
| `invoice-storage-schema` | Bảng invoice/attempt riêng, migration schema, repository | `src/Invoice/Schema.php`, `InvoiceRepository.php`; ADR 0002 |
| `matbao-api-client` | Auth, cache token, base URL, gọi API, parse invoice/template, cache template, map lỗi | `src/Matbao/*` |
| `buyer-invoice-request` | Người mua yêu cầu hóa đơn ở checkout Classic và Blocks, validate thông tin | `src/Buyer/BuyerInput.php`, `BuyerRules.php`, `BuyerType.php`, `BuyerRequestStore.php`, `src/Checkout/*`, `assets/src/checkout*.ts` |
| `buyer-snapshot-privacy` | Snapshot người mua, bảo vệ CCCD, privacy exporter/eraser, che dữ liệu trong REST | `src/Buyer/BuyerSnapshot*.php`, `OrderRestPrivacy.php`, `src/Privacy/*` |
| `order-change-handling` | Xử lý khi đơn bị sửa sau khi đã completed hoặc đã có draft | `src/Invoice/OrderChangeWatcher.php`, `OrderState.php` |
| `admin-settings` | Trang settings (htmx + REST fragment, chỉ admin, nonce) | `src/Admin/Settings*.php`, `Fragment.php`, `src/Settings.php`, `src/Uninstall/UninstallSettingsController.php`; ADR 0003 |
| `admin-invoice-management` | Danh sách, dashboard, thao tác invoice, meta box đơn hàng, filter đơn, đơn vị tính sản phẩm | `src/Admin/Invoice*.php`, `DashboardStats.php`, `OrderInvoiceMetaBox.php`, `OrderListInvoiceFilter.php`, `ProductUnitField.php` |
| `credential-encryption` | Mã hóa secret bằng libsodium với key trong wp-config, từ chối lưu khi thiếu key | `src/Encryption.php`, `CredentialStore.php`, `MissingEncryptionKey.php` |
| `failure-alerts` | Admin notice và Lark webhook khi hóa đơn lỗi | `src/Alert/*`, `src/Lark/*` |
| `plugin-lifecycle` | Bootstrap, chặn khi thiếu WooCommerce, activation, uninstall | `einvoice-matbao.php`, `uninstall.php`, `src/Plugin.php`, `src/WooCommerceDependency.php`, `src/Uninstall/Uninstaller.php` |

## Alternatives Considered

### Alternative A: Giữ nguyên 12 candidate như map, gồm cả build/CI tooling
- **Approach**: `plugin-lifecycle-dependencies` mô tả cả scripts, workflows và docker.
- **Pros**: Phủ toàn bộ repo.
- **Cons**: Trộn hành vi sản phẩm với hạ tầng phát triển; requirement khó kiểm chứng như hành vi observable.
- **Why not chosen**: Spec chỉ mô tả hành vi của plugin.

### Alternative B: Ít capability hơn (gộp theo thư mục `src/`)
- **Approach**: Một capability cho mỗi thư mục cấp một (`Invoice`, `Buyer`, `Admin`...).
- **Pros**: Ít file hơn.
- **Cons**: Capability quá rộng, ranh giới theo cấu trúc code thay vì hành vi.
- **Why not chosen**: Khó review và khó sửa đổi về sau.

## Agreed Approach

Dùng 12 capability theo hành vi như bảng trên, bỏ build/CI/dev tooling khỏi `plugin-lifecycle`. Mỗi capability có một scout chỉ thu thập evidence; parent viết spec. No product code changes; tasks verify each spec against the code and its evidence.

## Key Decisions

- Loại trừ khỏi phạm vi: `vendor/`, `node_modules/`, `assets/dist/`, `OneDrive_1_10-13-2026/`, `openspec/`, cùng build/CI/dev tooling (`scripts/`, `.github/workflows/`, `docker/`, hạ tầng e2e).
- Requirement chỉ dựa trên code, không có test hoặc tài liệu chứng minh, được đánh dấu `Confidence: inferred`.
- Mỗi hành vi chỉ có một capability sở hữu; capability khác tham chiếu bằng "xem <capability>". Ví dụ: gửi một lần thuộc `draft-invoice-creation`; dừng do đơn rời completed hoặc hoàn tiền thuộc `order-change-handling`; khóa mã hóa thuộc `credential-encryption`; đăng nhập lại khi 401 và token thuộc `matbao-api-client`; quy tắc snapshot và kiểm tra lại người mua thuộc `buyer-snapshot-privacy`; kiểm soát truy cập REST thuộc `admin-settings`.

## Open Questions

- Không còn câu hỏi mở. Ranh giới giữa các capability đã được chốt qua review spec nội bộ (xem Key Decisions).
