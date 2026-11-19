## 1. Lookup code: parse, snapshot, storage

Blocked by: none

- [x] 1.1 `MatbaoInvoice` đọc `maTraCuu` (rỗng → null) thành `lookup_code`; test trong `tests/integration/MatbaoLookupTest.php` (Lookup code read, Lookup code missing)
- [x] 1.2 `MatbaoSnapshot` thêm `lookup_code` (`from_invoice` lấy từ hóa đơn, `deleted()` để null)
- [x] 1.3 `Schema` lên version 7, thêm cột `lookup_code varchar(100) NULL`; test nâng cấp trong `tests/integration/SchemaUpgradeTest.php`
- [x] 1.4 `InvoiceRecord` thêm `lookup_code`; `InvoiceRepository::record_matbao_status` ghi cột; `MATBAO_STATUS_COLUMNS` thêm `lookup_code`; test trong `tests/integration/InvoiceRepositoryTest.php` (Lookup code stored, Deleted clears the lookup code, Recorded after recreation)

## 2. Mat Bao PDF download call

Blocked by: none

- [x] 2.1 `MatbaoClient::download_invoice_pdf( $invoice_id, $lookup_code )`: POST `/api/invoice/download-invoice`, đọc `data.data_PDF_Base64` không phân biệt hoa thường, `base64_decode` strict, kiểm `%PDF-`, ném `MatbaoError` `api` khi sai
- [x] 2.2 Test integration mới `tests/integration/MatbaoDownloadInvoiceTest.php` dùng `FakeHttp` cho mọi scenario của requirement *Invoice PDF download*
- [x] 2.3 Fake Mắt Bão e2e (`tests/e2e/fake-matbao/router.php`) hỗ trợ `download-invoice` (trả PDF tối thiểu khi khớp `maSoHDon` + `maTraCuu`, hỗ trợ step script) và action `issue` nhận thêm `maLoaiHDon`, `shDon`

## 3. Customer status mapping

Blocked by: 1

- [ ] 3.1 Enum `src/Customer/CustomerInvoiceStatus.php` với `from_record( InvoiceRecord )`, `label()`, `allows_pdf()` theo design D2
- [ ] 3.2 Test `tests/integration/CustomerInvoiceStatusTest.php` dạng data provider phủ toàn bộ plugin statuses, Mắt Bão 0–9, null, 12, loại 3/5/chưa biết, thiếu mã hóa đơn

## 4. Customer invoice section on view-order

Blocked by: 3

- [ ] 4.1 `src/Customer/OrderInvoiceSection.php`: hook `woocommerce_order_details_after_order_table`, chỉ khi endpoint `view-order` và người xem là chủ đơn; render nhãn và, khi `ISSUED`, số/ký hiệu/ngày lập và link tải có nonce theo đơn
- [ ] 4.2 Đăng ký trong `src/Plugin.php::boot()` (ngoài nhánh `is_admin()`)
- [ ] 4.3 Test `tests/integration/CustomerOrderInvoiceSectionTest.php`: các scenario của *Invoice section on the customer order page*, *Customer view hides staff details*, nút tải theo trạng thái

## 5. PDF download handler

Blocked by: 2, 3

- [ ] 5.1 `src/Customer/PdfResponder.php`: seam gửi header + body + exit; bản test thu lại header/body
- [ ] 5.2 `src/Customer/InvoicePdfDownload.php`: `template_redirect` trên `view-order` có query arg; kiểm đăng nhập → nonce → chủ đơn → `ISSUED` → `MatbaoStatusSync::sync_one` (chỉ `UPDATED`/`UNCHANGED`) → đọc lại (cùng mã hóa đơn, vẫn `ISSUED`, có mã tra cứu) → `download_invoice_pdf` → `PdfResponder`; mọi lỗi → `wc_add_notice` chung + redirect về URL `view-order` sạch; lỗi Mắt Bão → `Monitor::log` không body
- [ ] 5.3 Đăng ký trong `src/Plugin.php::boot()`
- [ ] 5.4 Test `tests/integration/CustomerInvoicePdfDownloadTest.php`: các scenario của *PDF download only by the order owner*, *PDF only for a still-valid issued invoice*, *PDF served without storing it*, và *Customer download check* (backoff, alert)

## 6. E2E, docs and demo verification

Blocked by: 4, 5

- [ ] 6.1 Seam e2e (`tests/e2e/wordpress/einv-e2e-http.php`): tạo user `customer`, gán `customer_id` cho đơn qua `/order`, và seam `/status-sync` chạy đồng bộ trạng thái
- [ ] 6.2 E2E Army `tests/e2e/army/customer-invoice.army.ts`: khách thấy "Đang xuất hóa đơn" khi nháp; sau `issue` và đồng bộ thấy "Đã xuất hóa đơn" và tải được PDF (fetch có cookie/nonce, kiểm `application/pdf` và `%PDF-`); khách khác không xem được đơn
- [ ] 6.3 Cập nhật `docs/discovery.md` (bỏ "bản đầu không hiển thị cho khách", ghi phạm vi mới) và `docs/huong-dan-su-dung.md` (mục khách xem hóa đơn trong My Account)
- [ ] 6.4 Xác minh `download-invoice` trên demo Mắt Bão với một hóa đơn đã phát hành (script read-only theo mẫu `docs/research/demo-probe.py`, redact base64); ghi kết quả vào `docs/research/`
