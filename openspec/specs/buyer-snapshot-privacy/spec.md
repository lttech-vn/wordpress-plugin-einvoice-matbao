# buyer-snapshot-privacy Specification

## Purpose

Snapshot người mua, bảo vệ CCCD, privacy exporter/eraser và che dữ liệu trong REST.

## Requirements

### Requirement: Buyer snapshot per buyer type
Hệ thống SHALL chụp snapshot người mua vào order meta `_einvoice_buyer_snapshot` khi đơn completed, và payload hóa đơn chỉ chứa các trường người mua tương ứng với loại người mua.
Evidence: src/Buyer/BuyerSnapshotter.php:99-124, tests/integration/DraftInvoiceBuyerSnapshotTest.php:35-84, tests/integration/DraftInvoiceWorkflowTest.php:24-87

#### Scenario: Walk-in buyer
- **WHEN** đơn không có yêu cầu xuất hóa đơn được gửi
- **THEN** trường `NMua_*` duy nhất là `NMua_Ten` = tên người mua vãng lai đã cấu hình (mặc định "Khách hàng không lấy hóa đơn")

#### Scenario: Individual buyer
- **WHEN** đơn của người mua cá nhân được gửi
- **THEN** payload có `NMua_Ten`, `NMua_HVTNMHang`, `NMua_DChi`, `NMua_CCCDan`, `NMua_SDThoai`, `NMua_DCTDTu` (khi email hợp lệ) và không có `NMua_MST`

#### Scenario: Business buyer
- **WHEN** đơn của người mua doanh nghiệp được gửi
- **THEN** payload có `NMua_Ten` = tên doanh nghiệp và `NMua_MST`, không có `NMua_HVTNMHang` hay `NMua_CCCDan`

### Requirement: Attempts send the stored snapshot
Hệ thống SHALL dùng snapshot đã lưu cho mọi lần gửi (kể cả gửi lại), không đọc lại billing hiện tại của đơn.
Evidence: tests/integration/DraftInvoiceBuyerSnapshotTest.php:86-100,249-268

#### Scenario: Billing changed after capture
- **WHEN** billing bị sửa sau khi snapshot được chụp, rồi tác vụ gửi chạy
- **THEN** payload mang giá trị trong snapshot

### Requirement: Snapshot captured once per completion
Hệ thống SHALL chụp snapshot đúng một lần cho lần completed giữ được bản ghi hóa đơn, không chụp cho đơn 0 đồng, và chỉ cho phép chụp lại khi chưa có nháp thành công và chưa attempt nào có thể đã tới Mắt Bão.
Evidence: src/Buyer/BuyerSnapshotter.php:14-16,36-38, tests/integration/DraftInvoiceBuyerSnapshotTest.php:102-113,363-400, tests/integration/PostDraftOrderChangeTest.php:264-340

#### Scenario: Completed twice
- **WHEN** hook completed chạy hai lần cho một đơn
- **THEN** chỉ có một snapshot, một ghi chú CCCD và một tác vụ được xếp

#### Scenario: Zero-total order
- **WHEN** đơn có tổng 0 completed
- **THEN** hóa đơn `skipped` và không có meta snapshot

### Requirement: Citizen ID kept only in encrypted order meta
Hệ thống SHALL lưu CCCD đầy đủ chỉ ở dạng mã hóa trong order meta và chỉ giải mã khi dựng payload; bảng hóa đơn, attempt, ghi chú đơn và lý do lỗi chỉ chứa bản mask 4 số cuối (ví dụ `********1234`).
Evidence: src/Buyer/BuyerSnapshot.php:15-17,45-63, docs/adr/0002-invoice-tables-and-order-meta-split.md:3-5, tests/integration/DraftInvoiceBuyerSnapshotTest.php:115-136

#### Scenario: No CCCD leak
- **WHEN** nháp cho người mua cá nhân được tạo thành công
- **THEN** số CCCD đầy đủ không xuất hiện ở dạng rõ trong ghi chú đơn, bản ghi hóa đơn, attempt hay order meta

### Requirement: Unusable snapshot fails safely
Hệ thống SHALL đánh dấu hóa đơn lỗi và không gọi Mắt Bão khi snapshot thiếu hoặc tên rỗng (`buyer_snapshot_invalid`), thiếu khóa mã hóa (`buyer_id_key_missing`) hoặc không giải mã được CCCD (`buyer_id_unreadable`); thông báo không chứa số CCCD.
Evidence: src/Buyer/BuyerSnapshot.php:45-58, tests/integration/DraftInvoiceBuyerSnapshotTest.php:138-247

#### Scenario: Corrupted ciphertext
- **WHEN** CCCD đã mã hóa bị hỏng
- **THEN** hóa đơn `failed` với `buyer_id_unreadable`, không có request tới Mắt Bão và hook needs-attention phát một lần

#### Scenario: Missing snapshot
- **WHEN** tác vụ gửi chạy mà đơn không có snapshot
- **THEN** hóa đơn `failed` với `buyer_snapshot_invalid` và không gọi API

### Requirement: Buyer revalidation before capture
Hệ thống SHALL kiểm tra lại yêu cầu xuất hóa đơn và billing hiện tại theo luật của checkout trước khi chụp snapshot; dữ liệu không hợp lệ làm hóa đơn lỗi `buyer_invalid`, không gửi, không retry, và lý do không chứa giá trị vi phạm.
Evidence: src/Buyer/BuyerSnapshotter.php:64-91, tests/integration/DraftInvoiceBuyerSnapshotTest.php:333-360

#### Scenario: Invalid phone
- **WHEN** admin sửa số điện thoại billing thành `12345` trước khi đơn completed
- **THEN** hóa đơn `failed` với `buyer_invalid`, không có request API và lý do không chứa `12345`

### Requirement: REST order responses hide buyer meta
Hệ thống SHALL loại `_einvoice_buyer_request` và `_einvoice_buyer_snapshot` khỏi `meta_data` trong mọi phản hồi đơn hàng của WooCommerce REST API (các phiên bản v1 tới v4, đơn lẻ lẫn danh sách), và vẫn trả các meta khác.
Evidence: src/Buyer/OrderRestPrivacy.php:22-68, tests/integration/OrderRestBuyerPrivacyTest.php:47-110

#### Scenario: Shop manager reads an order
- **WHEN** shop manager gọi `GET /wc/v3/orders/{id}`
- **THEN** phản hồi không chứa CCCD, bản mã hóa, bản mask, địa chỉ hay tên hai meta key; các meta khác vẫn có

### Requirement: Privacy exporter
Hệ thống SHALL cung cấp WordPress personal-data exporter xuất dữ liệu xuất hóa đơn theo email (billing email hoặc tài khoản cùng email), gồm tên, MST, địa chỉ, điện thoại, email, `MTChieu`, trạng thái và CCCD dạng mask.
Evidence: src/Privacy/BuyerPersonalData.php:65-120,209-248, tests/integration/BuyerPersonalDataPrivacyTest.php:57-95,153,253,286

#### Scenario: Export customer orders
- **WHEN** xuất dữ liệu cho email của một đơn cá nhân
- **THEN** có `CCCD` = `********5678`, không có số đầy đủ hay bản mã hóa, và không có dữ liệu của email khác

#### Scenario: Paged export
- **WHEN** email có hơn 10 đơn
- **THEN** dữ liệu được xuất theo trang 10 đơn, bỏ qua đơn vãng lai không có dữ liệu

### Requirement: Privacy eraser
Hệ thống SHALL cung cấp WordPress personal-data eraser chạy trước eraser của WooCommerce, xóa yêu cầu và snapshot người mua của các đơn thuộc email được yêu cầu, ẩn danh tên người mua và dữ liệu cá nhân trong lý do lỗi và attempt.
Evidence: src/Privacy/BuyerPersonalData.php:55,129-201,299-378, tests/integration/BuyerPersonalDataPrivacyTest.php:101-250

#### Scenario: Erase an order with a draft
- **WHEN** xóa dữ liệu cho email của đơn đã `draft_created`
- **THEN** hai meta bị xóa, tên người mua thành marker ẩn danh và status vẫn `draft_created`

#### Scenario: Walk-in order
- **WHEN** xóa dữ liệu cho đơn vãng lai
- **THEN** eraser không báo đã ẩn danh và tên người mua vãng lai giữ nguyên

### Requirement: Accounting records retained on erase
Hệ thống SHALL giữ bản ghi hóa đơn và attempt (`MTChieu`, trạng thái, mã hóa đơn Mắt Bão) khi xóa dữ liệu cá nhân, thông báo lý do giữ lại là nghĩa vụ kế toán và hóa đơn trên Mắt Bão nằm ngoài plugin; chạy lại không xóa thêm và chỉ ảnh hưởng email được yêu cầu.
Evidence: src/Privacy/BuyerPersonalData.php:129-201, tests/integration/BuyerPersonalDataPrivacyTest.php:101-250,274

#### Scenario: Retention message
- **WHEN** eraser chạy cho đơn có hóa đơn
- **THEN** thông báo trả về nhắc dữ liệu "trên Mắt Bão" và bản ghi hóa đơn vẫn còn

#### Scenario: Erase again
- **WHEN** eraser chạy lần hai cho cùng email
- **THEN** không có mục nào bị xóa thêm

#### Scenario: Erase wins over pending send
- **WHEN** đơn đang chờ gửi hóa đơn thì bị xóa dữ liệu cá nhân
- **THEN** hóa đơn không được gửi với dữ liệu đã xóa

### Requirement: Erase on WooCommerce order anonymization
Hệ thống SHALL xóa dữ liệu người mua của plugin (meta, tên người mua, dữ liệu trong lý do lỗi và attempt) khi WooCommerce ẩn danh một đơn hàng.
Evidence: src/Privacy/BuyerPersonalData.php:56,163-167, tests/integration/BuyerPersonalDataPrivacyTest.php:213-235,298

#### Scenario: WooCommerce anonymizes order
- **WHEN** WooCommerce chạy eraser dữ liệu cá nhân cho một đơn
- **THEN** hai meta key bị xóa, tên người mua không còn và `MTChieu` giữ nguyên
