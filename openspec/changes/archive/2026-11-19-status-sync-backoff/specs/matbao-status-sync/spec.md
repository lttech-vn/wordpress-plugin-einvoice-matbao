## ADDED Requirements

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

## MODIFIED Requirements

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

### Requirement: Status sync stops with the plugin
Action đồng bộ, gồm action hằng giờ và lượt thử lại sau tạm dừng, SHALL thuộc nhóm action của plugin để bị hủy khi plugin bị vô hiệu hóa hoặc gỡ cài đặt, và action hằng giờ SHALL được xếp lại khi plugin chạy lại.

#### Scenario: Deactivation
- **WHEN** plugin bị vô hiệu hóa
- **THEN** không còn action đồng bộ trạng thái nào được xếp lịch

#### Scenario: Deactivation during the pause
- **WHEN** plugin bị vô hiệu hóa khi đang có lượt thử lại được xếp lịch
- **THEN** lượt thử lại bị hủy
