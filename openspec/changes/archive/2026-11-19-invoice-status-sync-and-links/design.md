## Context

- Bảng `{prefix}einvoice_matbao_invoice` (`src/Invoice/Schema.php`, `VERSION = '5'`, dbDelta, ADR 0002) lưu trạng thái plugin, `matbao_invoice_id` (`maSoHDon`), mẫu/ký hiệu và `issued_on` (`NLap`). Chưa có gì về trạng thái Mắt Bão sau nháp.
- `MatbaoClient::find_invoices_by_reference()` gọi `invoice-detail-batch` với `loai=1`, một `MTChieu`. `MatbaoInvoice` chỉ đọc `maSoHDon`, `mtChieu`, `maTThaiHDon`, `tenTThaiHDon`, `nLap`, `tgTTTBSo`. Phản hồi còn có `shDon`, `mccqt`, `maLoaiHDon`, `ghiChuCQT`. API nhận `danhSach` là một danh sách tham chiếu cùng mẫu/ký hiệu.
- `InvoiceController::check_status()` chỉ đọc. `InvoicePage::render_check()` coi mọi trạng thái khác Nháp là cảnh báo.
- Recovery sweep dùng recurring action của Action Scheduler trong group `einvoice-matbao`. Deactivate và uninstall hủy cả group (`Uninstaller::cancel_scheduled_actions()`).
- Đã có pattern cảnh báo sau nháp: `OrderChangeWatcher` giữ `draft_created`, ghi `error_code` kèm `alert_cycle + 1` bằng update có điều kiện (`replace_order_state`), rồi phát `NEEDS_ATTENTION_ACTION`. `InvoiceAlerts` lo dedupe (`AlertStore`), gửi Rustrak (`Monitor::capture_needs_attention`), Lark và admin notice.
- Trang admin dùng htmx với REST fragment (ADR 0003). Chi tiết hóa đơn nạp vào `#einv-invoices` nên chưa có URL riêng. Meta box đơn (`OrderInvoiceMetaBox`) chỉ hiện text.
- `DraftInvoiceWorkflow.php` đã dài 857 dòng, `InvoicePage.php` dài 377 dòng.

## Goals / Non-Goals

**Goals:**
- Lưu và hiển thị trạng thái Mắt Bão cùng số HĐ, MCCQT, loại HĐ, ghi chú CQT, thời điểm đồng bộ.
- Đồng bộ định kỳ có giới hạn. Nút kiểm tra thủ công dùng chung logic và ghi kết quả.
- Cảnh báo một lần khi chuyển sang trạng thái bất thường, đi qua hạ tầng alert hiện có.
- Có liên kết hai chiều giữa đơn và hóa đơn. Chi tiết hóa đơn có URL riêng.

**Non-Goals:**
- Callback hoặc webhook từ Mắt Bão. Thay đổi tìm kiếm, bộ lọc hoặc dashboard.
- Đổi trạng thái plugin, tạo lại, gửi lại hay xóa hóa đơn từ đồng bộ.
- Check-in cron monitor riêng cho đồng bộ. Exception trong action đã được `ActionSchedulerTracing` báo.

## Decisions

### D1. Trạng thái Mắt Bão là các cột riêng trên bảng hóa đơn
Schema `VERSION` lên `'6'`. dbDelta thêm các cột cho phép NULL:

| Cột | Nguồn |
|---|---|
| `matbao_status` (tinyint) | `maTThaiHDon`; giá trị riêng `0` = "Đã xóa trên Mắt Bão" (Mắt Bão không dùng 0) |
| `matbao_status_name` (varchar 100) | `tenTThaiHDon`, dùng khi mã chưa biết |
| `invoice_number` (int unsigned) | `shDon`, NULL khi 0 |
| `tax_authority_code` (varchar 50) | `mccqt`, NULL khi rỗng |
| `matbao_invoice_type` (tinyint) | `maLoaiHDon` |
| `tax_note` (text) | `ghiChuCQT` |
| `matbao_synced_at` (datetime) | lần tra cứu thành công gần nhất |
| `matbao_checked_at` (datetime) | lần thử gần nhất, dùng để xoay vòng lô |

Thêm index `matbao_sync (status, matbao_checked_at)`.
- Vì sao không gộp vào `status`: retry, gửi lại, bộ lọc và badge đều dựa trên `status`. Lớp riêng giữ chúng nguyên vẹn.
- Vì sao không dùng bảng riêng: quan hệ 1-1, lại cần hiển thị trong danh sách, nên join chỉ thêm chi phí.

### D2. `MatbaoStatus` gom ngữ nghĩa trạng thái
Enum `Invoice\MatbaoStatus` (int-backed) gồm 0 Đã xóa và 1–9 theo tài liệu Mắt Bão. Nó cung cấp:
- `label()`: nhãn tiếng Việt qua text domain;
- `is_final()`: 4 và 0;
- `alert_code(): ?InvoiceErrorCode`: 2, 6, 0;
- `group()` để tô badge.

Mã ngoài danh sách thì hiển thị `matbao_status_name`, coi như trung gian và không cảnh báo. Nhãn loại HĐ (`maLoaiHDon` 1–10) đặt trong một map riêng ở cùng namespace. Mọi quy tắc phân loại vì thế nằm ở một chỗ, giao diện, đồng bộ và alert dùng chung.

### D3. Dịch vụ đồng bộ `Invoice\MatbaoStatusSync`, tách khỏi `DraftInvoiceWorkflow`
- Recurring action `einvoice_matbao_status_sync` chạy mỗi giờ trong group `einvoice-matbao`, đăng ký ở `action_scheduler_init` như recovery sweep. Deactivate và uninstall vì vậy tự hủy nó mà không cần đổi code vòng đời.
- Mỗi lượt gọi `InvoiceRepository::due_for_status_sync(limit: 200, since: now - 30 ngày)`. Hóa đơn được chọn khi: `status = draft_created`, có `matbao_invoice_id` và mẫu, `matbao_status` NULL hoặc chưa phải trạng thái cuối, và `COALESCE(issued_on, DATE(created_at)) >= since`. Thứ tự `matbao_checked_at` tăng dần, NULL trước. Hóa đơn `draft_created` có từ trước khi nâng cấp tự rơi vào đây.
- Gom theo (`template_form`, `template_series`) rồi chia lô 20 tham chiếu cho mỗi lần gọi `invoice-detail-batch`.
- Không có credential thì bỏ cả lượt. Lỗi tra cứu của một lô chỉ cập nhật `matbao_checked_at` cho lô đó, ghi `Monitor::log` mức warning và chuyển sang lô kế, không cảnh báo.
- `sync_one(InvoiceRecord)` dùng cho nút thủ công, chung đường áp kết quả với `sync_batch`.
- Vì sao tách class: workflow tạo nháp đã lớn, và đồng bộ có vòng đời, lịch và lỗi riêng.

### D4. Ghép kết quả tra cứu theo `maSoHDon`
- Client thêm `find_invoices_by_references(list $references, $form, $series)`. Hàm cũ thành trường hợp một phần tử. errorCode 301 vẫn trả rỗng.
- Với mỗi hóa đơn, chọn dòng có `invoice_id === matbao_invoice_id`.
- Không có dòng khớp nhưng có dòng cùng `mtChieu`: không ghi gì, chỉ log warning. Ví dụ hóa đơn thay thế có mã khác: không đoán.
- Chỉ ghi `matbao_status = 0` (Đã xóa) khi Mắt Bão xác nhận vắng mặt: errorCode 301, hoặc `mtChieu` nằm trong `notFound`. Client vì thế trả cả `notFound` (value object `MatbaoLookup { invoices, not_found }`).
- Dòng không parse được, hoặc `mtChieu` vắng ở cả hai danh sách: giữ nguyên trạng thái, log warning. Một phản hồi bất thường không bao giờ làm hóa đơn bị đánh dấu xóa và ngừng đồng bộ.
- Ghép theo `maSoHDon` vì đó là định danh plugin đã lưu khi tạo nháp, chắc hơn `mtChieu` (một tham chiếu có thể có nhiều dòng).

### D5. Ghi kết quả bằng update có điều kiện; cảnh báo theo pattern `order_*_changed`
`InvoiceRepository::record_matbao_status(InvoiceRecord $read, MatbaoSnapshot $new): bool` gồm hai bước:
- Luôn cập nhật các cột thông tin và `matbao_synced_at`/`matbao_checked_at`.
- Mọi lần ghi đều có điều kiện `WHERE id = ? AND status = 'draft_created' AND matbao_invoice_id = ? AND matbao_status <=> ?` theo giá trị đã đọc. Kết quả tra cứu cũ không ghi đè được nháp đã tạo lại. Khi trạng thái đổi, chỉ tiến trình thắng mới tiếp tục, nên cron và nút thủ công chạy đồng thời không cảnh báo hai lần.
  - Trạng thái mới có `alert_code()`: cùng câu lệnh ghi `error_code`, `error_kind`, `status_reason`, tăng `alert_cycle`, giữ `status = draft_created`, rồi phát `DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION`.
  - Trạng thái mới không có `alert_code()` mà `error_code` hiện tại là một mã trạng thái Mắt Bão: xóa `error_code` bằng phép gán có điều kiện trong cùng câu lệnh (`error_code = CASE WHEN error_code IN (...) THEN '' ELSE error_code END`, tương tự cho `error_kind` và `status_reason`). Điều kiện này không nằm trong `WHERE`, nên thông tin Mắt Bão vẫn được ghi khi hóa đơn đang có cảnh báo đơn hàng. Notice biến mất nhờ *Notice reflects current invoice state*. Mã `order_*_changed` không bao giờ bị đồng bộ xóa.
- `InvoiceRepository::update_status()` khi ghi `matbao_invoice_id` mới (đường `mark_created`) cùng lúc đặt các cột Mắt Bão về NULL. Nháp tạo lại vì thế được đồng bộ lại từ đầu.

Thêm ba case vào `InvoiceErrorCode`: `matbao_sign_failed`, `matbao_not_eligible`, `matbao_draft_deleted`. Mỗi case có `label()`, `hint()` (theo *Resolution hint per error code*) và `needs_attention() = true`. Việc dedupe, Lark, admin notice và event Rustrak `warning` đi theo đường cũ nên không cần code alert mới.

### D6. Nút "Kiểm tra trạng thái" dùng `sync_one`
- Hóa đơn `draft_created`: áp kết quả như D4/D5, rồi `render_check()` hiển thị trạng thái Mắt Bão vừa ghi. Đã cấp mã hiện notice thành công; Lỗi ký và Không đủ điều kiện hiện notice lỗi kèm hướng dẫn. Đã xóa vẫn hiện `lost_draft_form` như cũ (*Lost draft recreation needs explicit confirmation* giữ nguyên).
- Trạng thái plugin khác: giữ hành vi chỉ đọc hiện tại, vì không có nháp nào của plugin để ghép.
- Phản hồi 409 khi chưa có mẫu, và lỗi tra cứu 422, giữ nguyên.

### D7. URL riêng cho chi tiết hóa đơn và liên kết hai chiều
- URL: `admin.php?page=<SettingsPage::SLUG>&tab=invoices&invoice=<id>`. `InvoicePage::render_panel()` đọc `invoice` (absint). Nếu hợp lệ, `#einv-invoices` tải ngay `/invoices/{id}` thay vì danh sách.
- Nút "Chi tiết" trong danh sách và nút "‹ Danh sách" dùng `hx-push-url` với admin URL tương ứng, để nút Back và bookmark chạy đúng.
- Danh sách: cột "Đơn" thành link `$order->get_edit_order_url()` (HPOS-aware). Đơn đã xóa thì hiện text. Thêm cột Số HĐ, badge trạng thái Mắt Bão, "Đồng bộ lúc".
- Chi tiết: link "Xem đơn #N", thêm các dòng thông tin ở D1. `tax_note` đi qua `Redactor::redact()`.
- Meta box đơn: thêm Số HĐ, MCCQT, trạng thái Mắt Bão, và link "Xem chi tiết hóa đơn". Link chỉ hiện khi người xem có `manage_options`, vì trang hóa đơn yêu cầu quyền này.
- Không dùng route mới: trang admin đã có tab và quyền, chỉ cần một query arg.

### D8. Tách phần hiển thị trạng thái Mắt Bão
Badge, nhãn và các dòng thông tin Mắt Bão dùng chung giữa danh sách, chi tiết và meta box. Chúng đặt trong `Admin\MatbaoStatusView` (static render helpers) để `InvoicePage` không phình thêm và meta box không lặp code.

### Contracts công khai
- Action hook mới `einvoice_matbao_status_sync` (Action Scheduler, group `einvoice-matbao`).
- Ba giá trị mã lỗi mới truyền qua `einvoice_matbao_invoice_needs_attention`; listener bên ngoài nhận thêm mã.
- Query arg `invoice` trên trang plugin.

Hình dạng chi tiết đặt trong plan.md.

## Risks / Trade-offs

- [Tra cứu báo không thấy do sự cố tạm của Mắt Bão, dẫn tới đánh dấu nhầm "Đã xóa" và ngừng đồng bộ] → Chỉ coi là xóa khi có 301 hoặc `notFound` rõ ràng, theo D4. Lỗi transport/HTTP/API và dòng hỏng không bao giờ dẫn tới xóa. Admin vẫn có thể bấm kiểm tra để ghi lại.
- [Một hóa đơn chỉ có một ô `error_code`, nên cảnh báo trạng thái Mắt Bão đè cảnh báo `order_*_changed` chưa xử lý] → Alert cũ đã gửi Lark và Rustrak. Notice cũ ẩn đi vì mã đã đổi. Chấp nhận, ghi trong hướng dẫn.
- [Giới hạn kích thước `danhSach` và rate limit của Mắt Bão chưa được tài liệu nêu] → Lô 20 và trần 200 hóa đơn mỗi giờ đều là hằng số dễ chỉnh. Ghi nhận ở Open Questions.
- [Shop nhiều đơn: hơn 200 hóa đơn chờ đồng bộ, có hóa đơn bị chậm hơn 1 giờ] → Thứ tự theo `matbao_checked_at` bảo đảm xoay vòng công bằng. Chấp nhận độ trễ.
- [`ghiChuCQT` có thể chứa dữ liệu người mua] → Chỉ hiển thị sau `Redactor`, không đưa vào Rustrak/Lark hay tag.
- [Thêm lượt gọi API] → Chỉ hỏi hóa đơn chưa ở trạng thái cuối, trong 30 ngày, và gom theo mẫu.

## Migration Plan

- Khi boot, `Schema::maybe_install()` thấy version `5` khác `6` thì dbDelta thêm cột và index; dữ liệu cũ giữ nguyên, cột mới NULL.
- Lượt đồng bộ đầu sau nâng cấp nhận các `draft_created` trong 30 ngày, ưu tiên những hóa đơn chưa từng kiểm tra.
- Rollback về bản cũ: các cột thừa bị bỏ qua, recurring action không còn handler, và Action Scheduler đánh dấu lỗi cho tới khi deactivate dọn group. Không có bước xóa dữ liệu.

## Open Questions

- Mắt Bão có giới hạn số phần tử `danhSach` hay tần suất gọi `invoice-detail-batch` không? Mặc định tạm 20 phần tử mỗi lần gọi, 200 hóa đơn mỗi giờ.
