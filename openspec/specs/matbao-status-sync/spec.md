# matbao-status-sync Specification

## Purpose

Đồng bộ trạng thái hóa đơn trên Mắt Bão sau khi tạo nháp: phạm vi và lịch đồng bộ, ghép kết quả tra cứu, trạng thái cuối và bất thường, cảnh báo một lần khi chuyển sang trạng thái bất thường.

## Requirements

### Requirement: Mat Bao status values
Hệ thống SHALL biểu diễn trạng thái hóa đơn trên Mắt Bão theo bảng mã `maTThaiHDon` 1–9 của Mắt Bão (1 Nháp, 2 Lỗi ký, 3 Chưa gửi CQT, 4 Đã cấp mã/tiếp nhận, 5 CQT chưa phản hồi, 6 Không đủ điều kiện cấp mã/tiếp nhận, 7–9 các trạng thái Mẫu 04/SS), cộng trạng thái riêng "Đã xóa trên Mắt Bão". Mã ngoài bảng SHALL hiển thị bằng `tenTThaiHDon` của Mắt Bão.

#### Scenario: Known code
- **WHEN** Mắt Bão trả `maTThaiHDon` = 5
- **THEN** trạng thái hiển thị là "CQT chưa phản hồi"

#### Scenario: Unknown code
- **WHEN** Mắt Bão trả `maTThaiHDon` = 12 với `tenTThaiHDon` "Trạng thái mới"
- **THEN** giao diện hiện "Trạng thái mới", hóa đơn tiếp tục được đồng bộ và không có cảnh báo

### Requirement: Final and abnormal Mat Bao statuses
Hệ thống SHALL coi Đã cấp mã/tiếp nhận và Đã xóa trên Mắt Bão là trạng thái cuối, và MUST coi đúng Lỗi ký, Không đủ điều kiện cấp mã/tiếp nhận và Đã xóa trên Mắt Bão là trạng thái bất thường; mọi trạng thái khác là trung gian.

#### Scenario: Issued is final
- **WHEN** Mắt Bão trả `maTThaiHDon` = 4
- **THEN** trạng thái là "Đã cấp mã/tiếp nhận", là trạng thái cuối và không bất thường

#### Scenario: Sign error is abnormal
- **WHEN** Mắt Bão trả `maTThaiHDon` = 2
- **THEN** trạng thái là "Lỗi ký", không phải trạng thái cuối và là bất thường

### Requirement: Hourly status sync of created drafts
Hệ thống SHALL chạy đồng bộ trạng thái Mắt Bão mỗi giờ qua Action Scheduler cho các hóa đơn `draft_created` có mã hóa đơn Mắt Bão và mẫu đã lưu, chưa ở trạng thái cuối, và có ngày lập (hoặc ngày tạo bản ghi khi thiếu ngày lập) trong vòng 30 ngày. Mỗi lượt SHALL xử lý tối đa 200 hóa đơn, ưu tiên hóa đơn chưa từng được kiểm tra rồi tới hóa đơn kiểm tra lâu nhất, gom theo mẫu số và ký hiệu với tối đa 20 `MTChieu` mỗi lần gọi Mắt Bão. Hóa đơn ngoài điều kiện trên MUST NOT được tra cứu bởi đồng bộ.

#### Scenario: Draft gets issued
- **WHEN** một hóa đơn `draft_created` được kế toán ký và Mắt Bão trả `maTThaiHDon` = 4 ở lượt đồng bộ kế tiếp
- **THEN** bản ghi có trạng thái Mắt Bão "Đã cấp mã/tiếp nhận", số hóa đơn, MCCQT và thời điểm đồng bộ, và status plugin vẫn là `draft_created`

#### Scenario: Final invoice not polled again
- **WHEN** hóa đơn đã ở "Đã cấp mã/tiếp nhận"
- **THEN** các lượt đồng bộ sau không tra cứu hóa đơn đó

#### Scenario: Older than 30 days
- **WHEN** hóa đơn `draft_created` có ngày lập cách đây 31 ngày và vẫn là Nháp
- **THEN** đồng bộ không tra cứu hóa đơn đó

#### Scenario: Draft created before upgrade
- **WHEN** plugin được nâng cấp và có hóa đơn `draft_created` lập 3 ngày trước, chưa có trạng thái Mắt Bão
- **THEN** lượt đồng bộ đầu tiên tra cứu và ghi trạng thái Mắt Bão cho hóa đơn đó

#### Scenario: Batch limit and rotation
- **WHEN** có 250 hóa đơn cần đồng bộ
- **THEN** một lượt tra cứu 200 hóa đơn và lượt sau ưu tiên 50 hóa đơn còn lại

#### Scenario: Requests grouped by template
- **WHEN** một lượt có 25 hóa đơn mẫu `1/C26TAT` và 3 hóa đơn mẫu `1/C26TBB`
- **THEN** có hai request cho mẫu `1/C26TAT` (20 và 5 `MTChieu`) và một request cho mẫu `1/C26TBB`

#### Scenario: Other plugin statuses ignored
- **WHEN** hóa đơn ở `failed`, `pending` hoặc `skipped`
- **THEN** đồng bộ không tra cứu hóa đơn đó

### Requirement: Sync never changes the invoice workflow
Đồng bộ SHALL chỉ ghi trạng thái và thông tin Mắt Bão của hóa đơn; đồng bộ MUST NOT đổi status plugin, tạo, gửi lại, sửa, ký hay xóa hóa đơn trên Mắt Bão.

#### Scenario: Deleted draft not recreated
- **WHEN** đồng bộ thấy nháp đã bị xóa trên Mắt Bão
- **THEN** status plugin vẫn `draft_created` và không có request `create-invoice` nào

### Requirement: Matching the stored Mat Bao invoice
Đồng bộ SHALL tra cứu theo `MTChieu` với mẫu số và ký hiệu đã lưu, và SHALL chỉ nhận dòng có `maSoHDon` bằng mã hóa đơn Mắt Bão đã lưu. Hệ thống SHALL ghi "Đã xóa trên Mắt Bão" chỉ khi Mắt Bão xác nhận không có hóa đơn: errorCode 301, hoặc `MTChieu` nằm trong danh sách `notFound`. Mọi trường hợp khác không có dòng khớp (dòng cùng `MTChieu` nhưng khác mã, dòng không đọc được, hoặc `MTChieu` vắng mặt ở cả hai danh sách) SHALL giữ nguyên trạng thái Mắt Bão và ghi log cảnh báo.

#### Scenario: Matching row
- **WHEN** Mắt Bão trả một dòng có `maSoHDon` bằng mã đã lưu với `maTThaiHDon` = 3
- **THEN** trạng thái Mắt Bão là "Chưa gửi CQT"

#### Scenario: Not found
- **WHEN** tra cứu trả errorCode 301 cho `MTChieu` của hóa đơn
- **THEN** trạng thái Mắt Bão là "Đã xóa trên Mắt Bão" và hóa đơn không còn được đồng bộ

#### Scenario: Listed as not found
- **WHEN** Mắt Bão trả thành công với `MTChieu` của hóa đơn trong `notFound`
- **THEN** trạng thái Mắt Bão là "Đã xóa trên Mắt Bão"

#### Scenario: Unreadable row
- **WHEN** Mắt Bão trả một dòng cho `MTChieu` của hóa đơn nhưng dòng đó không đọc được
- **THEN** trạng thái Mắt Bão không đổi, hóa đơn vẫn được đồng bộ ở lượt sau và một log cảnh báo được ghi

#### Scenario: Reference missing from the answer
- **WHEN** lô tra cứu ba `MTChieu`, Mắt Bão trả dòng cho hai và không liệt kê cái thứ ba ở đâu cả
- **THEN** hóa đơn thứ ba giữ trạng thái Mắt Bão cũ và không bị ghi "Đã xóa"

#### Scenario: Different invoice under the reference
- **WHEN** Mắt Bão trả một dòng cùng `MTChieu` nhưng `maSoHDon` khác mã đã lưu
- **THEN** trạng thái Mắt Bão không đổi và một log cảnh báo được ghi

### Requirement: Lookup failures leave the status unchanged
Khi tra cứu lỗi (transport, HTTP hoặc API, kể cả phản hồi 200 không đọc được), đồng bộ SHALL giữ nguyên trạng thái Mắt Bão đã lưu, chỉ ghi thời điểm đã thử cho các hóa đơn của request lỗi, và ghi log cảnh báo; lỗi tra cứu MUST NOT tạo alert. Khi lỗi là tạm thời (lỗi transport, HTTP 5xx, 408 hoặc 429, hoặc phản hồi 200 không đọc được, kể cả khi lỗi xảy ra lúc đăng nhập), lượt đồng bộ SHALL dừng ngay: các nhóm chưa tra cứu MUST NOT được gửi tới Mắt Bão và MUST NOT bị ghi thời điểm đã thử. Với lỗi khác, đồng bộ SHALL tiếp tục các nhóm còn lại. Khi chưa có thông tin kết nối Mắt Bão dùng được, lượt đồng bộ SHALL bỏ qua mà không gọi Mắt Bão. Scenario "Mat Bao 503" được thay kỳ vọng có chủ đích: nhóm kế tiếp không còn được tra cứu trong cùng lượt.

#### Scenario: Mat Bao 503
- **WHEN** tra cứu nhóm hóa đơn đầu tiên của lượt trả HTTP 503
- **THEN** các hóa đơn đó giữ trạng thái Mắt Bão cũ, được ghi thời điểm đã thử, không bị ghi "Đã xóa", không có alert, và lượt đó không gửi thêm request nào tới Mắt Bão

#### Scenario: Mat Bao times out
- **WHEN** tra cứu nhóm hóa đơn đầu tiên của lượt gặp lỗi transport (timeout)
- **THEN** lượt đó không gửi thêm request nào, các nhóm chưa tra cứu giữ nguyên thời điểm đã thử, trạng thái Mắt Bão không đổi và không có alert

#### Scenario: Other temporary failures stop the run
- **WHEN** tra cứu nhóm đầu tiên trả HTTP 408, HTTP 429, hoặc HTTP 200 với nội dung không đọc được
- **THEN** lượt đó không gửi thêm request nào và đồng bộ tạm dừng 15 phút

#### Scenario: Login fails temporarily
- **WHEN** token đã hết hạn và đăng nhập trước tra cứu nhóm đầu tiên bị timeout hoặc trả HTTP 503
- **THEN** không có request tra cứu nào, nhóm đó được ghi thời điểm đã thử, các nhóm khác không bị gọi, và đồng bộ tạm dừng 15 phút

#### Scenario: Expired token refreshed
- **WHEN** tra cứu nhận HTTP 401, đăng nhập lại thành công rồi tra cứu lại thành công
- **THEN** kết quả được ghi và đồng bộ không tạm dừng

#### Scenario: Unreadable row is not a failure
- **WHEN** tra cứu trả HTTP 200 với danh sách hóa đơn đọc được nhưng dòng của một hóa đơn không đọc được
- **THEN** lượt tiếp tục các nhóm còn lại và đồng bộ không tạm dừng (theo requirement Matching the stored Mat Bao invoice)

#### Scenario: Non-temporary failure continues
- **WHEN** tra cứu một nhóm trả HTTP 400 và nhóm kế tiếp trả lời được
- **THEN** nhóm kế tiếp vẫn được tra cứu và ghi trạng thái trong cùng lượt, và đồng bộ không tạm dừng

#### Scenario: No credentials
- **WHEN** lượt đồng bộ chạy khi chưa lưu thông tin kết nối Mắt Bão
- **THEN** không có request nào tới Mắt Bão

### Requirement: Abnormal status alerted once per transition
Khi trạng thái Mắt Bão chuyển sang trạng thái bất thường, hệ thống SHALL ghi mã cảnh báo tương ứng (`matbao_sign_failed`, `matbao_not_eligible`, `matbao_draft_deleted`), giữ status `draft_created`, tăng chu kỳ alert và phát đúng một lần hook needs-attention, kể cả khi hai tiến trình cùng ghi. Cùng trạng thái ở lần sau MUST NOT cảnh báo lại.

#### Scenario: Sign error detected
- **WHEN** đồng bộ thấy hóa đơn chuyển từ Nháp sang Lỗi ký
- **THEN** hóa đơn có `error_code` = `matbao_sign_failed`, status vẫn `draft_created`, chu kỳ alert tăng một và hook needs-attention phát đúng một lần (tin Lark và event giám sát theo failure-alerts và error-monitoring)

#### Scenario: Same abnormal status next hour
- **WHEN** lượt đồng bộ kế tiếp vẫn thấy Lỗi ký
- **THEN** hook needs-attention không phát lại và không có alert mới

#### Scenario: Concurrent sync and manual check
- **WHEN** đồng bộ tự động và nút "Kiểm tra trạng thái" cùng thấy hóa đơn chuyển sang Không đủ điều kiện cấp mã/tiếp nhận
- **THEN** chỉ có một alert

#### Scenario: Issued is not alerted
- **WHEN** hóa đơn chuyển từ Nháp sang "Đã cấp mã/tiếp nhận"
- **THEN** hook needs-attention không phát

### Requirement: Mat Bao warning shares the invoice error code
Mã cảnh báo trạng thái Mắt Bão SHALL thay mã cảnh báo đang có trên hóa đơn, kể cả `order_*_changed` vốn đã được alert riêng. Khi trạng thái Mắt Bão chuyển sang không bất thường, hệ thống SHALL chỉ xóa mã lỗi đang có nếu đó là mã cảnh báo trạng thái Mắt Bão.

#### Scenario: Recovered after re-signing
- **WHEN** hóa đơn đang `matbao_sign_failed` được ký lại và đồng bộ thấy "Đã cấp mã/tiếp nhận"
- **THEN** mã cảnh báo được xóa và admin notice của alert đó biến mất

#### Scenario: Order warning kept on recovery
- **WHEN** hóa đơn đang `matbao_sign_failed`, sau đó đơn bị đổi tổng tiền (`order_amounts_changed`), rồi đồng bộ thấy "Đã cấp mã/tiếp nhận"
- **THEN** `error_code` vẫn là `order_amounts_changed`

#### Scenario: Abnormal status replaces order warning
- **WHEN** hóa đơn đang có `order_buyer_changed` và đồng bộ thấy Lỗi ký
- **THEN** `error_code` là `matbao_sign_failed` và một alert mới được tạo

### Requirement: Recreated draft starts a fresh Mat Bao status
Khi hóa đơn nhận một mã hóa đơn Mắt Bão mới (tạo lại sau khi nháp bị xóa), hệ thống SHALL xóa trạng thái và thông tin Mắt Bão đã lưu để hóa đơn được đồng bộ lại từ đầu. Kết quả tra cứu được đọc trước khi mã hóa đơn hoặc status plugin đổi MUST NOT được ghi vào bản ghi.

#### Scenario: Draft recreated
- **WHEN** hóa đơn "Đã xóa trên Mắt Bão" được admin xác nhận tạo lại và nhận mã hóa đơn mới
- **THEN** trạng thái Mắt Bão rỗng và hóa đơn có mặt trong lượt đồng bộ kế tiếp

#### Scenario: Stale lookup after recreation
- **WHEN** một lượt đồng bộ đọc hóa đơn với mã cũ, rồi hóa đơn được tạo lại với mã mới trước khi lượt đó ghi kết quả
- **THEN** kết quả của lượt đó không được ghi

### Requirement: Status sync stops with the plugin
Action đồng bộ, gồm action hằng giờ và lượt thử lại sau tạm dừng, SHALL thuộc nhóm action của plugin để bị hủy khi plugin bị vô hiệu hóa hoặc gỡ cài đặt, và action hằng giờ SHALL được xếp lại khi plugin chạy lại.

#### Scenario: Deactivation
- **WHEN** plugin bị vô hiệu hóa
- **THEN** không còn action đồng bộ trạng thái nào được xếp lịch

#### Scenario: Deactivation during the pause
- **WHEN** plugin bị vô hiệu hóa khi đang có lượt thử lại được xếp lịch
- **THEN** lượt thử lại bị hủy

### Requirement: Status sync backs off while Mat Bao fails
Mỗi lượt đồng bộ tự động dừng vì lỗi tạm thời SHALL tăng chuỗi lỗi liên tiếp và tạm dừng đồng bộ tự động lần lượt 15 phút, 30 phút, 1 giờ, 2 giờ, 4 giờ, rồi 6 giờ cho mọi lần sau. Lượt đồng bộ tự động chạy trong thời gian tạm dừng MUST NOT gọi Mắt Bão. Trạng thái backoff không đọc được SHALL được coi là không có backoff. Backoff MUST NOT tạo alert.

#### Scenario: First failure pauses 15 minutes
- **WHEN** chưa có chuỗi lỗi và một lượt đồng bộ dừng vì lỗi tạm thời
- **THEN** đồng bộ tự động tạm dừng 15 phút và một lượt thử lại được xếp lịch vào lúc hết tạm dừng

#### Scenario: Pause grows up to six hours
- **WHEN** các lượt liên tiếp dừng vì lỗi tạm thời lần thứ 2, 3, 4, 5, 6 và 7
- **THEN** thời gian tạm dừng lần lượt là 30 phút, 1 giờ, 2 giờ, 4 giờ, 6 giờ và 6 giờ

#### Scenario: Pause expired without success
- **WHEN** đã có hai lỗi liên tiếp, thời gian tạm dừng đã hết và lượt kế tiếp lại dừng vì lỗi tạm thời
- **THEN** thời gian tạm dừng là 1 giờ

#### Scenario: Unreadable backoff state
- **WHEN** trạng thái backoff đã lưu không đọc được và lượt hằng giờ chạy
- **THEN** lượt đó tra cứu Mắt Bão như khi không có backoff, và nếu gặp lỗi tạm thời thì tạm dừng 15 phút

#### Scenario: Hourly run during the pause
- **WHEN** lượt hằng giờ chạy khi thời gian tạm dừng chưa hết
- **THEN** không có request nào tới Mắt Bão và không hóa đơn nào bị ghi thời điểm đã thử

#### Scenario: Backoff state removed on uninstall
- **WHEN** plugin bị xóa với công tắc xóa dữ liệu tắt khi đồng bộ đang tạm dừng
- **THEN** trạng thái backoff bị xóa

### Requirement: Successful lookup resets the backoff
Tra cứu thành công là request tra cứu nhận được câu trả lời Mắt Bão đọc được (kể cả errorCode 301, `notFound` hoặc dòng không khớp), bất kể kết quả ghi vào hóa đơn. Một tra cứu thành công của đồng bộ tự động SHALL xóa chuỗi lỗi, thời gian tạm dừng và lượt thử lại đang chờ. Hết thời gian tạm dừng MUST NOT xóa chuỗi lỗi. Alert trạng thái bất thường SHALL vẫn phát theo requirement riêng.

#### Scenario: Success in the middle of a run
- **WHEN** đang có hai lỗi liên tiếp, thời gian tạm dừng đã hết, và trong một lượt có ba nhóm A, B, C thì A tra cứu thành công, B gặp HTTP 503
- **THEN** A giữ kết quả đã ghi, B được ghi thời điểm đã thử, C không bị gọi và không bị ghi thời điểm đã thử, và thời gian tạm dừng mới là 15 phút

#### Scenario: Not found counts as success
- **WHEN** đồng bộ đang có chuỗi lỗi và tra cứu trả errorCode 301 cho nhóm hóa đơn
- **THEN** chuỗi lỗi bị xóa, hóa đơn được ghi "Đã xóa trên Mắt Bão" và alert `matbao_draft_deleted` phát như bình thường

### Requirement: Retry run when the pause ends
Khi hết tạm dừng, hệ thống SHALL chạy một lượt đồng bộ thử lại mà không chờ lượt hằng giờ kế tiếp. Mỗi lần tạm dừng mới SHALL thay lượt thử lại đang chờ bằng lượt thử lại vào lúc hết tạm dừng mới. Một lượt thử lại chạy khi vẫn còn tạm dừng (do hai lượt chạy chồng) MUST NOT gọi Mắt Bão. Lịch hằng giờ SHALL giữ nguyên.

#### Scenario: Retry run after the pause
- **WHEN** lượt thử lại chạy sau khi hết tạm dừng và Mắt Bão trả dòng "Chưa gửi CQT" cho hóa đơn
- **THEN** hóa đơn được ghi trạng thái, chuỗi lỗi bị xóa, không còn lượt thử lại chờ và action hằng giờ vẫn được xếp lịch

#### Scenario: Retry run while still paused
- **WHEN** một lượt thử lại chạy khi thời gian tạm dừng vẫn chưa hết
- **THEN** không có request nào tới Mắt Bão

#### Scenario: Only one retry queued
- **WHEN** hai lượt liên tiếp dừng vì lỗi tạm thời
- **THEN** chỉ còn một lượt thử lại được xếp lịch, vào lúc hết tạm dừng mới nhất

### Requirement: Manual check bypasses the backoff
Nút "Kiểm tra trạng thái" MUST NOT bị chặn bởi thời gian tạm dừng của đồng bộ tự động. Tra cứu thành công từ nút này SHALL xóa chuỗi lỗi, thời gian tạm dừng và lượt thử lại đang chờ; lỗi của nút này MUST NOT tăng chuỗi lỗi hay đổi thời gian tạm dừng.

#### Scenario: Manual check during the pause
- **WHEN** đồng bộ tự động đang tạm dừng với một lượt thử lại đang chờ, có thêm một hóa đơn Nháp khác cần đồng bộ, và admin bấm "Kiểm tra trạng thái" trên một hóa đơn, Mắt Bão trả dòng "Đã cấp mã/tiếp nhận"
- **THEN** trạng thái hóa đơn được ghi, chuỗi lỗi bị xóa, không còn lượt thử lại chờ, và lượt hằng giờ kế tiếp tra cứu hóa đơn Nháp còn lại

#### Scenario: Manual check fails
- **WHEN** admin bấm "Kiểm tra trạng thái" và Mắt Bão trả HTTP 503
- **THEN** chuỗi lỗi và thời gian tạm dừng không đổi
