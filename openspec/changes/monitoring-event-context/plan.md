# Monitoring Event Context Implementation Plan

> Executed via `/opsx-apply`, which chooses the executor.
> Spec reference: this change's `specs/` and `design.md`.

**Goal:** Mỗi event gửi lên Sentry/Rustrak mang đủ ngữ cảnh tra soát (đơn, hóa đơn, dòng hàng, lỗi Mắt Bão, action) và một hướng dẫn xử lý dùng chung cho Sentry, Lark và admin UI.

**Architecture:** `InvoiceErrorCode::hint()` là nguồn duy nhất cho hướng dẫn. Lỗi dữ liệu mang `LineFailure`. Workflow đặt chi tiết lỗi vào stack theo request qua `Monitor::with_failure_detail()`, và `Monitor::capture_needs_attention()` đọc chi tiết khớp mã lỗi. Contract `Backend` nhận thêm `$contexts`, các context được lọc bởi `scrub_event` hiện có.

**Tech Stack:** PHP 8.2+, WordPress 6.6+, WooCommerce 9.8+, `sentry/sentry` 4.x (đã scoped), PHPUnit với wp-phpunit, `SentrySink` / `FakeHttp`.

**Lệnh test** (gọi tắt là `einv` trong plan):

```bash
alias einv='docker compose exec -T -w /var/www/html/wp-content/plugins/einvoice-matbao wordpress'
einv composer test:integration -- --filter <TestClass>
```

## Global Constraints

- Chuỗi UI tiếng Việt qua text domain `einvoice-matbao`. Code comment, docblock, commit và identifier viết tiếng Anh.
- Không đổi DB schema, không thêm dependency, không đổi chữ ký hook `einvoice_matbao_invoice_needs_attention( InvoiceRecord, InvoiceErrorCode )` hay REST route.
- Fingerprint needs-attention giữ `['needs-attention', <code>]`.
- Lớp giám sát không được ném lỗi vào luồng hóa đơn (error-monitoring / Monitoring never breaks invoicing). Mọi việc dựng context mới chạy trong `Monitor::guard`.
- Mọi giá trị context và tag đi qua `Redactor` hiện có. Không được bypass `Pattern masking`.
- Mã nguồn tuân theo `~/.agents/skills/coding-standards/SKILL.md`, PHPCS (`einv composer lint`) và PHPStan (`einv composer analyse`).

## Review Focus

1. **Recovery sweep xử lý nhiều đơn trong một request:** chi tiết lỗi của đơn trước không được dính sang event của đơn sau. Đã có test ở Task 5 ("Detail does not leak to another order").
2. **Hóa đơn bị xóa giữa chừng hoặc `wc_get_order` trả false khi dựng `invoice` context:** event vẫn gửi, chỉ thiếu `admin_path`. Thêm test `test_invoice_context_without_order_has_no_admin_path` ở Task 4.
3. **`MatbaoError` không có `api_code` (lỗi transport):** không gắn tag `matbao.api_code`, context `matbao` có `api_code` null. Thêm test `test_transport_error_has_no_api_code_tag` ở Task 5.
4. **Dòng phí (`fee`) lỗi không có product:** `product_id` = 0, nên không gắn tag `product_id`. Thêm assert trong test dòng vận chuyển ở Task 2 và test `test_fee_line_failure_has_no_product_tag` ở Task 5.
5. **`with_failure_detail()` lồng nhau:** callback trong lồng callback; chi tiết trong cùng phải thắng và stack phải trả về đúng sau `finally`, kể cả khi callback ném lỗi. Thêm test `test_nested_failure_detail_innermost_wins_and_unwinds` ở Task 3.

---

## Contracts

| Name | new / modified | Shape | public |
|---|---|---|---|
| `InvoiceErrorCode::hint` | new | `public function hint(): string`: chuỗi dịch được, không rỗng, có cho mọi case (bảng D1) | no |
| `LineFailure` | new | `final class LineFailure` (namespace `LTTech\EinvoiceMatbao\Invoice`):<br>• `__construct( public readonly int $item_id, public readonly string $item_type, public readonly int $product_id, public readonly int $variation_id, public readonly string $sku, public readonly string $name, public readonly array $tax_rate_ids /* list<int> */ )`<br>• `public static function from_item( \WC_Order_Item $item ): self`: đọc tax rate IDs từ `get_taxes()['total']` (bỏ giá trị `''`/null) **trước** mọi bước validation; với item không có `get_taxes` thì trả `[]`. Product ID, variation ID và SKU chỉ có cho `WC_Order_Item_Product`, còn lại là 0/0/`''`<br>• `public function to_context(): array{item_id:int,item_type:string,product_id:int,variation_id:int,sku:string,name:string,tax_rate_ids:list<int>}` | no |
| `InvoiceDataError::__construct` | modified | `__construct( public readonly InvoiceErrorCode $error_code, string $message, public readonly ?LineFailure $line = null )` | no |
| `DraftPayloadBuilder` private helpers | modified | • `private static function fail( InvoiceErrorCode $code, string $reason, ?LineFailure $line = null ): never`<br>• `private function rate( LineFailure $line, array $line_taxes, array $rates ): int`<br>• `private static function whole_vnd( string\|int\|float $amount, LineFailure $line ): int`<br>Tên dòng trong lý do lấy từ `$line->name` | no |
| `DraftInvoiceWorkflow` private helpers | new | • `private static function record_data_error( InvoiceRepository $repository, int $order_id, InvoiceDataError $error, ?string $status, ?string $from ): void`: cùng ngữ nghĩa `$status`/`$from` với `record_error`; khi `$error->line` khác null, bọc trong `with_failure_detail`<br>• `private function record_matbao_failure( InvoiceRecord $invoice, InvoiceErrorCode $code, string $reason ): void`: thân cũ của `fail()` sau khi có `$code`/`$reason` | no |
| `FailureDetail` | new | `final class FailureDetail` (namespace `LTTech\EinvoiceMatbao\Monitoring`):<br>• `__construct( public readonly int $order_id, public readonly InvoiceErrorCode $code, public readonly array $contexts /* array<string, array<string, mixed>> */, public readonly array $tags /* array<string,string> */ )`<br>• `public static function for_matbao( int $order_id, InvoiceErrorCode $code, MatbaoError $error ): self` (Task 3): context `matbao` = {kind:string, http_status:?int, api_code:?int, target_sent:bool, auto_retry:bool}; tag `matbao.api_code` khi `api_code` khác null<br>• `public static function for_line( int $order_id, InvoiceErrorCode $code, LineFailure $line ): self` (Task 5): context `line` = `$line->to_context()`; tag `product_id` khi > 0 | no |
| `Monitor::with_failure_detail` | new | `public static function with_failure_detail( FailureDetail $detail, callable $callback ): mixed`: chạy callback đúng một lần, trả giá trị, ném lại exception; pop trong `finally`. Khi giám sát tắt thì gọi thẳng callback | no |
| `Monitor::failure_detail_for` | new | `public static function failure_detail_for( int $order_id, InvoiceErrorCode $code ): ?FailureDetail` (`@internal`): detail trong cùng có `order_id` và `code` khớp, nếu không có thì null | no |
| `Monitor::capture_exception` | modified | `public static function capture_exception( \Throwable $e, array $tags = array(), ?\Closure $contexts = null ): ?string`: `$contexts` có dạng `Closure(): array<string, array<string, mixed>>`, **được gọi bên trong `guard`**, nên lỗi khi dựng context không bao giờ lọt ra caller | no |
| `Monitor::capture_needs_attention` | modified | `public static function capture_needs_attention( InvoiceRecord $i, InvoiceErrorCode $c ): void`: message `Hóa đơn cần xử lý: %1$s – Đơn #%2$d` (`$c->label()`, `$i->order_id`); context `invoice` và `failure_detail_for( $i->order_id, $c )` được dựng bên trong `guard` | no |
| `Backend::capture_exception` | modified | `public function capture_exception( \Throwable $e, array $tags, array $known, array $contexts ): ?string` | no |
| `Backend::capture_message` | modified | `public function capture_message( string $msg, string $level, array $tags, array $fingerprint, array $known, array $contexts ): ?string` | no |
| `SentryBackend::capture` | modified | `private function capture( array $tags, array $fingerprint, array $known, array $contexts, callable $send ): ?string`: trong `withScope`, gọi `$scope->setContext( $name, $ctx )` cho mỗi context | no |
| `InvoiceContext` | new | `final class InvoiceContext` (namespace `LTTech\EinvoiceMatbao\Monitoring`):<br>• `public static function for_invoice( InvoiceRecord $invoice ): array{order_id:int,invoice_id:int,reference:string,status:string,error_code:string,label:string,hint:string,status_reason:string,admin_path:string}`: `label`/`hint` là `''` khi mã lỗi không hợp lệ; `admin_path` là `''` khi không có order<br>• `public static function admin_path( string $url ): string`<br>• `public static function for_order( int $order_id ): array`: trả `[]` khi không có hóa đơn, ngược lại `['invoice' => for_invoice(...)]` | no |
| `ActionSchedulerTracing::$runs` | modified | `array<int, array{recovery:bool, check_in:?string, started:float, hook:string, order_id:?int}>`; `order_id` là `args[0]` khi đó là int > 0, ngược lại null | no |
| `Scrubber::TAG_ALLOWLIST` | modified | thêm `'product_id'`, `'matbao.api_code'` | no |
| Lark message format | modified | `Hóa đơn cần xử lý – Đơn #%1$d – %2$s (%3$s) – Cách xử lý: %4$s – %5$s` (order, label, code, hint, link) | no |

---

## Task 1: Resolution hint — implements 1

**Covers:** 1.1, 1.2
**Difficulty:** Easy
**Blocked by:** none

**Files:**
- Modify: `src/Invoice/InvoiceErrorCode.php`
- Test: `tests/integration/InvoiceErrorCodeTest.php`

**Interfaces:**
- Produces: `InvoiceErrorCode::hint(): string`

- [ ] **Step 1: Viết test fail.**
  - `test_every_code_has_a_hint`: với mỗi `InvoiceErrorCode::cases()`, assert `'' !== trim( $c->hint() )`.
  - `test_data_error_hint`: hint của `LINE_TAX_RATE_COUNT` chứa `thuế` và `Gửi lại`.
  - `test_config_error_hint`: hint của `CREDENTIALS_MISSING` chứa `Kết nối Mắt Bão` và `Kiểm tra kết nối`.
  - `test_uncertain_send_hint`: hint của `UNEXPECTED` chứa `Kiểm tra trạng thái` và `Gửi lại`, và vị trí `Kiểm tra trạng thái` đứng trước `Gửi lại`.
- [ ] **Step 2:** Chạy `einv composer test:integration -- --filter InvoiceErrorCodeTest`. Kỳ vọng: FAIL, `hint()` chưa được định nghĩa.
- [ ] **Step 3:** Cài `hint()` bằng `match` có đủ case, câu chữ lấy từ bảng ở design D1, mỗi câu bọc trong `__( …, 'einvoice-matbao' )`.
- [ ] **Step 4:** Chạy lại lệnh ở Step 2. Kỳ vọng: PASS.
- [ ] **Step 5:** Commit `feat(failure-alerts): add resolution hint per invoice error code`.

---

## Task 2: Line details on line data errors — implements 2

**Covers:** 2.1, 2.2, 2.3
**Difficulty:** Normal
**Blocked by:** none

**Files:**
- Create: `src/Invoice/LineFailure.php`
- Modify: `src/Invoice/InvoiceDataError.php`, `src/Invoice/DraftPayloadBuilder.php` (`line()`, `rate()`, `whole_vnd()`, `fail()`)
- Test: `tests/integration/DraftPayloadLineFailureTest.php` (mới, extends `InvoiceWorkflowTestCase`; cách ép totals mismatch theo `DraftInvoiceWorkflowTest.php:256-266`)

**Interfaces:**
- Produces: `LineFailure`, `InvoiceDataError::$line` (xem Contracts).

- [ ] **Step 1: Viết test fail.** Mỗi test gọi `DraftPayloadBuilder::build()` trực tiếp và bắt `InvoiceDataError`.
  - `test_two_tax_rates_on_product_line`: sản phẩm SKU `AO-01`, dòng có thuế ở 2 rate ID. Assert:
    - `error_code === LINE_TAX_RATE_COUNT`;
    - `line->item_type === 'line_item'`;
    - `line->product_id` bằng ID sản phẩm, `line->sku === 'AO-01'`;
    - `line->tax_rate_ids` bằng hai rate ID đã tạo;
    - `getMessage()` bằng `Dòng "<tên>" cần đúng một thuế suất GTGT.`
  - `test_shipping_line_with_fractional_amount`: dòng vận chuyển 30000.5. Assert:
    - `error_code === AMOUNT_NOT_WHOLE_VND`;
    - `line->item_type === 'shipping'`;
    - `line->product_id === 0` và `line->sku === ''`.
  - `test_fractional_amount_keeps_tax_rate_ids`: dòng sản phẩm giá lẻ đồng, có một thuế suất đã lưu. Assert `error_code === AMOUNT_NOT_WHOLE_VND` và `line->tax_rate_ids` chứa rate ID đó.
  - `test_totals_mismatch_has_no_line`: ép tổng lệch (sửa `total` của đơn sau khi tính). Assert `error_code === TOTALS_MISMATCH` và `null === $e->line`.
- [ ] **Step 2:** Chạy `einv composer test:integration -- --filter DraftPayloadLineFailureTest`. Kỳ vọng: FAIL.
- [ ] **Step 3: Cài đặt.**
  - Tạo `LineFailure`. `from_item()` đọc `get_id()`, `get_type()`, và với `WC_Order_Item_Product` thì đọc thêm `get_product_id()`, `get_variation_id()`, SKU của product.
  - `InvoiceDataError` nhận thêm `?LineFailure $line = null`.
  - `DraftPayloadBuilder::line()` dựng `$failure = LineFailure::from_item( $item )` **một lần ở đầu hàm**, trước mọi validation (thứ tự validation và lý do giữ nguyên).
  - Đổi chữ ký `fail()`, `rate()`, `whole_vnd()` theo Contracts; mọi lời gọi `fail()` theo dòng truyền `$failure`.
- [ ] **Step 4:** Chạy lại lệnh ở Step 2, sau đó chạy `--filter DraftInvoiceWorkflowTest` để kiểm tra không có regression ở lý do lưu. Kỳ vọng: PASS.
- [ ] **Step 5:** Commit `feat(draft-invoice-creation): carry line details on line data errors`.

---

## Task 3: Event context plumbing — implements 3

**Covers:** 3.1, 3.2, 3.3, 3.4
**Difficulty:** Hard
**Blocked by:** none

**Files:**
- Create: `src/Monitoring/FailureDetail.php`
- Modify: `src/Monitoring/Backend.php`, `src/Monitoring/SentryBackend.php` (`capture_exception`, `capture_message`, `capture`), `src/Monitoring/Monitor.php`, `src/Monitoring/Scrubber.php`
- Modify tests: `tests/integration/Monitoring/MonitorTest.php` (hai anonymous `Backend`), `tests/integration/Monitoring/SdkBoundaryTest.php` (nếu có cài đặt `Backend`)
- Test: `tests/integration/Monitoring/EventContextTest.php` (mới)

**Interfaces:**
- Consumes: `LineFailure` (Task 2) chỉ qua `FailureDetail::for_line`. Nếu Task 2 chưa merge thì task này dùng `FailureDetail` dựng tay, `for_line` được thêm ở Task 5.
- Produces: `Backend` mới, `FailureDetail` (constructor + `for_matbao`), `Monitor::with_failure_detail`, `Monitor::failure_detail_for`, `Monitor::capture_exception` mới (xem Contracts).

> Ghi chú phụ thuộc: tasks.md không cho group 3 phụ thuộc group 2. Vì vậy `FailureDetail::for_line()` được tạo ở Task 5, nơi đã có cả hai; Task 3 chỉ tạo constructor và `for_matbao()`.

- [ ] **Step 1: Viết test fail** trong `EventContextTest`, dùng `SentrySink` và `MonitoringFixture` theo mẫu của `MonitorRedactionTest`.
  - `test_exception_contexts_are_sent_and_masked`: `Monitor::capture_exception( new RuntimeException('x'), array(), static fn(): array => array( 'probe' => array( 'email' => 'a@b.vn', 'n' => 5 ) ) )` → event có `contexts.probe.n === 5`, `contexts.probe.email === '[filtered]'`.
  - `test_context_closure_failure_never_escapes`: closure ném `RuntimeException` → `capture_exception` trả null, không ném, `InternalFailure` ghi log.
  - `test_new_filter_tags_kept`: capture với tag `product_id` = `'55'` và `matbao.api_code` = `'306'`. Event giữ nguyên cả hai.
  - `test_filter_wins_over_new_tag`: tag `product_id` = `'1234567890'`. Event có tag đó với giá trị `[filtered]`.
  - `test_failure_detail_scoped_to_callback`: trong `with_failure_detail( new FailureDetail( 10, LINE_TAX_RATE_COUNT, array( 'line' => array( 'item_id' => 1 ) ), array() ), fn )`, `failure_detail_for( 10, LINE_TAX_RATE_COUNT )` trả detail đó; `failure_detail_for( 10, MATBAO_HTTP )` và `failure_detail_for( 11, LINE_TAX_RATE_COUNT )` trả null; sau callback đều null.
  - `test_reentrant_other_order_same_code`: detail của đơn 10 đang mở, bên trong mở detail đơn 11 cùng mã → `failure_detail_for( 11, … )` là của 11, `failure_detail_for( 10, … )` vẫn là của 10; sau callback trong, 10 còn nguyên.
  - `test_nested_failure_detail_innermost_wins_and_unwinds`: lồng hai detail cùng mã. Bên trong nhận detail trong cùng. Khi callback trong cùng ném lỗi, sau `catch` detail ngoài còn lại.
- [ ] **Step 2:** Chạy `einv composer test:integration -- --filter EventContextTest`. Kỳ vọng: FAIL.
- [ ] **Step 3: Cài đặt.**
  - Thêm `array $contexts` vào hai hàm của `Backend`.
  - `SentryBackend::capture()` nhận thêm `$contexts`, và trong `withScope` gọi `$scope->setContext( $name, $ctx )` cho từng context.
  - `Monitor::capture_exception` gọi closure `$contexts` bên trong closure của `self::capture` (đã có guard) rồi truyền mảng xuống backend. `capture_needs_attention` tạm truyền `array()`, Task 4 sẽ thay.
  - `failure_detail_for` duyệt stack từ trong ra ngoài, trả phần tử đầu tiên khớp cả `order_id` và `code`.
  - Stack `private static array $failure_details` cùng `with_failure_detail()` theo mẫu của `with_order_context()`.
  - Thêm `product_id` và `matbao.api_code` vào allowlist của `Scrubber`.
  - Sửa hai test double cho đúng chữ ký mới.
- [ ] **Step 4:** Chạy lại lệnh ở Step 2, sau đó chạy `--filter Monitoring`. Kỳ vọng: PASS toàn bộ thư mục Monitoring.
- [ ] **Step 5:** Commit `feat(error-monitoring): send structured contexts and scope failure details`.

---

## Task 4: Needs-attention event content — implements 4

**Covers:** 4.1, 4.2, 4.3
**Difficulty:** Normal
**Blocked by:** Task 1, Task 3

**Files:**
- Create: `src/Monitoring/InvoiceContext.php`
- Modify: `src/Monitoring/Monitor.php` (`capture_needs_attention`), `tests/integration/Monitoring/WorkflowMonitoringTest.php` (assert message cũ)
- Test: `tests/integration/Monitoring/NeedsAttentionEventTest.php` (mới, extends `InvoiceWorkflowTestCase` + `MonitoringFixture`)

**Interfaces:**
- Consumes: `InvoiceErrorCode::hint()` (Task 1), `Monitor::with_failure_detail` / `FailureDetail` (Task 3).
- Produces: `InvoiceContext::for_invoice()`, `InvoiceContext::admin_path()`.

- [ ] **Step 1: Viết test fail.**
  - `test_readable_title`: đơn dừng với `line_tax_rate_count`. Message của event là `Hóa đơn cần xử lý: Dòng không có đúng một thuế suất – Đơn #<id>`.
  - `test_invoice_context_present`: kiểm tra `contexts.invoice`:
    - `order_id` bằng ID đơn;
    - `hint` bằng `InvoiceErrorCode::LINE_TAX_RATE_COUNT->hint()`;
    - `admin_path` bắt đầu bằng `/` và không chứa `://`.
  - `test_admin_path_without_sensitive_parameters`: `InvoiceContext::admin_path( 'https://shop.vn/wp-admin/admin.php?page=wc-orders&action=edit&id=42&_wpnonce=abc#x' )` trả `/wp-admin/admin.php?page=wc-orders&action=edit&id=42`. Với URL `post.php?post=42&action=edit` thì giữ `post` và `action`.
  - `test_alert_without_failure_detail`: dùng seam `pre_as_enqueue_async_action` → 0 và gọi `recover_stuck()` hai lần như `BlockedDraftInvoiceTest.php:392-405`. Hóa đơn dừng với `queue_failed`; event có `contexts.invoice`, không có key `line` hay `matbao`.
  - `test_context_building_fails_keeps_invoice_outcome`, data provider hai case. Mỗi case chạy hai lần trên hai đơn mới: lần đầu giám sát tắt; lần sau giám sát bật, kèm filter `woocommerce_get_edit_order_url` vừa đếm số lần gọi vừa ném `RuntimeException`. So sánh, sau khi bỏ order/invoice ID:
    - `status` và `error_code`;
    - số action pending của hook `einvoice_matbao_create_draft` cho đơn đó;
    - có alert trong `AlertStore` hay không.
    
    Hai case:
    - `final`: create-invoice trả HTTP 400, hóa đơn dừng với `matbao_http`.
    - `retries_exhausted`: create-invoice luôn trả 503; chạy hết các lần retry (`run_queued_draft_actions` cho tới khi không còn action, theo mẫu trong `DraftInvoiceRetryTest`). Hóa đơn thành `needs_attention`, không còn retry nào được xếp.
    
    Ở lần chạy có giám sát, assert bộ đếm của filter > 0, để chứng minh lỗi dựng context thật sự đã xảy ra, và không có exception lọt ra ngoài. Đường retry đang chờ (503 lần đầu, hóa đơn `pending`) không dựng invoice context; trường hợp đó đã được phủ bởi `DraftInvoiceRetryTest` hiện có.
  - `test_invoice_context_without_order_has_no_admin_path`: `InvoiceContext::for_invoice()` với order ID không tồn tại trả `admin_path === ''`.
  - Giữ các test sẵn có `Same error code on two orders` và `Needs attention once per alert` trong `WorkflowMonitoringTest`. Sửa assert của các test đó nếu chúng kiểm message cũ.
- [ ] **Step 2:** Chạy `einv composer test:integration -- --filter 'NeedsAttentionEventTest|WorkflowMonitoringTest'`. Kỳ vọng: FAIL.
- [ ] **Step 3: Cài đặt.**
  - `InvoiceContext::for_order()`, gồm cả lookup `InvoiceRepository::find_by_order`, được tạo ở task này. `InvoiceContext::for_invoice()` đọc order qua `wc_get_order`. Không có order thì `admin_path = ''`.
  - `capture_needs_attention` dựng context bên trong closure của `self::capture` (đã có guard), gộp context `invoice` với `$detail->contexts`, và tags với `$detail->tags`, khi `failure_detail_for( $i->order_id, $c )` khác null.
- [ ] **Step 4:** Chạy lại lệnh ở Step 2. Kỳ vọng: PASS.
- [ ] **Step 5:** Commit `feat(error-monitoring): readable needs-attention events with invoice context`.

---

## Task 5: Workflow passes failure detail — implements 5

**Covers:** 5.1, 5.2
**Difficulty:** Hard
**Blocked by:** Task 2, Task 4

**Files:**
- Modify: `src/Invoice/DraftInvoiceWorkflow.php` (hai nhánh `catch ( InvoiceDataError $error )` tại `:145`, `:438`; `fail()` tại `:735` tách thêm private method), `src/Monitoring/FailureDetail.php` (thêm `for_line`)
- Test: `tests/integration/Monitoring/WorkflowFailureDetailTest.php` (mới)

**Interfaces:**
- Consumes: `LineFailure`, `InvoiceDataError::$line` (Task 2); `FailureDetail`, `Monitor::with_failure_detail` (Task 3); needs-attention có context (Task 4).

- [ ] **Step 1: Viết test fail.**
  - `test_tax_rate_count_error_on_product_line`: sản phẩm SKU `AO-01` có hai thuế suất, `complete_and_run`. Event có:
    - `contexts.line.product_id` bằng ID sản phẩm, `contexts.line.sku === 'AO-01'`;
    - `count( contexts.line.tax_rate_ids ) === 2`;
    - tag `product_id` bằng ID sản phẩm.
  - `test_mat_bao_rejection`: fake create-invoice trả data có `errorCode` 306. Event có:
    - `contexts.matbao.kind === 'api'`, `api_code === 306`, `target_sent === true`;
    - tag `matbao.api_code === '306'`.
  - `test_transport_error_has_no_api_code_tag`: create-invoice timeout cho tới khi hết retry. Event cuối có `contexts.matbao.api_code === null` và không có tag `matbao.api_code`.
  - `test_product_name_equals_buyer_name`: tên sản phẩm trùng tên người mua. Body thô của request tới sink không chứa tên đó, và `contexts.line.name === '[filtered]'`.
  - `test_fee_line_failure_has_no_product_tag`: dòng phí có số tiền lẻ. Event không có tag `product_id`.
  - `test_detail_does_not_leak_to_another_order`: chạy hai đơn trong cùng request, A có hai thuế suất, B nhận Mắt Bão HTTP 400. Event của B không có `contexts.line`.
  - `test_post_draft_warning_during_failure`: trong `with_failure_detail( LINE_TAX_RATE_COUNT … )`, phát `order_amounts_changed` qua `OrderChangeWatcher`. Event của cảnh báo không có `contexts.line`.
- [ ] **Step 2:** Chạy `einv composer test:integration -- --filter WorkflowFailureDetailTest`. Kỳ vọng: FAIL.
- [ ] **Step 3: Cài đặt.**
  - `FailureDetail::for_line()`.
  - Trong workflow, thêm `record_data_error` (xem Contracts), dùng ở hai nhánh catch: nhánh `:145` gọi với `( $repository, $order_id, $error, null, $error->error_code->kind()->status() )` thay cho `record_error( …, null, $error->error_code->kind()->status() )` hiện có, và nhánh `:438` gọi với `( $this->repository, $order_id, $error, null, InvoiceStatus::SENDING )`. Đối số giữ đúng như code hiện tại; khi `$error->line` khác null, bọc `record_error` bằng `Monitor::with_failure_detail( FailureDetail::for_line( $order_id, $error->error_code, $error->line ), … )`; nếu không thì gọi thẳng.
  - Tách phần sau khi đã có `$code` và `$reason` trong `fail()` thành `private function record_matbao_failure( InvoiceRecord $invoice, InvoiceErrorCode $code, string $reason ): void` (nhánh final, hết retry, lên lịch retry; thân giữ nguyên). `fail()` chỉ còn: finish attempt, tính `$code`, `$reason` (gồm xử lý template rejection), rồi `Monitor::with_failure_detail( FailureDetail::for_matbao( $invoice->order_id, $code, $error ), fn() => $this->record_matbao_failure( $invoice, $code, $reason ) )`. Closure chỉ delegate.
- [ ] **Step 4:** Chạy lại lệnh ở Step 2, sau đó chạy `--filter 'DraftInvoice|InvoiceAlert'`. Kỳ vọng: PASS.
- [ ] **Step 5:** Commit `feat(error-monitoring): attach line and Mat Bao details to needs-attention events`.

---

## Task 6: Exception context — implements 6

**Covers:** 6.1, 6.2, 6.3
**Difficulty:** Normal
**Blocked by:** Task 3, Task 4

**Files:**
- Modify: `src/Monitoring/CaughtFailure.php`, `src/Monitoring/ActionSchedulerTracing.php` (`on_begin`, `on_failed`, mảng `$runs`), `tests/integration/Monitoring/WorkflowMonitoringTest.php`
- Test: `tests/integration/Monitoring/ExceptionContextTest.php` (mới)

**Interfaces:**
- Consumes: `Monitor::capture_exception( $e, $tags, $contexts )`, `InvoiceContext::for_invoice()`.

- [ ] **Step 1: Viết test fail.**
  - `test_unexpected_error_while_sending`: mở rộng `WorkflowMonitoringTest::test_unexpected_error_reported` (seam `FakeHttp::respond_with( CREATE_ROUTE, fn ném RuntimeException )`) để assert thêm `contexts.invoice.order_id`.
  - `test_caught_failure_without_order_still_sent`: `CaughtFailure::report( new RuntimeException('x'), 'probe', 'probe failed' )` không có order → có đúng một event lỗi, không có `contexts.invoice`.
  - `test_caught_failure_context_failure_keeps_unexpected`: cùng seam, cộng filter `woocommerce_get_edit_order_url` ném lỗi → hóa đơn vẫn `failed`/`unexpected`, không exception lọt ra.
  - `test_action_scheduler_action_throws`: theo mẫu `TracingTest::test_failed_action_marks_internal_error` (`TracingTest.php:80-101`): `add_action( DraftInvoiceWorkflow::ACTION, fn ném, 5 )`, `as_enqueue_async_action( ACTION, array( $order_id ), GROUP )`, `ActionScheduler::runner()->process_action( $id, 'einv-test' )`. Event có tag `order_id`, `contexts.action.hook === 'einvoice_matbao_create_draft'`, `contexts.action.action_id === $id`.
    - tag `order_id`;
    - `contexts.action.hook === 'einvoice_matbao_create_draft'`;
    - `contexts.action.action_id` là int dương.
  - `test_action_scheduler_exception_echoes_buyer`: giống test trên, nhưng message của exception chứa tên và địa chỉ billing của đơn. Body thô không chứa hai giá trị đó.
  - `test_action_without_an_order`: action `einvoice_matbao_recover_stuck_invoices` ném lỗi. Event có `contexts.action` và không có tag `order_id`.
- [ ] **Step 2:** Chạy `einv composer test:integration -- --filter ExceptionContextTest`. Kỳ vọng: FAIL.
- [ ] **Step 3: Cài đặt.**
  - `CaughtFailure::report`: khi `null !== $order_id` thì `$contexts = static fn(): array => InvoiceContext::for_order( $order_id )`, ngược lại `$contexts = null`. Truyền vào `Monitor::capture_exception( $failure, $tags, $contexts )`. Không tra repository trực tiếp trong `$send`; closure chỉ được gọi bên trong guard.
  - `ActionSchedulerTracing::on_begin` lưu `hook` và `order_id` (từ `$action->get_args()[0]` khi là int > 0, ngược lại null) vào `$runs` theo shape trong Contracts.
  - `on_failed`: tags = `component` (+ `order_id` khi có); `$contexts = static fn(): array => array( 'action' => array( 'hook' => $run['hook'], 'action_id' => (int) $action_id ) ) + ( null === $order_id ? array() : InvoiceContext::for_order( $order_id ) )` (closure, chạy trong guard). Gọi `Monitor::capture_exception( $e, $tags, $contexts )` bên trong `Monitor::with_order_context( $order_id, … )` khi có đơn, nếu không thì gọi trực tiếp.
- [ ] **Step 4:** Chạy lại lệnh ở Step 2, sau đó chạy `--filter 'Tracing|WorkflowMonitoring'`. Kỳ vọng: PASS.
- [ ] **Step 5:** Commit `feat(error-monitoring): order and action context on caught exceptions`.

---

## Task 7: Hint in Lark and admin UI — implements 7

**Covers:** 7.1, 7.2, 7.3
**Difficulty:** Easy
**Blocked by:** Task 1

**Files:**
- Modify: `src/Alert/InvoiceAlerts.php` (`message()`), `src/Alert/AdminAlertNotice.php` (`render`), `src/Admin/OrderInvoiceMetaBox.php`, `src/Admin/InvoicePage.php` (bảng chi tiết, gần `:233`)
- Test: `tests/integration/InvoiceAlertTest.php`, `tests/integration/OrderInvoiceMetaBoxTest.php`, `tests/integration/InvoiceAdminListRestTest.php`

**Interfaces:**
- Consumes: `InvoiceErrorCode::hint()`.

- [ ] **Step 1: Viết test fail.**
  - Trong `InvoiceAlertTest`:
    - sửa test "Final error" để text Lark chứa `$code->hint()`;
    - sửa test "Administrator sees an error" để notice chứa `class="einv-hint"` cùng hint;
    - test "No personal data" giữ nguyên.
  - `OrderInvoiceMetaBoxTest::test_error_with_hint`: hóa đơn dừng với `line_tax_rate_count`. HTML chứa `data-einv-hint` và hint đã escape.
  - `InvoiceAdminListRestTest`: sửa test "Detail shown" để HTML chi tiết chứa hint của mã lỗi.
- [ ] **Step 2:** Chạy `einv composer test:integration -- --filter 'InvoiceAlertTest|OrderInvoiceMetaBoxTest|InvoiceAdminListRestTest'`. Kỳ vọng: FAIL.
- [ ] **Step 3: Cài đặt.**
  - Đổi format Lark sang dạng trong Contracts, có cập nhật comment `translators`.
  - Notice: thêm `<span class="einv-hint">%s</span>` sau nhãn.
  - Meta box và chi tiết: thêm dòng "Cách xử lý" có `data-einv-hint`, nội dung qua `esc_html`.
- [ ] **Step 4:** Chạy lại lệnh ở Step 2. Kỳ vọng: PASS.
- [ ] **Step 5:** Commit `feat(failure-alerts): show resolution hint in Lark, notices and invoice screens`.

---

## Task 8: Docs and end-to-end verification — implements 8

**Covers:** 8.1, 8.2, 8.3
**Difficulty:** Normal
**Blocked by:** Task 5, Task 6, Task 7

**Files:**
- Modify: `docs/phat-trien.md` (phần giám sát lỗi, cảnh báo, `SentrySink`), `docs/huong-dan-su-dung.md` (phần cảnh báo và giám sát lỗi)
- Test: `tests/integration/Monitoring/SameHintEverywhereTest.php` (mới)

- [ ] **Step 1:** Cập nhật docs, viết tiếng Việt:
  - danh sách context và tag mới;
  - hướng dẫn xử lý hiện ở đâu;
  - format tin Lark mới.
- [ ] **Step 2:** Viết `tests/integration/Monitoring/SameHintEverywhereTest.php::test_same_hint_everywhere`, với giám sát bật và Lark đã cấu hình. Cho một đơn dừng với `line_tax_rate_count`, chạy action gửi Lark, rồi assert chuỗi `InvoiceErrorCode::LINE_TAX_RATE_COUNT->hint()` có mặt ở cả 5 nơi:
  - text Lark;
  - HTML của `AdminAlertNotice::render`;
  - HTML của meta box;
  - fragment chi tiết hóa đơn;
  - `contexts.invoice.hint` của event.

  Chạy `einv composer test:integration -- --filter SameHintEverywhereTest`. Kỳ vọng: PASS, vì các Task 4 và 7 đã cài phần này.
- [ ] **Step 3:** Chạy `einv composer check`. Kỳ vọng: lint, analyse, floor guard và cả hai bộ test đều PASS.
- [ ] **Step 4: E2E trên site local đang nối Rustrak** (project 3).
  - Tạo sản phẩm có lớp thuế sinh ra hai thuế suất, tạo đơn rồi chuyển sang completed.
  - Dùng MCP `rustrak.list_issues` / `get_event` để kiểm tra event mới:
    - message `Hóa đơn cần xử lý: … – Đơn #<id>`;
    - có `contexts.invoice` (kèm `hint`, `admin_path`) và `contexts.line` (kèm SKU);
    - có tag `product_id`;
    - event vẫn gom vào issue `EINVOICE-MATBAO-1`.
  - Nếu có webhook Lark thì kiểm tra tin có hướng dẫn.
  - Nếu môi trường không có Rustrak hoặc Lark, ghi rõ giới hạn vào `apply.md`.
- [ ] **Step 5:** Commit `docs: describe monitoring event context and resolution hints`.

---

## Scenario → test

- Every code has a hint → `InvoiceErrorCodeTest::test_every_code_has_a_hint`
- Data error hint → `InvoiceErrorCodeTest::test_data_error_hint`
- Config error hint → `InvoiceErrorCodeTest::test_config_error_hint`
- Uncertain send hint → `InvoiceErrorCodeTest::test_uncertain_send_hint`
- Same hint everywhere → `SameHintEverywhereTest::test_same_hint_everywhere` (Task 8)
- Administrator sees an error → `InvoiceAlertTest` (test notice hiện có, thêm assert hint)
- User without capability → `InvoiceAlertTest` (test hiện có, không đổi)
- Final error → `InvoiceAlertTest` (test Lark hiện có, thêm assert hint)
- No personal data → `InvoiceAlertTest` (test hiện có, không đổi)
- Detail shown → `InvoiceAdminListRestTest` (test chi tiết hiện có, thêm assert hint)
- Unknown invoice → `InvoiceAdminListRestTest` (test hiện có, không đổi)
- Draft created → `OrderInvoiceMetaBoxTest` (test hiện có, không đổi)
- Error with hint → `OrderInvoiceMetaBoxTest::test_error_with_hint`
- No invoice → `OrderInvoiceMetaBoxTest` (test hiện có, không đổi)
- Skipped order → `OrderInvoiceMetaBoxTest` (test hiện có, không đổi)
- Invoice context present → `NeedsAttentionEventTest::test_invoice_context_present`
- Admin path without sensitive parameters → `NeedsAttentionEventTest::test_admin_path_without_sensitive_parameters`
- Alert without failure detail → `NeedsAttentionEventTest::test_alert_without_failure_detail`
- Context building fails → `NeedsAttentionEventTest::test_context_building_fails_keeps_invoice_outcome` + `ExceptionContextTest::test_caught_failure_context_failure_keeps_unexpected`
- Tax rate count error on a product line → `WorkflowFailureDetailTest::test_tax_rate_count_error_on_product_line`
- Product name equals buyer name → `WorkflowFailureDetailTest::test_product_name_equals_buyer_name`
- Mat Bao rejection → `WorkflowFailureDetailTest::test_mat_bao_rejection`
- Detail does not leak to another order → `WorkflowFailureDetailTest::test_detail_does_not_leak_to_another_order` + `EventContextTest::test_reentrant_other_order_same_code`
- Post-draft warning during a failure → `WorkflowFailureDetailTest::test_post_draft_warning_during_failure`
- Unexpected error while sending → `WorkflowMonitoringTest::test_unexpected_error_reported` (mở rộng ở Task 6)
- Action Scheduler action throws → `ExceptionContextTest::test_action_scheduler_action_throws`
- Action Scheduler exception echoes the buyer → `ExceptionContextTest::test_action_scheduler_exception_echoes_buyer`
- Action without an order → `ExceptionContextTest::test_action_without_an_order`
- Needs attention once per alert → `WorkflowMonitoringTest` (test hiện có, cập nhật message)
- Readable title → `NeedsAttentionEventTest::test_readable_title`
- Same error code on two orders → `WorkflowMonitoringTest` (test hiện có, cập nhật message)
- Extra tag dropped → `ScrubberTest` (allowlist, `ScrubberTest.php:176`) + `MonitorTest::test_capture_exception_scrubbed` (tag `site`), hiện có
- New filter tags kept → `EventContextTest::test_new_filter_tags_kept`
- Filter wins over a new tag → `EventContextTest::test_filter_wins_over_new_tag`
- No server name → `MonitorTest::test_capture_exception_scrubbed` (hiện có, không đổi)
- Two tax rates on a product line → `DraftPayloadLineFailureTest::test_two_tax_rates_on_product_line`
- Shipping line with fractional amount → `DraftPayloadLineFailureTest::test_shipping_line_with_fractional_amount` (+ `test_fractional_amount_keeps_tax_rate_ids`)
- Totals mismatch → `DraftPayloadLineFailureTest::test_totals_mismatch_has_no_line`
