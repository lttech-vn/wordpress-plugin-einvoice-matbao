# Browser E2E (framework e2e)

Test trình duyệt cho các user story của plugin, chạy bằng [`e2e`](https://e2e.tester.army/), dựng theo mẫu `workvps/manage`. `bun run e2e:replay` là cổng kiểm tra: job `e2e` (thêm cùng pipeline phát hành, #32) chạy nó khi push lên `main` và trước mỗi lần phát hành; pull request không chạy. Kiểm tra API demo Mắt Bão nằm trong [`demo-api/`](demo-api) (Python, có bảo vệ), kết quả ở [`docs/research/e2e-demo-results.md`](../../docs/research/e2e-demo-results.md).

## Các suite (hybrid: agent điều khiển, bước chính xác để chốt)

Agent (`agent.act`) thực hiện các luồng người dùng; mọi bước agent đều có một `expect` chính xác theo sau. Các bước giữ dạng chính xác khi agent không phù hợp: ép một lần từ chối, chọn một trong nhiều nút trùng tên (ba nút "Lưu" trên trang cài đặt), trình sửa địa chỉ bị ẩn của WooCommerce, và trạng thái màn hình không hiển thị được. Có hai agent: `default` (checkout) và `admin` (wp-admin; context của nó nêu tên các màn hình plugin).

| File | Story (spec #1) | Bước agent |
|---|---|---|
| `checkout-invoice.army.ts` | 13-15, 17, 19: đặt đơn ở Classic và Blocks checkout, hoàn thành, thấy nháp | điền yêu cầu xuất hóa đơn |
| `checkout-validation.army.ts` | 16, 18: quy tắc trình duyệt khớp quy tắc server; Store API từ chối dữ liệu sai; CCCD không bao giờ lưu dạng rõ | yêu cầu doanh nghiệp và cá nhân hợp lệ |
| `settings.army.ts` | 28-31, 33: credential mã hóa, kiểm tra kết nối, chọn mẫu, prefix MTChieu, tên vãng lai, đơn vị tính | lưu và kiểm tra kết nối, chọn mẫu |
| `invoices.army.ts` | 1, 3, 20-26: danh sách, bộ lọc, tìm kiếm, chi tiết, kiểm tra trạng thái, gửi lại, tổng quan | mở chi tiết hóa đơn |
| `failures.army.ts` | 4-7, 8, 10, 12, 38-40: chặn nháp, dòng phí (7), 503/mất phản hồi/305/số lần retry và khoảng chờ/hết retry | mở chi tiết hóa đơn |
| `order-changes.army.ts` | 11, 12, 27: cảnh báo sau khi có nháp, hành vi snapshot | không (chính xác; xem trên) |
| `units-permissions.army.ts` | 32, 34: đơn vị tính (32), từ chối user không phải admin | sửa đơn vị tính sản phẩm |

Không phủ ở đây: cảnh báo Lark, xuất/xóa dữ liệu cá nhân, uninstall và trường hợp thiếu WooCommerce (PHPUnit phủ các phần này). Đơn dùng HPOS (`setup-site.sh` ghim); màn hình đơn legacy do PHPUnit phủ.

## Mắt Bão giả

`fake-matbao/router.php` là một server PHP nhỏ, do `setup-site.sh` khởi động trong container WordPress; plugin gọi tới nó qua `EINVOICE_MATBAO_BASE_URL` (đặt trong `wp-config.php`, chỉ ở chế độ fake). Test kịch bản hóa lỗi theo từng endpoint (`script("create-invoice", [{ http: 503, store: true }])`: nháp được lưu nhưng phản hồi là lỗi, tức trường hợp mất phản hồi) và đọc lại những gì Mắt Bão nhận được. Shape phản hồi theo các câu trả lời demo đã ghi, kể cả việc kiểm tra trùng không nguyên tử.

`wordpress/einv-e2e-http.php` (mu-plugin) từ chối mọi host Mắt Bão trừ fake và API demo, và mở các seam `einv-e2e/v1` (tạo đơn, hoàn thành, làm retry đến hạn, đọc dòng) để dựng trạng thái nhanh.

Stack đã kiểm chứng: WordPress 7.1.4, WooCommerce 11.2.1, PHP 8.5.12 (stack `compose.yaml`), Chromium từ bản cài `@e2e-dev/web`, Node 26 trên Debian trixie trong image runner (cài đặt bằng bun).

## Những gì được test (`army/checkout-invoice.army.ts`)

Classic và Checkout Blocks: vãng lai (không tick) và doanh nghiệp; Classic có thêm cá nhân. Mỗi test đặt đơn, hoàn thành đơn, rồi kỳ vọng thấy nháp trong hộp hóa đơn trên đơn ("Đã tạo nháp") và trong WooCommerce → danh sách hóa đơn.

- Mắt Bão do fake trong `fake-matbao/router.php` trả lời; mu-plugin `wordpress/einv-e2e-http.php` từ chối request tới mọi host khác fake hoặc API demo, nên request không bao giờ tới host production.
- Bước agent: các trường yêu cầu xuất hóa đơn.
- Hành động `screen` chính xác: trường billing (nhãn chính xác: `Address` không được khớp `Email address`), đặt hàng, đăng nhập. Hoàn thành đơn đi qua một REST route chỉ dành cho E2E của cùng mu-plugin.

Kết quả thật với executor decision-model trước đây (chế độ live, cùng code, mỗi loại một lần):

| Trình duyệt | Classic vãng lai | Classic cá nhân | Classic doanh nghiệp | Blocks vãng lai | Blocks doanh nghiệp | Tổng lần gọi model |
|---|---|---|---|---|---|---|
| Chromium (mặc định) | pass 8 s | pass 19 s, 12 lần | pass 12 s, 15 lần | pass 4 s | pass 13 s, 15 lần | 42 |
| Lightpanda 1.0.2 | pass 7 s | pass 47 s, 12 lần | pass 14 s, 15 lần | pass 4 s | pass 13 s, 15 lần | 42 |

- Cả hai trình duyệt đều pass, nên Chromium (rẻ và đơn giản hơn) là mặc định. Lightpanda: `E2E_BROWSER=lightpanda`; cache đã commit được ghi trên Chromium.
- Agent `text` (`E2E_BLOCKS_AGENT=text`) được giữ làm tùy chọn nhưng không cần.
- Blocks ban đầu lỗi vì bundle Classic và Blocks đè global đã minify của nhau (sửa ở PR #30: bundle IIFE, script Classic không nạp trên trang Checkout Blocks).
- Lightpanda ban đầu lỗi trên image bookworm với `GLIBC_2.38 not found`; runner giờ dùng `node:26-trixie-slim` (glibc 2.41) và binary ghim sha256.

**Ghi chú setup:** trên trang Checkout do WooCommerce tạo, block hóa đơn không tự hiện ở front end (`blockHooks` chỉ chèn khi editor lưu trang). Vì vậy `setup-site.sh` chèn markup block bằng `wp eval`; đây là quan sát về sản phẩm, không phải thứ test này sửa.

## Các chế độ chạy

```bash
cd tests/e2e && bun install --frozen-lockfile
cp .env.e2e.example .env.e2e.local      # điền giá trị model; đã gitignore
bun run e2e:list                        # liệt kê test
bun run e2e:record                      # model + cache đọc-ghi, toàn bộ stack trong Docker
bun run e2e:live                        # model, tắt cache
bun run e2e:replay                      # cache chỉ đọc + strict, từ chối mọi *_API_KEY trong môi trường
```

Shell dev thường export sẵn khóa API (`OPENAI_API_KEY`, …) nên replay bị từ chối. Bỏ chúng chỉ cho lệnh này:

```bash
env $(env | grep -E '^[A-Z0-9_]*(API_KEY|AUTH_TOKEN|ACCESS_TOKEN)=' | cut -d= -f1 | sed 's/^/-u /') bun run e2e:replay
```

`scripts/run-e2e.ts` khởi động một compose project cho mỗi lần chạy (không publish cổng, mật khẩu admin ngẫu nhiên), một container trình duyệt dùng chung network namespace với WordPress (`http://localhost`), copy `.e2e/` và `army-cache/` sang `test-results/runs/<id>/`, áp cổng báo cáo (tất cả pass) và xóa mọi thứ đã tạo, kể cả khi nhận SIGINT/SIGTERM.

- Bước agent dùng một chat model thường (agent tích hợp sẵn, tool call) cho mọi agent, như trong [tài liệu model của e2e](https://e2e.tester.army/docs/models); `E2E_SYSTEMONE_*` không còn được đọc.
- Biến model (`E2E_TEXT_*`) chỉ vào container ở chế độ record/live. URL của text model phải truy cập được từ container.
- Bước agent replay từ `army-cache/` (8 mục, ghi bằng `gpt-6-luna`). Chi phí ghi xem mục bên dưới; replay không tốn gì.
- Secret cho test cài đặt được khai báo trong `secrets` của config (giá trị tổng hợp); agent chỉ thấy tên của chúng.

**Review cache:** sau `record`, kiểm tra `test-results/runs/<id>/army-cache/*.json`, xác nhận không có credential hay dữ liệu cá nhân (mật khẩu admin ngẫu nhiên mỗi lần chạy, dữ liệu người mua là tổng hợp), copy vào `army-cache/` và commit. Đổi instruction, màn hình ứng dụng hoặc `params` thì phải ghi lại.

**Order ID và replay cache (#35):** cache được khóa theo `params` của bước agent, nên bước mang order ID WooCommerce chỉ replay được khi mọi test trước tạo đúng các đơn đó. Lần phát hành đầu lỗi ở một lần đăng nhập (`signInAdmin` submit trước khi trang sẵn sàng), làm lệch mọi ID sau đó và khiến sáu bước miss cache (`MODEL_PROVIDER_FAILED` / `CI_MODEL_CALL_FORBIDDEN`). Hai cách sửa: `signInAdmin` thử lại form một lần, và mở hóa đơn duy nhất trong danh sách dùng locator chính xác (`openOnlyInvoice`), không phải bước agent có ID trong `params`. Giữ ID và timestamp ngoài `params`; đọc lỗi đầu tiên, không phải chuỗi lỗi dây chuyền.

**API demo thật:** `EINV_DEMO_E2E=1 EINV_DEMO_MST=… EINV_DEMO_USER=… EINV_DEMO_PASS=… bun run e2e:live` (chỉ tạo nháp; không bao giờ replay).

## Chi phí và giới hạn

- Ghi: khoảng 245k token cho năm test checkout, khoảng 1,5M cho toàn bộ suite.
- Replay: không gọi model, khoảng 10 phút cho 67 test.
- Giới hạn: vãng lai không có bước agent (không có gì để thao tác); một trình duyệt (Chromium); cache phụ thuộc markup chính xác của trang, nên cập nhật WooCommerce có thể cần ghi lại.
