# Runtime V1 Cheatsheet

## 1. Nói ngắn gọn: đã nâng cấp cái gì?

### Bản cũ

- chat được
- gọi tool được
- nhưng còn dễ:
  - loạn ngữ cảnh
  - quên session
  - nói quá kết quả
  - phụ thuộc vào việc model có "ngoan" hay không

### Runtime V1

- vẫn dùng model để hiểu ngôn ngữ và đề xuất tool
- nhưng phần quan trọng đã được kéo về runtime:
  - giữ session
  - normalize history
  - classify action
  - enforce confirmation
  - phân biệt sync vs async
  - đối chiếu text với tool result để chặn hallucination

> Câu chốt: model không còn là nơi quyết định cuối cùng. Runtime mới là nơi giữ sự thật.

Nếu cần note riêng chỉ tập trung vào orchestration loop, xem `11-runtime-v1-orchestration-analysis.md`.

---

## 2. "Tinh thần Codex" đã được implement ở đâu?

### A. Runtime sở hữu state

- có `conversation_id`
- save/load được `messages + history + usage + meta`
- reload xong vẫn chat tiếp được

File chính:

- `src/hooks/useChat.js`
- `includes/class-rest-api.php`

### B. Runtime sở hữu policy

- action nào cần confirm không do model tự quyết
- action nào là async/background không do model tự kể
- action nào an toàn hay rủi ro được quyết bằng code

File chính:

- `includes/class-agent.php`

Keyword chính:

- `classify_action()`
- `requires_confirmation()`

### C. Runtime sở hữu message contract

- assistant/tool messages có shape cố định
- history được normalize trước khi gửi provider
- không nhét nguyên transcript thô vào model

File chính:

- `includes/class-agent.php`
- `src/lib/sse.js`

### D. UI không tin hoàn toàn vào lời assistant

- tool fail thì UI tự chèn warning
- async mới `queued` thì UI không cho nhìn như đã complete

File chính:

- `src/hooks/useChat.js`
- `src/components/TaskRail.jsx`

---

## 3. Flow hiện tại: request đi như thế nào?

1. User nhập yêu cầu.
2. Frontend gửi `message + history + conversation_id + confirmation`.
3. Backend resolve lại history thật.
4. Agent gửi prompt + normalized history sang provider.
5. Provider trả `text` và/hoặc `tool_calls`.
6. Runtime classify từng tool call.
7. Runtime quyết định:
   - chạy ngay
   - chặn để confirm
   - đánh dấu async/background
8. Tool result quay lại UI cùng status thật.
9. Nếu assistant text nói sai so với tool result, runtime/UI chèn guard.

> Tức là request không đi thẳng từ user sang model rồi sang tool. Nó đi qua một lớp runtime kiểm soát.

---

## 4. Phần "chọn tool" bây giờ bớt phụ thuộc model như thế nào?

Không phải là model bị bỏ đi. Model vẫn dùng để:

- hiểu ý user
- chọn bước tiếp theo
- đề xuất tool call

Nhưng code đã thu hẹp tự do của model bằng 4 cách:

1. Registry
- chỉ có tool thật mới được gọi

2. Classification
- tool gọi rồi vẫn phải đi qua `classify_action()`

3. Verification
- text không thắng được tool result

4. State ownership
- session, history, confirmation, async status đều nằm ở runtime

Nói gọn:

> Ta không cố làm model thông minh tuyệt đối. Ta làm runtime đủ chặt để model khó làm bậy.

---

## 5. Các test case đáng đem ra dạy nhất

### Case 1. Restore session rồi chat tiếp

Ý nghĩa:

- chứng minh hệ thống không quên sạch sau reload

Xem:

- `tests/php/integration/RestApiSafetyTest.php`

### Case 2. Publish cần confirm, draft thì không

Ý nghĩa:

- policy nằm ở runtime, không nằm ở prompt chung chung

Xem:

- `tests/php/integration/RestApiSafetyTest.php`
- `tests/e2e/confirmation.spec.js`

### Case 3. Tool fail nhưng assistant nói "xong"

Ý nghĩa:

- text không phải source of truth

Xem:

- `tests/js/hooks/useChat.test.js`
- `tests/e2e/hallucination-guards.spec.js`

### Case 4. Async queued nhưng assistant nói "complete"

Ý nghĩa:

- queued != completed

Xem:

- `tests/js/hooks/useChat.test.js`
- `tests/e2e/hallucination-guards.spec.js`

### Case 5. Có bước `list_plugins` trước, rồi mới tới `install_plugin`

Ý nghĩa:

- multi-step reasoning không được làm mất evidence cũ

Xem:

- `tests/js/hooks/useChat.test.js`

---

## 6. 4 câu phải nhớ khi code agent kiểu này

1. Prompt chỉ là guidance; runtime mới là enforcement.
2. Model chỉ nên đề xuất; runtime phải quyết định.
3. Tool result mới là source of truth, không phải assistant text.
4. Muốn bớt hallucination, phải chuyển logic từ prompt sang contract + state + test.

---

## 7. Demo nhanh bằng command nào?

```bash
npm run build
npm run test:php:integration
npm run test:js:runtime
npx playwright test tests/e2e/confirmation.spec.js
npx playwright test tests/e2e/hallucination-guards.spec.js
```

---

## 8. Đừng nói quá

Runtime V1 đã có nhiều pattern tốt của Codex-style runtime, nhưng chưa phải full agent OS.

Chưa có:

- long-term memory thật
- semantic retrieval
- planner/executor hoàn chỉnh
- summarization thông minh
- async orchestration đầy đủ

Mô tả đúng nhất là:

> Đây là một Runtime V1 đã kéo phần quan trọng của agent về phía code: state, policy, guardrail, verification.
