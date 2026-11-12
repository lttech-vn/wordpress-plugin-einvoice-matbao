## Design Summary

### Bối cảnh và lý do

Issue `EINVOICE-MATBAO-1` trên Rustrak ("Invoice needs attention: line_tax_rate_count", 2 event, đơn 901399) cho thấy event hiện tại không đủ để tra soát: mã đơn chỉ nằm trong tag, không biết dòng hàng/sản phẩm nào sai, không có lý do chi tiết, không có hướng dẫn xử lý. Người trực phải mở WooCommerce, tìm đơn, rồi tự đoán nguyên nhân.

Mục tiêu: nhìn một event là biết **đơn nào, hóa đơn nào, dòng hàng/sản phẩm nào, lỗi gì, và cần làm gì tiếp**.

### Hiện trạng (living specs liên quan)

- `error-monitoring` / **Plugin errors reported as issues**: needs-attention gửi event `warning` với tag `order_id`, `error_code`, `error_kind`, `invoice.status`, gom theo `error_code`. Exception bắt được gắn `component` và `order_id` "khi biết".
- `error-monitoring` / **Tag allowlist**: chỉ 8 tag được phép.
- `error-monitoring` / **Sensitive data never sent**, **Pattern masking**, **Known buyer values redacted**: mọi chuỗi qua bộ lọc; URL ngoài host Mắt Bão bị bỏ.
- `failure-alerts` / **Lark message content and privacy**: tin Lark "chỉ chứa" số đơn, nhãn lỗi, mã lỗi, liên kết admin.
- `admin-invoice-management` / **Order edit screen invoice meta box**, **Invoice detail with attempt history**: hiện nhãn lỗi và `status_reason`, chưa có hướng dẫn.

Khoảng trống quan sát trong code:

| Đường gửi | Có | Thiếu |
|---|---|---|
| Exception làm action Action Scheduler thất bại | `component` | `order_id`, hook, action ID |
| `CaughtFailure` (7 chỗ catch) | `component`, `order_id` | `invoice.id`, `invoice.status` |
| Needs-attention | `order_id`, `error_code`, `error_kind`, `invoice.status` | `status_reason`, dòng hàng/sản phẩm lỗi, chi tiết Mắt Bão, hướng dẫn, đường dẫn admin |

### Thiết kế đã thống nhất

1. **Hướng dẫn xử lý một nguồn**: mỗi mã lỗi có một câu hướng dẫn ngắn tiếng Việt ("cần làm gì") đặt cạnh nhãn lỗi hiện có, dùng chung cho Sentry, tin Lark và admin UI (meta box đơn hàng, chi tiết hóa đơn, admin notice).
2. **Tiêu đề event đọc được ngay**: needs-attention có message dạng `Hóa đơn cần xử lý: <nhãn lỗi> – Đơn #<order_id>`; vẫn gom một issue theo `error_code` (fingerprint không đổi), nên issue không bị tách theo đơn.
3. **Ngữ cảnh tra soát có cấu trúc** gắn vào event (hiển thị trên trang event, không cần tìm kiếm):
   - **invoice**: mã đơn, ID hóa đơn, trạng thái, mã tham chiếu, `status_reason` (qua bộ lọc), hướng dẫn xử lý, đường dẫn admin tới đơn.
   - **line** (lỗi dữ liệu theo dòng): line item ID, loại dòng, product ID, variation ID, SKU, tên sản phẩm (qua bộ lọc), các thuế suất tìm thấy trên dòng.
   - **matbao** (lỗi Mắt Bão): loại lỗi (transport/http/api), HTTP status, mã lỗi API, request đã tới Mắt Bão hay chưa, có retry tự động hay không.
   - **action** (exception trong Action Scheduler): hook, action ID, đơn của action.
4. **Thêm tag để lọc**: `product_id` và `matbao.api_code` vào danh sách cho phép (thay đổi requirement Tag allowlist).
5. **Thông tin dòng hàng mang theo lỗi** (phương án A, user chọn): lỗi dữ liệu đơn hàng mang thông tin dòng gây lỗi; event needs-attention phát trong cùng request đính kèm thông tin này. Không đổi DB.
6. **Exception của Action Scheduler có ngữ cảnh đơn**: khi action của plugin nhận mã đơn làm tham số, event exception mang `order_id` và chạy trong ngữ cảnh lọc dữ liệu của đơn đó.
7. **Tin Lark** thêm một dòng hướng dẫn xử lý (thay đổi requirement Lark message content: vẫn không chứa dữ liệu người mua).
8. **Admin UI** hiện hướng dẫn xử lý cạnh nhãn lỗi.

### Goals

- Từ một event Rustrak, xác định được đơn, hóa đơn, dòng hàng/sản phẩm (khi lỗi gắn với dòng) và bước xử lý mà không phải đoán.
- Một chỗ duy nhất định nghĩa hướng dẫn xử lý.

### Non-goals

- Không đổi DB schema, không lưu chi tiết dòng lỗi lâu dài.
- Không đổi logic retry, alert dedupe hay cách phân loại mã lỗi.
- Không thêm kênh cảnh báo mới.

### Anti-goals (thất bại dù chạy được)

- Dữ liệu cá nhân người mua lọt lên Rustrak hoặc Lark.
- Issue bị tách thành một issue mỗi đơn (mất khả năng gom theo mã lỗi).
- Lỗi khi dựng ngữ cảnh làm hỏng luồng hóa đơn.

## Alternatives Considered

### Alternative A: Lỗi mang sẵn thông tin dòng hàng (chọn)
- **Approach**: lỗi dữ liệu mang thông tin dòng gây lỗi; workflow giữ trong request để event needs-attention đính kèm.
- **Pros**: không đổi DB; đủ ID/SKU/tên để tìm sản phẩm ngay.
- **Cons**: chỉ có ở request phát sinh lỗi; needs-attention phát lại ở request khác sẽ không có dòng hàng (vẫn có `status_reason`).
- **Why chosen**: needs-attention luôn phát ngay trong request ghi lỗi, nên đủ cho trường hợp thực tế.

### Alternative B: Lưu chi tiết lỗi vào DB
- **Approach**: thêm cột chi tiết lỗi trên hóa đơn, dùng cho cả Sentry và admin UI.
- **Pros**: không phụ thuộc request; admin UI xem được.
- **Cons**: đổi schema, cần migration, phải lọc dữ liệu cá nhân khi lưu.
- **Why not chosen**: chi phí vòng đời cao hơn so với nhu cầu tra soát hiện tại.

### Alternative C: Chỉ dùng dữ liệu đã có
- **Approach**: chỉ thêm `status_reason`, hướng dẫn, đường dẫn admin.
- **Pros**: rẻ nhất.
- **Cons**: không có ID sản phẩm, vẫn phải mở đơn để tìm.
- **Why not chosen**: không giải quyết đúng vấn đề user gặp ("không biết sản phẩm nào").

## Agreed Approach

Phương án A cùng hướng dẫn xử lý một nguồn (Key Decisions 1–8 ở trên). User đã chọn: gửi tên sản phẩm (qua bộ lọc), một nguồn hướng dẫn cho Sentry/Lark/admin UI, thông tin dòng mang theo lỗi.

## Key Decisions

- Gửi tên sản phẩm lên Rustrak, vẫn qua bộ lọc dữ liệu người mua (user chọn).
- Hướng dẫn xử lý một nguồn, dùng ở Sentry, Lark, admin UI (user chọn).
- Thông tin dòng hàng mang theo lỗi, không đổi DB (user chọn).
- Fingerprint needs-attention giữ theo `error_code` (đề xuất của agent, để không tách issue theo đơn).
- Đường dẫn admin gửi dạng đường dẫn tương đối (không scheme/host; host đã có ở tag `site`), nên không phải nới requirement Pattern masking (đề xuất của agent).
- Thêm tag `product_id`, `matbao.api_code`; các thông tin còn lại để trong context, không làm tag (đề xuất của agent, giữ allowlist gọn).

## Open Questions

- Không còn câu hỏi chặn. Nội dung cụ thể từng câu hướng dẫn (khoảng 33 mã lỗi) sẽ được soạn trong spec; user review ở gate cuối.
