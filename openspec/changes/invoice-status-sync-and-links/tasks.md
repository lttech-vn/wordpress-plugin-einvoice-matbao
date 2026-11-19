## 1. Trạng thái Mắt Bão: client, enum và lưu trữ

Blocked by: none

- [x] 1.1 `MatbaoInvoice::from_api()` đọc `shDon` (0 → null), `mccqt` (rỗng → null), `maLoaiHDon`, `ghiChuCQT`; test parse cho mọi scenario của `specs/matbao-api-client` (design D4)
- [x] 1.2 `MatbaoClient::find_invoices_by_references()` gửi nhiều `MTChieu` trong một request, trả `MatbaoLookup { invoices, not_found }`; `find_invoices_by_reference()` thành trường hợp một phần tử, caller hiện có giữ hành vi; test 301, 200 không đọc được, một phần `notFound` (design D4)
- [x] 1.3 Enum `Invoice\MatbaoStatus` (0 Đã xóa, 1–9) với `label()`, `is_final()`, `alert_code()`, `group()`, cùng map nhãn loại hóa đơn; test theo `Mat Bao status values` và `Final and abnormal Mat Bao statuses` (design D2)
- [x] 1.4 `InvoiceErrorCode`: thêm `matbao_sign_failed`, `matbao_not_eligible`, `matbao_draft_deleted` với nhãn, `kind`, hint đúng nguyên văn `specs/failure-alerts`; cập nhật `InvoiceErrorCodeTest` (design D5)
- [x] 1.5 `Schema` lên version 6 với các cột và index của design D1; `InvoiceRecord` đọc các trường mới; `SchemaUpgradeTest` cho nâng cấp từ 5 giữ dữ liệu
- [x] 1.6 `InvoiceRepository`: `due_for_status_sync(limit, since)`, `record_matbao_status()` có điều kiện theo status/mã hóa đơn/trạng thái đã đọc kèm cảnh báo và phép gán `CASE` xóa mã Mắt Bão, `touch_matbao_checked()`; `update_status()` reset cột Mắt Bão khi ghi `matbao_invoice_id` mới; test mọi scenario của `specs/invoice-storage-schema` (design D1, D5)

## 2. Dịch vụ đồng bộ và lịch chạy

Blocked by: 1

- [x] 2.1 `Invoice\MatbaoStatusSync`: `sync_batch()` gom theo mẫu, mỗi request tối đa 20 `MTChieu`, tối đa 200 hóa đơn mỗi lượt, ghép theo `maSoHDon`, chỉ ghi "Đã xóa" khi 301/`notFound`, lỗi tra cứu chỉ chạm `matbao_checked_at` và `Monitor::log` warning, bỏ lượt khi thiếu credential; `sync_one()` dùng chung đường áp kết quả (design D3, D4)
- [x] 2.2 Phát `DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION` khi `record_matbao_status()` thắng một chuyển sang trạng thái bất thường; không phát với trạng thái khác (design D5)
- [x] 2.3 Đăng ký recurring action `einvoice_matbao_status_sync` mỗi giờ trong group `einvoice-matbao` ở `action_scheduler_init`; xác nhận deactivate/uninstall hủy nó (design D3)
- [x] 2.4 Test integration `MatbaoStatusSyncTest` cho mọi scenario của `specs/matbao-status-sync` (kể cả race hai tiến trình, nháp tạo lại, cảnh báo đơn hàng còn giữ), và các scenario mới của `specs/failure-alerts` và `specs/error-monitoring` (alert, Lark không chứa ghi chú CQT, event warning, log khi 503)

## 3. Kiểm tra trạng thái thủ công

Blocked by: 2

- [x] 3.1 `InvoiceController::check_status()` gọi `MatbaoStatusSync::sync_one()` cho hóa đơn `draft_created`, giữ chỉ đọc cho status khác; giữ 409/422 hiện có (design D6)
- [x] 3.2 `InvoicePage::render_check()` hiển thị theo trạng thái Mắt Bão vừa ghi: thành công cho nháp/trung gian/đã cấp mã, lỗi kèm hint cho bất thường, form tạo lại chỉ khi xác nhận đã xóa, thông báo không xác định khi vắng mặt chưa xác nhận (design D6)
- [x] 3.3 Cập nhật `InvoiceAdminActionsRestTest` cho mọi scenario của requirement `Mat Bao status check`

## 4. Hiển thị thông tin và liên kết

Blocked by: 1

- [x] 4.1 `Admin\MatbaoStatusView`: badge `data-einv-matbao-badge` (`issued`/`error`/`progress`, "—" khi chưa có) và các dòng thông tin Mắt Bão, ghi chú CQT qua `Redactor` (design D8)
- [x] 4.2 Danh sách hóa đơn: link đơn qua `get_edit_order_url()` (text khi đơn đã xóa), thêm cột Số HĐ, trạng thái Mắt Bão, "Đồng bộ lúc"; nút "Chi tiết" và "‹ Danh sách" dùng `hx-push-url` (design D7)
- [x] 4.3 `InvoicePage::render_panel()` đọc query arg `invoice` (absint) để nạp thẳng chi tiết; chi tiết hóa đơn thêm link "Xem đơn #N" và các dòng Mắt Bão (design D7)
- [x] 4.4 `OrderInvoiceMetaBox`: thêm trạng thái Mắt Bão, số HĐ, MCCQT và link "Xem chi tiết hóa đơn" chỉ khi có `manage_options` (design D7)
- [x] 4.5 Test integration (`InvoiceAdminListRestTest`, `OrderInvoiceMetaBoxTest`, `SettingsPageTest` hoặc test panel) cho mọi scenario ADDED/MODIFIED về hiển thị và liên kết của `specs/admin-invoice-management`

## 5. Browser E2E

Blocked by: 3, 4

- [x] 5.1 `tests/e2e/fake-matbao/router.php`: cho phép seed trạng thái, `shDon`, `mccqt`, `maLoaiHDon`, `ghiChuCQT`, `notFound` theo `MTChieu`; nhận `danhSach` nhiều phần tử
- [x] 5.2 `tests/e2e/army/invoices.army.ts`: kiểm tra trạng thái của hóa đơn đã cấp mã hiện thành công với số HĐ/MCCQT (thay assertion "không còn ở trạng thái Nháp"); Lỗi ký hiện hint; mở chi tiết bằng URL `invoice=<id>`; Back quay về danh sách; link từ danh sách tới đơn
- [x] 5.3 `tests/e2e/army/checkout-invoice.army.ts` (hoặc file meta box phù hợp): link "Xem chi tiết hóa đơn" từ đơn mở đúng chi tiết; ghi lại cache replay

## 6. Tài liệu

Blocked by: 3, 4

- [x] 6.1 `docs/huong-dan-su-dung.md` và `README.md`: trạng thái Mắt Bão, đồng bộ mỗi giờ trong 30 ngày, ý nghĩa ba cảnh báo mới, liên kết đơn ↔ hóa đơn
- [x] 6.2 `docs/phat-trien.md`: lớp trạng thái Mắt Bão, `MatbaoStatusSync`, action `einvoice_matbao_status_sync`, quy tắc "Đã xóa" chỉ khi 301/`notFound`, giới hạn lô
