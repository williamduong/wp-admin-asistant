# Runtime V1 Teaching Guide

## 1. Mục tiêu của note này

Note này dùng để dạy nhanh sự khác nhau giữa:

- bản cũ: chatbot WordPress "vừa đủ dùng", còn dễ loạn ngữ cảnh, quên trạng thái, nói quá kết quả, và phụ thuộc nhiều vào khả năng generative của model
- bản hiện tại (`Runtime V1`): đã đưa nhiều phần quan trọng của logic agent về phía runtime, theo tinh thần các agent kiểu Codex

Điểm cốt lõi cần nhớ:

> Model vẫn sinh text và đề xuất tool call, nhưng **runtime mới là nơi nắm state, policy, guardrail, và cách diễn giải kết quả tool**.

Nếu cần bản cực ngắn để trình bày nhanh, xem thêm `10-runtime-v1-cheatsheet.md`.
Nếu cần đào sâu riêng phần điều phối runtime, xem thêm `11-runtime-v1-orchestration-analysis.md`.

---

## 2. Bản cũ thường hỏng ở đâu

### 2.1. Loạn ngữ cảnh

Nếu chỉ append lịch sử chat thô vào model, hệ thống dễ gặp:

- mất session khi reload
- không biết user đang tiếp tục task cũ hay bắt đầu task mới
- history dài làm trôi mất bước tool trước đó
- lẫn lộn giữa text hiển thị cho user và context thật cần gửi cho provider

### 2.2. Ảo giác trạng thái

Nếu tin hoàn toàn vào câu trả lời của model:

- tool fail nhưng model vẫn nói "xong rồi"
- job nền mới chỉ `queued` nhưng model lại nói "scan complete"
- action nguy hiểm có thể bị diễn đạt như chuyện nhỏ, dù thực tế làm đổi site live

### 2.3. Quá phụ thuộc prompt

Nếu chỉ viết prompt kiểu "hãy cẩn thận", "hãy hỏi confirm", hệ thống vẫn yếu vì:

- model có thể quên rule
- provider khác nhau map tool call khác nhau
- cùng một action nhưng rủi ro phụ thuộc state hiện tại của site, không phải chỉ phụ thuộc câu user

Nói ngắn gọn: prompt chỉ là **gợi ý hành vi**, chưa đủ làm **runtime contract**.

---

## 3. So sánh rất nhanh: bản cũ vs Runtime V1

| Chủ đề | Bản cũ | Runtime V1 |
|---|---|---|
| Session | Chủ yếu là chat tạm trong browser | Có `conversation_id`, save/load session, restore history thật |
| Context | Dễ append transcript thô | Có `normalize_history()`, cắt window, bỏ message sai shape |
| Tool usage | Dễ trông chờ model "biết gọi đúng" | Tool đi qua registry + internal contract + runtime classification |
| Safety | Chủ yếu nhờ prompt dặn dò | `classify_action()` quyết định confirm, async, risk level |
| Confirmation | Thường chỉ là câu chữ | Thành metadata có cấu trúc: `summary`, `impact`, `current_state`, `proposed_state` |
| Async | Dễ bị nói như đã xong | Có `queued` state, follow-up metadata, rail riêng |
| Hallucination | Dễ tin assistant text | UI đối chiếu text với `tool_end.result` rồi tự chèn warning |
| Source of truth | Model text thường lấn át | Runtime state + tool result + policy code là source of truth |

Đây là ý chính nhất để dạy:

> Bản cũ là "LLM gọi tool". Runtime V1 là "runtime kiểm soát vòng đời của tool".

---

## 4. Runtime V1 đã học theo "tinh thần Codex" ở những điểm nào

### 4.1. Session backbone thuộc về runtime, không thuộc về model

Ở `src/hooks/useChat.js`:

- lưu riêng `messages`, `history`, `usage`, `conversationId`, `pendingConfirmation` vào `localStorage`
- phân biệt:
  - `messages`: transcript để hiển thị UI
  - `apiHistory`: history nội bộ để gửi provider
- khi load session cũ, hệ thống khôi phục cả history và usage, không chỉ khôi phục text

Ở `includes/class-rest-api.php`:

- `/chat` nhận `conversation_id`
- `resolve_history()` ưu tiên `history` inline, nếu không có mới fallback sang conversation đã lưu

Đây là pattern rất quan trọng: **runtime sở hữu session state**.

### 4.2. History được normalize trước khi gửi provider

Ở `includes/class-agent.php`:

- `build_runtime_messages()` chỉ ghép:
  - history đã normalize
  - user message hiện tại
- `normalize_history()`:
  - bỏ entry sai shape
  - chỉ giữ `user`, `assistant`, `tool` đúng contract
  - cắt còn `MAX_HISTORY_MESSAGES = 24`
  - bỏ orphan `tool` message ở đầu window

Đây chưa phải semantic memory hoàn chỉnh, nhưng đã là một dạng **compact/trim có kiểm soát** thay vì nhồi nguyên transcript vô model.

### 4.3. Request không đi thẳng từ user sang model; nó đi qua runtime loop

Flow đơn giản hóa hiện tại:

1. User nhập yêu cầu ở UI.
2. Frontend gửi `message + history + conversation_id + confirmation` qua `chatStream()` trong `src/lib/api.js`.
3. Backend `handle_chat()` ở `includes/class-rest-api.php` resolve lại history thật từ request hoặc session đã lưu.
4. `WAA_Agent::run()` gửi prompt + messages đã normalize sang provider.
5. Provider trả `text` và/hoặc `tool_calls`.
6. Mỗi `tool_call` đi qua `classify_action()`.
7. Runtime quyết định một trong ba hướng:
   - cho chạy ngay
   - chặn và phát `confirmation_required`
   - đánh dấu là async/background
8. Sau `tool_end`, frontend không chỉ hiển thị text, mà còn giữ:
   - tool evidence
   - status
   - follow-up metadata
   - hallucination warning nếu text mâu thuẫn result

Điểm quan trọng:

- `parseSSE()` ở `src/lib/sse.js` chỉ là parser stream
- `chatStream()` ở `src/lib/api.js` chỉ là transport
- logic "được làm gì, khi nào được làm, tin vào cái gì" nằm ở runtime

### 4.4. Model đề xuất tool; runtime quyết định có được chạy không

Phần "giống Codex" rõ nhất nằm ở `includes/class-agent.php`:

- provider trả về `tool_calls`
- mỗi tool call đi qua `classify_action()`
- runtime tự quyết:
  - `action_type`
  - `risk_level`
  - `requires_confirmation`
  - `is_async`
  - `current_state`
  - `proposed_state`

Tức là:

- model **không phải** nơi quyết định cuối cùng action nào nguy hiểm
- prompt **không phải** lớp enforcement cuối
- runtime mới là "policy engine"

Ví dụ:

- `wordfence_run_scan` được classify là `background_job`, `is_async = true`, không cần confirm
- `create_post` chỉ cần confirm nếu `status` là `publish` hoặc `private`
- `update_post` còn nhìn cả trạng thái hiện tại của bài để biết sửa đó có đụng nội dung live hay không

### 4.5. Phần chọn tool không nên dựa quá nhiều vào generative API

Nói chính xác hơn:

- model vẫn dùng khả năng generative để hiểu yêu cầu và sinh `tool_calls`
- nhưng code đã kéo phần rủi ro lớn về runtime:
  - tool nào được phép tồn tại: registry
  - tool nào cần confirm: `classify_action()`
  - content action nào là live-risk: `update_post_requires_confirmation()`
  - async nào chưa complete: `isQueuedAsyncResult()`
  - text nào overclaim: `guardAssistantTurnContent()`

Nghĩa là code không cố "thay model suy nghĩ", mà làm việc quan trọng hơn:

- **thu hẹp sân chơi**
- **áp constraint**
- **xác minh kết quả**
- **giữ state lâu hơn một turn**

Đây mới là chỗ giúp agent bớt phụ thuộc vào "model hôm nay có ngoan không".

### 4.6. Confirmation là contract có cấu trúc, không chỉ là câu chữ

Khi cần chặn action, runtime không chỉ trả text "hãy confirm", mà phát event `confirmation_required`.

Ở backend:

- `includes/class-agent.php`

Ở frontend:

- `src/hooks/useChat.js`
- `src/components/TaskRail.jsx`

Contract này có:

- `title`
- `summary`
- `impact`
- `risk_level`
- `action_type`
- `current_state`
- `proposed_state`
- `confirm_label`
- `cancel_label`

Nghĩa là UI render theo metadata chuẩn hóa, không cần đoán từ text của model.

### 4.7. Confirm xong mới replay action; không chạy lén

Khi user đồng ý:

- frontend gửi lại payload `confirmation`
- backend chạy `run_confirmed_action()`

Xem:

- `includes/class-agent.php`
- `includes/class-rest-api.php`

Ý nghĩa kiến trúc:

- model không được "lụi" qua confirm bằng cách tự nói "đã được duyệt"
- chỉ payload có cấu trúc từ UI mới mở được bước execute thật

### 4.8. UI không tin hoàn toàn vào lời assistant

Ở `src/hooks/useChat.js`, `guardAssistantTurnContent()` tự kiểm:

- nếu tool fail nhưng text lại nói như đã xong, append warning
- nếu tool chỉ `queued` mà text lại nói như đã complete, append warning

Đây là điểm rất đáng dạy:

> Assistant text là một tín hiệu, không phải source of truth.

Source of truth phải là:

- tool result
- tool status
- runtime state

### 4.9. Async action được phân loại riêng, không giả vờ như sync action

`wordfence_run_scan` hiện được xem là background job:

- backend classify là async
- frontend hiển thị riêng trên `TaskRail`
- tool result được giữ metadata follow-up như:
  - `recommended_poll_tool`
  - `recommended_delay_sec`

Tham chiếu:

- `includes/class-agent.php`
- `src/components/TaskRail.jsx`

### 4.10. Tool surface bị khóa bằng registry và internal contract

Agent không được gọi tool "tưởng tượng".

Ở `includes/class-agent.php`:

- system prompt append danh sách tool thật từ registry
- yêu cầu dùng đúng exact name

Ở toàn bộ runtime:

- assistant/tool messages đi theo contract nội bộ thống nhất:
  - `assistant.tool_calls[] = { id, name, input }`
  - `tool = { tool_call_id, tool_name, result }`

Điểm này rất quan trọng khi dạy:

> Muốn bớt phụ thuộc model, phải thu hẹp không gian tự do của model.

---

## 5. Bài học kiến trúc quan trọng nhất

### 5.1. Đừng để model tự "quản lý sự thật"

Model không nên tự chịu trách nhiệm cho:

- state hiện tại của site
- policy an toàn
- kết quả thật của tool
- chuyện job nền đã xong chưa

Những thứ này phải đi qua code.

### 5.2. Prompt là guidance, runtime mới là enforcement

Prompt vẫn cần, nhưng chỉ để:

- giải thích vai trò
- hướng tool usage
- nói cách phản hồi

Enforcement thật nằm ở:

- `classify_action()`
- `normalize_history()`
- `resolve_history()`
- `guardAssistantTurnContent()`
- REST + registry + SSE contract

### 5.3. Hãy tách ba lớp dữ liệu

Runtime V1 đã bắt đầu tách đúng:

1. `display messages`
2. `provider runtime history`
3. `tool result / state / confirmation metadata`

Nếu trộn ba thứ này vào một transcript duy nhất, sớm muộn agent sẽ loạn.

---

## 6. Các test case nên dùng để dạy

### Case A. Khôi phục session rồi chat tiếp

Mục tiêu: chứng minh hệ thống không "quên sạch" khi reload hoặc load conversation cũ.

Xem:

- `tests/php/integration/RestApiSafetyTest.php`

Ý nghĩa:

- saved conversation có tool history
- runtime load lại history đó
- fake provider có thể tiếp tục đúng ngữ cảnh cũ

### Case B. Publish cần confirm, draft thì không

Mục tiêu: chứng minh policy không nằm ở prompt chung chung, mà nằm ở runtime classification.

Xem:

- `tests/php/integration/RestApiSafetyTest.php`
- `tests/e2e/confirmation.spec.js`

Ý nghĩa:

- cùng là create/update content
- nhưng status khác thì policy khác

### Case C. Tool fail nhưng model nói "xong"

Mục tiêu: dạy về hallucination guard.

Xem:

- `tests/js/hooks/useChat.test.js`
- `tests/e2e/hallucination-guards.spec.js`

Ý nghĩa:

- text của assistant không được xem là chân lý
- UI phải đối chiếu với tool result thật

### Case D. Async queued nhưng model nói "complete"

Mục tiêu: dạy về background job semantics.

Xem:

- `tests/js/hooks/useChat.test.js`
- `tests/e2e/hallucination-guards.spec.js`

Ý nghĩa:

- queued != completed
- runtime phải surface rõ trạng thái trung gian

### Case E. Multi-step tool reasoning nhưng confirmation không được biến mất

Mục tiêu: dạy về việc giữ evidence của các bước trước.

Xem:

- `tests/js/hooks/useChat.test.js`

Pattern:

1. model gọi `list_plugins`
2. sau đó mới đòi `install_plugin`
3. UI vẫn phải giữ:
   - evidence của `list_plugins`
   - rail chờ confirm

### Case F. Structured state render không được crash

Mục tiêu: dạy về structured UI contract.

Xem:

- `tests/js/components/task-rail.test.jsx`

Ý nghĩa:

- `proposed_state` có thể là object
- UI phải render được an toàn, không nổ React

### Case G. Session thật sự đi xuyên suốt frontend → backend → runtime

Mục tiêu: dạy rằng runtime không còn coi mỗi turn là stateless.

Xem:

- `src/lib/api.js`
- `src/hooks/useChat.js`
- `tests/js/hooks/useChat.test.js`

Ý nghĩa:

- chat request gửi `conversation_id`
- runtime update lại conversation sau từng turn
- session không còn chỉ là "text đã chat", mà là state có thể khôi phục

### Case H. Runtime vẫn giữ tool evidence khi provider đi nhiều iteration

Mục tiêu: dạy về sự khác nhau giữa:

- assistant text cuối cùng
- tool evidence của các bước trung gian
- history thật gửi ngược lại provider

Xem:

- `tests/js/hooks/useChat.test.js`
- `src/hooks/useChat.js`

Ý nghĩa:

- một turn có thể gồm nhiều phase
- không được để phase sau xóa sạch evidence của phase trước
- đây là nền để agent đỡ "quên giữa đường"

---

## 7. Câu chốt để dạy team

Nếu phải nói gọn trong 4 câu:

1. Bản cũ là "LLM chat biết gọi tool".
2. Runtime V1 là "agent runtime có state, policy, guardrail, và evidence".
3. Model vẫn quan trọng, nhưng model **không còn là nơi quyết định cuối cùng**.
4. Chất lượng agent tăng lên khi ta chuyển dần logic từ prompt sang runtime contract + test.

---

## 8. Nếu phải dạy về "logic chọn tool", hãy nhấn mạnh 4 câu này

1. Model chỉ nên làm phần hiểu ngôn ngữ và đề xuất bước tiếp theo.
2. Runtime phải giữ registry tool thật và internal message contract thật.
3. Mọi action có rủi ro phải đi qua policy code, không đi qua niềm tin vào prompt.
4. Kết quả cuối cùng phải được xác nhận bằng tool result và runtime state, không chỉ bằng lời assistant.

Nếu team hiểu được 4 câu này, họ sẽ bớt viết kiểu:

- "prompt dài hơn cho chắc"
- "nhắc model cẩn thận hơn"

và chuyển sang viết kiểu:

- "state nào runtime phải sở hữu?"
- "action nào phải classify?"
- "result nào cần verify?"
- "UI nên tin event nào, không nên tin câu nào?"

---

## 9. Command verify hữu ích

```bash
npm run build
npm run test:php:integration
npm run test:js:runtime
npx playwright test tests/e2e/confirmation.spec.js
npx playwright test tests/e2e/hallucination-guards.spec.js
```

Các command này đủ tốt để demo:

- state/session backbone
- confirmation policy
- async awareness
- hallucination guard
- browser UX cho các pattern quan trọng

---

## 10. Giới hạn hiện tại

Để dạy đúng, cũng cần nói thẳng những gì chưa có:

- chưa có long-term/shared memory thực thụ
- chưa có semantic retrieval
- chưa có planner/executor tách lớp hoàn chỉnh
- chưa có summarization thông minh; hiện tại mới là bounded window + cleanup
- async orchestration mới ở mức nhận diện và surface state, chưa phải workflow nền hoàn chỉnh

Vì vậy, cách mô tả chính xác là:

> Runtime V1 đã hấp thụ nhiều pattern quan trọng của Codex-style agent runtime, đặc biệt ở chỗ session, policy, guardrail, structured tool workflow và anti-hallucination; nhưng chưa phải full agent OS.
