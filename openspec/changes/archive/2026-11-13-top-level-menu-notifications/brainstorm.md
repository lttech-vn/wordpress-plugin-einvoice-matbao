## Design Summary

Quy mô: thay đổi nhỏ, ba việc gắn với nhau trên cùng một trang cấu hình.

1. **Menu chính.** Trang "Hóa đơn Mắt Bão" chuyển từ submenu của WooCommerce thành một mục menu cấp cao nhất của WordPress admin. Vẫn là một trang duy nhất với các tab bên trong, không thêm submenu cho từng tab.
2. **Tab Thông báo.** Hai tab "Cảnh báo Lark" và "Giám sát lỗi" gộp thành một tab "Thông báo" chứa hai thẻ: Lark và Sentry. Tab bar còn 5 tab: Hóa đơn, Kết nối Mắt Bão, Xuất hóa đơn, Thông báo, Nâng cao.
3. **Công tắc Lark.** Thẻ Lark có checkbox "Bật cảnh báo Lark", giống checkbox bật Sentry đã có. Tắt thì không gửi cảnh báo Lark nhưng vẫn giữ webhook đã lưu.

Phác thảo tab Thông báo:

```
[Hóa đơn] [Kết nối Mắt Bão] [Xuất hóa đơn] [Thông báo*] [Nâng cao]

+-- Cảnh báo Lark ---------------------------+
| [x] Bật cảnh báo Lark                       |
| Webhook URL ........   Secret ........      |
| [Lưu] [Gửi tin nhắn kiểm tra]               |
+---------------------------------------------+
+-- Giám sát lỗi (Sentry) --------------------+
| [x] Bật gửi lỗi ...   DSN ...   Tỉ lệ ...   |
| [Lưu cấu hình] [Gửi event thử]               |
+---------------------------------------------+
```

Spec hiện hành bị ảnh hưởng (thay đổi các requirement đã quy định, không im lặng):

- `admin-settings` › "Settings page access": đổi vị trí từ "dưới menu WooCommerce" sang menu cấp cao nhất.
- `admin-settings` › "Tabbed plugin screen": từ 6 tab thành 5; scenario "Deep link to a tab" và "Monitoring tab order" đổi theo.
- `admin-settings` › "Settings grouped by tab": `lark` và `monitoring` gộp vào `notifications`.
- `admin-settings` › "Admin assets scoped to plugin pages": điều kiện nhận biết trang đổi theo vị trí menu mới.
- `admin-settings` › "Tab switching without page reload": scenario tab Lark đổi sang tab mới.
- `failure-alerts` › "Missing Lark configuration or queue failure": thêm trường hợp Lark bị tắt, khác với chưa cấu hình.
- `error-monitoring` › "Monitoring settings form": form vẫn giữ nguyên, chỉ đổi tab chứa nó.

## Alternatives Considered

### Alternative A: Một menu cấp cao nhất, một trang, tab bên trong
- **Approach**: `add_menu_page` thay cho `add_submenu_page`, giữ slug `einvoice-matbao-settings`.
- **Pros**: Đổi ít nhất; giữ nguyên cơ chế htmx tab, route fragment và slug; link cũ gần như còn dùng được.
- **Cons**: Menu bên trái không thấy ngay từng khu vực.
- **Why not chosen**: Đây chính là phương án được chọn.

### Alternative B: Menu cấp cao nhất kèm submenu cho từng tab
- **Approach**: Mỗi tab thành một submenu trỏ về `&tab=...`.
- **Pros**: Điều hướng thẳng từ menu WordPress.
- **Cons**: Hai nơi chọn tab (menu và tab bar) phải đồng bộ; phải xử lý trạng thái "đang chọn" của menu; tab bar htmx và submenu dễ lệch nhau.
- **Why not chosen**: Phức tạp hơn mà người dùng không nhờ.

### Alternative C: Tách Thông báo thành trang riêng
- **Approach**: Trang thứ hai cho Lark và Sentry.
- **Pros**: Tách biệt hoàn toàn.
- **Cons**: Hai trang, hai bộ asset, phá quy tắc "trang plugin duy nhất".
- **Why not chosen**: Trái yêu cầu gom vào một tab.

## Agreed Approach

Phương án A. Một menu cấp cao nhất, một trang, năm tab, tab Thông báo chứa hai thẻ Lark và Sentry. Người dùng đã chọn phương án này.

## Key Decisions

Do người dùng chốt:

- Menu cấp cao nhất "Hóa đơn Mắt Bão", vẫn một trang với tab bên trong.
- Link cũ `tab=lark` và `tab=monitoring` đều mở tab `notifications` (alias).
- Site đã lưu webhook Lark thì coi là đang bật khi nâng cấp; site mới mặc định tắt.
- Khi Lark tắt mà có cảnh báo hóa đơn lỗi, cảnh báo ghi trạng thái mới `disabled`; nút "Gửi tin nhắn kiểm tra" vẫn dùng được khi tắt.

Do tôi tự quyết (người dùng có thể sửa):

- Cảnh báo trong admin vẫn hiện cho đơn lỗi khi Lark tắt; công tắc chỉ điều khiển việc gửi tới Lark.
- Dữ liệu gỡ plugin: option công tắc mới được xóa khi bật "xóa dữ liệu khi gỡ plugin".
- Vị trí và biểu tượng menu cấp cao nhất để cho design chọn.

## Open Questions

- Không còn câu hỏi nào chặn proposal. Vị trí, biểu tượng menu và tên option công tắc để design.
