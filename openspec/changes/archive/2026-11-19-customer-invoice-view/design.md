## Context

- Trạng thái Mắt Bão chỉ được ghi bởi `MatbaoStatusSync` (lượt hằng giờ `sync_batch` và `sync_one` dùng cho nút "Kiểm tra trạng thái" của admin, `src/Admin/InvoiceController.php::check_created_draft`). `sync_one` tra cứu, ghi snapshot qua `InvoiceRepository::record_matbao_status` (có guard giá trị kỳ vọng), phát alert khi chuyển sang trạng thái bất thường, xóa backoff khi tra cứu thành công và không tăng backoff khi lỗi.
- `invoice-detail-batch` đã trả `maTraCuu` (xem `docs/research/demo-results.json`, `tests/e2e/fake-matbao/router.php`), nhưng `MatbaoInvoice::from_api` bỏ qua trường này.
- `download-invoice` nhận `{MaTraCuu, MaSoHDon}` và trả envelope `success`/`errorCode` 200 với `data.data_PDF_Base64` và `data.data_XML_Base64` (Postman collection của Mắt Bão). Cách gọi đi qua `MatbaoClient::authorized()` như các endpoint khác (token, re-login khi 401, mapping lỗi).
- Plugin chưa có code phía frontend của khách. Mọi giao diện hiện tại nằm trong `src/Admin/`.

## Goals / Non-Goals

**Goals:**
- Tách bạch: (1) ánh xạ bản ghi sang trạng thái cho khách, đây là logic thuần, test được không cần WordPress; (2) render khối; (3) xử lý tải PDF; (4) lời gọi Mắt Bão.
- Không thêm đường ghi trạng thái Mắt Bão mới; tái dùng `sync_one`.
- Không dữ liệu khách nào rời khỏi request: không lưu PDF, không log body hay mã tra cứu.

**Non-Goals:**
- Setting bật/tắt tính năng (skipped: thêm khi có shop cần tắt).
- Giới hạn tần suất tải (xem Risks).
- Tải XML, hiện mã tra cứu cho khách, hiện mã tra cứu cho admin.

## Decisions

**D1. Module `src/Customer/` gồm bốn unit, mỗi unit một trách nhiệm.**
- `CustomerInvoiceStatus` (enum): các trạng thái khách thấy (`PROCESSING`, `NEEDS_STORE`, `NOT_REQUIRED`, `ISSUED`, `ADJUSTED`), kèm `label()` và `from_record(InvoiceRecord)`. Đây là toàn bộ quy tắc của bảng trong brainstorm, viết thành hàm thuần. `ISSUED` là trạng thái duy nhất cho phép PDF.
- `OrderInvoiceSection`: hook render khối, sinh link tải.
- `InvoicePdfDownload`: nhận request tải, kiểm quyền, tra cứu lại, lấy PDF, trả file hoặc báo lỗi.
- `MatbaoClient::download_invoice_pdf()`: lời gọi Mắt Bão, trả bytes PDF đã kiểm.
Lý do: `register*` không ôm nhiều trách nhiệm (coding-standards #6). Quy tắc hiển thị được test độc lập với HTTP và giao diện.

**D2. Ánh xạ trạng thái.** Thứ tự đánh giá trong `from_record`:
1. `skipped` → `NOT_REQUIRED`.
2. `pending`, `sending` → `PROCESSING`.
3. Status plugin khác `draft_created` (`failed`, `needs_attention`, `not_configured`, `stopped` hoặc giá trị lạ) → `NEEDS_STORE`.
4. `draft_created`: Mắt Bão bất thường (`MatbaoStatus::alert_code()` khác null: 0, 2, 6) → `NEEDS_STORE`; `ISSUED` (4) với loại 3 hoặc 5 → `ADJUSTED`; `ISSUED` với loại khác (kể cả chưa biết loại) → `ISSUED`; còn lại (null, 1, 3, 5, 7–9, mã lạ) → `PROCESSING`.
`ISSUED` còn đòi bản ghi có mã hóa đơn Mắt Bão. Thiếu mã tra cứu không chặn trạng thái `ISSUED`, vì lần tra cứu lại lúc tải sẽ lấy được mã.

**D3. Điểm hiển thị: `woocommerce_order_details_after_order_table`, chỉ khi `is_wc_endpoint_url( 'view-order' )`.** Hook này còn chạy ở trang order-received, nên phải lọc endpoint. WooCommerce đã chặn người không sở hữu đơn ở `view-order`; renderer vẫn tự kiểm tra `is_user_logged_in()` và `get_customer_id() === get_current_user_id()` (customer ID 0 luôn trượt) để phòng theme hoặc plugin khác gọi hook ở chỗ khác. Nằm sau bảng sản phẩm, cùng chỗ WooCommerce hiện thông tin bổ sung. Đơn không có bản ghi hóa đơn thì không render gì.

**D4. Endpoint tải: query arg trên chính URL `view-order`, xử lý ở `template_redirect`.** Link có dạng `wc_get_endpoint_url( 'view-order', $id, wc_get_page_permalink( 'myaccount' ) )` + `einvoice-matbao-pdf=1&_wpnonce=…`. Handler chỉ chạy khi `is_wc_endpoint_url( 'view-order' )` và có query arg, rồi kiểm theo thứ tự: đã đăng nhập → nonce `einvoice_matbao_invoice_pdf_{order_id}` → `$order->get_customer_id() === get_current_user_id()`.
Phương án khác đã cân nhắc:
- `admin-post.php`: WooCommerce có thể chặn khách vào wp-admin (`woocommerce_prevent_admin_access`), làm link ra ngoài vùng My Account.
- REST route: phải dùng cookie kèm nonce `wp_rest`, trả binary qua `rest_pre_serve_request` vụng hơn, và lộ route công khai.
- Endpoint rewrite riêng của My Account: phải flush rewrite khi kích hoạt.
Query arg trên `view-order` không cần rewrite và giữ người dùng trong My Account. Chủ đơn được so trực tiếp bằng customer ID, không dùng capability `view_order`, để đúng nghĩa "chủ đơn".

**D5. Tra cứu lại bằng `MatbaoStatusSync::sync_one()` rồi đọc lại bản ghi, theo hướng fail closed.** Thứ tự: kiểm quyền (D4) → `from_record` của bản ghi hiện tại phải là `ISSUED`, nếu không thì từ chối mà không gọi Mắt Bão → `sync_one`. Chỉ `UPDATED` (dòng khớp đã được ghi) và `UNCHANGED` (guard không khớp vì một writer khác vừa ghi, hoặc cùng giá trị) cho đi tiếp. `FAILED` và `UNCONFIRMED` (dòng vắng, khác mã, không đọc được) → không tải, vì trạng thái cũ không phải bằng chứng. Sau đó handler gọi `find()`, yêu cầu `matbao_invoice_id` bằng giá trị đã đọc trước tra cứu, `CustomerInvoiceStatus::from_record` vẫn là `ISSUED` và có `lookup_code`; đủ cả ba điều kiện mới gọi `download_invoice_pdf`. Với `UNCHANGED`, bản ghi đọc lại phản ánh lần ghi mới nhất của writer thắng, nên vẫn an toàn khi đánh giá lại. Bước tra cứu lại được inject dưới dạng `Closure( InvoiceRecord ): SyncOutcome` (mặc định là `sync_one`), để test ép được từng outcome một cách xác định.
Lý do: một đường ghi duy nhất cho trạng thái Mắt Bão (guard, alert, backoff đã được test). Loại hóa đơn mới (xóa bỏ/thay thế) được ghi lại nên staff thấy trong admin. Alert chỉ phát cho trạng thái bất thường 0, 2, 6 như baseline; đổi loại khi vẫn là status 4 không phát alert. Hóa đơn `ISSUED` từ trước change này lấy được mã tra cứu ở lần tải đầu, vì `record_matbao_status` ghi mọi trường của snapshot.
Phương án gọi `find_invoices_by_reference` trực tiếp mà không ghi bị loại: phải lặp lại logic ghép dòng của `snapshot_for`, và lần sau lại thiếu mã tra cứu.
Hệ quả: lần tải của khách tính như một lần kiểm tra thủ công với backoff, được ghi thành requirement mới trong `matbao-status-sync`.

**D6. `download_invoice_pdf( string $invoice_id, string $lookup_code ): string`.** Gọi `POST /api/invoice/download-invoice` với body `{MaTraCuu, MaSoHDon}` qua `authorized()`. Lấy `data.data_PDF_Base64` (đọc key không phân biệt hoa thường như các parser khác), `base64_decode( …, true )`, rồi kiểm bytes bắt đầu bằng `%PDF-`. Trước mỗi bước đều kiểm kiểu (`data` là mảng, trường PDF là chuỗi) vì đây là trust boundary. Bất kỳ bước nào sai thì ném `MatbaoError` kiểu `api`. Span chỉ ghi path và status, giống các lời gọi khác; không log body.

**D7. Trả file.** Gồm `nocache_headers()`, `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="hoa-don-<form><series>[-<number>].pdf"` (bỏ hậu tố số khi chưa có số hóa đơn) (chỉ giữ ký tự ASCII chữ, số, `-`), `X-Content-Type-Options: nosniff`, `Content-Length`, echo bytes rồi `exit`. Phần gửi header và exit nằm sau một seam nhỏ (`PdfResponder`). Test chỉ thay các primitive output (`clean_buffers`, `disable_compression`, `emit`, `finish`), nên danh sách header thật do production dựng vẫn được kiểm tra mà không thoát tiến trình. Trước khi gửi, `send()` đóng output buffer và tắt `zlib.output_compression` nếu đang bật, để `Content-Length` khớp số byte gửi đi (giống download handler của WooCommerce).

**D8. Lỗi khi tải.** Mọi đường thất bại (chưa đăng nhập, sai nonce, không phải chủ đơn, không còn `ISSUED`, Mắt Bão lỗi, thiếu mã tra cứu, PDF không hợp lệ) đều `wc_add_notice( <thông báo chung>, 'error' )` rồi `wp_safe_redirect` về URL `view-order` không kèm query arg. Chưa đăng nhập thì redirect về trang My Account (form đăng nhập). Thông báo không phân biệt nguyên nhân, để không lộ việc một đơn có tồn tại hay không. Lỗi Mắt Bão được `Monitor::log( 'warn', … )` kèm `order_id`, `error_kind`, `api_code`.

**D9. Schema version 7: cột `lookup_code varchar(100) NULL`**, thêm vào `MATBAO_STATUS_COLUMNS` để được xóa khi ghi mã hóa đơn Mắt Bão mới; `MatbaoSnapshot` và `MatbaoInvoice` có thêm `lookup_code`. Mã tra cứu không phải thông tin cá nhân, nhưng dùng được trên portal tra cứu công khai nên không hiện trong admin, không đưa vào monitoring context. `MatbaoSnapshot::deleted()` để null.

## Risks / Trade-offs

- [Khách bấm tải liên tục gây nhiều request Mắt Bão] → Chỉ chủ đơn đã đăng nhập mới tải được, mỗi lần tải phải có nonce, và `sync_one` không đổi backoff khi lỗi. Chưa giới hạn tần suất; thêm khóa theo đơn khi log thấy lạm dụng.
- [Mắt Bão chậm, timeout 30 giây] → Khách chờ tối đa hai lời gọi. Chấp nhận được vì tải PDF là thao tác hiếm; thông báo lỗi chung.
- [`download-invoice` thực tế khác Postman mẫu (key, mã hóa)] → Parser kiểm `%PDF-` và báo lỗi thay vì trả file hỏng. Task e2e/demo xác minh trên demo trước khi đóng change.
- [Tra cứu lúc tải phát hiện trạng thái bất thường (0, 2, 6) nên staff nhận alert do khách kích hoạt] → Đúng ý muốn. Đổi loại sang xóa bỏ/thay thế không phát alert; chỉ hiện trong admin (giới hạn chấp nhận được, vì staff là người tự thao tác xóa bỏ/thay thế trên portal).
- [Một số theme bỏ hook `woocommerce_order_details_after_order_table`] → Ghi vào tài liệu; không xử lý thêm.

## Migration Plan

- `Schema::maybe_install()` chạy `dbDelta` khi `VERSION` đổi 6 → 7, chỉ thêm cột, dữ liệu cũ giữ nguyên.
- Rollback: phiên bản cũ bỏ qua cột thừa. Không cần migration ngược.
- Hóa đơn `ISSUED` cũ lấy mã tra cứu ở lần tải đầu (D5); không cần backfill.

## Open Questions

- Xác nhận trên demo Mắt Bão hình dạng response `download-invoice` cho hóa đơn có `maTraCuu` do Mắt Bão tự sinh (task e2e/demo).
