# Domain docs

Quy tắc cho engineering skills khi đọc domain documentation.
Repo dùng single-context; glossary và ADR được tạo khi có thuật ngữ
hoặc quyết định cần ghi nhận.

## Đọc trước khi khám phá codebase

- Đọc `GLOSSARY.md` tại repo root.
- Nếu có `GLOSSARY-MAP.md` tại root, đọc map và các glossary liên quan.
- Đọc ADR trong `docs/adr/` liên quan tới phạm vi đang xử lý.
  Với multi-context, kiểm tra thêm `src/<context>/docs/adr/`.

Nếu tài liệu chưa tồn tại, tiếp tục im lặng: không báo thiếu,
không đề xuất tạo trước. Skill `/domain-modeling`, được gọi qua
`/grill-with-docs` hoặc `/improve-codebase-architecture`, tạo tài liệu
khi thuật ngữ hoặc quyết định thực sự được chốt.

## Layout single-context

- `GLOSSARY.md`: thuật ngữ dùng chung cho repo.
- `docs/adr/`: architectural decisions của repo.

Nếu chuyển sang multi-context, dùng `GLOSSARY-MAP.md` tại root
để trỏ tới glossary từng context; giữ ADR toàn hệ thống tại
`docs/adr/`, ADR từng context tại `src/<context>/docs/adr/`.

## Dùng vocabulary của glossary

Khi đặt tên domain concept trong issue title, đề xuất refactor,
giả thuyết hoặc test name, dùng thuật ngữ được định nghĩa trong
glossary. Tránh synonyms mà glossary đã loại trừ.

Nếu concept chưa có trong glossary, kiểm tra xem đó là ngôn ngữ
không thuộc dự án hay khoảng trống thực sự.
Ghi nhận khoảng trống thực sự cho `/domain-modeling`.

## Nêu rõ xung đột ADR

Nếu đề xuất trái ADR hiện có, nêu ADR và lý do cần xem xét lại
thay vì âm thầm ghi đè quyết định.

Ví dụ: “Trái ADR-0007, nhưng cần xem xét lại vì…”.
