# Customer invoice view Implementation Plan

> Executed via `/opsx-apply`, which chooses the executor.
> Spec reference: this change's `specs/` and `design.md`.

**Goal:** Khách đã đăng nhập xem được trạng thái hóa đơn của từng đơn trong My Account > Orders > chi tiết đơn, và tải được PDF của hóa đơn đã phát hành hợp lệ.

**Architecture:** Mã tra cứu được đọc từ lookup và lưu trên bản ghi hóa đơn (schema v7). Module `src/Customer/` gồm bốn phần: enum ánh xạ trạng thái thuần, renderer chạy trên hook `woocommerce_order_details_after_order_table`, handler tải PDF chạy ở `template_redirect` trên URL `view-order`, và seam `PdfResponder`. Handler tái dùng `MatbaoStatusSync::sync_one()` để tra cứu lại, sau đó gọi `MatbaoClient::download_invoice_pdf()` để lấy PDF.

**Tech Stack:** PHP 8.2, WordPress, WooCommerce (HPOS), PHPUnit 9 + wp-phpunit, e2e Army (Bun).

## Global Constraints

- PHP floor 8.2; WPCS (`composer lint`) và PHPStan (`composer analyse`) phải sạch.
- Không thêm dependency.
- Chuỗi UI tiếng Việt qua text domain `einvoice-matbao`; code comment, docblock và commit message viết tiếng Anh.
- Các nhãn khách thấy, đúng nguyên văn:
  - `PROCESSING` = "Đang xuất hóa đơn"
  - `NEEDS_STORE` = "Đang xử lý, cửa hàng sẽ liên hệ nếu cần"
  - `NOT_REQUIRED` = "Đơn hàng không cần xuất hóa đơn"
  - `ISSUED` = "Đã xuất hóa đơn"
  - `ADJUSTED` = "Hóa đơn đã được điều chỉnh, vui lòng liên hệ cửa hàng"
  - Tiêu đề khối: "Hóa đơn điện tử"
  - Nút: "Tải hóa đơn (PDF)"
  - Lỗi chung: "Không tải được hóa đơn lúc này. Vui lòng thử lại sau hoặc liên hệ cửa hàng."
- Không log hoặc đưa vào monitoring: PDF, base64, mã tra cứu, body phản hồi Mắt Bão.
- Chạy test: `einv composer test:integration -- --filter <Class>` (alias trong `docs/phat-trien.md`).

## Review Focus

- **Hai lần bấm tải song song.** Lần thua cuộc đua nhận `UNCHANGED` (writer khác đã ghi trước) nhưng vẫn tải được từ bản ghi đọc lại; không có alert trùng. Nhánh này được ép xác định qua `$recheck` inject được, vì `record_matbao_status` luôn ghi lại timestamp nên không tạo zero-row update ổn định qua HTTP fake. Test `test_concurrent_writer_still_downloads` trong Task 5.
- **Đơn đã bị xóa (trash) hoặc đơn của người dùng đã bị xóa** khi mở link tải: phải ra lỗi chung, không fatal. Test `test_missing_order_gives_generic_error` trong Task 5.
- **Ký hiệu hoặc ngày lập rỗng trên hóa đơn đã phát hành**: khối vẫn hiện "Đã xuất hóa đơn", bỏ qua phần thiếu. Test `test_issued_without_issue_date_omits_date` trong Task 4.
- **Mắt Bão trả HTTP 401 ở `download-invoice`**: client đăng nhập lại một lần rồi tải được, như các endpoint khác. Test `test_download_relogin_on_401` trong Task 2.
- **Link tải mở ở trang chi tiết đơn của một đơn khác** (`order_id` trên URL khác đơn có nonce): bị nonce chặn. Test `test_nonce_of_another_order` trong Task 5.

---

## Contracts

| Name | new / modified | Shape | public |
|---|---|---|---|
| DB column `{prefix}einvoice_matbao_invoice.lookup_code` | new | `varchar(100) NULL`; `Schema::VERSION` = `'7'` | no |
| `MatbaoInvoice::$lookup_code` | modified | `public readonly ?string $lookup_code = null` (last ctor param); `from_api` reads `maTraCuu` via `optional_text` | no |
| `MatbaoSnapshot::$lookup_code` | modified | `public readonly ?string $lookup_code = null` (last ctor param); `from_invoice` copies; `deleted()` null | no |
| `InvoiceRecord::$lookup_code` | modified | `public readonly ?string $lookup_code = null` (last ctor param); `from_row` reads `lookup_code` | no |
| `MatbaoClient::download_invoice_pdf` | new | `public function download_invoice_pdf( string $invoice_id, string $lookup_code ): string` returns raw PDF bytes; `@throws MatbaoError` (`KIND_API` for missing field, bad base64, not `%PDF-`) | no |
| `CustomerInvoiceStatus` | new | `enum CustomerInvoiceStatus: string { PROCESSING='processing'; NEEDS_STORE='needs_store'; NOT_REQUIRED='not_required'; ISSUED='issued'; ADJUSTED='adjusted' }` + `static from_record( InvoiceRecord $r ): self`, `label(): string`, `allows_pdf(): bool` | no |
| `OrderInvoiceSection` | new | `final class` in `LTTech\EinvoiceMatbao\Customer`; `static register(): void`; `static render( WC_Order $order ): void` (hook callback) | no |
| `InvoicePdfDownload` | new | `final class`; `QUERY_ARG = 'einvoice-matbao-pdf'`; `NONCE_ACTION_PREFIX = 'einvoice_matbao_invoice_pdf_'`; `static register(): void`; `static url( WC_Order $order ): string`; `__construct( InvoiceRepository $repository = new InvoiceRepository(), ?MatbaoClient $client = null, PdfResponder $responder = new PdfResponder(), ?\Closure $recheck = null )`; `$recheck` is `Closure( InvoiceRecord ): SyncOutcome`, defaulting to `( new MatbaoStatusSync( $repository, $client ) )->sync_one(...)`; `handle( int $order_id ): void` | no |
| Download URL | new | `wc_get_endpoint_url( 'view-order', $id, wc_get_page_permalink( 'myaccount' ) )` + `?einvoice-matbao-pdf=1&_wpnonce=<wp_create_nonce( 'einvoice_matbao_invoice_pdf_' . $id )>` | yes (customer-visible URL) |
| `PdfResponder` | new | `class PdfResponder { public function send( string $filename, string $bytes ): void; public function redirect( string $url ): void; public static function headers( string $filename, int $length ): array<string,string>; protected function emit( array $headers, string $body ): void; protected function finish(): never }`. `send()` = `emit( headers(...) + wp_get_nocache_headers(), $bytes )` then `finish()`; production `emit` calls `header()`/`echo`, `finish` calls `exit`. Tests subclass and override only `emit`/`finish`, so the real header list is observed | no |
| Fake Mắt Bão `download-invoice` | new | `POST /api/invoice/download-invoice` body `{MaTraCuu, MaSoHDon}` → `{success:true,errorCode:200,data:{data_PDF_Base64,data_XML_Base64}}` when a stored invoice matches, else `{success:false,errorCode:301}`; control `issue` accepts optional `maLoaiHDon`, `shDon` | no (test only) |
| E2E seam `/order` param `customer_id` | modified | optional int; sets `$order->set_customer_id()` | no (test only) |
| E2E seam `/user` response | modified | also returns `id` | no (test only) |
| E2E seam `/invoice/<id>` response | modified (deviation, apply) | also returns `matbao_status` (int or null), needed by the Task 6 poll | no (test only) |
| `PdfResponder::clean_buffers` | new (deviation, apply) | `protected function clean_buffers( int $keep = 0 ): void`, called by `send()` before `emit()`; production closes all buffers (`wc_clean_buffers()`) — final review Minor 1 | no |
| E2E seam `/status-sync` | new | POST, no body; runs `MatbaoStatusSync::run()`; returns `{ok:true}` | no (test only) |

---

## Task 1: Lookup code: parse, snapshot, storage — implements 1

**Covers:** 1.1, 1.2, 1.3, 1.4
**Difficulty:** Normal
**Blocked by:** none

**Files:**
- Modify: `src/Matbao/MatbaoInvoice.php`, `src/Invoice/MatbaoSnapshot.php`, `src/Invoice/Schema.php`, `src/Invoice/InvoiceRecord.php`, `src/Invoice/InvoiceRepository.php`
- Test: `tests/integration/MatbaoLookupTest.php`, `tests/integration/SchemaUpgradeTest.php`, `tests/integration/InvoiceRepositoryTest.php`

**Interfaces:** Produces `MatbaoInvoice::$lookup_code`, `MatbaoSnapshot::$lookup_code`, `InvoiceRecord::$lookup_code`, column `lookup_code` (see Contracts).

- [ ] **Step 1: Write failing tests.**
  - `MatbaoLookupTest::test_lookup_code_read`: row with `'maTraCuu' => 'FM80520C49'` → `assertSame( 'FM80520C49', $invoice->lookup_code )`.
  - `test_lookup_code_missing`: rows without `maTraCuu` and with `''` → `assertNull`.
  - `SchemaUpgradeTest::test_upgrade_from_v6_adds_lookup_code`: v6 fixture with a `draft_created` row carrying `matbao_status` 4, `invoice_number` 125, `tax_authority_code` `00ABC`; set the version option to `'6'`, call `maybe_install()` → those values are unchanged, the column exists, `lookup_code` is NULL, and the version option `=== Schema::VERSION`.
  - Change the existing v5 assertion `assertSame( '6', … )` (`SchemaUpgradeTest.php:216`) to `Schema::VERSION`.
  - `InvoiceRepositoryTest::test_record_matbao_status_stores_lookup_code`: `new MatbaoSnapshot( 4, 'Đã cấp mã', 125, '00ABC', 2, null, 'FM80520C49' )` → `find()->lookup_code === 'FM80520C49'`.
  - `test_deleted_snapshot_clears_lookup_code`: write the snapshot above, then write `MatbaoSnapshot::deleted()` from the re-read record → `lookup_code` is null.
  - Extend `InvoiceRepositoryTest::test_new_invoice_id_resets_matbao_fields` to seed `lookup_code` and assert it is null afterwards.
- [ ] **Step 2: Run** `einv composer test:integration -- --filter 'MatbaoLookupTest|SchemaUpgradeTest|InvoiceRepositoryTest'`. Expected: the new tests FAIL with an unknown property or argument.
- [ ] **Step 3: Implement.**
  - Add `lookup_code` as the last ctor param in `MatbaoInvoice`, `MatbaoSnapshot` and `InvoiceRecord`.
  - In `Schema::install`, add the line `lookup_code varchar(100) NULL,` after `tax_note`, and set `VERSION = '7'`.
  - Add `'lookup_code'` to `InvoiceRepository::MATBAO_STATUS_COLUMNS`.
  - Add `'lookup_code' => array( $snapshot->lookup_code, '%s' )` to the column map of `record_matbao_status`.
- [ ] **Step 4: Run** the same filter plus `MatbaoStatusSyncTest`. Expected: PASS.
- [ ] **Step 5: Run** `einv composer lint && einv composer analyse`. Expected: clean.
- [ ] **Step 6: Commit** `feat: store Mat Bao lookup code with invoice status`.

---

## Task 2: Mat Bao PDF download call — implements 2

**Covers:** 2.1, 2.2, 2.3
**Difficulty:** Normal
**Blocked by:** none

**Files:**
- Modify: `src/Matbao/MatbaoClient.php`, `tests/e2e/fake-matbao/router.php`, `tests/e2e/army/support.ts`
- Create: `tests/integration/MatbaoDownloadInvoiceTest.php`

**Interfaces:** Produces `MatbaoClient::download_invoice_pdf( string $invoice_id, string $lookup_code ): string`.

- [ ] **Step 1: Write failing tests** in `MatbaoDownloadInvoiceTest`. Extend `SettingsRestTestCase`, set up like `MatbaoLookupTest`, route `POST /api/invoice/download-invoice`, and use the fixture `PDF = "%PDF-1.4\n%fake\n"`.
  - `test_pdf_returned`: answer `{success:true,errorCode:200,data:{data_PDF_Base64: base64(PDF)}}`. Expect the return value `=== PDF`, exactly one request on the route, and a request body JSON equal to `{"MaTraCuu":"FM80520C49","MaSoHDon":"abc"}`.
  - `test_field_name_casing`: answer with `Data.Data_PDF_Base64` → returns PDF.
  - `test_missing_pdf_field`: `data:{}` → `MatbaoError` with `kind === 'api'`.
  - `test_invalid_base64`: `data_PDF_Base64 = '%%%'` → `api` error.
  - `test_not_a_pdf`: base64 of `'hello'` → `api` error.
  - `test_download_rejected`: `{success:false,errorCode:301}` → `api` error with `api_code === 301`.
  - `test_download_relogin_on_401`: first answer HTTP 401, then OK (counter in `respond_with`) → returns PDF, and there are two login requests.
  - `test_malformed_shapes` (data provider): `data` null, `data` scalar `'x'`, `data_PDF_Base64` as array, as int → each throws `MatbaoError` `api`, and the message does not contain the raw body.
- [ ] **Step 2: Run** `--filter MatbaoDownloadInvoiceTest`. Expected: FAIL (method missing).
- [ ] **Step 3: Implement `download_invoice_pdf`.**
  - Call `$this->authorized( 'POST', '/api/invoice/download-invoice', array( 'MaTraCuu' => $lookup_code, 'MaSoHDon' => $invoice_id ) )`.
  - Guard in this order: `is_array( $data )` → `array_change_key_case( $data, CASE_LOWER )` → `is_string( $data['data_pdf_base64'] ?? null )` → `$bytes = base64_decode( $value, true )` and `false !== $bytes` → `str_starts_with( $bytes, '%PDF-' )`.
  - On any failure, throw `new MatbaoError( MatbaoError::KIND_API, '<plain English message>' )`, and never include the body in the message.
- [ ] **Step 4: Run** the filter. Expected: PASS.
- [ ] **Step 5: Extend the fake server.**
  - Add `'POST /api/invoice/download-invoice' => 'download-invoice'` to the endpoint match.
  - Case `download-invoice`: run `$respond_scripted( $step )`, then find a stored invoice whose `maSoHDon` and `maTraCuu` match the body. If one matches, return `einv_fake_ok( array( 'data_PDF_Base64' => base64_encode( "%PDF-1.4\n% e2e " . $reference . "\n%%EOF\n" ), 'data_XML_Base64' => base64_encode( '<HDon/>' ) ) )`. Otherwise return envelope `false`, `301`.
  - Control `issue`: also set `maLoaiHDon` (default 2) and `shDon` (default 1) from the body.
  - Add `"download-invoice"` to the `script` endpoint union type in `tests/e2e/army/support.ts`.
- [ ] **Step 6: Run** `php -l tests/e2e/fake-matbao/router.php`, then `einv composer lint && einv composer analyse`. Expected: clean.
- [ ] **Step 7: Commit** `feat: add Mat Bao invoice PDF download call`.

---

## Task 3: Customer status mapping — implements 3

**Covers:** 3.1, 3.2
**Difficulty:** Easy
**Blocked by:** Task 1

**Files:**
- Create: `src/Customer/CustomerInvoiceStatus.php`, `tests/integration/CustomerInvoiceStatusTest.php`

**Interfaces:** Consumes `InvoiceRecord` (with `lookup_code`). Produces `CustomerInvoiceStatus` (see Contracts).

- [ ] **Step 1: Write a failing data-provider test** `test_maps_record( string $status, ?int $matbao, ?int $type, ?string $invoice_id, CustomerInvoiceStatus $expected )`. Build the record with the `InvoiceRecord` ctor and named args. Rows:
  - `pending`, `sending` → PROCESSING.
  - `failed`, `needs_attention`, `not_configured`, `stopped`, `'weird'` → NEEDS_STORE.
  - `skipped` → NOT_REQUIRED.
  - `draft_created`, id `'abc'`, with Mắt Bão:
    - null, 1, 3, 5, 7, 8, 9, 12 → PROCESSING.
    - 0, 2, 6 → NEEDS_STORE.
    - 4 with type 2 → ISSUED; type null → ISSUED; type 3 → ADJUSTED; type 5 → ADJUSTED.
  - `draft_created`, 4, type 2, id null → PROCESSING.

  Also add `test_only_issued_allows_pdf` (iterate over `cases()`) and `test_labels` (the exact strings from Global Constraints).
- [ ] **Step 2: Run** `--filter CustomerInvoiceStatusTest`. Expected: FAIL (class missing).
- [ ] **Step 3: Implement the enum** following design D2, in that order. Use `MatbaoStatus::tryFrom()?->alert_code() !== null` for the abnormal check, and treat Mắt Bão status 4 with type 3 or 5 as adjusted.
- [ ] **Step 4: Run** the filter. Expected: PASS.
- [ ] **Step 5: Commit** `feat: map invoice records to customer-facing status`.

---

## Task 4: Customer invoice section on view-order — implements 4

**Covers:** 4.1, 4.2, 4.3
**Difficulty:** Normal
**Blocked by:** Task 3

**Files:**
- Create: `src/Customer/OrderInvoiceSection.php`, `src/Customer/InvoicePdfDownload.php` (constants and `url()` only), `tests/integration/CustomerOrderInvoiceSectionTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- Consumes: `CustomerInvoiceStatus`, `InvoiceRepository::find_by_order`, `InvoicePdfDownload::url( WC_Order )`.
- Task 4 may land before Task 5, so declare `url()` here as a static method on `InvoicePdfDownload` that only builds the URL. Task 5 then adds the handler to the same class.
- Produces: `OrderInvoiceSection::register()`, `render( WC_Order )`.

- [ ] **Step 1: Write failing tests.** Extend `InvoiceWorkflowTestCase`. In each test, create a customer user, `wp_set_current_user`, create an order with `set_customer_id`, and insert the invoice row via `InvoiceRepository`/`$wpdb`. Simulate the endpoint by setting `$GLOBALS['wp']->query_vars['view-order'] = $order_id` (save `query_vars` in `set_up`; restore them and the current user in `tear_down`) and capture output with `ob_start`/`render()`.
  - `test_walk_in_order_shows_processing`: `pending` → HTML contains "Hóa đơn điện tử" and "Đang xuất hóa đơn".
  - `test_no_invoice_record_renders_nothing` → `''`.
  - `test_order_received_renders_nothing`: query var `order-received` instead of `view-order` → `''`.
  - `test_not_owner_renders_nothing`: another user is current, and also the guest order case (`customer_id` 0) → `''`.
  - `test_issued_shows_details_and_link`: `draft_created`, Mắt Bão 4, type 2, number 125, form `1`, series `C26TAT`, issued_on `2026-11-19`, lookup_code `FM80520C49`, tax_authority_code `00ABC`. HTML contains `125`, `1C26TAT`, the formatted date, "Tải hóa đơn (PDF)" and `einvoice-matbao-pdf=1`. It does not contain `00ABC`, `FM80520C49`, the reference or `abc`.
  - `test_failed_hides_error_details`: `needs_attention` with reason "Thiếu mã số thuế người mua" and code `buyer_tax_code_missing` → none of those strings and no `MTChieu` value; contains "Đang xử lý, cửa hàng sẽ liên hệ nếu cần"; no download link.
  - `test_issued_without_issue_date_omits_date`: issued_on null → still "Đã xuất hóa đơn" and the link, with no empty date label.
  - `test_cancelled_shows_adjusted_without_link`.
- [ ] **Step 2: Run** `--filter CustomerOrderInvoiceSectionTest`. Expected: FAIL.
- [ ] **Step 3: Implement `OrderInvoiceSection`.**
  - Guard order: `is_wc_endpoint_url( 'view-order' )`, then `is_user_logged_in()`, then `$order->get_customer_id() !== 0 && === get_current_user_id()`, then the record exists.
  - Render a `<section class="einvoice-matbao-customer-invoice">` with an `<h2>`, the status `<p>`, and, when status is ISSUED, a `<p>` with number, series (`form . series`) and date formatted by `wc_format_datetime( new WC_DateTime( issued_on ) )`, plus an `<a class="woocommerce-button button" href="esc_url( InvoicePdfDownload::url( $order ) )">`.
  - Escape every value.
  - Add the `InvoicePdfDownload` class with the constants and `url()` only.
- [ ] **Step 4: Register** `Customer\OrderInvoiceSection::register()` in `Plugin::boot()` next to the other non-admin registrations.
- [ ] **Step 5: Run** the filter, then `einv composer lint && einv composer analyse`. Expected: PASS, clean.
- [ ] **Step 6: Commit** `feat: show invoice status on customer order page`.

---

## Task 5: PDF download handler — implements 5

**Covers:** 5.1, 5.2, 5.3, 5.4
**Difficulty:** Hard
**Blocked by:** Task 2, Task 3 (Task 4 for the shared `InvoicePdfDownload` file; run after Task 4 to avoid overlap)

**Files:**
- Create: `src/Customer/PdfResponder.php`, `tests/integration/CustomerInvoicePdfDownloadTest.php`
- Modify: `src/Customer/InvoicePdfDownload.php`, `src/Plugin.php`

**Interfaces:**
- Consumes: `MatbaoStatusSync::sync_one( InvoiceRecord ): SyncOutcome`, `MatbaoClient::download_invoice_pdf`, `CustomerInvoiceStatus`.
- Produces: `InvoicePdfDownload::register()`, `handle( int $order_id )`, `PdfResponder`.

- [ ] **Step 1: Write failing tests** in `CustomerInvoicePdfDownloadTest extends InvoiceWorkflowTestCase`, using `MonitoringFixture`.
  - `set_up`: `configure_matbao()`; start a WooCommerce session (`WC()->session = new WC_Session_Handler(); WC()->session->init();`) and `wc_clear_notices()`; save `$_GET`.
  - `tear_down`: `wc_clear_notices()`, restore `$_GET`, `wp_set_current_user( 0 )`, `reset_monitoring()`.
  - `RecordingResponder extends PdfResponder` overrides only `emit()` (stores headers and body) and `finish()` (throws a test-local `ResponseFinished`). Production `send()`, `headers()` and `redirect()` stay under test; `redirect()` is observed through `finish()` plus `wp_redirect` filter capturing the URL.
  - `FakeHttp` routes for `invoice-detail-batch` (reuse `fake_lookup_rows`) and `download-invoice`, and a customer-owned order with an issued invoice (Mắt Bão 4, type 2, id `abc`, number 125, `1C26TAT`).
  - Each test sets the current user and `$_GET['_wpnonce']`, then calls `( new InvoicePdfDownload( responder: $r ) )->handle( $order_id )` inside `try/catch ( ResponseFinished )`.
  - Error assertions read `wc_get_notices( 'error' )` and expect exactly the generic message.
  - Owner and auth:
    - `test_owner_downloads`: lookup returns a matching row with `maTraCuu` `FM80520C49`. Expect `bytes` = the PDF, filename matching `/^hoa-don-1C26TAT-125\.pdf$/`, and the download request body `MaTraCuu` = `FM80520C49`.
    - `test_another_customer`, `test_logged_out` (redirect to `wc_get_page_permalink( 'myaccount' )`), `test_missing_nonce`, `test_nonce_of_another_order`: zero requests to any Mắt Bão route, no bytes, and the error notice from Global Constraints (`wc_get_notices( 'error' )`).
    - `test_missing_order_gives_generic_error`: order ID that does not exist → error, no fatal.
  - Pre-check:
    - `test_draft_link_forged` and `test_non_issued_never_reach_matbao` (data provider: `pending`, `sending`, `needs_attention`, `stopped`, `skipped`, issued type 3) → zero Mắt Bão requests.
  - Recheck:
    - `test_cancelled_after_issue`: lookup row type 3 → no download request; record `matbao_invoice_type` = 3.
    - `test_lookup_fails_at_download`: lookup HTTP 503 → no download request.
    - `test_lookup_does_not_confirm` (provider: reference absent from both lists, different `maSoHDon`, row without a valid status) → no download request.
    - `test_deleted_at_download`: errorCode 301 → no download; Mắt Bão status 0; `lookup_code` null.
    - `test_still_no_lookup_code_after_check`: matching row without `maTraCuu` → no download.
    - `test_invoice_id_changed_during_check`: from inside the lookup fake handler, update the row's `matbao_invoice_id` to `'other'` → no download.
    - `test_concurrent_writer_still_downloads`: the handler takes an injectable recheck (Contracts: `$recheck`). The test passes a closure that, like a competing writer that won the race, stores the issued snapshot with `maTraCuu` `FM80520C49` through `InvoiceRepository::record_matbao_status`, then returns `SyncOutcome::UNCHANGED`. Assert the PDF is delivered, the `download-invoice` body carries `FM80520C49` (read from the re-read record), and the `NEEDS_ATTENTION_ACTION` count did not increase. A second case returns `SyncOutcome::UNCONFIRMED` after the same write → no download (fail closed even when the stored state looks valid). `test_owner_downloads` stays the real `UPDATED` path through `MatbaoStatusSync`.
  - Response:
    - `test_served_headers`: headers recorded by `emit` include `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="hoa-don-1C26TAT-125.pdf"`, `X-Content-Type-Options: nosniff`, `Content-Length: <strlen>`, and `Cache-Control` containing `no-store` or `no-cache`.
    - `test_unsafe_file_name`: series `C26"/Ấ` → filename contains only `[A-Za-z0-9-]` plus `.pdf`.
    - Monitoring procedure shared by the next two tests:
      1. `$sink = SentrySink::install( $this->http )`.
      2. `enable_monitoring()`.
      3. `Monitor::start_transaction( 'customer download', 'test' )`.
      4. Run the handler.
      5. `Monitor::finish_transaction()`, then `Monitor::flush()`.
    - `test_not_a_pdf_logs_warning`: download returns base64 of `hello` → error notice and no bytes. Assert the sink holds at least one warning log, and that no `raw_bodies()` entry contains the fake body.
    - `test_nothing_sensitive_logged`: one success and one rejected download. First assert the sink holds a transaction with an `http.client` span for `POST /api/invoice/download-invoice`, and a warning log. Then assert no `raw_bodies()` entry contains `%PDF-`, the base64 string, `FM80520C49` or the fake response body.
    - `test_nothing_stored`: snapshot the file lists of `wp_upload_dir()['basedir']` and `get_temp_dir()` before and after → no new file. No `option_value` and no column of the raw invoice row contains `%PDF-` or the base64 string. Token and backoff options are allowed to change.
  - Backoff (requirement *Customer download check*):
    - `test_download_check_during_pause`: `StatusSyncBackoff` paused with a queued retry, then lookup OK → streak cleared, pause cleared, no queued retry.
    - `test_download_check_fails_keeps_backoff`: lookup 503 → streak and pause unchanged.
    - `test_issued_invoice_rechecked`: lookup row type 5 → record type 5, no alert fired, and `due_for_status_sync` does not return it.
  - Redirect:
    - `test_error_redirects_to_clean_url`: redirect URL equals `wc_get_endpoint_url( 'view-order', $id, wc_get_page_permalink( 'myaccount' ) )`, with no `einvoice-matbao-pdf` or `_wpnonce`.
- [ ] **Step 2: Run** `--filter CustomerInvoicePdfDownloadTest`. Expected: FAIL.
- [ ] **Step 3: Implement `PdfResponder`.**
  - `headers( $filename, $length )` returns `Content-Type`, `Content-Disposition`, `X-Content-Type-Options` and `Content-Length`.
  - `send()` merges `wp_get_nocache_headers()`, calls `emit()` (production: `header()` per entry, then `echo`), then `finish()` (production: `exit`).
  - `redirect()`: `wp_safe_redirect( $url )`, then `finish()`.
  - Add `// phpcs:ignore` notes only where WPCS requires them for raw output.
- [ ] **Step 4: Implement `InvoicePdfDownload`.**
  - `register()` hooks `template_redirect`. The callback continues only when `is_wc_endpoint_url( 'view-order' )` and `isset( $_GET[ QUERY_ARG ] )`; it reads the order ID from `get_query_var( 'view-order' )` and calls `handle()`.
  - `handle()` follows design D4, D5 and D8. Split it into private methods `authorize( int ): ?WC_Order`, `issued_record( WC_Order ): ?InvoiceRecord`, `recheck( InvoiceRecord ): ?InvoiceRecord` and `fail( int $order_id, ?MatbaoError $error )` to keep it flat.
  - Build the client from `CredentialStore` when none is injected. With no credentials, call `fail`.
  - Filename: `'hoa-don-' . preg_replace( '/[^A-Za-z0-9-]/', '', form . series ) . '-' . number . '.pdf'`.
  - Log on `MatbaoError`: `Monitor::log( 'warn', 'customer invoice download failed', array( 'order_id' => …, 'error_kind' => …, 'api_code' => … ) )`.
- [ ] **Step 5: Register** `Customer\InvoicePdfDownload::register()` in `Plugin::boot()`.
- [ ] **Step 6: Run** the filter, then the full `einv composer check`. Expected: PASS, clean.
- [ ] **Step 7: Commit** `feat: let order owners download issued invoice PDF`.

---

## Task 6: E2E, docs and demo verification — implements 6

**Covers:** 6.1, 6.2, 6.3, 6.4
**Difficulty:** Normal
**Blocked by:** Task 4, Task 5

**Files:**
- Modify: `tests/e2e/wordpress/einv-e2e-http.php`, `tests/e2e/army/support.ts`, `docs/discovery.md`, `docs/huong-dan-su-dung.md`
- Create: `tests/e2e/army/customer-invoice.army.ts`, `docs/research/demo-download-invoice.md`, `docs/research/demo-download-probe.py`

**Interfaces:** Consumes the fake `download-invoice` endpoint and the `issue` params from Task 2, and the seam changes listed in Contracts.

- [ ] **Step 1: Seams.**
  - `/order` accepts `customer_id`.
  - `/user` returns `id`.
  - New `/status-sync` runs `MatbaoStatusSync::run()`.
  - Add `PAGES.viewOrder = (id) => \`/my-account/view-order/${id}/\``, and a helper `signInCustomer(ctx)` that returns `{ id }` and signs in through the store login form (`/my-account/`) rather than wp-admin.
- [ ] **Step 2: E2E tests** in `customer-invoice.army.ts`:
  - "customer sees the draft as being issued, then downloads the PDF once issued":
    1. Completed order owned by the customer → `waitForStatus` `draft_created`.
    2. View-order shows "Đang xuất hóa đơn" and no download button.
    3. `fake('issue', { mtChieu: 'E2EWC<id>' })`, then `seam('/status-sync', {})`, then poll `invoiceOf(...)` until `invoice.matbao_status === 4`.
    4. Reload → "Đã xuất hóa đơn" and the button is visible.
    5. Read the button `href` and fetch it from the page with `browser.evaluate` + `fetch( href, { credentials: 'same-origin' } )` (pattern of `storeApiCheckout` in `checkout-validation.army.ts`). Return status, `content-type` and the first 5 bytes.
    6. Expect 200, `application/pdf`, `%PDF-`, and `fake('requests?endpoint=download-invoice')` of length 1.
    - Add a test comment for the limitation: the fetch carries the browser cookies and nonce, but does not exercise the browser download UI.
    - Use only `app.open`, `screen` assertions and `browser.evaluate`, with no `agent.act`, so replay needs no model cache.
  - "another customer cannot see the invoice": order of customer A, sign in as B, open A's view-order → no "Hóa đơn điện tử".
- [ ] **Step 3: Run** the suite via `cd tests/e2e && bun run e2e:record`. The runner takes no file argument; to narrow the run, pass CLI args through `E2E_ARGS` as `tests/e2e/README.md` describes. Commit the replay cache.
- [ ] **Step 4: Docs.**
  - `docs/discovery.md` line 19: replace the "Bản đầu không hiển thị hoặc gửi nháp cho khách" rule with: khách đã đăng nhập xem trạng thái trong My Account; chỉ hóa đơn đã cấp mã, không xóa bỏ hay bị thay thế, mới tải được PDF; nháp không bao giờ hiện.
  - `docs/huong-dan-su-dung.md`: add section `### Khách xem hóa đơn trong My Account` under "Quy trình hằng ngày", with the status table, the download condition and the theme-hook caveat.
- [ ] **Step 5: Demo verification** (task 6.4).
  - Use a read-only probe modelled on `docs/research/demo-probe.py`, run via `uv run`, with credentials from env and never written to the repo.
  - Steps: look up an issued invoice on demo, then call `download-invoice`. Record the HTTP status, the envelope keys, whether the decoded bytes start with `%PDF-`, and their length. Redact base64 and `maTraCuu`.
  - Write the findings to `docs/research/demo-download-invoice.md`.
  - If the shape differs from the Postman sample, stop and report back before changing code.
- [ ] **Step 6: Commit** `test: cover customer invoice view end to end` and `docs: document customer invoice view`.

---

## Scenario → test

**customer-invoice-view**
- Walk-in buyer order → `CustomerOrderInvoiceSectionTest::test_walk_in_order_shows_processing`
- Order without invoice record → `CustomerOrderInvoiceSectionTest::test_no_invoice_record_renders_nothing`
- Order received page → `CustomerOrderInvoiceSectionTest::test_order_received_renders_nothing`
- Viewer is not the owner → `CustomerOrderInvoiceSectionTest::test_not_owner_renders_nothing`; e2e "another customer cannot see the invoice"
- Skipped zero-total order → `CustomerInvoiceStatusTest::test_maps_record` (skipped) + `test_only_issued_allows_pdf`
- Failed invoice → `CustomerInvoiceStatusTest::test_maps_record` (needs_attention); `CustomerOrderInvoiceSectionTest::test_failed_hides_error_details`
- Every plugin status → `CustomerInvoiceStatusTest::test_maps_record` (plugin rows)
- Draft not yet issued → `CustomerInvoiceStatusTest::test_maps_record` (1); e2e first half
- Draft awaiting tax authority → `CustomerInvoiceStatusTest::test_maps_record` (5)
- Sign error on Mat Bao → `CustomerInvoiceStatusTest::test_maps_record` (2)
- Issued invoice → `CustomerInvoiceStatusTest::test_maps_record` (4/2); `CustomerOrderInvoiceSectionTest::test_issued_shows_details_and_link`
- Cancelled invoice → `CustomerInvoiceStatusTest::test_maps_record` (4/3); `CustomerOrderInvoiceSectionTest::test_cancelled_shows_adjusted_without_link`
- Replaced invoice → `CustomerInvoiceStatusTest::test_maps_record` (4/5)
- Every Mat Bao status of a created draft → `CustomerInvoiceStatusTest::test_maps_record` (Mat Bao rows)
- Issued with unknown type → `CustomerInvoiceStatusTest::test_maps_record` (4/null)
- Issued without Mat Bao invoice ID → `CustomerInvoiceStatusTest::test_maps_record` (id null)
- Error details hidden → `CustomerOrderInvoiceSectionTest::test_failed_hides_error_details`
- Issued details shown → `CustomerOrderInvoiceSectionTest::test_issued_shows_details_and_link`
- Owner downloads → `CustomerInvoicePdfDownloadTest::test_owner_downloads`; e2e second half (authenticated fetch)
- Another customer → `CustomerInvoicePdfDownloadTest::test_another_customer`
- Logged out → `CustomerInvoicePdfDownloadTest::test_logged_out`
- Missing nonce → `CustomerInvoicePdfDownloadTest::test_missing_nonce`
- Nonce of another order → `CustomerInvoicePdfDownloadTest::test_nonce_of_another_order`
- Error returns to a clean order page → `CustomerInvoicePdfDownloadTest::test_error_redirects_to_clean_url`
- Cancelled after issue → `CustomerInvoicePdfDownloadTest::test_cancelled_after_issue`
- Lookup fails at download → `CustomerInvoicePdfDownloadTest::test_lookup_fails_at_download`
- Lookup does not confirm the invoice → `CustomerInvoicePdfDownloadTest::test_lookup_does_not_confirm`
- Deleted on Mat Bao at download → `CustomerInvoicePdfDownloadTest::test_deleted_at_download`
- Issued before this feature → `CustomerInvoicePdfDownloadTest::test_owner_downloads` (record starts with null `lookup_code`)
- Draft link forged → `CustomerInvoicePdfDownloadTest::test_draft_link_forged`
- Non-issued invoices never reach Mat Bao → `CustomerInvoicePdfDownloadTest::test_non_issued_never_reach_matbao`
- Still no lookup code after the check → `CustomerInvoicePdfDownloadTest::test_still_no_lookup_code_after_check`
- Invoice ID changed during the check → `CustomerInvoicePdfDownloadTest::test_invoice_id_changed_during_check`
- Served headers → `CustomerInvoicePdfDownloadTest::test_served_headers`
- Unsafe characters in the file name → `CustomerInvoicePdfDownloadTest::test_unsafe_file_name`
- Not a PDF → `CustomerInvoicePdfDownloadTest::test_not_a_pdf_logs_warning`
- Nothing stored → `CustomerInvoicePdfDownloadTest::test_nothing_stored`
- Nothing sensitive logged → `CustomerInvoicePdfDownloadTest::test_nothing_sensitive_logged`

**matbao-api-client**
- PDF returned → `MatbaoDownloadInvoiceTest::test_pdf_returned`
- Field name casing → `MatbaoDownloadInvoiceTest::test_field_name_casing`
- Invalid base64 → `MatbaoDownloadInvoiceTest::test_invalid_base64`
- Missing PDF field → `MatbaoDownloadInvoiceTest::test_missing_pdf_field`
- Not a PDF → `MatbaoDownloadInvoiceTest::test_not_a_pdf`
- Download rejected → `MatbaoDownloadInvoiceTest::test_download_rejected`
- Draft by name → `MatbaoLookupTest::test_draft_by_name_without_code` (unchanged)
- Template out of numbers → `BlockedDraftInvoiceTest::test_template_without_remaining_numbers_blocks_the_draft` (unchanged)
- Issued invoice fields → `MatbaoLookupTest::test_issued_invoice_fields` (unchanged)
- Draft without number → `MatbaoLookupTest::test_draft_without_number` (unchanged)
- Lookup code read → `MatbaoLookupTest::test_lookup_code_read`
- Lookup code missing → `MatbaoLookupTest::test_lookup_code_missing`

**invoice-storage-schema**
- Upgrade from the previous schema → `SchemaUpgradeTest::test_upgrade_from_v6_adds_lookup_code`
- Lookup code stored → `InvoiceRepositoryTest::test_record_matbao_status_stores_lookup_code`
- Deleted clears the lookup code → `InvoiceRepositoryTest::test_deleted_snapshot_clears_lookup_code`
- Recorded after recreation → `InvoiceRepositoryTest::test_new_invoice_id_resets_matbao_fields` (extended with `lookup_code`)

**matbao-status-sync**
- Issued invoice rechecked → `CustomerInvoicePdfDownloadTest::test_issued_invoice_rechecked`
- Download check during the pause → `CustomerInvoicePdfDownloadTest::test_download_check_during_pause`
- Download check fails → `CustomerInvoicePdfDownloadTest::test_download_check_fails_keeps_backoff`
