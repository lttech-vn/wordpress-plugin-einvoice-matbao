## ADDED Requirements

### Requirement: Monitoring is off by default
Hệ thống SHALL coi giám sát lỗi là tắt khi chưa có cấu hình. Khi giám sát tắt hoặc chưa có DSN hợp lệ, hệ thống MUST NOT nạp SDK Sentry và MUST NOT gửi bất kỳ request nào tới server giám sát. Hành vi `error_log` hiện có SHALL giữ nguyên.

#### Scenario: Fresh install
- **WHEN** plugin vừa cài, chưa lưu cấu hình giám sát, và một hóa đơn gặp lỗi không xác định
- **THEN** không có request nào tới server giám sát và `error_log` vẫn ghi tên class lỗi

#### Scenario: Enabled without DSN
- **WHEN** công tắc giám sát bật nhưng không có DSN từ UI lẫn hằng
- **THEN** không có request nào tới server giám sát

### Requirement: Monitoring settings form
Hệ thống SHALL cho Administrator trong tab `monitoring` lưu công tắc bật/tắt, DSN và `traces_sample_rate` (số từ 0 đến 1, mặc định 1.0) qua route `POST einvoice-matbao/v1/settings/monitoring` với cùng kiểm soát truy cập của các route plugin khác. DSN SHALL hợp lệ khi có scheme http(s), public key và project id dạng số; dữ liệu sai SHALL bị từ chối với notice tiếng Việt và không ghi gì. Ô DSN để trống SHALL giữ DSN đã lưu.

#### Scenario: Save valid settings
- **WHEN** Administrator bật giám sát, nhập DSN `https://<key>@rustrak.ltt.onl/3` và tỉ lệ 0.5 rồi lưu
- **THEN** notice thành công hiện và cấu hình hiệu lực là bật, project 3, tỉ lệ 0.5

### Requirement: Stored DSN never rendered
Form và mọi phản hồi của route giám sát MUST NOT chứa DSN hay public key; form SHALL chỉ hiện host và project id của DSN đang dùng, hoặc "Chưa có DSN".

#### Scenario: Form after save

- **WHEN** đã lưu DSN `https://<key>@rustrak.ltt.onl/3` và Administrator mở tab Giám sát lỗi
- **THEN** form hiện "rustrak.ltt.onl · project 3" và HTML không chứa `<key>`

#### Scenario: Invalid DSN
- **WHEN** Administrator lưu DSN `not-a-dsn`
- **THEN** notice lỗi hiện và cấu hình cũ giữ nguyên

#### Scenario: Sample rate out of range
- **WHEN** Administrator lưu tỉ lệ lấy mẫu 1.5
- **THEN** notice lỗi hiện và tỉ lệ cũ giữ nguyên

#### Scenario: Blank DSN keeps stored value
- **WHEN** đã có DSN lưu và Administrator lưu form với ô DSN trống
- **THEN** DSN cũ vẫn được dùng

### Requirement: DSN stored encrypted
Hệ thống SHALL lưu DSN nhập từ UI ở dạng mã hóa cùng cơ chế với credential khác (xem credential-encryption), SHALL từ chối lưu DSN khi thiếu khóa mã hóa, và SHALL xóa DSN khi gỡ cài đặt ở cả hai chế độ (xem plugin-lifecycle). Khi DSN đã lưu không giải mã được (thiếu hoặc đổi khóa, bản mã hỏng), hệ thống SHALL coi như không có DSN và form SHALL báo DSN đã lưu không đọc được, cần nhập lại.

#### Scenario: Stored ciphertext
- **WHEN** Administrator lưu một DSN
- **THEN** option credential của plugin không chứa DSN ở dạng rõ

#### Scenario: Missing key on save
- **WHEN** chưa cấu hình khóa mã hóa và Administrator lưu một DSN
- **THEN** notice lỗi hiện và không có DSN nào được lưu

#### Scenario: Undecryptable stored DSN
- **WHEN** khóa mã hóa đổi sau khi lưu DSN và giám sát đang bật
- **THEN** không có request nào tới server giám sát và form báo DSN đã lưu không đọc được

#### Scenario: Default uninstall removes DSN
- **WHEN** plugin bị xóa với công tắc xóa dữ liệu đang tắt
- **THEN** DSN đã lưu không còn trong database

### Requirement: wp-config constants override the UI
Hệ thống SHALL dùng hằng `EINVOICE_MATBAO_SENTRY_DSN` thay cho DSN trong UI khi hằng là một DSN hợp lệ; hằng được định nghĩa nhưng không hợp lệ SHALL bị bỏ qua (dùng DSN trong UI) và form SHALL cảnh báo hằng không hợp lệ. Hệ thống SHALL dùng hằng `EINVOICE_MATBAO_SENTRY_ENABLED` (boolean) thay cho công tắc UI khi hằng được định nghĩa. Trường bị hằng ghi đè SHALL hiện chỉ đọc kèm nhãn "Đặt trong wp-config" và route lưu SHALL bỏ qua giá trị gửi lên cho trường đó.

#### Scenario: DSN constant wins
- **WHEN** `EINVOICE_MATBAO_SENTRY_DSN` được đặt và UI có một DSN khác, giám sát đang bật
- **THEN** event được gửi tới project của DSN trong hằng

#### Scenario: DSN constant alone does not enable
- **WHEN** chỉ `EINVOICE_MATBAO_SENTRY_DSN` được đặt và công tắc UI đang tắt
- **THEN** không có request nào tới server giám sát

#### Scenario: Enabled constant false wins
- **WHEN** `EINVOICE_MATBAO_SENTRY_ENABLED` là false, công tắc UI bật và có DSN hợp lệ
- **THEN** không có request nào tới server giám sát và công tắc trong form hiện chỉ đọc kèm nhãn "Đặt trong wp-config"

#### Scenario: Overridden field ignored on save
- **WHEN** `EINVOICE_MATBAO_SENTRY_DSN` đang ghi đè và Administrator gửi form với một DSN khác
- **THEN** DSN lưu trong database không đổi và cấu hình hiệu lực vẫn dùng DSN của hằng

#### Scenario: Invalid DSN constant
- **WHEN** `EINVOICE_MATBAO_SENTRY_DSN` là `not-a-dsn` và UI có DSN hợp lệ, giám sát đang bật
- **THEN** event được gửi tới DSN trong UI và form cảnh báo hằng không hợp lệ

### Requirement: Test event
Hệ thống SHALL cho Administrator gửi một event thử qua route `POST einvoice-matbao/v1/settings/monitoring/test` khi giám sát đang bật với DSN hợp lệ, và SHALL báo thành công kèm event ID hoặc báo lỗi bằng tiếng Việt; thông báo MUST NOT chứa DSN.

#### Scenario: Server accepts
- **WHEN** server giám sát trả 200 cho event thử
- **THEN** notice thành công hiện kèm event ID

#### Scenario: Monitoring disabled
- **WHEN** giám sát đang tắt và Administrator bấm gửi thử
- **THEN** notice lỗi nêu cần bật giám sát và nhập DSN, không có request ra ngoài

### Requirement: Isolated from the site's Sentry
Hệ thống SHALL gửi dữ liệu qua client Sentry riêng của plugin và MUST NOT đổi Hub hay client Sentry toàn cục của site, MUST NOT cài error handler, exception handler hay shutdown handler toàn cục. Zip phát hành SHALL đổi namespace các thư viện vendor để plugin kích hoạt và gửi event được khi một plugin khác đã nạp phiên bản Sentry SDK, Guzzle PSR-7 hoặc Symfony OptionsResolver của riêng nó.

#### Scenario: Site already uses Sentry
- **WHEN** một plugin khác đã khởi tạo Sentry với DSN riêng và plugin này bật giám sát với DSN khác
- **THEN** lỗi của plugin này chỉ tới DSN của plugin, và lỗi của site vẫn chỉ tới DSN của site

#### Scenario: Unrelated PHP warning
- **WHEN** một plugin khác phát sinh PHP warning trong khi giám sát đang bật
- **THEN** không có event nào cho warning đó được gửi tới DSN của plugin

#### Scenario: Conflicting libraries in release zip
- **WHEN** zip phát hành được kích hoạt trên site có plugin khác đã nạp class `Sentry\SentrySdk` và `GuzzleHttp\Psr7\Request`
- **THEN** plugin kích hoạt không lỗi và gửi được event thử

### Requirement: Plugin errors reported as issues
Khi giám sát bật, hệ thống SHALL gửi một event lỗi kèm stacktrace cho mỗi exception bị bắt trong code plugin mà trước đây chỉ được `error_log` hoặc ghi thành lỗi `unexpected`, gắn tag `component` và `order_id` khi biết. Hệ thống SHALL gửi một event mức `warning` cho mỗi alert mới cần admin xử lý (cùng điều kiện tạo alert trong failure-alerts), với tag `order_id`, `error_code`, `error_kind`, `invoice.status`, và gom các event này theo `error_code`.

#### Scenario: Unexpected error while sending
- **WHEN** gửi hóa đơn nháp ném một exception không lường trước
- **THEN** server giám sát nhận một event lỗi có stacktrace và tag `order_id`, còn hóa đơn vẫn được ghi lỗi `unexpected` như trước

#### Scenario: Needs attention once per alert
- **WHEN** hết retry và cả hai hook needs-attention lẫn retries-exhausted phát cho cùng đơn và lỗi
- **THEN** server giám sát nhận đúng một event `warning` cho alert đó

#### Scenario: Same error code on two orders
- **WHEN** hai đơn khác nhau cùng dừng với `matbao_rejected`
- **THEN** hai event có cùng fingerprint dựa trên `error_code`

### Requirement: Performance tracing
Khi giám sát bật, hệ thống SHALL tạo một transaction cho mỗi lần chạy action Action Scheduler thuộc group `einvoice-matbao` và cho mỗi request tới route `einvoice-matbao/v1`, lấy mẫu theo `traces_sample_rate`, và SHALL tạo một span con cho mỗi HTTP request tới Mắt Bão và Lark ghi method, đường dẫn (Mắt Bão) hoặc nhãn `lark-webhook` (Lark), HTTP status và thời gian. Span MUST NOT chứa request body, response body, header hay URL webhook Lark. Action của plugin khác MUST NOT tạo transaction.

#### Scenario: Draft run traced
- **WHEN** action tạo nháp chạy, đăng nhập Mắt Bão rồi gọi create-invoice, với tỉ lệ lấy mẫu 1.0
- **THEN** server nhận một transaction tên `einvoice_matbao_create_draft` có span cho `/api/auth/login` và `/api/invoice/create-invoice`

#### Scenario: Sampling off
- **WHEN** tỉ lệ lấy mẫu là 0 và action tạo nháp chạy
- **THEN** không có transaction nào được gửi, nhưng lỗi vẫn được gửi thành issue

#### Scenario: Other plugin's action
- **WHEN** Action Scheduler chạy một action của WooCommerce
- **THEN** không có transaction nào được tạo

### Requirement: Structured logs
Khi giám sát bật, hệ thống SHALL gửi một log mức `info` cho mỗi lần hóa đơn đổi trạng thái (invoice ID, trạng thái cũ, trạng thái mới, mã lỗi nếu có) và một log mức `error` tại mỗi chỗ đang `error_log`, gắn vào trace hiện tại khi có.

#### Scenario: Status change logged
- **WHEN** một hóa đơn chuyển từ `pending` sang `sending` rồi `draft_created`
- **THEN** server nhận hai log `invoice status changed` với `from`/`to` tương ứng

### Requirement: Recovery sweep cron monitor
Khi giám sát bật, hệ thống SHALL gửi check-in cho mỗi lần chạy recovery sweep tới monitor có slug `einvoice-matbao-recovery-<host>-<hash>`, trong đó `<host>` là host của site chuẩn hóa thành chữ thường, chữ số và dấu gạch, cắt còn tối đa 16 ký tự, và `<hash>` là 8 ký tự hex đầu của sha1 của host gốc viết thường, kèm cấu hình lịch 15 phút để server tự tạo monitor, gồm `in_progress` lúc bắt đầu và `ok` hoặc `error` kèm thời gian chạy lúc kết thúc.

#### Scenario: Sweep runs
- **WHEN** recovery sweep chạy xong không lỗi trên site `shop.example.vn`
- **THEN** server nhận check-in `ok` cho monitor `einvoice-matbao-recovery-shop-example-vn-<hash>` với `<hash>` là 8 ký tự hex đầu của sha1("shop.example.vn")

#### Scenario: Two sites share a project
- **WHEN** hai site `a.b.example` và `a-b.example` dùng chung một DSN
- **THEN** hai site check-in vào hai monitor có slug khác nhau

#### Scenario: Long host
- **WHEN** host của site dài 80 ký tự
- **THEN** slug monitor dài không quá 50 ký tự và vẫn kết thúc bằng hash của host

### Requirement: Sensitive data never sent
Hệ thống MUST NOT gửi tới server giám sát dữ liệu cá nhân người mua (tên, mã số thuế, email, địa chỉ, số CCCD, tên công ty), credential hay token Mắt Bão, URL hoặc secret webhook Lark, DSN, hay giá trị tham số hàm trong stacktrace. Mọi chuỗi trong event, span, log (kể cả attribute), breadcrumb và check-in SHALL đi qua bộ lọc trước khi gửi.

#### Scenario: Buyer name and address in exception message
- **WHEN** gửi hóa đơn của một đơn ném exception có message chứa tên người mua, tên công ty và địa chỉ của đơn đó
- **THEN** event nhận được không chứa các giá trị đó

#### Scenario: Buyer data in log attribute
- **WHEN** một log được gửi kèm attribute có giá trị là email người mua
- **THEN** log nhận được có attribute đó bị che

### Requirement: Known buyer values redacted
Trong khi xử lý một đơn, hệ thống SHALL ghi nhận các giá trị người mua đã biết của đơn đó (tên, tên công ty, mã số thuế, email, điện thoại, địa chỉ thanh toán và giao hàng, số CCCD) và SHALL thay mọi lần xuất hiện của chúng (không phân biệt hoa thường, giá trị từ 3 ký tự) bằng `[filtered]` trong dữ liệu gửi đi.

#### Scenario: Address echoed by Mat Bao error
- **WHEN** Mắt Bão trả lỗi có message lặp lại địa chỉ người mua của đơn đang gửi
- **THEN** event và log nhận được thay địa chỉ đó bằng `[filtered]`

### Requirement: Pattern masking
Hệ thống SHALL thay bằng `[filtered]` các mẫu sau trong mọi chuỗi gửi đi: email; URL (với host Mắt Bão chỉ giữ scheme và host, URL khác bỏ hẳn); dãy đúng 12 chữ số; mã số thuế 10 chữ số hoặc 10 chữ số + `-` + 3 chữ số; chuỗi `Bearer <token>`; chuỗi liền từ 32 ký tự trở lên chỉ gồm chữ, số và `+/=_-`.

#### Scenario: Token and tax code masked
- **WHEN** một exception có message `login failed for 0312345678-001 with Bearer abc.def`
- **THEN** event nhận được có message `login failed for [filtered] with [filtered]`

#### Scenario: Buyer data in exception message
- **WHEN** một exception ngoài ngữ cảnh đơn có message chứa email, MST và số CCCD
- **THEN** event nhận được không chứa các giá trị đó

#### Scenario: Lark webhook failure
- **WHEN** gửi Lark thất bại khi giám sát bật
- **THEN** event và span không chứa URL hay secret webhook

#### Scenario: Stack frame arguments
- **WHEN** exception được ném trong hàm nhận buyer snapshot làm tham số
- **THEN** các frame của event không có giá trị tham số

### Requirement: Tag allowlist
Event gửi tới server giám sát SHALL chỉ mang tag thuộc danh sách cho phép (`site`, `order_id`, `invoice.id`, `invoice.status`, `error_code`, `error_kind`, `matbao.env`, `component`), với `site` là host của site, và MUST NOT mang thông tin request, user hay server name.

#### Scenario: Extra tag dropped
- **WHEN** code plugin gắn một tag ngoài danh sách cho phép
- **THEN** event nhận được không có tag đó nhưng có tag `site`

#### Scenario: No server name
- **WHEN** giám sát gửi một event lỗi
- **THEN** event không có `server_name`, `request` hay `user`

### Requirement: Monitoring never breaks invoicing
Lỗi của lớp giám sát (server chậm, server trả lỗi, DSN sai, thiếu extension, lỗi nội bộ) MUST NOT làm đổi kết quả hay ném lỗi ra luồng hóa đơn, checkout hay route admin. Mỗi lần gửi SHALL có timeout không quá 3 giây.

#### Scenario: Monitoring internal error
- **WHEN** bộ lọc dữ liệu ném exception khi xử lý một event
- **THEN** event bị bỏ, luồng hóa đơn tiếp tục bình thường và `error_log` chỉ ghi tên class lỗi

### Requirement: Send backoff
Khi một lần gửi thất bại (lỗi kết nối, timeout hoặc HTTP ngoài 2xx), hệ thống SHALL ngừng gửi trong phần còn lại của request đó và trong các request bắt đầu trong 60 giây sau. Với HTTP 429 có `Retry-After` là số giây nguyên dương, thời gian ngừng SHALL theo `Retry-After` và tối đa 600 giây; `Retry-After` thiếu hoặc không hợp lệ SHALL dùng 60 giây. Dữ liệu phát sinh trong thời gian ngừng SHALL bị bỏ.

#### Scenario: Next request during backoff
- **WHEN** một lần gửi trả 503 và 30 giây sau một action khác của plugin gặp lỗi
- **THEN** action sau không gửi request nào tới server giám sát

#### Scenario: After backoff
- **WHEN** một lần gửi trả 503 và 61 giây sau một action khác gặp lỗi
- **THEN** action sau gửi event bình thường

#### Scenario: Rate limited with Retry-After
- **WHEN** server trả 429 với `Retry-After: 120`
- **THEN** không có request nào được gửi trong 120 giây tiếp theo

#### Scenario: Retry-After too long
- **WHEN** server trả 429 với `Retry-After: 99999`
- **THEN** việc gửi được nối lại sau 600 giây

#### Scenario: Server down
- **WHEN** server giám sát không phản hồi và action tạo nháp chạy với một lỗi Mắt Bão
- **THEN** hóa đơn vẫn được ghi lỗi và lên lịch retry như khi giám sát tắt, và trong cùng request có tối đa một lần thử gửi

#### Scenario: Missing mbstring
- **WHEN** PHP không có `ext-mbstring` và giám sát được bật
- **THEN** giám sát coi như tắt và plugin hoạt động bình thường
