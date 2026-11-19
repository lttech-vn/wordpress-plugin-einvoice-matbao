# Mat Bao Status Sync and Order/Invoice Links Implementation Plan

> Executed via `/opsx-apply`, which chooses the executor.
> Spec reference: this change's `specs/` and `design.md`.

**Goal:** Hóa đơn đã tạo nháp được theo dõi trạng thái Mắt Bão (đồng bộ mỗi giờ và kiểm tra thủ công có ghi), hiển thị số HĐ/MCCQT/trạng thái, cảnh báo một lần khi bất thường, và có liên kết hai chiều đơn ↔ hóa đơn.

**Architecture:**
- Lớp trạng thái Mắt Bão là các cột riêng trên bảng hóa đơn (design D1). Mọi quy tắc phân loại nằm trong enum `MatbaoStatus` (D2).
- `MatbaoStatusSync` (D3) tra cứu theo lô qua `MatbaoClient::find_invoices_by_references()` (D4). Nó ghi bằng `InvoiceRepository::record_matbao_status()` có điều kiện, và phát hook needs-attention hiện có cho trạng thái bất thường (D5), nên alert/Lark/Rustrak đi đường cũ.
- Admin: `MatbaoStatusView` dựng badge và các dòng thông tin dùng chung (D8). Chi tiết hóa đơn có query arg `invoice` (D7).

**Tech Stack:** PHP 8.2+, WordPress REST + fragment htmx, WooCommerce (HPOS), Action Scheduler, PHPUnit (wp-phpunit), e2e Army (`*.army.ts`).

## Global Constraints

- **Ngôn ngữ:** comment, docblock, identifier, commit bằng tiếng Anh; chuỗi UI tiếng Việt qua text domain `einvoice-matbao`; `docs/` và README tiếng Việt.
- **`InvoiceErrorCode`:** chỉ thêm case, không đổi hay xóa giá trị cũ.
- **Không thêm dependency.** Schema đổi đúng một lần: `Schema::VERSION` `'5'` → `'6'` qua dbDelta, không xóa cột.
- **Đồng bộ không bao giờ:** đổi `status` plugin, gọi `create-invoice`, hay ghi "Đã xóa" ngoài errorCode 301/`notFound`.
- **Hằng số:** `SYNC_INTERVAL_SECONDS = HOUR_IN_SECONDS`, `SYNC_WINDOW_DAYS = 30`, `SYNC_LIMIT = 200`, `LOOKUP_CHUNK = 20`.
- **Hint ba mã mới:** đúng nguyên văn `specs/failure-alerts`.
- **Coding standards** (`~/.agents/skills/coding-standards/SKILL.md`):
  - phân loại trạng thái chỉ trong `MatbaoStatus`;
  - SQL chỉ trong `InvoiceRepository`/`Schema`;
  - markup Mắt Bão chỉ trong `MatbaoStatusView`;
  - không thêm logic đồng bộ vào `DraftInvoiceWorkflow` (đã 857 dòng).
- **Lệnh kiểm tra** (dev container, theo `docs/phat-trien.md`):
  - `alias einv='docker compose exec -T -w /var/www/html/wp-content/plugins/einvoice-matbao wordpress'` (Docker context `kiri`);
  - script `einv composer test:integration`, `einv composer lint`, `einv composer analyse`.

## Review Focus

- **Mắt Bão trả 200 nhưng `danhSach` chỉ có một phần dòng.** Hóa đơn vắng mặt ở cả `invoices` lẫn `notFound` không bị ghi "Đã xóa". Test: Task 2 `test_reference_missing_from_answer_keeps_status`.
- **Cron và nút kiểm tra chạy cùng lúc trên hóa đơn chuyển sang Lỗi ký.** Chỉ một alert. Test: Task 1 `test_two_writers_only_one_wins`, Task 2 `test_concurrent_writers_alert_once`.
- **Nháp bị xóa, admin tạo lại, rồi một lượt đồng bộ cũ (đọc mã cũ) mới ghi kết quả.** Không ghi gì. Test: Task 1 `test_write_ignored_when_invoice_id_changed`.
- **Hóa đơn đang có `order_amounts_changed` rồi được cấp mã.** Cảnh báo đơn hàng còn nguyên, thông tin Mắt Bão vẫn được ghi. Test: Task 2 `test_order_warning_kept_on_recovery`.
- **`ghiChuCQT` chứa số CCCD hoặc URL.** Chi tiết che còn 4 số cuối hoặc `[đã ẩn]`; Lark/notice/event không chứa ghi chú. Test: Task 4 `test_tax_note_is_redacted`, Task 2 `test_alert_excludes_tax_note`.

---

## Contracts

| Name | new / modified | Shape | public |
|---|---|---|---|
| Bảng `{prefix}einvoice_matbao_invoice` | modified | thêm `matbao_status tinyint(3) unsigned NULL`, `matbao_status_name varchar(100) NULL`, `invoice_number int(10) unsigned NULL`, `tax_authority_code varchar(50) NULL`, `matbao_invoice_type tinyint(3) unsigned NULL`, `tax_note text NULL`, `matbao_synced_at datetime NULL`, `matbao_checked_at datetime NULL`, `KEY matbao_sync (status, matbao_checked_at)`; `Schema::VERSION = '6'` | no |
| `MatbaoInvoice` | modified | thêm `public readonly ?int $invoice_number` (`shDon`, 0 → null), `?string $tax_authority_code` (`mccqt`, rỗng → null), `?int $invoice_type` (`maLoaiHDon`), `?string $tax_note` (`ghiChuCQT`, rỗng → null); thêm cuối constructor, mặc định null | no |
| `Matbao\MatbaoLookup` | new | `final class` readonly: `list<MatbaoInvoice> $invoices`, `list<string> $not_found` (`MTChieu` trong `data.notFound`; 301 → `not_found` = mọi tham chiếu đã gửi) | no |
| `MatbaoClient::find_invoices_by_references( list<string> $references, string $form, string $series ): MatbaoLookup` | new | một request `loai=1`, `danhSach=$references`; 200 thiếu `data.invoices` → `MatbaoError` KIND_API | no |
| `MatbaoClient::find_invoices_by_reference(...)` | modified | giữ chữ ký và hành vi (`list<MatbaoInvoice>`), gọi hàm mới với một phần tử | no |
| `Invoice\MatbaoStatus` | new | `enum MatbaoStatus: int { DELETED=0; DRAFT=1; SIGN_FAILED=2; NOT_SENT_TO_AUTHORITY=3; ISSUED=4; AUTHORITY_NO_RESPONSE=5; NOT_ELIGIBLE=6; FORM04_NO_DECLARATION=7; FORM04_NOT_RECEIVED=8; FORM04_APPROVED=9 }`; `label(): string` (nhãn tiếng Việt theo spec); `is_final(): bool` (ISSUED, DELETED); `alert_code(): ?InvoiceErrorCode` (SIGN_FAILED→MATBAO_SIGN_FAILED, NOT_ELIGIBLE→MATBAO_NOT_ELIGIBLE, DELETED→MATBAO_DRAFT_DELETED); `group(): string` (`issued`/`error`/`progress`); `public const FINAL_VALUES = array( 0, 4 )`; `static invoice_type_label( ?int $code ): string` (1 Nháp … 10 Hóa đơn bị điều chỉnh, null/khác → '') | no |
| `Invoice\MatbaoSnapshot` | new | `final class` readonly: `?int $status`, `string $status_name`, `?int $invoice_number`, `?string $tax_authority_code`, `?int $invoice_type`, `?string $tax_note`; `static from_invoice( MatbaoInvoice ): self`; `static deleted(): self` (status 0, name '') | no |
| `InvoiceErrorCode::MATBAO_SIGN_FAILED`, `MATBAO_NOT_ELIGIBLE`, `MATBAO_DRAFT_DELETED` | new | `'matbao_sign_failed'`, `'matbao_not_eligible'`, `'matbao_draft_deleted'`; kind `MATBAO`; `needs_attention()` true; `is_post_draft_warning()` false; thêm `is_matbao_status_warning(): bool`; nhãn `Lỗi ký trên Mắt Bão`, `Không đủ điều kiện cấp mã/tiếp nhận`, `Nháp đã bị xóa trên Mắt Bão`; hint nguyên văn spec | yes (cột `error_code`, tag Sentry, Lark, hook) |
| `InvoiceRecord` | modified | thêm `?int $matbao_status`, `string $matbao_status_name`, `?int $invoice_number`, `?string $tax_authority_code`, `?int $matbao_invoice_type`, `string $tax_note`, `?string $matbao_synced_at`, `?string $matbao_checked_at` (cuối constructor, có mặc định); `matbao_status(): ?MatbaoStatus`; `needs_admin()` thêm nhánh `draft_created` + `error()?->is_matbao_status_warning()` (để notice và `AlertStore::cleanup` giữ alert) | no |
| `InvoiceRepository::due_for_status_sync( int $limit, string $since_date ): list<InvoiceRecord>` | new | điều kiện theo spec *Bounded status sync query*; `$since_date` = `Y-m-d`; `ORDER BY matbao_checked_at IS NOT NULL, matbao_checked_at, id` | no |
| `InvoiceRepository::record_matbao_status( InvoiceRecord $read, MatbaoSnapshot $snapshot, string $reason = '' ): RecordResult` | new | Deviation (Task 1): tham số `$new` đổi tên thành `$snapshot` vì phpcs cảnh báo reserved word; `$new` bên dưới đọc là `$snapshot`. Một `UPDATE`. Guard: `WHERE id=%d AND status='draft_created' AND matbao_invoice_id=%s AND ` + (`matbao_status IS NULL` khi `$read->matbao_status === null`, ngược lại `matbao_status = %d`). Luôn set cột snapshot, `matbao_synced_at`, `matbao_checked_at`. Ba nhánh chọn trong PHP: (a) `$new` có `alert_code()` và `$new->status !== $read->matbao_status` → set `error_code`, `error_kind`, `status_reason=$reason`, `alert_cycle=alert_cycle+1`, `updated_at`; (b) `$new` có `alert_code()` và trạng thái không đổi → không chạm cột lỗi; (c) `$new` không có `alert_code()` → `status_reason`, `error_kind` rồi `error_code` (đặt cuối, vì MySQL gán trái sang phải) đều qua `CASE WHEN error_code IN ('matbao_sign_failed','matbao_not_eligible','matbao_draft_deleted') THEN '' ELSE <col> END`. Kết quả: `false === $result` → `RecordResult::FAILED`; `0` → `STALE` (guard không khớp hoặc không có gì đổi trong cùng giây, cả hai đều an toàn để bỏ qua); `1` → `WRITTEN`. Caller chỉ phát alert khi `WRITTEN` ở nhánh (a) | no |
| `Invoice\RecordResult` | new | `enum RecordResult { WRITTEN; STALE; FAILED }` | no |
| `InvoiceRepository::touch_matbao_checked( list<int> $invoice_ids ): void` | new | `matbao_checked_at = now` | no |
| `InvoiceRepository::update_status()` | modified | khi `$fields` có `matbao_invoice_id`, thêm 8 cột Mắt Bão = NULL vào cùng `UPDATE` | no |
| `Invoice\MatbaoStatusSync` | new | `final class`; `const ACTION = 'einvoice_matbao_status_sync'`; `static register(): void`; `static schedule(): void`; `static run(): void` (handler); `__construct( InvoiceRepository $repository, ?MatbaoClient $client )`; `sync_batch(): void`; `sync_one( InvoiceRecord $invoice ): SyncOutcome` | hook name yes |
| `Invoice\SyncOutcome` | new | `enum SyncOutcome: string { UPDATED='updated'; UNCHANGED='unchanged'; UNCONFIRMED='unconfirmed'; FAILED='failed' }` | no |
| Action Scheduler `einvoice_matbao_status_sync` | new | recurring, interval `HOUR_IN_SECONDS`, group `einvoice-matbao`, không tham số | yes |
| `Admin\MatbaoStatusView` | new | `final class`, static: `badge( InvoiceRecord ): string` (`<span data-einv-matbao-badge="issued\|error\|progress">nhãn</span>`, hoặc `—` khi null); `rows( InvoiceRecord ): array<string,string>` (Trạng thái Mắt Bão, Số hóa đơn, MCCQT, Loại hóa đơn, Ghi chú CQT qua `Redactor::redact`, Đồng bộ lúc; bỏ dòng rỗng); `order_link( int $order_id, string $prefix = '' ): string` (link `get_edit_order_url()` hoặc `#N` text khi đơn không tồn tại); `meta_box_rows( InvoiceRecord ): string`. Deviation (Task 4): thêm `$prefix` cho nhãn "Xem đơn #N" và `meta_box_rows()` để markup Mắt Bão của meta box nằm trong view; `InvoicePage::BADGE`/`BADGE_COLORS` chuyển public để view dùng chung màu | no |
| `InvoicePage::detail_url( int $invoice_id ): string` | new | `admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=invoices&invoice=' . $id )` | no |
| Query arg `invoice` trên trang plugin | new | chuỗi khớp `/^[1-9][0-9]{0,18}$/` và `<= PHP_INT_MAX` (so sánh chuỗi độ dài/giá trị trước khi cast) → `#einv-invoices` `hx-get` `/invoices/{id}`; khác → danh sách | yes |

---

## Task 1: Trạng thái Mắt Bão — client, enum và lưu trữ — implements 1

**Covers:** 1.1, 1.2, 1.3, 1.4, 1.5, 1.6
**Difficulty:** Hard
**Blocked by:** none

**Files:**
- Create: `src/Matbao/MatbaoLookup.php`, `src/Invoice/MatbaoStatus.php`, `src/Invoice/MatbaoSnapshot.php`, `src/Invoice/RecordResult.php`
- Modify: `src/Matbao/MatbaoInvoice.php`, `src/Matbao/MatbaoClient.php`, `src/Invoice/InvoiceErrorCode.php`, `src/Invoice/Schema.php`, `src/Invoice/InvoiceRecord.php`, `src/Invoice/InvoiceRepository.php`
- Test: `tests/integration/MatbaoLookupTest.php` (new), `tests/integration/MatbaoStatusTest.php` (new), `tests/integration/InvoiceErrorCodeTest.php`, `tests/integration/SchemaUpgradeTest.php`, `tests/integration/InvoiceRepositoryTest.php`, `tests/integration/Support/HintText.php` (nếu hint được gom ở đây)

**Interfaces:**
- Produces: `MatbaoLookup`, `MatbaoClient::find_invoices_by_references`, `MatbaoStatus`, `MatbaoSnapshot`, `RecordResult`, ba case `InvoiceErrorCode` mới và `is_matbao_status_warning()`, `InvoiceRecord` mở rộng, `InvoiceRepository::due_for_status_sync`/`record_matbao_status`/`touch_matbao_checked` (xem Contracts).

- [ ] **Step 1: Test parse và lookup (fail).** `MatbaoLookupTest` dùng `FakeHttp` route `POST /api/invoice/invoice-detail-batch`:
  - `test_issued_invoice_fields`: `shDon` 125, `mccqt` "00ABC", `maLoaiHDon` 2 → `invoice_number===125`, `tax_authority_code==='00ABC'`, `MatbaoStatus::invoice_type_label(2)==='Hóa đơn mới'`.
  - `test_draft_without_number`: `shDon` 0, `mccqt` '' → cả hai null.
  - `test_several_references_one_request`: ba ref mẫu `1/C26TAT` → `count(requests_to)===1`, body `danhSach` bằng ba ref.
  - `test_partly_not_found`: `notFound:["WC2"]` → `not_found===['WC2']`, `invoices` có một dòng.
  - `test_not_found_301_lists_every_reference`.
  - `test_unreadable_200_throws`.
  - `test_single_reference_wrapper_unchanged`: `find_invoices_by_reference` vẫn trả list.
  - `test_draft_by_name_without_code`.
- [ ] **Step 2: Chạy, xác nhận fail.** `einv composer test:integration -- --filter MatbaoLookupTest` → FAIL (class/method chưa có).
- [ ] **Step 3: Cài đặt `MatbaoInvoice` (4 field mới), `MatbaoLookup`, `find_invoices_by_references`.** Hàm cũ ủy quyền rồi trả `->invoices`; `notFound` không phải mảng thì coi như rỗng; chỉ giữ phần tử `is_scalar`.
- [ ] **Step 4: Test enum và mã lỗi (fail rồi pass).**
  - `MatbaoStatusTest`:
    - `label()` cho 1–9 và 0 đúng nhãn spec (5 → "CQT chưa phản hồi");
    - `is_final()` chỉ true với 4, 0;
    - `alert_code()` chỉ non-null với 2, 6, 0;
    - `group()`: 4 → `issued`, 2/6/0 → `error`, còn lại → `progress`;
    - `tryFrom(12)===null`.
  - `InvoiceErrorCodeTest`:
    - thêm ba mã vào danh sách "every code has a hint";
    - `test_matbao_status_hints_verbatim` so khớp nguyên văn ba hint;
    - kind `MATBAO`, `needs_attention()` true.
  - Cài `MatbaoStatus`, `MatbaoSnapshot`, ba case và `is_matbao_status_warning()`. Bổ sung mọi `match` exhaustive (`kind`, `label`, `hint`, `status`/`retryable` nếu có), để không còn `UnhandledMatchError`.
- [ ] **Step 5: Test schema (fail rồi pass).**
  - `SchemaUpgradeTest::test_v5_table_gets_matbao_columns_and_keeps_its_rows`, theo pattern temporary table của `test_v1_table_gets_the_error_columns_and_keeps_its_rows`:
    - helper `create_v5_table()` tạo đúng các cột của version 5, rồi assert 8 cột mới chưa tồn tại;
    - chèn một hóa đơn `draft_created`, đặt option `'5'`, chạy `Schema::maybe_install()`;
    - kết quả: giá trị cũ giữ nguyên, 8 cột mới NULL, index `matbao_sync` tồn tại, option bằng `'6'`.
  - Cài `Schema` (cột/index theo Contracts) và `InvoiceRecord::from_row` (đọc phòng thủ như field cũ).
- [ ] **Step 6: Test repository (fail).** `InvoiceRepositoryTest`:
  - `test_due_for_status_sync_ordering_and_filters`:
    - có các hóa đơn: chưa thử; thử cách đây 2 giờ; đã `ISSUED`; `issued_on` cách đây 31 ngày; `failed`; `draft_created` thiếu `matbao_invoice_id`;
    - kết quả với `since` = hôm nay − 30: đúng thứ tự [chưa thử, 2 giờ], không có các dòng còn lại;
    - `issued_on` NULL thì dùng `DATE(created_at)`.
  - `test_due_for_status_sync_respects_limit`.
  - `test_record_matbao_status_writes_snapshot`.
  - `test_two_writers_only_one_wins`: hai lần `record_matbao_status` với cùng `$read` (Nháp) và snapshot Lỗi ký → lần một `WRITTEN`, lần hai `STALE`, `alert_cycle` +1 đúng một lần, `error_code==='matbao_sign_failed'`, `status==='draft_created'`.
  - `test_write_ignored_when_invoice_id_changed`: đọc bản ghi, rồi `update_status(... matbao_invoice_id 'B')`, rồi ghi với `$read` cũ → `STALE`, không đổi.
  - `test_recovery_clears_only_matbao_warning`: `matbao_sign_failed` → ISSUED xóa mã; `order_amounts_changed` → ISSUED giữ mã, snapshot vẫn ghi.
  - `test_new_invoice_id_resets_matbao_fields`: bản ghi status 0, `update_status` với `matbao_invoice_id` mới → 8 cột NULL.
  - `test_same_abnormal_status_keeps_warning`: `matbao_sign_failed` rồi ghi lại Lỗi ký → `error_code`, `status_reason`, `alert_cycle` không đổi.
  - `test_recovery_clears_kind_and_reason_too`: sau nhánh (c), cả `error_kind` và `status_reason` rỗng.
  - `test_repeat_write_is_never_failed`: ghi lần một → `WRITTEN`; `find()` lại để lấy `$read` mới; ghi ngay lần hai cùng snapshot → kết quả là `WRITTEN` hoặc `STALE` (tùy cùng giây hay không) nhưng không bao giờ `FAILED`, và `error_code`/`alert_cycle` không đổi.
  - `test_needs_admin_for_matbao_warning`: `draft_created` + `matbao_sign_failed` → `needs_admin()` true; `draft_created` + không mã → false.
- [ ] **Step 7: Cài repository.**
  - `record_matbao_status` theo contract (một `$wpdb->query( $wpdb->prepare(...) )`, guard NULL chọn trong PHP).
  - Trả `RecordResult`.
  - Khi đặt mã lỗi, ghi log trạng thái qua `changed()` như `record_error()`.
  - `update_status()` merge 8 cột Mắt Bão = null khi `$fields` có `matbao_invoice_id`.
  - `InvoiceRecord::needs_admin()` thêm nhánh `is_matbao_status_warning()`.
- [ ] **Step 8: Chạy pass + lint/analyse.** `einv composer test:integration -- --filter 'MatbaoLookupTest|MatbaoStatusTest|InvoiceErrorCodeTest|SchemaUpgradeTest|InvoiceRepositoryTest|DraftInvoiceRetryTest'` → PASS; `einv composer lint && einv composer analyse` → sạch.
- [ ] **Step 9: Commit.** `feat: store Mat Bao invoice status fields and batch lookup`

---

## Task 2: Dịch vụ đồng bộ và lịch chạy — implements 2

**Covers:** 2.1, 2.2, 2.3, 2.4
**Difficulty:** Hard
**Blocked by:** Task 1

**Files:**
- Create: `src/Invoice/MatbaoStatusSync.php`, `src/Invoice/SyncOutcome.php`, `tests/integration/MatbaoStatusSyncTest.php`, `tests/integration/Monitoring/MatbaoStatusEventTest.php`
- Modify: `src/Plugin.php` (gọi `MatbaoStatusSync::register()` cạnh các register khác), `tests/integration/InvoiceAlertTest.php`, `tests/integration/UninstallTest.php` hoặc `PluginActivationTest.php` (deactivation)

**Interfaces:**
- Consumes: Task 1 Contracts.
- Produces: `MatbaoStatusSync::sync_one( InvoiceRecord ): SyncOutcome`, `SyncOutcome`, action `einvoice_matbao_status_sync`.

- [ ] **Step 1: Test hành vi lô (fail).** `MatbaoStatusSyncTest extends InvoiceWorkflowTestCase`, tạo hóa đơn `draft_created` bằng helper hiện có, seed `FakeHttp` cho `LOOKUP_ROUTE`:
  - `test_draft_gets_issued`: dòng `maSoHDon` khớp, `maTThaiHDon` 4, `shDon` 125 → `matbao_status 4`, số 125, MCCQT, `matbao_synced_at` không rỗng, `status==='draft_created'`.
  - `test_final_invoice_not_polled_again`: lượt hai không có request.
  - `test_older_than_30_days_skipped`: `issued_on` = hôm nay − 31 → không request.
  - `test_draft_created_before_upgrade_is_synced`: cột Mắt Bão NULL, `issued_on` − 3 ngày → được tra cứu.
  - `test_batch_limit_and_rotation`: 250 hóa đơn → lượt một tra 200; lượt hai chứa 50 còn lại.
  - `test_requests_grouped_by_template`: 25 hóa đơn `1/C26TAT` + 3 hóa đơn `1/C26TBB` → ba request có `danhSach` dài 20, 5, 3.
  - `test_other_plugin_statuses_ignored`: `failed`, `pending`, `skipped`.
  - `test_unknown_code_kept_polling`: 12 + "Trạng thái mới" → `matbao_status_name` lưu, vẫn có trong `due_for_status_sync`, không alert. Hiển thị được kiểm ở Task 4 `test_unknown_code_badge`.
  - `test_matching_row_not_sent_to_authority`: 3.
  - `test_not_found_301_marks_deleted` và `test_listed_as_not_found_marks_deleted`: status 0, không request `CREATE_ROUTE`.
  - `test_unreadable_row_keeps_status`: phần tử không phải mảng.
  - `test_reference_missing_from_answer_keeps_status`.
  - `test_different_invoice_under_reference_keeps_status`: `maSoHDon` khác. Hai test cuối thêm assert có log warning qua `MonitoringFixture`/`SentrySink`.
  - `test_lookup_503_keeps_status_and_continues`: nhóm mẫu A trả 503, nhóm B trả 4 → A giữ trạng thái, có `matbao_checked_at`, không ghi 0, không alert; B được ghi.
  - `test_no_credentials_no_request`.
- [ ] **Step 2: Test cảnh báo (fail).**
  - `test_sign_error_detected_alerts_once`: đếm `did_action( DraftInvoiceWorkflow::NEEDS_ATTENTION_ACTION )` +1, `error_code` đúng, `alert_cycle` +1.
  - `test_same_abnormal_status_next_hour_no_new_alert`.
  - `test_concurrent_writers_alert_once`: hai instance cùng đọc bản ghi rồi lần lượt `sync_one` → hook +1.
  - `test_issued_is_not_alerted`.
  - `test_abnormal_status_shows_notice_and_survives_cleanup`: sau Lỗi ký, `AdminAlertNotice::render()` có alert; sau `AlertStore::cleanup()` alert vẫn còn.
  - `test_recovered_after_resigning_clears_code`, kèm `AdminAlertNotice` không còn alert (*Notice disappears when resolved*).
  - `test_order_warning_kept_on_recovery`.
  - `test_abnormal_status_replaces_order_warning`: `AlertStore` có alert mới.
  - `test_draft_recreated_is_synced_again`: status 0 → `update_status` với mã mới → có trong `due_for_status_sync`.
  - `test_stale_lookup_after_recreation_not_written`: `respond_with` callback đổi `matbao_invoice_id` trong lúc request → kết quả không ghi.
  - `test_alert_excludes_tax_note`: Lark request (qua `InvoiceAlerts::send`) chứa "#<order>", `matbao_not_eligible`, hint nguyên văn, không chứa `ghiChuCQT`.
  - `MatbaoStatusEventTest`:
    - `test_sign_error_event`: đúng một event `warning`, tag `error_code=matbao_sign_failed`, tag `order_id`;
    - `test_lookup_failure_logs_warning_only`: 503 → một log warning, không event lỗi.
- [ ] **Step 3: Chạy, xác nhận fail.** `einv composer test:integration -- --filter 'MatbaoStatusSyncTest|MatbaoStatusEventTest'`.
- [ ] **Step 4: Cài `MatbaoStatusSync` và `SyncOutcome`.**
  - `sync_batch()`:
    - credentials qua `CredentialStore::matbao()`; null thì return;
    - lấy `due_for_status_sync( SYNC_LIMIT, today_vn − SYNC_WINDOW_DAYS )`;
    - gom theo `template_form|template_series`, `array_chunk( LOOKUP_CHUNK )`;
    - với mỗi chunk: try lookup, catch `MatbaoError` → `touch_matbao_checked` + `Monitor::log( 'warn', 'Mat Bao status lookup failed', [error_kind, api_code, count] )`.
  - `apply( InvoiceRecord, MatbaoLookup ): SyncOutcome`:
    - dòng khớp `invoice_id === matbao_invoice_id` → `MatbaoSnapshot::from_invoice`;
    - `reference ∈ not_found` → `MatbaoSnapshot::deleted()`;
    - còn lại → `touch_matbao_checked` + log, trả `UNCONFIRMED`.
  - Sau đó `record_matbao_status`. Khi kết quả `WRITTEN`, `$new` có `alert_code()` và trạng thái khác `$read->matbao_status`: `find()` rồi `do_action( NEEDS_ATTENTION_ACTION, $updated, $code )` trong try/catch như `OrderChangeWatcher::notify`. `$reason` = `label()` của trạng thái, không chứa `tax_note`.
  - `apply` map kết quả: `WRITTEN` → `UPDATED`, `STALE` → `UNCHANGED`, `FAILED` (DB) → `FAILED` kèm `Monitor::log( 'warn', … )`.
  - `sync_one()` = lookup một phần tử + `apply`; lỗi lookup → `FAILED`.
  - Ngày "hôm nay" theo `Asia/Ho_Chi_Minh` như `issued_on`.
- [ ] **Step 5: Đăng ký lịch.**
  - `register()` hook `ACTION` → `run`, và `action_scheduler_init` → `schedule()` (theo mẫu `DraftInvoiceWorkflow::schedule_recovery`, group `DraftInvoiceWorkflow::GROUP`).
  - Test `test_deactivation_unschedules_status_sync`: sau `Plugin::deactivate()`, `as_has_scheduled_action( ACTION )` false.
- [ ] **Step 6: Chạy pass, gồm cả bộ alert/monitoring hiện có.** `einv composer test:integration -- --filter 'MatbaoStatusSync|MatbaoStatusEvent|InvoiceAlert|NeedsAttentionEvent|SameHintEverywhere|Uninstall|PluginActivation'` → PASS; lint/analyse sạch.
- [ ] **Step 7: Commit.** `feat: hourly Mat Bao status sync with one alert per abnormal transition`

---

## Task 3: Kiểm tra trạng thái thủ công — implements 3

**Covers:** 3.1, 3.2, 3.3
**Difficulty:** Normal
**Blocked by:** Task 2

**Files:**
- Modify: `src/Admin/InvoiceController.php` (`check_status`), `src/Admin/InvoicePage.php` (`render_check`)
- Test: `tests/integration/InvoiceAdminActionsRestTest.php`

**Interfaces:**
- Consumes: `MatbaoStatusSync::sync_one`, `SyncOutcome`, `MatbaoStatus` (`render_synced_check` dùng `MatbaoStatus::label()`; Task 4 chạy sau có thể chuyển sang `MatbaoStatusView::badge`).
- Produces: `InvoicePage::render_synced_check( InvoiceRecord $updated, SyncOutcome $outcome ): string`.

- [ ] **Step 1: Test (fail).** Trong `InvoiceAdminActionsRestTest`, POST `/invoices/{id}/check`:
  - `test_check_draft_still_exists`: `data-einv-check="draft"`, `status` không đổi.
  - `test_check_draft_missing_confirmed`: 301 → `matbao_status 0`, có `confirm_lost`, không request create.
  - `test_check_no_longer_a_draft`: 3 → `data-einv-check="synced"` chứa "Chưa gửi CQT", không có chữ "Cảnh báo", bản ghi lưu 3.
  - `test_check_unconfirmed_absence`: 200 không dòng, không `notFound` → `data-einv-check="unknown"`, không có `confirm_lost`, `matbao_status` không đổi.
  - `test_check_issued`: notice success, bản ghi có số HĐ và MCCQT, không alert.
  - `test_check_not_eligible`: notice error chứa hint `matbao_not_eligible`.
  - `test_check_not_a_created_draft_is_read_only`: `failed` có mẫu → bản ghi không đổi.
  - `test_check_no_stored_template_409`: giữ test hiện có.
  - Giữ test 422 lỗi tra cứu.
- [ ] **Step 2: Chạy, xác nhận fail.**
- [ ] **Step 3: Cài.**
  - `check_status`: `draft_created` → `sync_one`.
    - `FAILED` → 422 như cũ.
    - Còn lại → `repository->find()` rồi `render_synced_check`. Notice: success với nháp/trung gian/đã cấp mã; error kèm `alert_code()->hint()` với bất thường; error + `lost_draft_form` với `DELETED`; error "không xác định được trạng thái…" với `UNCONFIRMED`.
  - Status khác → giữ đường chỉ đọc hiện tại (`render_check`).
- [ ] **Step 4: Pass + lint/analyse.** `--filter InvoiceAdminActionsRestTest`.
- [ ] **Step 5: Commit.** `feat: status check records the Mat Bao status`

---

## Task 4: Hiển thị thông tin và liên kết — implements 4

**Covers:** 4.1, 4.2, 4.3, 4.4, 4.5
**Difficulty:** Normal
**Blocked by:** Task 1, Task 3 (thêm vào: Task 3 và Task 4 cùng sửa `src/Admin/InvoicePage.php`, nên chạy tuần tự để mỗi file có một writer)

**Files:**
- Create: `src/Admin/MatbaoStatusView.php`
- Modify: `src/Admin/InvoicePage.php` (`render_panel`, `render_list`, `render_detail`, `detail_url`), `src/Admin/OrderInvoiceMetaBox.php`
- Test: `tests/integration/InvoiceAdminListRestTest.php`, `tests/integration/OrderInvoiceMetaBoxTest.php`, `tests/integration/SettingsPageTest.php`

**Interfaces:**
- Consumes: `InvoiceRecord` mở rộng, `MatbaoStatus`.
- Produces: `MatbaoStatusView::badge|rows|order_link`, `InvoicePage::detail_url`.

- [ ] **Step 1: Test (fail).**
  - `InvoiceAdminListRestTest`:
    - `test_issued_invoice_row`: cột số 125, `data-einv-matbao-badge="issued"` chứa "Đã cấp mã/tiếp nhận", có thời điểm đồng bộ;
    - `test_deleted_badge_is_error`;
    - `test_unknown_code_badge`: `matbao_status` 12, name "Trạng thái mới" → badge `progress` chứa "Trạng thái mới";
    - `test_not_yet_synced_shows_dash`;
    - `test_order_id_links_to_edit_order`: href bằng `wc_get_order($id)->get_edit_order_url()`;
    - `test_deleted_order_is_text`;
    - `test_detail_button_pushes_url`: `hx-push-url` bằng `InvoicePage::detail_url(id)`;
    - `test_back_button_pushes_list_url`;
    - `test_issued_invoice_detail`: trạng thái, số, MCCQT, "Hóa đơn mới", đồng bộ lúc, link "Xem đơn #N";
    - `test_tax_note_is_redacted`: `ghiChuCQT` chứa `012345678901` và `https://x.y` → hiện `…8901` theo định dạng `Redactor`, và `[đã ẩn]`.
    - Giữ các test filter/search/pagination/detail/404 hiện có.
  - `SettingsPageTest`:
    - `test_invoice_query_arg_loads_detail`: `$_GET['invoice']='12'` → `#einv-invoices` `hx-get` kết thúc `/invoices/12`;
    - `test_invalid_invoice_query_arg_loads_list`: mỗi giá trị `'abc'`, `'-3'`, `'0'`, `'1.5'`, `'12abc'`, `'9999999999999999999'`, `array('12')` → danh sách.
  - `OrderInvoiceMetaBoxTest`:
    - `test_issued_invoice`: "Đã cấp mã/tiếp nhận", số, MCCQT;
    - `test_admin_sees_invoice_link`: href bằng `InvoicePage::detail_url`;
    - `test_shop_manager_has_no_invoice_link`;
    - giữ 4 test hiện có.
- [ ] **Step 2: Chạy, xác nhận fail.**
- [ ] **Step 3: Cài `MatbaoStatusView`.** Escape mọi output; dùng màu badge cùng bảng màu `InvoicePage::BADGE_COLORS` (issued = màu `created`, error = màu `error`, progress = màu `waiting`).
- [ ] **Step 4: Cài `InvoicePage`.**
  - `render_list`: cột theo spec (Đơn, Số HĐ, MTChieu, Trạng thái, Mắt Bão, Lỗi, Đồng bộ lúc, Chi tiết).
  - `render_detail`: thêm `MatbaoStatusView::rows`, link đơn, `hx-push-url` cho nút "‹ Danh sách" bằng `AdminTabs::url('invoices')`.
  - `render_panel`: `$raw = wp_unslash( $_GET['invoice'] ?? '' )`; chỉ nhận khi `is_string( $raw ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $raw )` và không vượt `PHP_INT_MAX` (độ dài < 19, hoặc độ dài 19 và `strcmp( $raw, (string) PHP_INT_MAX ) <= 0`), rồi `(int)`. phpcs nonce ignore có lý do: chỉ đọc để hiển thị, quyền kiểm ở REST.
- [ ] **Step 5: Cài `OrderInvoiceMetaBox`.** Dòng Mắt Bão qua `MatbaoStatusView::rows` (chỉ trạng thái, số, MCCQT). Link chỉ hiện khi `current_user_can( 'manage_options' )`.
- [ ] **Step 6: Pass + lint/analyse.** `--filter 'InvoiceAdminListRestTest|OrderInvoiceMetaBoxTest|SettingsPageTest|InvoiceStatusGroupTest'`.
- [ ] **Step 7: Commit.** `feat: show Mat Bao status and link orders with invoices`

---

## Task 5: Browser E2E — implements 5

**Covers:** 5.1, 5.2, 5.3
**Difficulty:** Normal
**Blocked by:** Task 3, Task 4

**Files:**
- Modify: `tests/e2e/fake-matbao/router.php`, `tests/e2e/army/invoices.army.ts`, `tests/e2e/army/checkout-invoice.army.ts`, `tests/e2e/army/support.ts` (seam seed nếu cần), `tests/e2e/army-cache/**` (cache replay ghi lại)

**Interfaces:**
- Consumes: UI của Task 3 và 4; selector `data-einv-check`, `data-einv-matbao-badge`.

- [ ] **Step 1: Fake Mắt Bão.** `router.php` nhận `danhSach` nhiều phần tử. Thêm seed theo `MTChieu` (cùng cơ chế seed hiện có của router): `maTThaiHDon`, `tenTThaiHDon`, `shDon`, `mccqt`, `maLoaiHDon`, `ghiChuCQT`, hoặc `notFound`.
- [ ] **Step 2: `invoices.army.ts`.**
  - Thay assertion "không còn ở trạng thái Nháp" bằng: seed 4 → bấm "Kiểm tra trạng thái" → notice thành công chứa "Đã cấp mã/tiếp nhận", chi tiết có số HĐ và MCCQT.
  - Seed 2 → notice lỗi chứa hint `matbao_sign_failed`.
  - Mở `…&tab=invoices&invoice=<id>` → thấy chi tiết.
  - Từ danh sách bấm "Chi tiết" → URL chứa `invoice=<id>`; Back của trình duyệt → danh sách hiện lại, URL không còn `invoice=`.
  - Bấm "#<order>" trong danh sách → màn hình sửa đơn.
- [ ] **Step 3: `checkout-invoice.army.ts`.** Trên màn hình đơn, bấm "Xem chi tiết hóa đơn" → chi tiết đúng hóa đơn.
- [ ] **Step 4: Ghi cache và replay.** Trong `tests/e2e`: `bun run e2e:record` cho hai file trên, rồi `bun run e2e:replay` → PASS. Commit cache.
- [ ] **Step 5: Commit.** `test(e2e): Mat Bao status and order/invoice links`

---

## Task 6: Tài liệu — implements 6

**Covers:** 6.1, 6.2
**Difficulty:** Easy
**Blocked by:** Task 3, Task 4

**Files:**
- Modify: `docs/huong-dan-su-dung.md`, `README.md`, `docs/phat-trien.md`

- [ ] **Step 1: `docs/huong-dan-su-dung.md`, `README.md`.**
  - Hai lớp trạng thái: plugin và Mắt Bão; bảng 1–9 + "Đã xóa trên Mắt Bão".
  - Đồng bộ mỗi giờ trong 30 ngày kể từ ngày lập; nút "Kiểm tra trạng thái" ghi kết quả.
  - Ba cảnh báo mới kèm hướng dẫn; liên kết đơn ↔ hóa đơn.
  - README giữ câu "Plugin không ký, phát hành, sửa hay hủy hóa đơn".
- [ ] **Step 2: `docs/phat-trien.md`.**
  - `MatbaoStatusSync`, action `einvoice_matbao_status_sync`, các hằng lô.
  - Quy tắc "Đã xóa" chỉ khi 301/`notFound`; ghi có điều kiện; reset khi có mã hóa đơn mới; `error_code` dùng chung với `order_*_changed`.
- [ ] **Step 3: Commit.** `docs: Mat Bao status sync and invoice links`

---

## Scenario → test

**matbao-status-sync**
- Known code → `MatbaoStatusTest::test_labels`
- Unknown code → `MatbaoStatusSyncTest::test_unknown_code_kept_polling`, `InvoiceAdminListRestTest::test_unknown_code_badge`
- Issued is final → `MatbaoStatusTest::test_final_and_abnormal`
- Sign error is abnormal → `MatbaoStatusTest::test_final_and_abnormal`
- Draft gets issued → `MatbaoStatusSyncTest::test_draft_gets_issued`
- Final invoice not polled again → `MatbaoStatusSyncTest::test_final_invoice_not_polled_again`
- Older than 30 days → `MatbaoStatusSyncTest::test_older_than_30_days_skipped`
- Draft created before upgrade → `MatbaoStatusSyncTest::test_draft_created_before_upgrade_is_synced`
- Batch limit and rotation → `MatbaoStatusSyncTest::test_batch_limit_and_rotation`
- Requests grouped by template → `MatbaoStatusSyncTest::test_requests_grouped_by_template`
- Other plugin statuses ignored → `MatbaoStatusSyncTest::test_other_plugin_statuses_ignored`
- Deleted draft not recreated → `MatbaoStatusSyncTest::test_not_found_301_marks_deleted`
- Matching row → `MatbaoStatusSyncTest::test_matching_row_not_sent_to_authority`
- Not found → `MatbaoStatusSyncTest::test_not_found_301_marks_deleted`
- Listed as not found → `MatbaoStatusSyncTest::test_listed_as_not_found_marks_deleted`
- Unreadable row → `MatbaoStatusSyncTest::test_unreadable_row_keeps_status`
- Reference missing from the answer → `MatbaoStatusSyncTest::test_reference_missing_from_answer_keeps_status`
- Different invoice under the reference → `MatbaoStatusSyncTest::test_different_invoice_under_reference_keeps_status`
- Mat Bao 503 → `MatbaoStatusSyncTest::test_lookup_503_keeps_status_and_continues`
- No credentials → `MatbaoStatusSyncTest::test_no_credentials_no_request`
- Sign error detected → `MatbaoStatusSyncTest::test_sign_error_detected_alerts_once`
- Same abnormal status next hour → `MatbaoStatusSyncTest::test_same_abnormal_status_next_hour_no_new_alert`
- Concurrent sync and manual check → `MatbaoStatusSyncTest::test_concurrent_writers_alert_once`
- Issued is not alerted → `MatbaoStatusSyncTest::test_issued_is_not_alerted`
- Recovered after re-signing → `MatbaoStatusSyncTest::test_recovered_after_resigning_clears_code`
- Order warning kept on recovery → `MatbaoStatusSyncTest::test_order_warning_kept_on_recovery`
- Abnormal status replaces order warning → `MatbaoStatusSyncTest::test_abnormal_status_replaces_order_warning`
- Draft recreated → `MatbaoStatusSyncTest::test_draft_recreated_is_synced_again`
- Stale lookup after recreation → `MatbaoStatusSyncTest::test_stale_lookup_after_recreation_not_written`
- Deactivation → `MatbaoStatusSyncTest::test_deactivation_unschedules_status_sync`

**admin-invoice-management**
- Open by address → `SettingsPageTest::test_invoice_query_arg_loads_detail`; e2e `invoices.army.ts`
- Address follows navigation → `InvoiceAdminListRestTest::test_detail_button_pushes_url`, `test_back_button_pushes_list_url`; e2e `invoices.army.ts`
- Invalid parameter → `SettingsPageTest::test_invalid_invoice_query_arg_loads_list`
- From invoice to order → `InvoiceAdminListRestTest::test_order_id_links_to_edit_order`; e2e `invoices.army.ts`
- From order to invoice → `OrderInvoiceMetaBoxTest::test_admin_sees_invoice_link`; e2e `checkout-invoice.army.ts`
- Shop manager without manage_options → `OrderInvoiceMetaBoxTest::test_shop_manager_has_no_invoice_link`
- Deleted order → `InvoiceAdminListRestTest::test_deleted_order_is_text`
- Issued badge → `InvoiceAdminListRestTest::test_issued_invoice_row`
- Deleted badge → `InvoiceAdminListRestTest::test_deleted_badge_is_error`
- Not yet synced → `InvoiceAdminListRestTest::test_not_yet_synced_shows_dash`
- Error group filter / Unknown status filter / Search / Pagination → test hiện có trong `InvoiceAdminListRestTest` (giữ nguyên)
- Issued invoice row → `InvoiceAdminListRestTest::test_issued_invoice_row`
- Detail shown / Unknown invoice → test hiện có trong `InvoiceAdminListRestTest`
- Issued invoice detail → `InvoiceAdminListRestTest::test_issued_invoice_detail`
- Tax note with identifier → `InvoiceAdminListRestTest::test_tax_note_is_redacted`
- Draft created / Error with hint / No invoice / Skipped order → test hiện có trong `OrderInvoiceMetaBoxTest`
- Issued invoice (meta box) → `OrderInvoiceMetaBoxTest::test_issued_invoice`
- Draft still exists → `InvoiceAdminActionsRestTest::test_check_draft_still_exists`
- Draft missing → `InvoiceAdminActionsRestTest::test_check_draft_missing_confirmed`
- No longer a draft → `InvoiceAdminActionsRestTest::test_check_no_longer_a_draft`
- Unconfirmed absence → `InvoiceAdminActionsRestTest::test_check_unconfirmed_absence`
- Issued (check) → `InvoiceAdminActionsRestTest::test_check_issued`; e2e `invoices.army.ts`
- Not eligible → `InvoiceAdminActionsRestTest::test_check_not_eligible`
- Not a created draft → `InvoiceAdminActionsRestTest::test_check_not_a_created_draft_is_read_only`
- No stored template → `InvoiceAdminActionsRestTest::test_check_no_stored_template_409`

**invoice-storage-schema**
- Upgrade from the previous schema → `SchemaUpgradeTest::test_v5_table_gets_matbao_columns_and_keeps_its_rows`
- Two writers → `InvoiceRepositoryTest::test_two_writers_only_one_wins`
- Invoice ID changed meanwhile → `InvoiceRepositoryTest::test_write_ignored_when_invoice_id_changed`
- Recorded after recreation → `InvoiceRepositoryTest::test_new_invoice_id_resets_matbao_fields`
- Ordering → `InvoiceRepositoryTest::test_due_for_status_sync_ordering_and_filters`

**matbao-api-client**
- Not found → `MatbaoLookupTest::test_not_found_301_lists_every_reference`
- Unreadable lookup response → `MatbaoLookupTest::test_unreadable_200_throws`
- Several references → `MatbaoLookupTest::test_several_references_one_request`
- Partly not found → `MatbaoLookupTest::test_partly_not_found`
- Draft by name → `MatbaoLookupTest::test_draft_by_name_without_code` (mới: thiếu `maTThaiHDon`, `tenTThaiHDon` "Nháp" → `is_draft()`)
- Template out of numbers → test hiện có trong `SettingsInvoicingRestTest`
- Issued invoice fields → `MatbaoLookupTest::test_issued_invoice_fields`
- Draft without number → `MatbaoLookupTest::test_draft_without_number`

**failure-alerts**
- Not eligible alert → `MatbaoStatusSyncTest::test_alert_excludes_tax_note`
- Notice disappears when resolved → `MatbaoStatusSyncTest::test_recovered_after_resigning_clears_code`
- Sign error hint / Not eligible hint / Deleted draft hint → `InvoiceErrorCodeTest::test_matbao_status_hints_verbatim`

**error-monitoring**
- Sign error event → `MatbaoStatusEventTest::test_sign_error_event`
- Lookup failure during sync → `MatbaoStatusEventTest::test_lookup_failure_logs_warning_only`
