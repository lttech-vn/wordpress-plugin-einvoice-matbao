## Design Summary

Gộp hai trang admin của plugin ("Hóa đơn Mắt Bão" settings và "Danh sách hóa đơn") thành **một màn hình duy nhất dưới menu WooCommerce, chia 5 tab**, tab đầu tiên và mặc định là danh sách hóa đơn. Mục tiêu: admin không phải cuộn qua 6 card cấu hình xếp dọc, và việc xem hóa đơn (việc làm hằng ngày) nằm ngay khi mở menu, còn cấu hình (việc làm một lần) lùi về các tab sau.

```
WooCommerce > Hóa đơn Mắt Bão
+--------------------------------------------------------------------------+
| [Hóa đơn] | Kết nối Mắt Bão | Xuất hóa đơn | Cảnh báo Lark | Nâng cao       |
+--------------------------------------------------------------------------+
| Hóa đơn       : 3 thẻ dashboard + bộ lọc + bảng + chi tiết hóa đơn         |
| Kết nối       : credential Mắt Bão, kiểm tra kết nối, mẫu hóa đơn mặc định |
| Xuất hóa đơn  : prefix MTChieu, tên người mua vãng lai, đơn vị tính        |
| Cảnh báo Lark : webhook, secret ký, gửi tin thử                           |
| Nâng cao      : gỡ plugin (vùng nguy hiểm)                                |
+--------------------------------------------------------------------------+
```

Kèm theo là polish nhẹ trong vùng tab: tab bar rõ trạng thái đang chọn, spacing/hierarchy nhất quán giữa các tab, badge màu cho trạng thái hóa đơn trong bảng. Giữ nguyên design tokens hiện có (`einv-*`, lấy từ Stripe DESIGN.md) và scope `#einv-app`.

**Goals**
- Một mục menu, một URL gốc; tab Hóa đơn mặc định.
- Chuyển tab không reload trang, URL phản ánh tab hiện tại (bookmark/F5/back hoạt động).
- Link cũ `page=einvoice-matbao-settings` và `page=einvoice-matbao-invoices` vẫn đưa admin tới đúng tab.
- Mọi hành vi hiện có của từng form/bảng giữ nguyên (lưu, kiểm tra kết nối, tải mẫu, lọc, chi tiết, gửi lại, kiểm tra trạng thái).

**Non-goals**
- Không đổi REST route của settings/hóa đơn, không đổi logic nghiệp vụ, không đổi dữ liệu.
- Không redesign toàn bộ giao diện hay đổi design tokens; meta box trên màn hình đơn và filter danh sách đơn WooCommerce không thuộc phạm vi.

**Anti-goals**
- Tab Hóa đơn chậm hơn hoặc mất tính năng so với trang danh sách hiện tại.
- Thao tác trong một tab (chi tiết hóa đơn, phân trang) làm đổi/vỡ tab bar hoặc nội dung tab khác.
- CSS/JS của plugin rò ra ngoài trang plugin.

## Alternatives Considered

### Alternative A: Server-side `?tab=` (WP nav-tab truyền thống)
- **Approach**: mỗi tab là link `admin.php?page=...&tab=x`, server render chỉ tab đó, chuyển tab là tải lại trang.
- **Pros**: đơn giản nhất, đúng kiểu WordPress, URL/bookmark tự nhiên, không cần route mới.
- **Cons**: reload toàn trang mỗi lần chuyển tab; trải nghiệm không liền mạch như phần còn lại của UI htmx.
- **Why not chosen**: người dùng chọn chuyển tab không reload.

### Alternative B: htmx swap nội dung tab + `hx-push-url`
- **Approach**: tab bar là các nút htmx tải fragment của tab vào vùng nội dung và cập nhật URL; mở trực tiếp URL có `tab=` thì server render sẵn tab đó.
- **Pros**: không reload; đúng ADR-0003 (htmx gọi REST trả HTML fragment); vẫn deep-link được.
- **Cons**: cần fragment cho từng tab, xử lý back/forward và việc khởi chạy lại các phần tử `hx-trigger="load"` sau swap.
- **Why not chosen**: được chọn.

### Alternative C: Render cả 5 tab, ẩn/hiện bằng JS
- **Approach**: server render toàn bộ nội dung, JS chỉ đổi panel hiển thị.
- **Pros**: chuyển tab tức thì.
- **Cons**: mọi request load (dashboard, danh sách, danh sách mẫu Mắt Bão) chạy ngay khi mở trang dù không xem; thêm JS tự viết ngoài htmx.
- **Why not chosen**: tốn request gọi Mắt Bão không cần thiết và lệch khỏi pattern htmx hiện có.

## Agreed Approach

**Alternative B** (người dùng chọn). Cùng với:
- Một menu duy nhất; slug cũ chuyển hướng sang tab tương ứng (settings → tab Kết nối, invoices → tab Hóa đơn).
- 5 tab theo sơ đồ trên, tab Hóa đơn mặc định.
- Polish nhẹ trong vùng tab cùng change này.

## Key Decisions

1. **Gộp menu**: một submenu WooCommerce "Hóa đơn Mắt Bão"; mục "Danh sách hóa đơn" bị bỏ, slug cũ redirect (người dùng chọn).
2. **Cơ chế tab**: htmx swap + `hx-push-url`, fallback render server khi mở trực tiếp URL (người dùng chọn).
3. **Chia tab**: Hóa đơn | Kết nối Mắt Bão | Xuất hóa đơn | Cảnh báo Lark | Nâng cao (người dùng chọn).
4. **Polish**: tab bar, spacing, badge trạng thái hóa đơn trong phạm vi vùng tab (người dùng chọn).
5. **Thay đổi spec đã có** (đề xuất của tôi, cần xác nhận khi duyệt):
   - `admin-settings` / "Settings page access": từ trang settings riêng (`einvoice-matbao-settings`) thành một trang plugin có tab, vẫn chỉ cho `manage_options`.
   - `admin-settings` / "Admin assets scoped to plugin pages": "các trang của plugin" thành trang plugin duy nhất (cộng slug cũ trong lúc redirect).
   - `admin-invoice-management` / "Invoice management page access": "trang Danh sách hóa đơn" thành tab Hóa đơn; shop manager vẫn không thấy menu và route vẫn 403.
   - Các requirement còn lại (form credential, mẫu, invoicing, đơn vị, uninstall, danh sách/lọc/chi tiết/gửi lại...) giữ nguyên hành vi, chỉ đổi vị trí hiển thị.
6. **Tab Kết nối giữ cả form credential lẫn danh sách mẫu** (đề xuất của tôi): event `einv-matbao-saved` dùng để tải lại danh sách mẫu sau khi lưu kết nối phải nằm cùng tab.
7. **Tab "Xuất hóa đơn" gộp card MTChieu/vãng lai và card Đơn vị tính** vẫn là hai form riêng như hiện tại (đề xuất của tôi), tránh đổi route.
8. **Banner thiếu khóa mã hóa** hiển thị phía trên tab bar, thấy được ở mọi tab (đề xuất của tôi): đây là cảnh báo cấu hình cấp plugin.

## Open Questions

- Tên menu giữ "Hóa đơn Mắt Bão" và tiêu đề trang chung "Hóa đơn Mắt Bão" (đề xuất của tôi); đổi nếu bạn muốn tên khác.
- Slug của trang gộp: giữ `einvoice-matbao-settings` làm slug chính (ít link vỡ nhất) hay slug mới `einvoice-matbao`? Đề xuất: giữ `einvoice-matbao-settings` làm slug chính để không cần redirect cho nó, chỉ redirect `einvoice-matbao-invoices`. Chi tiết chốt ở design.md.
