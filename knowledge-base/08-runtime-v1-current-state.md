# Runtime V1 — Current State

Tài liệu này tóm tắt trạng thái hiện tại của plugin theo đúng code đang chạy, không phải theo kế hoạch ban đầu.

Nếu cần một note thiên về dạy học và so sánh "bản cũ vs bản mới", xem thêm `09-runtime-v1-teaching-guide.md`.

---

## 1. Những gì đã hoàn thành

### Runtime backbone

- Chat runtime đã có `conversation_id` thật, không còn chỉ là session tạm trong browser.
- Saved conversations lưu được cả:
  - `messages`
  - `history`
  - `usage`
  - `meta`
- Có thể load lại conversation cũ rồi chat tiếp mà vẫn giữ `apiHistory`.
- `useChat()` phân biệt rõ:
  - display transcript cho UI
  - API history gửi lên backend

### Session UX

- Header chat có `New` để bắt đầu session mới.
- `Session History` hỗ trợ:
  - search
  - sort
  - rename inline
  - archive
  - load lại session cũ
- Current live session không còn xuất hiện trong danh sách history.
- Archived sessions bị ẩn mặc định khỏi history list.
- Session title không còn chỉ bám câu user đầu tiên; backend có heuristic rename từ tool/action outcome.

### Confirmation & safety policy

- Runtime có action classification metadata chuẩn hóa:
  - `action_type`
  - `risk_level`
  - `requires_confirmation`
  - `is_async`
  - `title`
  - `summary`
  - `impact`
  - `current_state`
  - `proposed_state`
  - `confirm_label`
  - `cancel_label`
- Confirmation được enforce ở runtime, không phụ thuộc model tự giác.
- Frontend có task-state rail riêng cho:
  - `awaiting approval`
  - `working`
  - `background task queued`

### Content policy

- `create_post`, `create_simple_post`, `create_rich_post`
  - `draft` không cần confirm
  - `publish` và `private` cần confirm
- `update_post`
  - confirm khi đổi sang `publish`, `private`, `trash`
  - confirm cả khi sửa nội dung/title của post hoặc page đang `publish` / `private`
- `set_post_image`
  - confirm khi target là post/page đang live hoặc private
  - draft-safe thì không confirm

### Site-level policy

Các action site-level sau đã có confirmation semantics:

- `install_plugin`
- `install_theme`
- `activate_plugin`
- `deactivate_plugin`
- `switch_theme`
- `update_site_settings`
- `set_site_icon`
- `update_user_role`
- `security_harden`
- `wordfence_update_settings`
- `wordfence_disconnect_central`

### Async / background actions

- `wordfence_run_scan` đã được phân loại là `background_job`.
- UI hiển thị trạng thái `queued` thay vì nhìn như action đã hoàn tất.
- Task rail hiển thị follow-up tool và delay gợi ý nếu có.

### Hallucination guards

Frontend runtime hiện có guard bổ sung cho 2 dạng sai phổ biến:

- Nếu tool result fail nhưng assistant text lại nói như đã thành công, transcript sẽ tự chèn warning.
- Nếu tool result chỉ là async `queued` nhưng assistant text lại nói như đã hoàn tất, transcript sẽ tự chèn warning.

Guard này không thay model, nhưng giảm rủi ro UI “nói thắng” khi tool state không chứng minh điều đó.

---

## 2. Coverage hiện có

### PHP integration

Đã có regression coverage cho:

- session payload envelope + legacy compatibility
- archived session filtering
- title auto-upgrade
- confirmation rules cho site-level actions
- confirmation rules cho content actions
- page/post live-state conditions
- async action classification
- long-history normalization và orphan tool trimming
- confirmed action fail path

### JS / hook / component

Đã có tests cho:

- `parseSSE()`
- `useChat()`
- `ConversationManager`
- `TaskRail`

### Browser E2E

Playwright hiện đã khóa các pattern quan trọng:

- destructive confirm / cancel
- install plugin / theme
- publish vs draft content
- live post update rules
- featured image confirmation rules
- hallucination guard cho:
  - failed tool nhưng assistant overclaims success
  - async queued nhưng assistant overclaims completion

---

## 3. UI hiện tại

Trong chat panel hiện có 3 lớp state chính:

1. Transcript
   - lịch sử hội thoại và tool badges
2. Task rail
   - approval / working / queued state
3. Session history
   - search, sort, rename, archive, load

Điểm quan trọng: confirmation controls không còn nằm trong input bar như trước. Input bar giờ chỉ còn vai trò nhập lệnh.

---

## 4. Các command verify đang dùng

```bash
npm run build
npm run test:php:integration
npm run test:js:runtime
npx vitest run tests/js/components/conversation-manager.test.jsx
npx vitest run tests/js/components/task-rail.test.jsx
npx playwright test tests/e2e/confirmation.spec.js
npx playwright test tests/e2e/hallucination-guards.spec.js
```

---

## 5. Những gì chưa nên hiểu lầm là “đã xong”

Runtime V1 hiện đã khá vững ở backbone và policy, nhưng chưa phải full agent architecture.

Chưa có các lớp sau:

- long-term/shared memory thực thụ
- semantic retrieval
- planner/executor tách lớp
- async job orchestration hoàn chỉnh
- summarization/compact thông minh thay cho window trimming đơn thuần

Nói ngắn gọn: đây là một Runtime V1 có session backbone, confirmation policy, async awareness và regression coverage tốt; chưa phải agent orchestration hoàn chỉnh kiểu multi-layer memory system.
