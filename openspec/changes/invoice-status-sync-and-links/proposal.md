## Why

Sau khi tạo nháp, plugin không biết hóa đơn đã ký hay được CQT cấp mã chưa. Nút "Kiểm tra trạng thái" chỉ đọc nên hóa đơn đã cấp mã bị báo bằng notice đỏ, bản ghi kẹt ở `draft_created`, không có gì tự cập nhật và Rustrak không nhận được gì. Thông tin hóa đơn thiếu số HĐ và MCCQT, và đơn với hóa đơn không liên kết được với nhau, nên admin phải mở Mắt Bão để tra soát. Thay đổi này giúp admin theo dõi trọn vòng đời hóa đơn ngay trong WordPress.

## What Changes

**Trạng thái Mắt Bão sau khi tạo nháp**
- From: plugin chỉ biết `draft_created`; trạng thái thực trên Mắt Bão không được lưu.
- To: mỗi hóa đơn có nháp có thêm trạng thái Mắt Bão (theo `maTThaiHDon`, cộng "Đã xóa trên Mắt Bão"), số HĐ, MCCQT, loại HĐ, ghi chú CQT và thời điểm đồng bộ. Trạng thái plugin giữ nguyên ý nghĩa.
- Reason: phân biệt quá trình gửi của plugin với quá trình ký/CQT xử lý.
- Impact: non-breaking; bảng hóa đơn có thêm dữ liệu, bản ghi cũ giữ nguyên.

**Đồng bộ tự động**
- From: không có.
- To: mỗi giờ tra cứu theo lô có giới hạn các hóa đơn có nháp, chưa ở trạng thái cuối (Đã cấp mã/tiếp nhận, Đã xóa trên Mắt Bão) và được tạo nháp trong vòng 30 ngày. Hóa đơn `draft_created` có sẵn trước khi nâng cấp cũng được đồng bộ theo cùng điều kiện.
- Reason: admin không phải bấm kiểm tra từng hóa đơn.
- Impact: thêm lượt gọi `invoice-detail-batch`; không tạo, gửi lại hay xóa gì.

**Kiểm tra trạng thái thủ công**
- From: *Read-only Mat Bao status check* không đổi bản ghi; hóa đơn không còn là nháp bị cảnh báo đỏ.
- To: kiểm tra ghi trạng thái Mắt Bão và thông tin hóa đơn vào bản ghi, hiển thị đúng nghĩa (đã cấp mã là thành công); vẫn không tạo hay gửi lại gì và không đổi trạng thái plugin.
- Reason: kết quả kiểm tra phải phản ánh vào danh sách và đơn hàng.
- Impact: đổi requirement đã có.

**Báo trạng thái bất thường**
- From: chỉ báo lỗi trong quá trình gửi.
- To: báo một lần lên Rustrak và qua alert/Lark khi hóa đơn chuyển sang Lỗi ký, Không đủ điều kiện cấp mã/tiếp nhận hoặc Đã xóa trên Mắt Bão; không tự tạo lại nháp.
- Reason: những trạng thái này cần kế toán xử lý trên Mắt Bão.
- Impact: thêm loại alert và event mới.

**Thông tin và liên kết**
- From: danh sách, chi tiết và hộp "Hóa đơn Mắt Bão" không có số HĐ, MCCQT, trạng thái Mắt Bão; không link qua lại; chi tiết hóa đơn không có URL riêng.
- To: hiển thị các thông tin đó; danh sách và chi tiết link tới đơn; hộp trong đơn link tới chi tiết hóa đơn; chi tiết hóa đơn có URL riêng.
- Reason: quản lý và tra soát nhanh hơn.
- Impact: non-breaking; tìm kiếm và bộ lọc giữ nguyên.

## Capabilities

### New Capabilities
- `matbao-status-sync`: đồng bộ trạng thái hóa đơn trên Mắt Bão sau khi tạo nháp: phạm vi hóa đơn được đồng bộ, lịch, giới hạn lô, trạng thái cuối, cửa sổ 30 ngày, phát hiện trạng thái bất thường và báo một lần.

### Modified Capabilities
- `admin-invoice-management`: *Read-only Mat Bao status check* thành kiểm tra có ghi kết quả; *Invoice detail with attempt history*, *Order edit screen invoice meta box*, *Filterable searchable paginated invoice list* (chỉ cột hiển thị) thêm thông tin Mắt Bão và liên kết; chi tiết hóa đơn có URL riêng.
- `invoice-storage-schema`: lưu trạng thái Mắt Bão, số HĐ, MCCQT, loại HĐ, ghi chú CQT, thời điểm đồng bộ; truy vấn lô hóa đơn cần đồng bộ.
- `matbao-api-client`: *Invoice and template parsing* đọc thêm số HĐ, MCCQT, loại HĐ, ghi chú CQT.
- `failure-alerts`: alert cho trạng thái Mắt Bão bất thường, một lần mỗi lần chuyển trạng thái; hướng dẫn xử lý cho từng trạng thái.
- `error-monitoring`: event `warning` cho trạng thái Mắt Bão bất thường.

## Impact

- Code: `src/Matbao/MatbaoInvoice.php`, `src/Admin/InvoiceController.php`, `src/Admin/InvoicePage.php`, `src/Admin/OrderInvoiceMetaBox.php`, repository và schema hóa đơn, `src/Alert/`, `src/Monitoring/`, đăng ký action định kỳ (Action Scheduler).
- Dữ liệu: nâng cấp schema bảng `{prefix}einvoice_matbao_invoice`.
- API ngoài: thêm lượt gọi `invoice-detail-batch` tới Mắt Bão theo lịch.
- Vòng đời plugin: action định kỳ mới tuân theo các requirement deactivation/uninstall hiện có của `plugin-lifecycle`.
- Test: integration (đồng bộ, schema, alert), e2e (link, hiển thị trạng thái), server Mắt Bão giả cần trả thêm trạng thái.
