## Context

Lý do, phạm vi và phương án A đã chốt trong `brainstorm.md`. File này ghi các quyết định kỹ thuật dựa trên code hiện có:

- `Monitor` là facade tĩnh, nơi duy nhất business code gọi để gửi giám sát. `Backend` là contract với SDK, có hai hàm `capture_exception( $e, $tags, $known )` và `capture_message( $msg, $level, $tags, $fingerprint, $known )`.
- `SentryBackend::capture()` gắn tag và fingerprint trong một `withScope`. Callback `scrub_event` lọc tag theo allowlist rồi che `extra` và mọi `contexts` bằng `Redactor::value()`, nên context mới đi qua bộ lọc mà không phải sửa gì thêm.
- `InvoiceAlerts::on_needs_attention()` chạy đồng bộ trong `do_action` ở `DraftInvoiceWorkflow::record_error()`, nghĩa là cùng request với lỗi.
- `DraftPayloadBuilder::fail()` là chỗ duy nhất ném `InvoiceDataError`. Lỗi theo dòng phát sinh trong `line()` và `rate()`, hai hàm này nhận `$name` nhưng không nhận `$item`.
- `ActionSchedulerTracing::on_failed()` gọi `capture_exception` chỉ với tag `component`.

## Goals / Non-Goals

**Goals:**
- Event needs-attention và exception đều mang đủ ngữ cảnh tra soát, đi qua bộ lọc hiện có.
- Hướng dẫn xử lý được định nghĩa ở một chỗ.

**Non-Goals:**
- Không đổi DB, hook công khai, REST route hay fingerprint.
- Không giữ chi tiết dòng lỗi qua nhiều request.

## Decisions

### D1. Hướng dẫn xử lý: `InvoiceErrorCode::hint()`

Thêm `hint(): string` cạnh `label()` trong cùng enum, viết bằng `match` có đủ mọi case để PHP báo lỗi khi thêm mã mới mà quên hướng dẫn. Câu hướng dẫn là chuỗi dịch được, tiếng Việt, không chứa dữ liệu, và gọi tên màn hình theo đúng nhãn UI hiện có ("Kết nối Mắt Bão", "Xuất hóa đơn", "Gửi lại", "Kiểm tra trạng thái").
- Phương án đã cân nhắc là một class `ErrorGuidance` riêng. Không chọn vì nhãn và hướng dẫn cùng thuộc mã lỗi, tách ra thì phải giữ hai bảng đồng bộ.

#### Bảng hướng dẫn (nguồn expected value cho test)

| Mã | Hướng dẫn |
|---|---|
| `currency_not_vnd` | Plugin chỉ xuất hóa đơn VND. Xuất tay trên Mắt Bão, hoặc đổi đơn sang VND rồi bấm "Gửi lại". |
| `no_lines` | Đơn không có dòng hàng. Thêm sản phẩm vào đơn rồi bấm "Gửi lại", hoặc bỏ qua nếu không cần hóa đơn. |
| `line_type_unsupported` | Đơn có loại dòng plugin không hỗ trợ. Xóa hoặc đổi dòng đó trong đơn rồi bấm "Gửi lại", hoặc xuất tay trên Mắt Bão. |
| `line_amount_invalid` | Sửa số tiền âm hoặc số lượng của dòng bị nêu trong đơn rồi bấm "Gửi lại". |
| `amount_not_whole_vnd` | Sửa giá hoặc thuế của dòng bị nêu thành số nguyên đồng rồi bấm "Gửi lại". |
| `line_tax_rate_count` | Kiểm tra lớp thuế của sản phẩm và thuế trên dòng trong đơn: mỗi dòng cần đúng một thuế suất GTGT. Sửa đơn rồi bấm "Gửi lại". |
| `tax_rate_not_stored` | Tính lại thuế của đơn (nút "Tính lại" trong WooCommerce) rồi bấm "Gửi lại". |
| `tax_rate_unsupported` | Đổi thuế suất của dòng sang 0%, 5%, 8% hoặc 10% rồi bấm "Gửi lại". |
| `untaxed_line_with_tax` | Dòng không chịu thuế nhưng có tiền thuế: sửa trạng thái thuế hoặc bỏ tiền thuế của dòng rồi bấm "Gửi lại". |
| `totals_mismatch` | Tính lại tổng đơn (nút "Tính lại") để tổng các dòng khớp tổng đơn rồi bấm "Gửi lại". |
| `reference_too_long` | Mã tham chiếu của đơn này đã cố định nên không thể gửi lại. Xuất tay trên Mắt Bão. Rút ngắn tiền tố ở tab "Xuất hóa đơn" để các đơn sau không bị lỗi này. |
| `buyer_invalid` | Sửa thông tin thanh toán của đơn theo các trường bị nêu rồi bấm "Gửi lại". |
| `buyer_snapshot_invalid` | Kiểm tra thông tin thanh toán của đơn rồi bấm "Gửi lại" để chụp lại thông tin người mua. |
| `buyer_id_unreadable` | Khóa mã hóa đã đổi hoặc dữ liệu CCCD hỏng. Khôi phục khóa `EINVOICE_MATBAO_ENCRYPTION_KEY` cũ, hoặc xuất tay trên Mắt Bão. |
| `buyer_id_key_missing` | Khai báo `EINVOICE_MATBAO_ENCRYPTION_KEY` trong wp-config.php rồi bấm "Gửi lại". |
| `credentials_missing` | Nhập tài khoản ở tab "Kết nối Mắt Bão", bấm "Kiểm tra kết nối" rồi bấm "Gửi lại". |
| `template_missing` | Chọn mẫu hóa đơn mặc định ở tab "Kết nối Mắt Bão" rồi bấm "Gửi lại". |
| `template_unavailable` | Mẫu hóa đơn hết hiệu lực hoặc hết số. Chọn lại mẫu mặc định ở tab "Kết nối Mắt Bão" rồi bấm "Gửi lại". |
| `template_rejected` | Mắt Bão từ chối mẫu. Chọn lại mẫu mặc định ở tab "Kết nối Mắt Bão" rồi bấm "Gửi lại". |
| `template_changed` | Kiểm tra trên Mắt Bão xem đã có hóa đơn với mẫu cũ chưa. Nếu chưa, dùng "Gửi lại" kèm xác nhận ở chi tiết hóa đơn. |
| `order_not_completed` | Đơn không còn hoàn thành. Chuyển đơn về "Hoàn thành" nếu vẫn cần xuất hóa đơn. |
| `order_refunded` | Đơn đã hoàn tiền nên không xuất hóa đơn. Xử lý tay nếu vẫn cần. |
| `order_amounts_changed` | Số tiền đơn đổi sau khi có nháp. Sửa hoặc hủy nháp trực tiếp trên Mắt Bão cho khớp đơn. |
| `order_buyer_changed` | Người mua đổi sau khi có nháp. Sửa thông tin người mua trên nháp trực tiếp trên Mắt Bão. |
| `order_refunded_after_draft` | Đơn hoàn tiền sau khi có nháp. Hủy hoặc điều chỉnh nháp trực tiếp trên Mắt Bão. |
| `matbao_transport` | Không kết nối được Mắt Bão. Bấm "Kiểm tra kết nối" ở tab "Kết nối Mắt Bão", sau đó "Kiểm tra trạng thái" rồi "Gửi lại". |
| `matbao_http_temporary` | Mắt Bão đang lỗi tạm thời. Chờ vài phút, bấm "Kiểm tra trạng thái" rồi "Gửi lại". |
| `matbao_response_unreadable` | Không đọc được phản hồi Mắt Bão. Bấm "Kiểm tra trạng thái" trước khi bấm "Gửi lại" để tránh tạo trùng. |
| `matbao_http` | Mắt Bão trả lỗi HTTP. Bấm "Kiểm tra kết nối" ở tab "Kết nối Mắt Bão" rồi bấm "Gửi lại". |
| `matbao_api` | Đọc lý do Mắt Bão trả về, sửa dữ liệu hoặc cấu hình tương ứng rồi bấm "Gửi lại". |
| `matbao_duplicate_reference` | Mắt Bão đã có hóa đơn cùng mã tham chiếu. Bấm "Kiểm tra trạng thái" để đối chiếu, rồi "Gửi lại": hệ thống tra cứu và nhận nháp đã có, không tạo trùng. |
| `matbao_lookup_mismatch` | Hóa đơn tìm thấy trên Mắt Bão không khớp đơn. Đối chiếu trực tiếp trên Mắt Bão theo mã tham chiếu, rồi xử lý tay. |
| `queue_failed` | Không xếp được tác vụ nền. Kiểm tra Action Scheduler (Công cụ > Tác vụ đã lên lịch) rồi bấm "Gửi lại". |
| `unexpected` | Lỗi không xác định. Bấm "Kiểm tra trạng thái" trên Mắt Bão trước khi bấm "Gửi lại", và xem event trên Rustrak. |

Câu chữ có thể tinh chỉnh khi implement, nhưng thao tác (màn hình, nút) phải giữ như bảng. Test kiểm tra từ khóa thao tác cho các mã tiêu biểu (`line_tax_rate_count`, `credentials_missing`, `unexpected`), và kiểm tra mọi mã đều có hướng dẫn không rỗng.

### D2. Chi tiết lỗi: value object `FailureDetail` thay vì mảng tự do

Thêm `Invoice\LineFailure` (readonly): `item_id`, `item_type`, `product_id`, `variation_id`, `sku`, `name`, `tax_rate_ids`. Có `to_context(): array` và `from_item( WC_Order_Item )`.
- `InvoiceDataError` có thêm tham số tùy chọn `?LineFailure $line = null`. Mã lỗi theo dòng (`LINE_TYPE_UNSUPPORTED`, `LINE_AMOUNT_INVALID`, `AMOUNT_NOT_WHOLE_VND`, `LINE_TAX_RATE_COUNT`, `TAX_RATE_NOT_STORED`, `TAX_RATE_UNSUPPORTED`, `UNTAXED_LINE_WITH_TAX`) luôn mang `LineFailure`.
- `DraftPayloadBuilder::line()` dựng `LineFailure` một lần cho mỗi dòng, rồi truyền xuống `rate()` và `whole_vnd()` thay cho `$name` (tên lấy từ `$line->name`). Cách này không thêm tham số cho các hàm đó.
- `MatbaoError` đã có `kind`, `http_status`, `api_code`, `target_sent`, nên không cần object mới. Context `matbao` được dựng từ nó.

### D3. Đưa chi tiết từ workflow tới event: `Monitor::with_failure_detail()`

Needs-attention phát qua `do_action` với chữ ký công khai `( InvoiceRecord, InvoiceErrorCode )`. Thêm tham số vào hook sẽ đổi public API, nên không làm. Thay vào đó, `Monitor` giữ một stack ngữ cảnh lỗi theo request, theo cùng mẫu với `$order_contexts`:

```
DraftInvoiceWorkflow                       Monitor                    InvoiceAlerts
  catch InvoiceDataError $e
  Monitor::with_failure_detail(  ------>  push detail
     $e->line / MatbaoError,
     fn() => record_error(...)  ------------------------------------>  on_needs_attention
  )                                                                    Monitor::capture_needs_attention
                                          read top detail  <--------
                                          pop (finally)
```

- Chi tiết chỉ sống trong phạm vi callback, nên không rò sang đơn khác trong cùng request (ví dụ recovery sweep xử lý nhiều đơn).
- Khớp đơn và mã lỗi: mỗi chi tiết mang `order_id` và `error_code`. `capture_needs_attention` chỉ đính chi tiết khi cả hai trùng với hóa đơn và mã đang báo. Như vậy cảnh báo `order_*_changed`, hoặc đơn khác cùng mã xử lý lồng bên trong, sẽ không bị gắn nhầm.
- Mọi việc dựng context (đọc repository, `wc_get_order`, URL) đều chạy bên trong `Monitor::guard`, vì `capture_exception` nhận context dưới dạng closure. Lỗi khi dựng context không bao giờ lọt ra luồng hóa đơn.
- `fail()` được tách: phần tính mã lỗi và lý do nằm ngoài scope; phần ghi lỗi/retry chuyển sang method riêng `record_matbao_failure()`. Closure của scope chỉ delegate, tránh tạo closure ôm cả state machine.
- Hai chỗ trong workflow cần bọc: hai nhánh `catch ( InvoiceDataError )` có gọi `record_error`, và `fail()` với `MatbaoError`.

### D4. Hình dạng event

Mở rộng contract `Backend`. Hai hàm `capture_exception` và `capture_message` nhận thêm `array $contexts` (tên context ⇒ mảng scalar). `SentryBackend::capture()` gọi `$scope->setContext()` cho từng context bên trong `withScope`.

| Event | message | tags | contexts |
|---|---|---|---|
| needs-attention | `Hóa đơn cần xử lý: <label> – Đơn #<id>` | như cũ + `product_id`, `matbao.api_code` khi có | `invoice`, `line`?, `matbao`? |
| `CaughtFailure` | exception | `component`, `order_id` | `invoice` (khi biết đơn và tìm được hóa đơn) |
| AS `on_failed` | exception | `component`, `order_id` khi có | `action`, `invoice`? |

- Context `invoice` gồm: `order_id`, `invoice_id`, `reference`, `status`, `error_code`, `label`, `hint`, `status_reason`, `admin_path`.
- `admin_path` được dựng từ `get_edit_order_url()`: bỏ scheme, host và fragment, chỉ giữ các query parameter `page`, `action`, `id`, `post` (dùng `wp_parse_url` và `wp_parse_str`). Pattern URL của `Scrubber` chỉ khớp `https?://`, nên path tương đối không bị bỏ. Host đã có ở tag `site`. Riêng order ID dài 10 hoặc 12 chữ số vẫn bị pattern che; khi đó bộ lọc được ưu tiên.
- Fingerprint vẫn là `['needs-attention', <code>]`. Message đổi nhưng không ảnh hưởng cách gom issue.
- `Scrubber::TAG_ALLOWLIST` thêm `product_id` và `matbao.api_code`.
- Ngữ cảnh mới tạo một hàm phụ `InvoiceContext::for_invoice( InvoiceRecord ): array` đặt trong `src/Monitoring/`. Hàm này dùng chung cho needs-attention và `CaughtFailure`, để tách trách nhiệm dựng context khỏi `Monitor`.

### D5. Exception trong Action Scheduler

`on_begin` đã fetch action. Lưu thêm `args` vào `$this->runs[$id]`. Trong `on_failed`, nếu `args[0]` là int dương (các action `ACTION` đều nhận `order_id`), gọi `Monitor::with_order_context( $order_id, fn() => capture_exception(...) )`, gắn tag `order_id` và context `action` (`hook`, `action_id`).
- `SEND_ACTION` nhận alert key là chuỗi, không phải order ID. Alert key có dạng chứa order ID, nhưng không tách chuỗi đó: action Lark đã tự bắt lỗi trong `deliver()` và gửi qua `CaughtFailure` có `order_id`.

### D6. Lark và admin UI

- Lark: `InvoiceAlerts::message()` thêm phần ` – Cách xử lý: <hint>` trước liên kết. Format string mới có placeholder cho hint, và tin vẫn chỉ gồm các thành phần không chứa dữ liệu người mua.
- Admin notice: mỗi `<li>` thêm hint trong `<span class="einv-hint">`.
- Meta box và chi tiết hóa đơn: thêm dòng "Cách xử lý" với `data-einv-hint` để test bám vào.

## Risks / Trade-offs

- **Tên sản phẩm trùng dữ liệu người mua, ví dụ quà tặng khắc tên.** → Context đi qua `Redactor::value()` với known values của đơn, nên tên trùng sẽ bị che. Đây là quyết định user đã chấp nhận.
- **Alert mới phát sinh ngoài request có chi tiết lỗi** (ví dụ `queue_failed` từ recovery sweep) không có `line`/`matbao`. → Context `invoice` vẫn có `status_reason`. Đây là giới hạn đã chấp nhận ở phương án A. Hook lặp lại cho cùng alert vẫn bị dedupe nên không tạo event mới.
- **Mở rộng contract `Backend` làm test double phải sửa.** → `Backend` là interface nội bộ, chỉ `SentryBackend` và test double cài đặt nó. Sửa cùng lúc trong change này.
- **Hướng dẫn cũ đi khi luồng xử lý đổi.** → `match` có đủ case, cộng với test kiểm tra mọi case có hint không rỗng.
- **ID dài 10 hoặc 12 chữ số bị che** trong tag, context và `admin_path`. → Chấp nhận, vì bảo vệ dữ liệu được ưu tiên hơn khả năng tra cứu. Shop hiện có ID 6 chữ số. Nếu sau này cần, mở change riêng để miễn che cho key ID đã biết.
- **Dựng context thất bại.** → `InvoiceContext` được gọi bên trong `Monitor::guard`, lỗi chỉ được `InternalFailure` ghi lại. Có test cho trường hợp này.
- **Context làm event to hơn.** → Chỉ chứa scalar, mỗi event vài trăm byte, không đáng kể.

## Migration Plan

Không cần migration. Không đổi DB hay option. Rollback bằng cách revert code. Issue cũ trên Rustrak vẫn gom đúng vì fingerprint giữ nguyên.

## Open Questions

Không có.
