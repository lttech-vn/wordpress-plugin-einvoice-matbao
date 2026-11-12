# Tài liệu phát triển

Tài liệu cho **developer**: môi trường phát triển, kiến trúc, quy tắc nghiệp vụ ở mức code, kiểm thử, CI và phát hành.

- **Cần biết trước:** WordPress plugin, WooCommerce (HPOS, Action Scheduler, Checkout Blocks), Docker Compose.
- **Không bao gồm:** hướng dẫn cho người dùng cuối. Xem [Hướng dẫn sử dụng](huong-dan-su-dung.md).
- **Nguồn quyết định:** [`discovery.md`](discovery.md), [`GLOSSARY.md`](../GLOSSARY.md), [`adr/`](adr/), living specs trong [`openspec/specs/`](../openspec/specs/).

## Môi trường phát triển

Cần:

- Docker với Compose v2. PHP, Composer, WP-CLI, WordPress và WooCommerce đều chạy trong container.
- [bun](https://bun.sh) để build CSS/JS admin và checkout.

Phiên bản PHP trên máy host không quan trọng; **không chạy Composer hay PHPUnit trên host**.

```sh
cp .env.example .env          # đặt COMPOSE_PROJECT_NAME và EINV_HTTP_PORT riêng cho mỗi worktree
docker compose up -d --build --wait
docker compose exec -T wordpress einv-setup
```

`einv-setup` idempotent: chạy `composer install`, cài WordPress, cài WooCommerce theo phiên bản ghim, kích hoạt WooCommerce và plugin. Site ở `http://localhost:$EINV_HTTP_PORT/wp-admin` (user `admin`, mật khẩu `admin`; chỉ dùng local).

Dừng bằng `docker compose down` (thêm `-v` để xóa database và volume WordPress).

## Kiểm thử, lint và phân tích tĩnh

Một lệnh chạy toàn bộ (tự khởi động stack nếu cần):

```sh
./scripts/check.sh          # tổ hợp latest
./scripts/check.sh --min    # tổ hợp tối thiểu
```

Từng bước, trong stack đang chạy:

```sh
alias einv='docker compose exec -T -w /var/www/html/wp-content/plugins/einvoice-matbao wordpress'
einv composer lint                      # PHPCS, chuẩn WordPress (phpcs.xml.dist)
einv composer analyse                   # PHPStan level 8 với stub WordPress/WooCommerce
einv composer test:integration          # PHPUnit trên WordPress + WooCommerce thật
einv composer test:without-woocommerce  # PHPUnit khi WooCommerce không được nạp
```

Các bộ PHPUnit dùng database riêng `wordpress_test`, không đụng site dev.

## Asset admin và checkout

```sh
bun install --frozen-lockfile
bun run build      # assets/dist/admin.css (Tailwind) + assets/dist/htmx.min.js (vendored) + bundle checkout
bun run watch:css  # build lại CSS khi sửa
bun run test       # test quy tắc checkout phía trình duyệt
```

- Utility Tailwind được scope dưới `#einv-app`, không dùng preflight, nên style wp-admin không bị ảnh hưởng. `bun run check:css-scope` (chạy trong CI) báo lỗi nếu build sinh rule không có scope.
- Design token (`einv-*`) lấy từ Stripe `DESIGN.md` trong VoltAgent/awesome-design-md.
- htmx copy từ package npm đã ghim; không nạp gì từ CDN.
- `assets/dist/` bị gitignore, CI build lại; `scripts/build-zip.sh` chạy build cho gói phát hành.

## Kiến trúc tổng quan

```text
 Checkout (Classic / Blocks)          wp-admin (htmx + REST einvoice-matbao/v1)
          |                                         |
          v                                         v
  BuyerRules + BuyerRequestStore          SettingsController / InvoiceController
  (order meta, CCCD mã hóa)                        |
          |                                         |
  Đơn -> completed --> DraftInvoiceWorkflow::reserve (bảng invoice, UNIQUE(order_id))
                              |
                    Action Scheduler: einvoice_matbao_create_draft
                              |
                       DraftPayloadBuilder --> MatbaoClient --> API Mắt Bão
                              |
                 InvoiceRepository (invoice + attempt) --> InvoiceAlerts --> admin notice / Lark
```

### Cài đặt và credential

Plugin có một màn hình duy nhất **WooCommerce → Hóa đơn Mắt Bão** (chỉ Administrator), chia 5 tab: **Hóa đơn** (mặc định), **Kết nối Mắt Bão**, **Xuất hóa đơn**, **Cảnh báo Lark**, **Nâng cao**. Registry tab ở `AdminTabs`; chuyển tab bằng htmx qua route `GET tabs/{tab}` (trả tab bar + panel, `hx-push-url` cập nhật `&tab=`), mở trực tiếp URL có `tab` thì server render đúng tab, key lạ quay về tab Hóa đơn. Slug cũ `einvoice-matbao-invoices` là trang ẩn chuyển hướng 302 sang `&tab=invoices`. CSS admin của WordPress không nằm trong `@layer` nên thắng utility Tailwind; link tab dùng utility `!important` (`!text-…`, `focus-visible:!outline-…`) để giữ màu và vòng focus. Credential Mắt Bão và webhook Lark được mã hóa (libsodium secretbox) bằng khóa lấy từ hằng trong `wp-config.php`:

```php
define( 'EINVOICE_MATBAO_ENCRYPTION_KEY', '<chuỗi ngẫu nhiên, tối thiểu 32 ký tự>' );
```

- Thiếu hằng thì plugin từ chối lưu credential và hiện thông báo.
- Đổi hoặc xóa khóa làm credential đã lưu không đọc được; phải nhập lại sau khi xoay khóa.
- Secret không bao giờ được render lại trên UI. Access token Mắt Bão được cache mã hóa trong transient (tối đa một giờ; demo cấp token 24 giờ theo giờ Việt Nam, `MatbaoClient::cache_token` đọc `expiredDate` theo `Asia/Ho_Chi_Minh`).
- Trang giao tiếp với REST namespace `einvoice-matbao/v1` qua htmx. Mọi route trả HTML fragment, yêu cầu `manage_options` và nonce `wp_rest` trong header `X-WP-Nonce` (ADR [0003](adr/0003-htmx-tailwind-admin-over-rest.md)).
- `EINVOICE_MATBAO_BASE_URL` trong `wp-config.php` chuyển mọi request Mắt Bão sang base URL khác (sandbox, proxy, test double). Giá trị không phải URL http(s) bị bỏ qua.

### Tạo nháp khi đơn hoàn thành

Khi đơn chuyển sang `completed` (chỉ lúc plugin đang bật; đơn cũ không được quét), plugin:

1. Giữ chỗ một dòng trong `{prefix}einvoice_matbao_invoice` (`UNIQUE(order_id)`, `MTChieu` cố định = prefix + `WC` + order ID).
2. Xếp một action Action Scheduler `einvoice_matbao_create_draft` (group `einvoice-matbao`).
3. Action claim dòng (`pending -> sending`) và gọi `create-invoice` với `LoaiHDon=0` (nháp) và `KTraMTChieuTrung=1`. Mỗi lần gọi được ghi vào `{prefix}einvoice_matbao_attempt`.

Trạng thái, `MTChieu`, mã hóa đơn Mắt Bão và lỗi hiện trong hộp **Hóa đơn Mắt Bão** ở màn hình đơn (HPOS và legacy). Lý do tách bảng invoice/attempt và order meta: ADR [0002](adr/0002-invoice-tables-and-order-meta-split.md).

Payload dùng số tiền và thuế đã lưu trên từng dòng đơn: giá sau giảm, fee và shipping là dòng riêng, `TSuat` 0/5/8/10 hoặc -1 khi tax status `none`. Tổng phải khớp tổng đơn; nếu không, không gửi gì và hóa đơn bị đánh dấu `failed`. Đơn 0 VND bị `skipped` kèm lý do.

Trước lần gọi create, action đọc lại đơn và cài đặt, rồi lấy danh sách mẫu của năm hóa đơn (`/api/invoice/templates`). Mẫu mặc định phải còn trong danh sách, là mẫu GTGT và còn số; nhờ vậy mẫu năm cũ bị chặn sau Tết Dương lịch. Danh sách được cache 5 phút (`TemplateCache`, một transient mỗi tài khoản và năm), bị xóa khi lưu mẫu mặc định hoặc credential, và khi mẫu không còn hoặc bị Mắt Bão từ chối.

### Danh sách hóa đơn và gửi lại

Administrator quản lý hóa đơn ở tab **Hóa đơn** của màn hình **WooCommerce → Hóa đơn Mắt Bão**: lọc theo trạng thái (gồm nhóm lỗi/cần xử lý), tìm theo order ID hoặc đầu `MTChieu` (prefix match), xem chi tiết với lịch sử attempt (CCCD luôn bị che), kiểm tra nháp trên Mắt Bão và gửi lại. Gửi lại đi qua retry workflow, tra cứu theo `MTChieu` trước khi tạo, nên không bao giờ tạo trùng:

- Nháp biến mất khỏi Mắt Bão không bao giờ được tạo lại tự động; gửi lại cần xác nhận rõ ràng và tra cứu rỗng dưới mẫu đã lưu.
- Trạng thái dừng `template_changed` chỉ được giải phóng khi admin gõ lại `MTChieu` **và** tra cứu dưới mẫu cũ (đã lưu) trả rỗng. Tìm thấy gì đó hoặc tra cứu lỗi thì từ chối, không đổi gì. Chỉ request thắng lượt restart mới ghi attempt `admin_absent` và xóa mẫu đã lưu.
- Sau `buyer_invalid` / `buyer_snapshot_invalid` (không có snapshot dùng được), gửi lại kiểm tra lại người mua: vẫn sai thì từ chối kèm tên trường (không bao giờ kèm giá trị); hợp lệ thì chụp snapshot, trừ khi attempt trước có thể đã tới Mắt Bão, khi đó giữ snapshot cũ.
- Mỗi lần gửi lại bắt đầu một chu kỳ cảnh báo mới, nên gửi lại một hóa đơn `template_changed` rồi lại dừng sẽ gửi một cảnh báo mới (admin notice và Lark) mỗi lần. Không lặp nếu admin không thao tác.

Danh sách đơn WooCommerce có bộ lọc hóa đơn trên cả HPOS và legacy. Bộ lọc thêm `EXISTS` trên bảng invoice vào query đơn, nên kết hợp được với bộ lọc khác và không giới hạn số kết quả.

### Retry và phục hồi

- **Lỗi tạm thời** (không phản hồi, HTTP 5xx/408/429, body 2xx không đọc được, trùng `MTChieu` 305; các mã có `InvoiceErrorCode::is_auto_retryable()` là true) giữ dòng ở `pending` kèm mã lỗi và xếp lại cùng action sau 1 phút, 5 phút, 15 phút, 1 giờ và 6 giờ. Không cảnh báo khi đang chờ retry. Sau retry thứ năm, trạng thái thành `needs_attention` ("Lỗi cần xử lý") với mã lỗi cuối, và `einvoice_matbao_invoice_needs_attention( $invoice, $code )` cùng `einvoice_matbao_retries_exhausted( $order_id, $reference )` được fire một lần.
- **Lỗi khác** là lỗi cuối (xem bảng dưới) và cảnh báo ngay.
- **Tra cứu trước khi gửi lại:** trước mọi lần gửi có thể theo sau một lần create trước đó, plugin tra cứu theo `MTChieu` (`invoice-detail-batch`) dưới mẫu đã lưu trên dòng ở lần gọi trước, không phải mẫu mặc định hiện tại. Mẫu được lưu trước mỗi lần create.
  - Đúng một dòng cùng `mtChieu`, trạng thái Nháp (`maTThaiHDon` 1) và, khi Mắt Bão trả `tgTTTBSo`, cùng tổng đơn: nhận nháp đó (không create lần hai).
  - Tìm thấy khác (nhiều dòng, `mtChieu` khác, đã phát hành hoặc đã xóa, tổng khác): dừng với `matbao_lookup_mismatch` (`needs_attention`, cảnh báo).
  - Không tìm thấy: gửi lại cùng `MTChieu` với `KTraMTChieuTrung=1`. Nếu mẫu mặc định đã đổi trong lúc đó, dừng với `template_changed` thay vì tạo dưới mẫu khác.
  - Lỗi 305 kích hoạt tra cứu, không bao giờ sinh `MTChieu` mới.
  - Lần gửi đầu tiên không có attempt trước, hoặc sau lỗi xảy ra trước lần gọi create (login, kiểm tra mẫu), bỏ qua tra cứu.
- **Sweep định kỳ** (mỗi 15 phút) phục hồi dòng kẹt ở `sending` (runner chết sau khi claim; chỉ claim lại nếu `updated_at` không đổi) và dòng `pending` không có action trong hàng đợi sau 15 phút. Sweep không thêm attempt và không tiêu lượt retry; chỉ attempt đã gọi Mắt Bão và lỗi mới tính. Nếu sweep cũng không xếp được action, dòng thành `failed` với `queue_failed` và cảnh báo.
- `DraftInvoiceWorkflow::restart( $order_id, $from_status )` gửi lại một dòng trong chu kỳ retry mới (`retry_cycle_start`, schema v3); attempt trước vẫn quyết định việc tra cứu. Dùng khi đơn bị hủy rồi hoàn thành lại sau khi hóa đơn `stopped`, và khi admin bấm gửi lại.

### Phân loại lỗi và chặn nháp

Mọi lý do không tạo được nháp được lưu trên dòng invoice dưới dạng `error_code` ổn định (`InvoiceErrorCode`) và `error_kind` (`InvoiceErrorKind`), cạnh `status_reason` tiếng Việt. Trạng thái đơn không bao giờ bị đổi. Hộp hóa đơn trên đơn hiện loại lỗi, nhãn ngắn và lý do (thuộc tính `data-einv-error-code` / `data-einv-error-kind`).

| Kind | Status | Codes | Tự retry |
|---|---|---|---|
| `data` | `failed` | tiền tệ không phải VND, VND lẻ, thuế suất không hỗ trợ hoặc thiếu, nhiều thuế suất trên một dòng, lệch tổng, `MTChieu` > 20 ký tự, người mua không còn hợp lệ khi hoàn thành (`buyer_invalid`), snapshot người mua thiếu hoặc không dùng được (`buyer_snapshot_invalid`), không giải mã được CCCD (`buyer_id_unreadable`), ... | không |
| `config` | `not_configured` | thiếu credential, thiếu mẫu, mẫu không còn hoặc hết số, Mắt Bão từ chối mẫu (`errorCode` 308/327), mẫu mặc định đổi sau một lần create không rõ kết quả (`template_changed`), thiếu khóa mã hóa khi gửi snapshot có CCCD (`buyer_id_key_missing`) | không |
| `order` | `stopped` | đơn rời `completed`, hoàn tiền trước khi gửi | không |
| `matbao` | `pending` → `needs_attention` | request không hoàn tất, HTTP 5xx/408/429, body 2xx không đọc được (create-invoice hoặc templates), trùng `MTChieu` 305 | có; retry create-invoice tra cứu `MTChieu` trước |
| `matbao` | `failed` | HTTP khác (4xx), Mắt Bão `success` false với `errorCode` khác hoặc không có (hóa đơn bị từ chối, login) | không |
| `matbao` | `needs_attention` | tra cứu theo `MTChieu` tìm thấy thứ có thể không phải nháp của đơn này (`matbao_lookup_mismatch`) | không |
| `system` | `pending` → `failed` | xếp hàng đợi lỗi (sweep thử thêm một lần, sau đó là lỗi cuối) | chỉ sweep |
| `system` | `failed` | exception không lường trước | không |

- `InvoiceErrorCode` có 31 mã. Lỗi người mua chỉ nêu tên trường sai, không bao giờ nêu giá trị hay CCCD, và đơn vẫn `completed`.
- `InvoiceErrorCode::is_auto_retryable()` là công tắc retry: `matbao_transport`, `matbao_http_temporary`, `matbao_response_unreadable` và `matbao_duplicate_reference`. Một lần create-invoice lỗi theo cách này vẫn có thể đã tạo nháp, nên retry MUST tra cứu theo `MTChieu` trước.
- Hoàn tiền toàn phần chuyển đơn sang `refunded` (`order_not_completed`, không cảnh báo); hoàn tiền một phần giữ `completed` (`order_refunded`, cảnh báo).
- Mỗi lỗi admin cần xử lý fire `do_action( 'einvoice_matbao_invoice_needs_attention', InvoiceRecord $invoice, InvoiceErrorCode $code )` một lần. Đơn mà shop chủ động chuyển khỏi `completed`, và lỗi còn đang chờ retry, không fire.
- `InvoiceRecord` có `buyer_name` (tên cá nhân hoặc tên doanh nghiệp), nên listener cảnh báo không được chuyển tiếp cột này.

### Thay đổi đơn sau khi có nháp

- `OrderChangeWatcher` so đơn với `order_state` (digest có khóa của số tiền, người mua và tổng hoàn tiền, schema v5; không có dữ liệu cá nhân đọc được) một lần mỗi request ở `shutdown`.
- Sau `draft_created`, thay đổi chỉ đặt `order_amounts_changed`, `order_buyer_changed` hoặc `order_refunded_after_draft` (kind `order`, status không đổi), thêm ghi chú không có dữ liệu cá nhân và fire `einvoice_matbao_invoice_needs_attention` một lần mỗi thay đổi. Nháp không bao giờ được cập nhật, hủy hay tạo lại; kế toán sửa tay. Cảnh báo ở lại trên dòng đến thay đổi tiếp theo hoặc lần restart.
- Digest dùng khóa `wp_salt('auth')`. Xoay salt của site làm đổi mọi digest, nên lần lưu kế tiếp của mỗi đơn đã có nháp sẽ sinh một cảnh báo `order_amounts_changed` hoặc `order_buyer_changed` giả (không đổi gì khác). Không dùng khóa riêng: khóa suy từ `EINVOICE_MATBAO_ENCRYPTION_KEY` sẽ không có trên site thiếu hằng này, và đổi khóa nào cũng có cùng hệ quả, nên rủi ro được ghi lại thay vì xử lý. Digest bỏ qua thứ tự dòng và khoảng trắng lặp.
- Trước khi có nháp, Administrator (`manage_options`) sửa người mua sẽ chụp lại snapshot (validate trước; sai thì giữ snapshot cũ và cảnh báo `buyer_invalid`). Sau một attempt có thể đã tới Mắt Bão, snapshot được giữ, vì việc nhận nháp có sẵn có thể mang theo người mua cũ; thay đổi được cảnh báo khi nháp tồn tại. Sửa bởi role khác không bao giờ chụp lại.
- Thay đổi qua code bỏ qua hook CRUD của WooCommerce (SQL trực tiếp) không được phát hiện.

### Cảnh báo (admin notice và Lark)

`Alert\InvoiceAlerts` nghe `einvoice_matbao_invoice_needs_attention` và `einvoice_matbao_retries_exhausted`:

- Ghi một cảnh báo cho mỗi (đơn, mã lỗi, chu kỳ cảnh báo) vào option không autoload (`add_option` không làm gì khi tên đã có; race giữa hai writer tệ nhất gửi trùng một tin Lark).
- Xếp tin Lark qua Action Scheduler (`einvoice_matbao_send_alert`) để lần chạy hóa đơn không phải chờ Lark.
- Mỗi lần restart hóa đơn (admin gửi lại, đơn hủy rồi hoàn thành lại) tăng `alert_cycle`, kể cả khi dừng trước mọi attempt, nên cùng lỗi sẽ cảnh báo lại.
- Tin Lark gồm số đơn, nhãn, mã lỗi, hướng dẫn xử lý và đường dẫn admin tới đơn (`Cách xử lý: …`).
- Hướng dẫn lấy từ `InvoiceErrorCode::hint()`, nguồn duy nhất cho tin Lark, admin notice, meta box đơn hàng, chi tiết hóa đơn và context `invoice.hint` của event giám sát. Mỗi mã lỗi mới phải có nhánh trong `match` của `hint()`; `match` không có `default`, nên thiếu nhánh sẽ ném `UnhandledMatchError` và `InvoiceErrorCodeTest::test_every_code_has_a_hint` bắt lỗi này.

Lark là best-effort. Lỗi mạng, HTTP 5xx, 408 hoặc 429 được retry tối đa 3 lần sau 1, 5 và 15 phút; lỗi khác (4xx khác, webhook sai hoặc thiếu) không retry. Tin lỗi được log không có dữ liệu cá nhân và hiện thành ghi chú trong admin notice; không bao giờ đụng hóa đơn hay xếp action tạo nháp.

Notice hiện cho user `manage_options` (tối đa 10 cảnh báo mở, một query), có link tới đơn và tắt được. Sweep phục hồi xóa cảnh báo có hóa đơn đã gửi lại, đã xong hoặc bị xóa, và cảnh báo đã tắt quá 30 ngày.

### Đơn vị tính (`DVTinh`)

Mặc định cửa hàng đặt ở tab **Xuất hóa đơn**; fee và shipping luôn dùng giá trị này. Sản phẩm hoặc biến thể có thể override; biến thể không có override riêng thì kế thừa sản phẩm cha (meta `_einvoice_matbao_unit`).

Giới hạn: đơn vị theo sản phẩm chỉ sửa được ở màn hình sửa sản phẩm (và panel biến thể). Quick edit, bulk edit và import CSV chưa hỗ trợ; chúng giữ nguyên override đang có.

### Yêu cầu xuất hóa đơn ở Classic checkout

- Checkbox **Yêu cầu xuất hóa đơn** dưới form billing mở lựa chọn cá nhân/doanh nghiệp. Cá nhân cần CCCD (12 chữ số) cùng tên, số điện thoại và địa chỉ billing; doanh nghiệp cần tên doanh nghiệp, MST (10 chữ số, hoặc 13 với `-` tùy chọn), cùng số điện thoại, email và địa chỉ billing. Không còn ô địa chỉ hóa đơn riêng: địa chỉ hóa đơn luôn lấy từ billing (address 1, address 2, city, state, nối bằng ", ").
- `BuyerRules` chỉ validate cấu trúc (không xác minh danh tính) ở server qua hook checkout của WooCommerce và lần nữa khi tạo đơn; `assets/src/checkout.ts` (build bằng `bun run build`) làm tương tự ở trình duyệt. Không tick thì người mua là vãng lai; khi đó `BuyerSnapshotter` gửi tên, địa chỉ, SĐT và email billing nếu hợp lệ (SĐT/email sai bị bỏ qua), còn `NMua_Ten` dùng tên billing nếu có, không thì dùng tên cấu hình.
- CCCD chỉ lưu trong order meta, mã hóa bằng `EINVOICE_MATBAO_ENCRYPTION_KEY`; thiếu khóa thì lựa chọn cá nhân bị tắt và bị server từ chối. Log, ghi chú đơn và dòng invoice chỉ hiện 4 số cuối; chuỗi 12 chữ số trong thông điệp lỗi Mắt Bão cũng được che như vậy.
- Meta yêu cầu và snapshot bị gỡ khỏi response REST đơn WooCommerce (wc/v2, wc/v3 và wc/v4 experimental).
- Khi đơn hoàn thành và dòng invoice được giữ chỗ, người mua được kiểm tra lại bằng `BuyerRules` (billing có thể đã sửa); sai thì nháp lỗi với lý do chỉ nêu tên trường. Nếu hợp lệ, **snapshot người mua** được chụp vào order meta và mọi lần gửi dựng payload từ snapshot (không từ đơn hiện tại).
- Blocks checkout dùng lại `BuyerRules`, `BuyerRequestStore::from_input` và `BuyerSnapshotter`; quy tắc trình duyệt trong `assets/src/checkout-rules.ts` được kiểm bằng `bun run test`.

### Yêu cầu xuất hóa đơn ở Checkout Blocks

- Block `einvoice-matbao/invoice-request` (con của Checkout fields block, tự chèn sau contact information qua `blockHooks`) có cùng checkbox và trường.
- Additional Checkout Fields API của WooCommerce không ẩn được trường theo loại người mua và không tránh được việc lưu CCCD, nên block là integration block (`assets/src/checkout-blocks.ts`) gửi tới một Store API checkout extension chỉ ghi (`extensions.einvoice-matbao`, `BlocksCheckout`).
- Server validate lại ở `woocommerce_store_api_checkout_update_order_from_request` bằng `BuyerRules` và lưu bằng `BuyerRequestStore::from_input`, nên gọi Store API trực tiếp cũng bị kiểm như vậy (HTTP 400 với thông điệp tiếng Việt). Extension không có data callback: CCCD không bao giờ được trả lại trong response Store API, cart extensions, ghi chú đơn hay session.
- Hook cũng fire khi PUT/PATCH với đơn pending/failed trong session (thanh toán lại sau khi lỗi). Các lần gọi này bị bỏ qua: chỉ `POST` đặt hàng mới validate và lưu, nên yêu cầu đang gõ dở không nhận 400 và CCCD mã hóa chỉ được ghi một lần, lúc đặt hàng thật. POST thanh toán lại trên đơn failed lưu yêu cầu bình thường. Đơn nháp (`checkout-draft`) không bao giờ giữ CCCD.
- Schema giới hạn độ dài (CCCD 12, MST 14, địa chỉ và tên 400), giới hạn `buyer_type` là `individual`/`business`, và `request_invoice` chỉ nhận boolean (WordPress REST còn nhận `"true"`/`1`); giá trị khác là HTTP 400, không bao giờ âm thầm thành vãng lai.
- Trên Checkout page do WooCommerce tạo, block không tự hiện ở front end (`blockHooks` chỉ chèn khi editor lưu trang); trên site đã tùy biến trang Checkout trước khi kích hoạt, admin thêm block trong editor. Seam server được kiểm bằng `tests/integration/BlocksCheckoutBuyerTest.php`; UI block được kiểm bằng browser E2E (`tests/e2e`).

### Dữ liệu cá nhân

`Privacy\BuyerPersonalData` đăng ký exporter và eraser của WordPress (`einvoice-matbao`). Cả hai tìm đơn có billing email là địa chỉ được yêu cầu (gồm đơn khách) cùng đơn của user có email đó, mười đơn mỗi trang, như WooCommerce.

- **Export:** một item mỗi đơn có người mua có tên (cá nhân hoặc doanh nghiệp): loại người mua, tên, MST, địa chỉ, CCCD, điện thoại, email, `MTChieu` và trạng thái hóa đơn. CCCD được xuất ở dạng che 4 số (`********1234`), không bao giờ giải mã: ADR 0002 chỉ giữ số đầy đủ trong order meta mã hóa, file export được tải về và gửi mail, và người đó đã biết số của mình. Không xuất ciphertext hay secret. Đơn vãng lai chỉ xuất khi có dữ liệu billing được snapshot (tên, địa chỉ, SĐT, email); đơn không có dữ liệu thì không xuất gì.
- **Erase:** xóa `_einvoice_buyer_request` và `_einvoice_buyer_snapshot` khỏi đơn (bản thân đơn WooCommerce không đổi), thay `buyer_name` trong dòng invoice bằng text `[deleted]` của core và xóa bản sao giá trị người mua khỏi `status_reason` và thông điệp attempt. Dòng invoice và attempt, `MTChieu`, status và mã hóa đơn Mắt Bão được giữ cho kế toán, báo là `items_retained` kèm thông điệp; thông điệp cũng nói nháp hoặc hóa đơn đã tạo trên Mắt Bão nằm ngoài plugin và phải xử lý ở đó.
- **Thứ tự với eraser của WooCommerce:** eraser plugin chạy priority 5, trước order eraser của WooCommerce (10), nên vẫn tìm được đơn theo billing email. Khi WooCommerce ẩn danh một đơn (eraser với "Remove personal data from orders" bật, hoặc xóa theo lịch), plugin xóa dữ liệu đơn đó qua `woocommerce_privacy_remove_order_personal_data`.
- **Erase khi đang gửi:** khi hóa đơn còn chờ gửi hoặc retry, quyền được xóa thắng. Meta người mua bị xóa, nên attempt tiếp theo lỗi dữ liệu người mua và hóa đơn thành lỗi để admin xử lý; nháp đã tạo trên Mắt Bão vẫn ở đó. Thông điệp eraser nói rõ điều này.
- Đơn mọi trạng thái đều được xử lý, gồm thùng rác và checkout draft. Export cũng liệt kê tên người mua trên dòng invoice và lý do trạng thái (số ID được che bằng `Invoice\Redactor`).
- **Giới hạn:** giá trị ngắn hơn 3 ký tự không được tìm trong text tự do. Ghi chú đơn cho số ID đã nộp chỉ giữ bản che (`********1234`) và không bị xóa, ở đây lẫn khi uninstall. Export không bao giờ có số ID đầy đủ: người cần số đó phải yêu cầu cửa hàng, cửa hàng xác minh danh tính và đọc tay từ order meta mã hóa.

### Deactivate và uninstall

Deactivate hủy mọi action đang xếp của plugin (group `einvoice-matbao`: nháp, sweep phục hồi và gửi cảnh báo `einvoice_matbao_send_alert`); dữ liệu giữ nguyên và sweep được xếp lại sau khi kích hoạt lại.

`uninstall.php` chỉ chạy khi WordPress xóa plugin. File nạp `Uninstall\Uninstaller` theo đường dẫn, nên chạy được khi không có `vendor/` và khi WooCommerce đã gỡ (khi đó action được hủy trực tiếp trong bảng Action Scheduler). Trên multisite, chạy trên mọi site.

| Dữ liệu | Uninstall mặc định | Bật "Xóa toàn bộ dữ liệu" |
|---|---|---|
| Credential Mắt Bão và Lark (`einvoice_matbao_credentials`) | xóa | xóa |
| Transient (token Mắt Bão, cache mẫu), xóa theo tên để phủ cả persistent object cache; tên token nằm trong option `einvoice_matbao_token_keys` | xóa | xóa |
| Bản ghi cảnh báo `einvoice_matbao_alert_*` (chỉ order id, mã lỗi và cờ Lark) và danh sách tên token | xóa | xóa |
| Action đang xếp trong group `einvoice-matbao` | hủy | hủy |
| Bảng `{prefix}einvoice_matbao_invoice` và `_attempt` | giữ | drop |
| Order meta `_einvoice_buyer_request` (gồm CCCD mã hóa `id_encrypted`), `_einvoice_buyer_snapshot` (legacy và HPOS) | giữ | xóa |
| Ghi chú đơn có CCCD đã che | giữ | giữ |
| Product meta `_einvoice_matbao_unit` | giữ | xóa |
| Mọi option `einvoice_matbao_*` khác (cài đặt, schema version) | giữ | xóa |
| Đơn hàng và sản phẩm WooCommerce | giữ | giữ |

Công tắc nằm ở phần **Gỡ plugin** trong tab **Nâng cao** (`POST settings/uninstall`): mặc định tắt, cần Administrator và nonce `wp_rest` như mọi route cài đặt, bật lên phải gõ `XOA HET DU LIEU`. Tắt không cần xác nhận. Hóa đơn trên Mắt Bão không bao giờ bị ảnh hưởng. Xóa meta trên shop rất lớn chạy một câu lệnh mỗi key, không chia batch.

## Giám sát lỗi (module `Monitoring`)

Code nằm trong `src/Monitoring/`. Tính năng mặc định tắt; cấu hình và cách bật xem [Hướng dẫn sử dụng](huong-dan-su-dung.md#giám-sát-lỗi-sentry).

```text
 Code nghiệp vụ / hook ──> Monitor (facade static) ──> Backend (interface)
                                                         |
                                                  SentryBackend (adapter duy nhất gọi Sentry\…)
                                                         |  Redactor (Scrubber) lọc mọi dữ liệu trước khi vào Hub
                                                         v
                                                  WpHttpClient ──> wp_remote_post (timeout 3s, backoff 60s)
```

- **`Monitor`** là facade duy nhất mà module nghiệp vụ gọi: `capture_exception`, `capture_needs_attention`, `log`, `start_transaction`/`finish_transaction`, `span`, `check_in`, `with_order_context`, `with_failure_detail`, `failure_detail_for`, `send_test_event`. Chỉ lỗi của chính phần đo bị nuốt (ghi tên class qua `InternalFailure`); `span()` và `with_order_context()` chạy callback của caller đúng một lần và ném lại exception nghiệp vụ nguyên vẹn.
- **`MonitoringConfig`** là value object cấu hình đã hiệu lực (cờ bật, DSN và nguồn `constant|ui|none`, sample rate, environment, release, site). `Monitor::is_active()` chỉ đọc cấu hình này, không tải SDK, và cache theo request. Hub Sentry chỉ được dựng lần gọi đầu tiên.
- **`SentryBackend`** và **`WpHttpClient`** là hai lớp duy nhất tham chiếu namespace `Sentry\…`. `SentryBackend` dựng `Options` với `default_integrations=false`, `send_default_pii=false`, `server_name=''`, và một `Hub` riêng; không gọi `\Sentry\init()` và không dùng `Sentry\Logs\Logs`/`LogsAggregator` (chúng đọc hub toàn cục). Logs được buffer riêng (tối đa 100) và flush bằng `captureEvent`.
- **`Scrubber`** (implement `Redactor`) lọc theo hai lớp: giá trị người mua đã biết (`with_order_context` nạp vào, chỉ giá trị ≥ 3 ký tự) và mẫu regex (email, URL, CCCD, MST, Bearer, chuỗi liền có chữ số). Tag đi qua allowlist `Scrubber::TAG_ALLOWLIST`. Frame `vars` trong stacktrace không nằm ở `Scrubber` mà bị xóa trong `before_send` của `SentryBackend`.
- **`OrderRedaction`** gom giá trị người mua của đơn cho `with_order_context`; **`CaughtFailure`** là chỗ duy nhất cho mọi `catch` đang `error_log` (issue có tag `component`/`order_id`, context `invoice` khi biết đơn và tìm được hóa đơn, và log `error`).
- **`Backend::capture_exception` / `capture_message`** nhận thêm `array $contexts` (tên context ⇒ mảng scalar) sau `$known`. `SentryBackend` gọi `setContext()` cho từng context trong `withScope`; mọi context đi qua `Redactor::value()` như `extra`.
- **`InvoiceContext`** dựng context `invoice` (dùng chung cho event needs-attention và `CaughtFailure`). **`FailureDetail`** là value object chi tiết lỗi, gắn với `(order_id, error_code)`. **`Invoice\LineFailure`** (readonly) mô tả dòng gây lỗi: `item_id`, `item_type`, `product_id`, `variation_id`, `sku`, `name`, `tax_rate_ids`; nằm ở `InvoiceDataError::$line_failure` và luôn có với mã lỗi theo dòng.
- **`Scrubber::TAG_ALLOWLIST`** gồm `product_id` và `matbao.api_code` ngoài các tag cũ.
- **`SentryDsn`** kiểm tra DSN (trim trước khi validate, `http|https`, có public key, project id số); **`MonitorSlug::for_host()`** tạo slug cron monitor ổn định và ≤ 50 ký tự.

### Điểm đo và vì sao chọn các hook đó

- **Action Scheduler** (`ActionSchedulerTracing`): `action_scheduler_begin_execute`/`after_execute`/`failed_execution` mở transaction `queue.task` chỉ cho group `einvoice-matbao`, và gửi check-in cho hook `einvoice_matbao_recover_stuck_invoices`. Dùng `begin_execute` thay vì `before_execute` vì hook sau chạy trước khi Action Scheduler kiểm tra trạng thái pending. Đây là hook duy nhất cần cron monitor; không sửa `DraftInvoiceWorkflow::sweep()`.
- **REST** (`RestTracing`): `rest_request_before_callbacks`/`rest_request_after_callbacks` cho route `einvoice-matbao/v1/*`, op `http.server`, tên là route pattern (không có query). Dùng cặp `rest_request_*_callbacks` vì `rest_post_dispatch` không chạy với `rest_do_request()` nội bộ. Dùng hook chuẩn của WP để không phải đụng từng controller.
- **HTTP ra ngoài:** `MatbaoClient::request()` bọc span `http.client`; `LarkClient` dùng span mô tả `POST lark-webhook` không có URL, vì path webhook chứa token.
- **Logs:** `InvoiceRepository` log `invoice status changed` sau mỗi lần đổi trạng thái thành công; `from` lấy từ trạng thái caller đang giữ, không SELECT lại.
- **Issues:** `InvoiceAlerts::on_needs_attention` gọi `capture_needs_attention` sau dedup của `AlertStore`, nên mỗi (đơn, lỗi, chu kỳ) gửi một lần.

### Hình dạng event needs-attention

| Phần | Nội dung |
|---|---|
| `message` | `Hóa đơn cần xử lý: <nhãn> – Đơn #<id>` (level `warning`) |
| Tag | `order_id`, `error_code`, `error_kind`, `invoice.status`, `site`; thêm `product_id` khi lỗi ở dòng sản phẩm và `matbao.api_code` khi Mắt Bão trả mã lỗi |
| `contexts.invoice` | `order_id`, `invoice_id`, `reference`, `status`, `error_code`, `label`, `hint`, `status_reason`, `admin_path` |
| `contexts.line` | Chỉ khi lỗi theo dòng xảy ra trong cùng request: `item_id`, `item_type`, `product_id`, `variation_id`, `sku`, `name`, `tax_rate_ids` |
| `contexts.matbao` | Chỉ khi lỗi Mắt Bão xảy ra trong cùng request: loại lỗi, HTTP status, mã lỗi API, `target_sent`, có retry hay không |
| Fingerprint | `['needs-attention', <code>]`, không đổi; message đổi không ảnh hưởng gom issue |

- `admin_path` chỉ giữ path và các query `page`, `action`, `id`, `post` (bỏ scheme, host, nonce, fragment). ID 10 hoặc 12 chữ số vẫn bị `Scrubber` che, và bộ lọc được ưu tiên.
- `contexts.line` và `contexts.matbao` chỉ tồn tại trong request có lỗi. `FailureDetail` được đăng ký bằng `Monitor::with_failure_detail()` quanh đúng lần `record_error()`, và gỡ ngay khi callback kết thúc; sweep xử lý nhiều đơn không làm rò chi tiết sang đơn khác. Cảnh báo `order_*_changed` phát trong lúc đó không bị gắn chi tiết vì khác mã lỗi.
- Context của event (closure của capture needs-attention và `CaughtFailure`) chạy trong `Monitor::guard`: lỗi dựng context chỉ được `InternalFailure` ghi lại, còn trạng thái, mã lỗi và lịch retry của hóa đơn vẫn như khi giám sát tắt. `FailureDetail` được dựng trong `with_failure_detail()` với try/catch riêng: nếu lỗi thì ghi `InternalFailure`, và callback vẫn chạy đúng một lần nhưng không có phạm vi chi tiết (event không có `line`/`matbao`).
- Action Scheduler: mọi exception của action được trace đều có context `action` (`hook`, `action_id`), kể cả action không liên quan đơn.
- Chỉ khi tham số đầu tiên (`args[0]`) là số nguyên dương thì mới có tag `order_id`, context ngữ cảnh đơn, và exception được gửi trong `Monitor::with_order_context()` để `Scrubber` che giá trị người mua có trong message.

### Kiểm thử với `SentrySink`

Test tích hợp không gửi HTTP thật. `tests/integration/Support/SentrySink::install(FakeHttp $http, host, project)` đăng ký một Sentry giả trên `FakeHttp`, parse envelope và cho các assert:

```php
$sink = SentrySink::install( $this->http );
$sink->respond_with( 429, array( 'retry-after' => '30' ) ); // hoặc fail_transport()
// ... chạy luồng ...
$this->assertCount( 1, $sink->events() );
$this->assertSame( 1, $sink->request_count() );
```

- `events()`, `transactions()`, `logs()`, `check_ins()` trả các item đã parse; `raw_bodies()` và `envelope_headers()` cho test kiểm tra không lộ dữ liệu.
- Hàng test bật giám sát dùng `MonitoringFixture::enable_monitoring()` (lưu DSN UI trỏ `sentry.test` project 3, bật cờ, reset `Monitor`). Gọi `reset_monitoring()` trong `tear_down()`.
- Test thay redactor bằng bản ném lỗi để chứng minh event bị bỏ (không gửi) khi chính bộ lọc lỗi.
- `MonitoringOverrides::allowed()` quyết định khi nào filter override cấu hình (chỉ trong test suite hoặc môi trường `local`/`development`); hàm thuần này có test ma trận đầu vào.

## Quy tắc tạo nháp và giới hạn đã biết

- **Tiền nguyên VND:** dòng có net, tax hoặc total lẻ bị từ chối (`failed`), bất kể `price_num_decimals`. Fee và shipping 0 đồng bị bỏ khỏi hóa đơn; dòng sản phẩm miễn phí được gửi với `TSuat` theo tax status (`none` → -1, còn lại 0).
- **Chiết khấu:** plugin gửi đơn giá sau giảm trong `DGia`, `TLCKhau` = `STCKhau` = 0 và `ThTienChuaCK` = `ThTien` = net. Demo chấp nhận cả shape này lẫn shape giá niêm yết (`DGia` gốc, `TLCKhau`, `STCKhau`) với cùng tổng; hóa đơn vì vậy không hiện giá gốc hay % chiết khấu. API **không** đối chiếu chéo các trường của dòng, nên `DraftPayloadBuilder` phải tự giữ số học nhất quán.
- **`DGia` không chia hết** (net 100000, số lượng 3): làm tròn 6 chữ số thập phân; demo chấp nhận vì `ThTien` được gửi tường minh.
- **`TgTTTBChu`** (tổng tiền bằng chữ) có dấu tiếng Việt; demo chấp nhận.
- **Retry dùng Action Scheduler**, nên các khoảng chờ là cận dưới: WP-Cron phải chạy thì retry mới chạy.
- **Chống trùng phía Mắt Bão không nguyên tử:** demo ngày 31/10/2026 cho thấy 5 request song song cùng `MTChieu`/mẫu tạo 2 nháp (ADR [0001](adr/0001-matbao-reference-as-sole-dedup-key.md)). Plugin không bao giờ gửi hai create đồng thời cho một đơn (`UNIQUE(order_id)`, claim `pending -> sending`, tra cứu trước retry). Nếu tra cứu vẫn trả nhiều nháp cho một `MTChieu`, plugin không chọn mà dừng ở `matbao_lookup_mismatch` cho admin.
- **Kiểm tra trùng theo từng mẫu:** cùng `MTChieu` dưới mẫu khác tạo được nháp mới. Tra cứu chỉ tìm một mẫu (`mauSo`/`kiHieu` bắt buộc), nên nháp dưới mẫu khác không thấy; vì vậy đổi mẫu mặc định dừng thay vì tạo mới.
- **Response tra cứu không có tổng tiền hay người mua:** kiểm tra tổng chỉ chạy khi Mắt Bão trả `tgTTTBSo`; so `nMua_Ten`/`nMua_MST` với snapshot chỉ chạy khi response có các trường này.
- **Sweep cũng chạy trên Action Scheduler:** nếu Action Scheduler ngừng hẳn, không gì được phục hồi và không có cảnh báo.
- **Nháp được nhận qua tra cứu** giữ `NLap` mà Mắt Bão báo, có thể sớm hơn ngày nhận.
- **Mã lỗi 308 và 327** của Mắt Bão chưa tái hiện được trên demo; mẫu không tồn tại hoặc hết số trả lỗi 201 "Trường 'KHMSHDon','KHHDon' bị thiếu hoặc không hợp lệ.".
- **Chưa kiểm chứng:** ký, phát hành và hành vi production; Mắt Bão có tự làm mới token 24 giờ sớm hay không.

Kết quả đầy đủ trên API demo: [research/e2e-demo-results.md](research/e2e-demo-results.md).

## Phiên bản ghim và giới hạn môi trường

Hai tổ hợp được kiểm thử: tối thiểu được hỗ trợ và mới nhất.

| Thành phần | Tối thiểu (`docker/min.env`) | Mới nhất (mặc định `compose.yaml`) |
|---|---|---|
| WordPress | 6.6.2 (`wordpress:6.6.2-php8.2-apache`) | 7.1.4 (`wordpress:7.1.4-php8.5-apache`) |
| PHP | 8.2 (từ image WordPress) | 8.5 (từ image WordPress) |
| WP-CLI | `wordpress:cli-2.12.0-php8.2` | `wordpress:cli-2.12.0-php8.5` |
| WooCommerce | 9.8.7 | 11.2.1 |
| MariaDB | 11.8 | 11.8 |
| wp-phpunit | ~6.6.0 (`einv-setup` cài vào `/opt/wp-phpunit`) | ~7.1.0 (`composer.lock`) |

- `scripts/check.sh --min` dùng project `<COMPOSE_PROJECT_NAME>-min` trên cổng `EINV_HTTP_PORT`+1 (mặc định 8081). Không chạy cả hai tổ hợp cùng lúc trong một worktree: chúng dùng chung bind mount `vendor/`.
- Ghi đè preset bằng `EINV_WORDPRESS_IMAGE`, `EINV_WPCLI_IMAGE`, `EINV_WOOCOMMERCE_VERSION`, `EINV_WP_PHPUNIT_VERSION` và `EINV_WP_PHPUNIT_DIR`.
- Nâng phiên bản đồng bộ: `docker/Dockerfile`, `compose.yaml`, `docker/min.env` và `composer.json`.
- Các phiên bản trung gian (PHP 8.3/8.4, WordPress 6.7–7.0, WooCommerce 9.9–11.1) không được kiểm thử trực tiếp.
- Bug core WooCommerce 9.8: Store API checkout trả 500 ("Undefined array key") khi không bật cổng thanh toán nào. Không phải lỗi plugin; fixture integration bật `bacs`.
- WooCommerce 9.8 không có REST API `wc/v4`, nên test privacy đơn v4 bị bỏ qua trên tổ hợp tối thiểu (feature-detect).
- PHPCS `testVersion` không có tác dụng (không cài PHPCompatibility); mức sàn PHP được bảo đảm bằng PHPStan (`phpVersion` 80200) và `php -l` trên PHP 8.2.
- WP-CLI 2.12 in deprecation notice của PHP 8.5 khi chạy `einv-setup`; vô hại.
- Kích hoạt khi thiếu WooCommerce bị core WordPress từ chối qua header `Requires Plugins`. Nếu WooCommerce bị tắt hoặc gỡ sau đó, plugin tự tắt ở request admin kế tiếp và hiện admin notice tiếng Việt.
- Không có `composer install` (thiếu `vendor/`), plugin đứng yên thay vì fatal. Gói phát hành phải gồm `vendor/` build bằng `composer install --no-dev`.

## CI

Mỗi mối quan tâm một workflow trong `.github/workflows/`:

| Workflow | Trigger | Việc làm |
|---|---|---|
| `assets.yml` | PR, `main` | test bun, build, kiểm tra CSS scope và không dùng CDN |
| `php.yml` | PR, `main` | matrix `PHP (min)` / `PHP (latest)`: PHPCS, PHPStan, hai bộ PHPUnit, floor guard |
| `e2e.yml` | `main` | browser E2E từ replay cache đã commit (`tests/e2e`) |
| `release.yml` | tag `v*.*.*` | kiểm tag, gọi ba workflow trên, đóng gói, smoke, publish |

- `PHP (min)` chạy thêm `php -l` trên PHP 8.2; cả hai job chạy `composer analyse:floor-guard`. Browser E2E và smoke zip chỉ chạy trên tổ hợp mới nhất.
- `assets`, `php` và `e2e` có `workflow_call`, nên `release.yml` dùng lại làm cổng.

## Phát hành

1. Nâng `Version:` và `EINVOICE_MATBAO_VERSION` trong `einvoice-matbao.php` cùng lúc (`scripts/plugin-version.sh` kiểm tra) và merge vào `main`.
2. Tạo và đẩy tag: `git tag vX.Y.Z && git push origin vX.Y.Z` (`vX.Y.Z-rc.N` tạo prerelease).
3. `release.yml` kiểm tag = header = hằng số, chạy các workflow assets, PHP và E2E làm cổng, build zip bằng `scripts/build-zip.sh`, cài zip vào WordPress + WooCommerce sạch (`scripts/smoke-zip.sh`), attest provenance và publish Release với ghi chú tự sinh, file zip và `.sha256`.

Build local bằng `scripts/build-zip.sh` (cần bun, zip, curl và Docker hoặc PHP ≥ 8.2 + Composer); kết quả ở `dist/`.

- Thư viện đóng kèm (Sentry SDK, Guzzle PSR-7, Symfony, PSR) được `scripts/scope-vendor.sh` đổi namespace sang `LTTech\EinvoiceMatbao\Vendor\…` bằng PHP-Scoper (`scoper.inc.php`), để plugin khác mang bản Sentry/Guzzle riêng không xung đột. Phar PHP-Scoper ghim version + sha256 trong `scripts/php-scoper.sha256`; symbol WordPress/WooCommerce/Action Scheduler giữ nguyên, lấy từ `php-stubs/*` đúng version trong `composer.lock`.
- Dev/test dùng `vendor/` chưa scope; chỉ zip phát hành được scope. `scripts/smoke-zip.sh` kiểm tra zip không còn namespace thư viện chưa prefix, kích hoạt được khi một mu-plugin đã nạp `Sentry\SentrySdk` và `GuzzleHttp\Psr7\Request` chưa scope, và gửi event, transaction, log, check-in thật tới một Sentry giả trong container.
- Thứ tự build: `scripts/build-zip.sh` chạy `scope-vendor.sh` (phar PHP-Scoper ghim sha256, không thêm vào `composer.json`), dump lại autoload, và fail nếu registry rename lỗi. Chạy `scripts/smoke-zip.sh dist/einvoice-matbao-X.Y.Z.zip` để kiểm tra lại kết quả.
- **Giới hạn đã biết:** cài từ source checkout (chưa scope) có thể xung đột nếu site đã nạp một bản Sentry SDK khác. Đây là giới hạn của bản source, không phải của zip phát hành.
- **Giới hạn đã biết:** cài từ source checkout (chưa scope) trên site đã chạy Sentry: số lượng event bị plugin loại bỏ (client report) có thể được cộng vào client report của site. Chỉ là con số đếm, không chứa dữ liệu cá nhân; zip phát hành không bị ảnh hưởng.
- Đổi DSN, cờ bật hoặc sample rate không cần build lại; mọi thứ chạy từ cấu hình lưu trong DB và hằng wp-config.

## Browser E2E và kiểm tra API demo Mắt Bão

`tests/e2e/` chứa bộ test trình duyệt (checkout, cài đặt, hóa đơn, lỗi và retry, thay đổi đơn, đơn vị tính và phân quyền; server Mắt Bão giả; là cổng CI của pipeline phát hành) và harness có bảo vệ cho API demo. Xem [tests/e2e/README.md](../tests/e2e/README.md) và [research/e2e-demo-results.md](research/e2e-demo-results.md).

## Quy ước dự án

- Tài liệu viết tiếng Việt; code comments, commit (Conventional Commits) và PR viết tiếng Anh; chuỗi UI tiếng Việt qua text domain `einvoice-matbao`. Chi tiết trong [`AGENTS.md`](../AGENTS.md).
- Issue và spec nằm trong GitHub Issues ([`agents/issue-tracker.md`](agents/issue-tracker.md)); thay đổi hành vi đi qua OpenSpec (`openspec/`).
