## Ngôn ngữ tài liệu

Plugin chỉ dùng cho website Việt Nam nên tài liệu (README, `docs/`, `tests/e2e/README.md`, description của `composer.json`/`package.json` và GitHub repo) viết tiếng Việt; đây là ngoại lệ của rule "repo public viết tiếng Anh".
Code comments/docblock, commit, PR và identifier vẫn viết tiếng Anh; chuỗi UI vẫn tiếng Việt qua text domain `einvoice-matbao`.

## Agent skills

### Issue tracker

Issue và spec nằm trong GitHub Issues; đọc quy trình thao tác tại `docs/agents/issue-tracker.md` trước khi xử lý ticket.

### Triage labels

Dùng 5 triage labels mặc định; đọc mapping tại `docs/agents/triage-labels.md` trước khi phân loại issue.

### Domain docs

Repo dùng single-context; đọc `docs/agents/domain.md` trước khi khám phá codebase để áp dụng glossary và ADR liên quan.

### Tài liệu API Mắt Bão (không commit)

Tài liệu gốc của Mắt Bão nằm ngoài git tại `OneDrive_1_10-13-2026/` ở root repo (đã gitignore, chỉ có trên máy local):

- `HoaDonBanRa/API-Proxy-HDDT-v1.0.1.docx`: API create-invoice, sign-invoice, invoice-detail-batch, mã lỗi. Đọc bằng `textutil -convert txt -stdout <file>`.
- `HopDongDienTu (Chữ ký số Tập Trung)/`: tài liệu hợp đồng điện tử, ngoài phạm vi hiện tại.

Không copy các file này vào repo. Kết luận đã khảo sát nằm ở `docs/discovery.md` và `docs/research/`.
