## Why

Khách vãng lai (không tick "Yêu cầu xuất hóa đơn") đã điền đủ billing nhưng hóa đơn nháp chỉ có `NMua_Ten` mặc định, nên thông tin khách khai bị mất. Địa chỉ còn bị nhập hai lần (billing và ô "Địa chỉ xuất hóa đơn") trong khi tên, SĐT, email lại lấy từ billing. Mắt Bão chỉ bắt buộc `NMua_Ten` và chỉ có một field `NMua_DChi`, nên cần thống nhất nguồn dữ liệu và gửi thông tin khách đã khai.

## What Changes

**Người mua vãng lai**
- From: payload chỉ có `NMua_Ten` (tên cấu hình), billing bị bỏ.
- To: gửi mọi thông tin billing hợp lệ (tên, địa chỉ, SĐT, email); tên cấu hình chỉ làm mặc định khi billing không có tên.
- Reason: khách điền thông tin thì phải đưa vào hóa đơn.
- Impact: không chặn checkout; trường sai định dạng bị bỏ qua. Dữ liệu cá nhân vào snapshot của đơn vãng lai nên exporter/eraser phải bao phủ.

**Địa chỉ cá nhân và doanh nghiệp**
- From: ô riêng "Địa chỉ xuất hóa đơn" (Classic và Blocks), bắt buộc.
- To: `NMua_DChi` ghép từ địa chỉ billing (địa chỉ 1, địa chỉ 2, thành phố, tỉnh/thành); bỏ ô riêng, vẫn bắt buộc và báo lỗi trước khi submit.
- Reason: một nguồn dữ liệu, khách nhập một lần, thống nhất với tên/SĐT/email.
- Impact: doanh nghiệp có địa chỉ đăng ký khác billing phải điền địa chỉ đăng ký vào billing; yêu cầu cũ đã lưu `address` vẫn dùng được khi billing trống.

## Capabilities

### New Capabilities

Không có.

### Modified Capabilities

- `buyer-invoice-request`: địa chỉ cá nhân/doanh nghiệp lấy từ billing (validate, lưu yêu cầu, ô Classic/Blocks, phản hồi tức thì trên trình duyệt); thêm yêu cầu cách ghép địa chỉ billing.
- `buyer-snapshot-privacy`: snapshot vãng lai mang thông tin billing, snapshot cá nhân/doanh nghiệp lấy địa chỉ từ billing; exporter và eraser bao phủ đơn vãng lai có dữ liệu billing.

## Impact

- PHP: `src/Buyer/BuyerRules.php`, `BuyerSnapshotter.php`, `BuyerSnapshot.php`, `BuyerRequestStore.php`, `src/Checkout/ClassicCheckout.php`, `BlocksCheckout.php`, `src/Privacy/BuyerPersonalData.php`, lớp mới ghép địa chỉ billing.
- Frontend: `assets/src/checkout.ts`, `checkout-blocks.ts`, `checkout-rules.ts` (và test).
- Test: integration (checkout Classic/Blocks, snapshot, privacy) và e2e `tests/e2e/army/checkout-validation.army.ts`.
- Tài liệu: `docs/huong-dan-su-dung.md`, `docs/discovery.md`.
- Không đổi DB schema, không thêm dependency, không đổi API/CLI công khai. Dữ liệu extension Blocks `address` được giữ trong schema nhưng bị bỏ qua để script cache cũ không nhận HTTP 400.
