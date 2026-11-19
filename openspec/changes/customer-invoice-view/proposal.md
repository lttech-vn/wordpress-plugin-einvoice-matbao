## Why

Người mua hiện không biết đơn hàng đã có hóa đơn điện tử chưa và phải hỏi cửa hàng để nhận file. Plugin đã đồng bộ trạng thái Mắt Bão mỗi giờ nhưng chỉ admin nhìn thấy. Cho khách tự xem trạng thái theo từng đơn và tự tải PDF khi hóa đơn đã phát hành hợp lệ sẽ giảm việc hỗ trợ thủ công và giúp cửa hàng chuyển hóa đơn cho người mua, mà không lộ hóa đơn nháp hay thông tin vận hành nội bộ.

## What Changes

- Trang chi tiết đơn trong My Account > Orders có khối "Hóa đơn điện tử" cho mọi đơn đã có bản ghi hóa đơn, kể cả đơn của người mua vãng lai. Khối hiện trạng thái bằng lời thân thiện (đang xuất, đang xử lý, không cần xuất, đã xuất, đã điều chỉnh), không có mã lỗi, lý do lỗi, `MTChieu` hay tên trạng thái Mắt Bão.
- Khi hóa đơn có trạng thái Mắt Bão "Đã cấp mã/tiếp nhận" và loại không phải xóa bỏ hay bị thay thế, khối hiện số hóa đơn, ký hiệu, ngày lập và nút tải PDF.
- Nút tải PDF chỉ dùng được với chủ đơn đã đăng nhập. Ngay trước khi trả file, plugin tra cứu lại Mắt Bão một lần và lấy PDF qua `download-invoice`. Plugin không lưu PDF.
- Plugin đọc và lưu mã tra cứu (`maTraCuu`) từ kết quả tra cứu hóa đơn; bảng hóa đơn có thêm một cột.
- Guest checkout, trang order-received và email nằm ngoài phạm vi.

**Hiển thị hóa đơn cho khách**
- From: "Bản đầu không hiển thị hoặc gửi nháp cho khách" (`docs/discovery.md`); khách không thấy gì về hóa đơn.
- To: khách thấy trạng thái theo đơn và tải được PDF của hóa đơn đã phát hành hợp lệ; nháp vẫn không bao giờ hiện hay tải được.
- Reason: khách tự phục vụ, cửa hàng chuyển hóa đơn cho người mua.
- Impact: non-breaking; chỉ thêm giao diện phía khách.

## Capabilities

### New Capabilities
- `customer-invoice-view`: khối trạng thái hóa đơn trong chi tiết đơn của My Account, quy tắc hiển thị theo trạng thái, điều kiện và luồng tải PDF có kiểm quyền và tra cứu lại.

### Modified Capabilities
- `matbao-api-client`: đọc thêm mã tra cứu khi parse hóa đơn (*Invoice and template parsing*); thêm lời gọi tải PDF `download-invoice`.
- `matbao-status-sync`: thêm requirement cho lần tra cứu lại khi khách tải PDF; lần này dùng chung đường ghi của đồng bộ và được đối xử với backoff như nút kiểm tra thủ công.
- `invoice-storage-schema`: thêm mã tra cứu vào các trường Mắt Bão lưu trên bản ghi (*Mat Bao status fields*) và xóa nó khi ghi mã hóa đơn Mắt Bão mới (*New Mat Bao invoice resets Mat Bao status fields*).

## Impact

- Code: `src/Matbao/MatbaoClient.php`, `src/Matbao/MatbaoInvoice.php`, `src/Invoice/MatbaoSnapshot.php`, `src/Invoice/InvoiceRecord.php`, `src/Invoice/InvoiceRepository.php`, `src/Invoice/Schema.php` (schema version 7), `src/Plugin.php`; module mới `src/Customer/` cho khối hiển thị và endpoint tải.
- Hooks WooCommerce/WordPress: `woocommerce_order_details_after_order_table` (chỉ trên endpoint `view-order`), `template_redirect` (query arg tải trên URL `view-order`).
- Mắt Bão: thêm lời gọi `POST /api/invoice/download-invoice`; mỗi lần khách tải tốn một lần tra cứu và một lần tải.
- Tests: integration (PHPUnit), fake Mắt Bão của e2e (`tests/e2e/fake-matbao/router.php`), e2e Army.
- Docs: `docs/discovery.md`, `docs/huong-dan-su-dung.md`.
- Không thêm dependency.
