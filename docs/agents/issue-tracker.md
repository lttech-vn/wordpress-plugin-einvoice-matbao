# Issue tracker: GitHub

Issue và spec của repo nằm trong GitHub Issues.
Dùng `gh` CLI cho mọi thao tác.

## Thao tác với issue

Repo hiện tại: `lttech-vn/wordpress-plugin-einvoice-matbao`.
Suy ra repo từ `git remote -v`; `gh` tự nhận remote khi chạy trong clone.

- **Tạo:** `gh issue create --title "..." --body "..."`.
  Dùng heredoc cho nội dung nhiều dòng.
- **Đọc:** `gh issue view <number> --comments`.
  Khi cần dữ liệu có cấu trúc, dùng `--json` và `--jq` để lấy labels, lọc comments.
- **Liệt kê:**

  ```bash
  gh issue list --state open \
    --json number,title,body,labels,comments \
    --jq '[.[] | {number, title, body, labels: [.labels[].name], comments: [.comments[].body]}]'
  ```

  Thêm `--label` và đổi `--state` theo phạm vi cần xử lý.

- **Comment:** `gh issue comment <number> --body "..."`.
- **Gắn label:** `gh issue edit <number> --add-label "..."`.
- **Gỡ label:** `gh issue edit <number> --remove-label "..."`.
- **Đóng:** `gh issue close <number> --comment "..."`.

## Pull requests as a triage surface

**PRs as a request surface: no.**

Đổi thành `yes` nếu repo dùng external PRs làm feature requests.
Skill `/triage` đọc flag này.

Khi bật, dùng cùng labels và states như issue:

- **Đọc:** `gh pr view <number> --comments` và `gh pr diff <number>`.
- **Liệt kê:** `gh api --method GET 'repos/{owner}/{repo}/pulls' --paginate -f state=open`.
  Chỉ giữ `author_association` thuộc `CONTRIBUTOR`,
  `FIRST_TIME_CONTRIBUTOR` hoặc `NONE`; loại `OWNER`, `MEMBER`,
  `COLLABORATOR`.
- **Comment:** `gh pr comment`.
- **Đổi labels:** `gh pr edit --add-label` hoặc `--remove-label`.
- **Đóng:** `gh pr close`.

GitHub dùng chung number space cho issues và PRs.
Với tham chiếu như `#42`, thử `gh pr view 42`; nếu không phải PR,
dùng `gh issue view 42`.

## Chuyển yêu cầu của skill thành thao tác

- **“publish to the issue tracker”:** tạo GitHub issue.
- **“fetch the relevant ticket”:** chạy `gh issue view <number> --comments`.

## Wayfinding operations

Skill `/wayfinder` dùng một issue làm **map**, các child issues làm tickets.

- **Map:** gắn label `wayfinder:map`; body chứa Notes,
  Decisions-so-far và Fog.
  Tạo bằng `gh issue create --label wayfinder:map`.
- **Child ticket:** liên kết với map bằng GitHub sub-issues qua `gh api`.
  Nếu sub-issues không khả dụng, thêm child vào task list của map,
  đặt `Part of #<map>` ở đầu body child.
  Labels: `wayfinder:<type>`, với type là `research`, `prototype`,
  `grilling` hoặc `task`. Khi claim, assign ticket cho dev xử lý.
- **Blocking:** ưu tiên native issue dependencies.
  Thêm dependency bằng:

  ```bash
  gh api --method POST \
    repos/<owner>/<repo>/issues/<child>/dependencies/blocked_by \
    -F issue_id=<blocker-db-id>
  ```

  Lấy database id của blocker bằng:

  ```bash
  gh api repos/<owner>/<repo>/issues/<n> --jq .id
  ```

  Dùng database id, không dùng issue number hoặc `node_id`.
  `issue_dependencies_summary.blocked_by` đếm blockers còn mở.
  Nếu dependencies không khả dụng, đặt
  `Blocked by: #<n>, #<n>` ở đầu body child.
  Ticket hết bị chặn khi mọi blocker đã đóng.
- **Frontier query:** liệt kê children còn mở của map, theo sub-issues
  hoặc task list. Loại ticket có assignee hoặc blocker còn mở.
  Chọn ticket đầu tiên theo thứ tự trong map.
- **Claim:** `gh issue edit <n> --add-assignee @me`;
  đây là thao tác ghi đầu tiên của session `/wayfinder`.
- **Resolve:** comment kết quả bằng `gh issue comment`,
  đóng bằng `gh issue close`, rồi thêm context pointer gồm
  tóm tắt và link vào Decisions-so-far của map.
