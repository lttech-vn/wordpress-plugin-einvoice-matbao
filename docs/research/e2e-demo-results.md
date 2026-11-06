# Kết quả kiểm chứng API demo Mắt Bão (ticket #15)

Dữ liệu thô: [`e2e-demo-results.json`](e2e-demo-results.json), sinh ngày 2026-10-31 (21:35 giờ Việt Nam) bởi `tests/e2e/demo-api/verify_demo.py` trên `https://demo-api-hddt.matbao.in:11443` (mẫu `1/C26TAT`; kiểm tra khác mẫu dùng thêm `1/C26TKK`). Chỉ tạo nháp: không ký hay phát hành gì, host production bị allowlist của harness chặn, và không POST nào được thử lại. Credential lấy từ biến môi trường, không có trong repo.

## Tóm tắt

| # | Kiểm tra | Quan sát | Kết luận | Hệ quả cho plugin |
|---|---|---|---|---|
| a1 | 5 create song song, cùng `MTChieu`, cùng mẫu | Tạo 2 nháp (`success`, hai `maTraCuu` khác nhau), 3 trả 305 "Trùng mã tham chiếu"; tra cứu theo `MTChieu` trả 2 nháp | Mắt Bão **không** chặn trùng nguyên tử | Chống trùng phải do plugin đảm nhận (xem dưới) |
| a2 | Trùng tuần tự (cùng `MTChieu`, cùng mẫu) | HTTP 200, envelope `errorCode` 201, item `errorCode` 305 "Trùng mã tham chiếu" | Trùng tuần tự bị từ chối | 305 nghĩa là "đã tạo": tra cứu, không bao giờ đổi mã tham chiếu (đã triển khai) |
| a3 | Cùng `MTChieu`, mẫu khác (`C26TKK`) | Tạo được nháp (200); tra cứu dưới mỗi mẫu trả nháp của mẫu đó | Kiểm tra trùng theo từng mẫu, không xuyên mẫu | Sau retry phải tra cứu bằng mẫu đã lưu (đã triển khai) |
| b | Người mua vãng lai (chỉ `NMua_Ten`) | Tạo được; chi tiết hiện `nMua_Ten` = "Khách hàng không lấy hóa đơn" | Khớp `Settings::DEFAULT_WALK_IN_BUYER_NAME` | Không |
| c1 | Chiết khấu, shape của plugin (`DGia` = đơn giá net 180000, `TLCKhau` 0) | Chấp nhận; `thTien` 540000, thuế 54000, tổng 594000 | OK | Plugin gửi shape này |
| c2 | Chiết khấu, shape giá niêm yết (`DGia` 200000, `TLCKhau` 10, `STCKhau` 60000) | Chấp nhận; cùng tổng; giữ 600000 trước chiết khấu, chiết khấu 60000 | OK, cũng hợp lệ | Không cần |
| c3 | Không nhất quán (`DGia` 200000, `TLCKhau` 0, `ThTienChuaCK` 600000, `ThTien` 540000) | Chấp nhận; cùng tổng | API không đối chiếu chéo các trường của dòng | Plugin phải tự giữ số học nhất quán (`DraftPayloadBuilder` làm vậy) |
| d1 | `DGia` không chia hết (3 x 33333.33333 = 100000) | Chấp nhận; `dGia` lưu `33333.33333`, `thTien` 100000, tổng bằng chữ "Một trăm mười nghìn đồng" | OK; `TgTTTBChu` có dấu được chấp nhận | Làm tròn `DGia` 6 chữ số thập phân an toàn vì `ThTien` được gửi tường minh |
| d2 | Mẫu không tồn tại / hết số | Envelope 201, item 201 "Trường 'KHMSHDon','KHHDon' bị thiếu hoặc không hợp lệ." | Hai trường hợp cùng thông điệp | Lỗi mẫu là lỗi dữ liệu (không retry) |
| d3 | Mã 308 và 327 | Không tái hiện được | Chưa xác nhận được nguyên nhân hay nội dung | Không đổi hành vi; giữ như tài liệu |
| d4 | Múi giờ `expiredDate` | `2026-11-01T21:35:51` lúc 14:35:51 UTC: sau 24 giờ nếu đọc theo giờ Việt Nam, 31 giờ nếu UTC; khóa login là `accessToken`, `expiredDate` | Giờ địa phương Việt Nam (token 24 giờ) | `MatbaoClient::cache_token` đọc theo `Asia/Ho_Chi_Minh` (đúng) |
| d5 | Tra cứu theo `MTChieu` không có `MaTraCuu` | Trả các nháp kèm `maTraCuu`, trạng thái "Nháp"; dòng tóm tắt không có người mua hay tổng | Hoạt động; response tra cứu không có số tiền | So khớp tra cứu dựa trên những gì có; người mua và tổng lấy từ `invoice-detail-batch` khi cần |

## Chống trùng (a)

Test đồng thời là kết quả quan trọng: cùng `MTChieu` và mẫu có thể sinh hai nháp khi request chồng lấn. Plugin không bao giờ gửi hai create cùng lúc cho một đơn, vì:

- `UNIQUE(order_id)` trong `Schema.php`: `InvoiceRepository::reserve` là `INSERT IGNORE`, nên mỗi đơn chỉ có một dòng invoice.
- `InvoiceRepository::claim(pending -> sending)` là `UPDATE` có điều kiện; chỉ request thắng claim mới gọi `create_invoice`. Runner thứ hai (retry Action Scheduler, sweep, bấm hai lần) thua claim và dừng.
- Sau một kết quả không rõ (timeout, 305), workflow tra cứu theo `MTChieu` dưới mẫu đã lưu trước mọi create tiếp theo, và không bao giờ đổi mã tham chiếu.

Nếu tra cứu vẫn trả nhiều hơn một nháp cho một `MTChieu` (trạng thái test này tạo ra), `DraftInvoiceWorkflow::lookup_mismatch` không chọn nháp nào. Nó ghi attempt `lookup_mismatch` với thông điệp "Mắt Bão trả về N hóa đơn cùng mã tham chiếu ...; cần admin kiểm tra" và để đơn cho admin. Đây là hành vi đúng.

## Shape chiết khấu (c)

Plugin gửi đơn giá net trong `DGia`, `TLCKhau` = `STCKhau` = 0 và `ThTienChuaCK` = `ThTien` = số tiền net (`DraftPayloadBuilder`). Demo chấp nhận cả shape này lẫn shape giá niêm yết, và tính ra cùng tổng. Vì vậy hóa đơn không hiện giá trước chiết khấu hay % chiết khấu; đó là lựa chọn trình bày, không phải lỗi API. Nếu cửa hàng muốn hiện chiết khấu trên hóa đơn, shape giá niêm yết (c2) hoạt động trên demo.

## Phát hiện cần xử lý

Không có vấn đề về tính đúng của plugin. Một thay đổi code phát sinh từ lần chạy: demo bọc lỗi item trong envelope lỗi (`errorCode` 201 kèm item 305). `MatbaoClient::create_invoice` từng bỏ qua item nên không bao giờ tới bước tra cứu trùng. Giờ nó đọc kết quả item khi envelope lỗi (test `4a6d637`, fix `314619a`).

## Chưa kiểm chứng

Ký, phát hành và hành vi production; mã 308 và 327; Mắt Bão có tự làm mới token 24 giờ sớm hay không.
