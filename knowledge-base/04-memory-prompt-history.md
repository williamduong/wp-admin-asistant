# Bộ nhớ, Prompt & History — WP Admin AI Assistant

## 1. System Prompt — Cấu trúc và thứ tự

System prompt được build trong `WAA_Agent::system_prompt()` mỗi khi có request. Không cache — luôn fresh để đảm bảo site context chính xác.

### Cấu trúc 3 phần

```
┌─────────────────────────────────────────────────────────┐
│  PHẦN 1: Base prompt (hardcode trong class-agent.php)   │
│  - Role definition                                      │
│  - Powered by: {provider_label}                         │
│  - Site context: URL, title, WP version, timezone, user │
│  - Runtime rules + safety rules                         │
├─────────────────────────────────────────────────────────┤
│  PHẦN 2: Model-specific guidance                        │
│  (từ $provider->get_model_instructions())               │
│  - Provider-specific behavior hints                     │
│  - Empty string nếu provider không override             │
├─────────────────────────────────────────────────────────┤
│  PHẦN 3: Custom rules                                   │
│  (từ WAA_Settings::get_custom_rules())                  │
│  - Admin-defined rules                                  │
│  - Appended last → highest priority trong LLM context   │
└─────────────────────────────────────────────────────────┘
```

### Phần 1: Base prompt đầy đủ

```
You are a WordPress admin assistant embedded in the wp-admin panel.
Powered by: Anthropic (Claude)

Site context:
- URL: https://mysite.com
- Title: My WordPress Site
- WordPress: 6.5.0
- Timezone: Asia/Ho_Chi_Minh
- Current user: Admin

Your job: help administrators configure WordPress through natural language.

Rules:
1. Read current state before modifying (use get_ tools first).
2. For destructive or sensitive site-level actions, and for content actions that publish/private/trash or edit live content, state clearly what you will do and ask for confirmation before using the write tool.
3. Never expose or repeat API keys, passwords, or credentials.
4. If a tool returns an error, explain it and suggest a fix.
5. Be concise. Confirm changes after every successful write.
6. Respond in the same language the user writes in.
7. When asked to change the site icon without a specific URL, call search_icon first, then immediately call set_site_icon with the best matching result — do not list options or ask the user to choose unless no results are found.
8. Use `create_simple_post` for short announcements; use `create_rich_post` for longer topic-driven posts with structure and image support.
9. Use `search_images` + `set_post_image` when the user wants to add or replace images on existing content.
10. Use `fetch_rss` for latest-news/news-post flows instead of pretending to know current events.
```

**Điểm quan trọng của Rule 7:** AI không hỏi user chọn icon — nó tự chọn cái tốt nhất và set luôn. Chỉ hỏi nếu `search_icon` không tìm thấy gì.

### Phần 2: Model-specific guidance

Mỗi provider có instructions riêng để tối ưu behavior:

**Anthropic (`get_model_instructions()`):**
```
You are running on Anthropic Claude. You excel at structured tool use and step-by-step reasoning.
- Always read current state before modifying (call a get_ tool first).
- Chain tool calls within one turn when steps are independent.
- Prefer concise confirmations after each successful write.
```

**Gemini (`get_model_instructions()`):**
```
You are running on Google Gemini. Follow these constraints exactly:
- Pass only parameters that are defined in the tool schema — do not invent extra fields.
- If a tool has no required parameters, call it with an empty argument object.
- Do not emit raw JSON in chat responses; use tools for structured actions.
```

> Gemini có xu hướng invent extra fields không có trong schema — instruction này prevent điều đó.

**Ollama (`get_model_instructions()`):**
```
You are running on a local Ollama model. Keep responses short and direct.
- Tool support depends on the model. If a tool call fails, explain what you tried and suggest an alternative.
- Prefer plain-text answers over tool calls when the answer is obvious.
- Do not attempt parallel tool calls — call one tool at a time.
```

> Ollama models thường không reliable với parallel tool calls — instruction này force sequential.

### Phần 3: Custom rules

Admin set trong Settings → System Prompt tab. Ví dụ:

```
- Always respond in formal Vietnamese (xưng hô: "Anh/Chị - tôi").
- Never activate or deactivate plugins without first listing what will change.
- When creating posts, always set the category to 'News' unless specified otherwise.
- Do not install plugins without mentioning the plugin's star rating and active installs.
```

Custom rules được lưu qua `update_option('waa_custom_rules', ...)` và retrieved bởi `WAA_Settings::get_custom_rules()`.

---

## 2. Session History — apiHistory vs Display Messages

Có 2 loại state riêng biệt trong `useChat.js`:

### Display messages (`messages` state)

Dùng để render UI. Chứa thêm UI-specific fields:

```javascript
// User message
{ role: 'user', content: 'List all plugins', id: 'uuid-1' }

// Assistant message (UI format)
{
    role: 'assistant',
    content: 'Here are your plugins:',
    toolCalls: [
        {
            tool_use_id: 'tc_123',
            name: 'list_plugins',
            status: 'done',      // 'running' | 'done'
            result: { plugins: [...] }
        }
    ],
    isError: false,
    id: 'uuid-2'
}
```

Fields `id`, `status`, `isError` không gửi lên server — chỉ dùng cho React rendering.

### API history (`apiHistory` state)

Format chuẩn để gửi lên backend. Không có UI fields, đúng internal message contract:

```javascript
// User message
{ role: 'user', content: 'List all plugins' }

// Assistant message
{
    role: 'assistant',
    content: 'Here are your plugins:',
    tool_calls: [
        { id: 'tc_123', name: 'list_plugins', input: {} }
    ]
}

// Tool result
{
    role: 'tool',
    tool_call_id: 'tc_123',
    tool_name: 'list_plugins',
    result: { plugins: [...] }
}
```

### Sự khác biệt quan trọng

| Field | Display messages | API history |
|-------|-----------------|-------------|
| `id` (UUID) | Có | Không |
| `toolCalls` (UI format) | Có | Không |
| `tool_calls` (backend format) | Không | Có |
| `status` ('running'/'done') | Có | Không |
| `isError` flag | Có | Không |
| `role: 'tool'` messages | Không (embedded trong assistant) | Có (separate entries) |

---

## 3. localStorage Persistence

### Key: `waa_chat_v1`

```javascript
// Schema của object lưu trong localStorage
{
    messages: [/* display messages array */],
    history:  [/* apiHistory array */],
    usage: {
        input_tokens:  1234,
        output_tokens: 567,
        cost_usd:      0.00456,
        elapsed_ms:    2500
    },
    conversationId: 42,
    pendingConfirmation: null
}
```

### Persistence lifecycle

```javascript
// Đọc khi component mount (lazy initialization)
const stored = loadFromStorage;  // function reference, không gọi ngay
useState(() => stored().messages)
useState(() => stored().history ?? [])
useState(() => stored().usage)

// Ghi mỗi khi state thay đổi
useEffect(() => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
        messages,
        history: apiHistory,
        usage: sessionUsage,
        conversationId,
        pendingConfirmation,
    }));
}, [messages, apiHistory, sessionUsage, conversationId, pendingConfirmation]);

// Xóa khi user click "New"
clearMessages() {
    setMessages([]);
    setApiHistory([]);
    setSessionUsage({ input_tokens: 0, output_tokens: 0, cost_usd: 0 });
    setConversationId(null);
    setPendingConfirmation(null);
    localStorage.removeItem(STORAGE_KEY);
}
```

### Điểm quan trọng

- Session **persist qua page reload** — user có thể navigate trong wp-admin mà không mất conversation
- Tool results cũng được persist — nếu user reload, họ thấy lại toàn bộ conversation
- Pending confirmation cũng được persist, nên nếu reload giữa lúc chờ approve thì UI vẫn restore đúng workflow state
- Nếu localStorage bị full hoặc JSON parse lỗi, `loadFromStorage()` trả về fresh state
- **Không sync giữa multiple tabs** — mỗi tab có state riêng

---

## 4. SSE Iteration Tracking — `usage` event là boundary

### Vấn đề cần giải quyết

Khi AI cần gọi nhiều tool qua nhiều iterations, SSE stream gửi events liên tục không có explicit iteration boundary. Frontend cần tự detect khi nào một iteration kết thúc và iteration mới bắt đầu.

**Giải pháp:** Dùng `usage` event làm boundary marker. Mỗi lần provider complete() được gọi → agent yield `usage` event → frontend biết đây là đầu iteration mới.

### Timeline ví dụ

```
Agent iteration 1 starts
  → yield usage         ← MARKER 1 (firstUsageSeen=false → skip flush)
  → yield text_delta("I'll check plugins first")
  → yield tool_start(list_plugins, tc_1)
  → [PHP: execute tool]
  → yield tool_end(tc_1, result)

Agent iteration 2 starts (gọi provider lần 2 với tool results)
  → yield usage         ← MARKER 2 (firstUsageSeen=true, iterationHasTools=true → FLUSH)
  → yield text_delta("Now I'll deactivate it")
  → yield tool_start(deactivate_plugin, tc_2)
  → [PHP: execute tool]
  → yield tool_end(tc_2, result)

Agent iteration 3 starts (gọi provider lần 3, end_turn)
  → yield usage         ← MARKER 3 (iterationHasTools=true → FLUSH iteration 2)
  → yield text_delta("Done! Plugin deactivated.")
  → [stop_reason=end_turn, no tool_calls]

Stream ends
  → FLUSH iteration 3 (no tools → historyChunks unchanged)
  → setApiHistory([...])
```

### Điểm mới trong Runtime V1 hiện tại

Ngoài history chunking, frontend còn có thêm 2 lớp guard:

- `guardAssistantTurnContent()`
  - nếu tool result fail nhưng assistant text lại nói như đã thành công, transcript sẽ tự chèn warning
  - nếu async result chỉ là `queued` nhưng assistant text lại nói như đã hoàn tất, transcript sẽ tự chèn warning
- `TaskRail`
  - pending confirmation
  - working step
  - queued background job

Nghĩa là transcript không còn là nơi duy nhất mang state của workflow.

### Code logic chi tiết

```javascript
// Tracking variables (local to sendMessage closure)
let currentText        = '';    // text đang build của iteration hiện tại
let currentToolCalls   = [];    // tool calls của iteration hiện tại
let currentToolResults = [];    // tool results của iteration hiện tại
const historyChunks    = [];    // completed iterations
let iterationHasTools  = false;
let firstUsageSeen     = false;

// Khi nhận usage event
if (firstUsageSeen && iterationHasTools) {
    historyChunks.push(
        // 1 assistant message với tool_calls
        { role: 'assistant', content: currentText, tool_calls: currentToolCalls },
        // N tool result messages (N = số tool calls)
        ...currentToolResults.map(tr => ({
            role: 'tool',
            tool_call_id: tr.tool_call_id,
            tool_name: tr.tool_name,
            result: tr.result,
        }))
    );
    // Reset for next iteration
    currentText = '';
    currentToolCalls = [];
    currentToolResults = [];
    iterationHasTools = false;
}
firstUsageSeen = true;
```

### Edge case: text-only iteration (không có tools)

Nếu AI trả lời text thuần (end_turn, no tool_calls), `iterationHasTools` vẫn là `false` → iteration đó không được flush vào `historyChunks` riêng. Thay vào đó, `currentText` của iteration cuối sẽ được dùng trong final assembly.

---

## 5. Multi-tool History Chunking

### Ví dụ 3 iterations

```
User: "Install Yoast SEO, activate it, then show me all active plugins"

Iteration 1: install_plugin{slug:'wordpress-seo'}
Iteration 2: activate_plugin{slug:'wordpress-seo'}
Iteration 3: list_plugins{} → end_turn → text response

historyChunks sau stream = [
    // Iteration 1 (flushed khi usage 2 đến)
    { role:'assistant', content:'Installing Yoast SEO...', tool_calls:[{id:'tc1', name:'install_plugin', input:{slug:'wordpress-seo'}}] },
    { role:'tool', tool_call_id:'tc1', tool_name:'install_plugin', result:{success:true} },

    // Iteration 2 (flushed khi usage 3 đến)
    { role:'assistant', content:'Activating...', tool_calls:[{id:'tc2', name:'activate_plugin', input:{slug:'wordpress-seo'}}] },
    { role:'tool', tool_call_id:'tc2', tool_name:'activate_plugin', result:{success:true} },
]

// Final iteration 3 state:
// currentText = "Here are your active plugins: ..."
// currentToolCalls = [{id:'tc3', name:'list_plugins', input:{}}]
// currentToolResults = [{tool_call_id:'tc3', result:{plugins:[...]}}]

// Final apiHistory:
[
    ...oldHistory,
    { role:'user', content:'Install Yoast SEO...' },
    ...historyChunks,  // iterations 1 & 2
    { role:'assistant', content:'', tool_calls:[{id:'tc3', name:'list_plugins',...}] },  // iteration 3
    { role:'tool', tool_call_id:'tc3', tool_name:'list_plugins', result:{plugins:[...]} },
    // Note: final text_delta là part của assistant message trong iteration 3
    // Nhưng nếu AI gọi tool trước rồi text sau cùng iteration...
    // → currentText capture tất cả text của iteration đó
]
```

> **Bug awareness:** Nếu AI trả về text VÀ tool_calls trong cùng 1 iteration (e.g., Anthropic), `currentText` chứa cả text đó và `currentToolCalls` chứa tool calls đó. Final assembly merge chúng vào 1 assistant message.

---

## 6. Saved Conversations vs Session History

### So sánh

| Aspect | Session (localStorage) | Saved conversations (DB) |
|--------|------------------------|--------------------------|
| Storage | `localStorage: waa_chat_v1` | `waa_conversations` table |
| Format | Display messages + apiHistory | Display messages only |
| Scope | Current browser session | Persistent across devices |
| History | Full apiHistory preserved | apiHistory RESET khi load |
| Max items | Giới hạn bởi localStorage quota | 20 conversations per user |
| Access | Automatic (page load) | Manual (click Conversations) |

### Tại sao apiHistory reset khi load saved conversation?

Khi user load một saved conversation từ DB:
```javascript
const loadMessages = useCallback((savedMessages, savedUsage) => {
    setMessages(savedMessages ?? []);
    setApiHistory([]);  // ← RESET về empty
    setSessionUsage(savedUsage ?? {...});
    setPendingNavUrl(null);
}, []);
```

**Lý do:** DB chỉ lưu display messages (không lưu apiHistory). Nếu user gửi message tiếp theo sau khi load, backend sẽ không có context về conversation cũ. Backend sẽ bắt đầu context mới với `$history = []`.

**Hệ quả:** Sau khi load saved conversation, AI không "nhớ" context cũ — conversation cũ chỉ hiển thị để tham khảo. Đây là limitation hiện tại.

### Save conversation flow

```javascript
// ConversationManager.jsx
const handleSave = async () => {
    await createConversation(title, messages);  // gửi display messages lên DB
    // → POST /wp-json/wp-admin-agent/v1/conversations
    // Body: { title: "...", messages: [display_messages_array] }
};
```

```php
// PHP: lưu vào DB
$wpdb->insert(WAA_TABLE_CONVERSATIONS, [
    'user_id'  => get_current_user_id(),
    'title'    => sanitize_text_field($body['title'] ?? 'New conversation'),
    'messages' => wp_json_encode($messages),
]);
```

### Load conversation flow

```javascript
// Load và hiển thị
const handleLoad = async (id) => {
    const conv = await loadConversation(id);
    onLoad(conv.messages, conv.usage ?? {});  // → loadMessages()
};
```

---

## 7. Custom Rules — Cách viết và Tác động

### Format

Plain text, thường mỗi rule một dòng. Không có syntax đặc biệt — đây là natural language cho AI.

### Ví dụ rules thực tế

**Ngôn ngữ:**
```
- Luôn trả lời bằng tiếng Việt, dù user hỏi bằng tiếng Anh.
- Xưng hô: "Anh/Chị" với user, tự xưng "tôi".
```

**Safety:**
```
- Không bao giờ deactivate plugin nào mà không hỏi xác nhận trước.
- Trước khi switch theme, luôn nêu tên theme hiện tại và theme mới.
- Không install plugin mới nếu chưa list danh sách plugin đang active.
```

**Workflow:**
```
- Khi tạo post, default category là 'Tin tức' trừ khi user chỉ định khác.
- Khi user hỏi về plugin, luôn bao gồm số lượng active installs và rating nếu có thể.
- Format danh sách plugins dưới dạng table với cột: Tên, Trạng thái, Version.
```

**Integration:**
```
- Nếu user hỏi về performance, recommend cài plugin 'WP Rocket' hoặc 'W3 Total Cache'.
- Luôn đề xuất backup trước khi thực hiện thay đổi lớn.
```

### Tác động kỹ thuật

Custom rules được append vào CUỐI system prompt → chúng có "weight" cao hơn base rules khi AI process context (do recency bias trong attention mechanism).

Nếu custom rule mâu thuẫn với base rule, custom rule thường "win" nhưng không guaranteed — LLM behavior có thể unpredictable.

---

## 8. Disabled Tools — Hoạt động ở Registry Level

### Flow

```
WAA_Settings::get_disabled_tools()
    → ['deactivate_plugin', 'install_plugin']  (array of tool names)

WAA_REST_API::handle_chat()
    → build_registry($settings->get_disabled_tools())

WAA_Tool_Registry::__construct($disabled)
    → $this->disabled = ['deactivate_plugin', 'install_plugin']

WAA_Tool_Registry::register($tool)
    → if (!in_array($name, BLOCKED) && !in_array($name, $this->disabled))
        $this->tools[$name] = $tool
    → SKIP nếu disabled

WAA_Tool_Registry::get_schemas()
    → chỉ trả về schemas của tools đã register
    → AI không thấy schema của disabled tools
    → AI không biết những tools đó tồn tại
```

### Kết quả

- Disabled tools **hoàn toàn ẩn** với AI — AI không thể gọi chúng
- AI không biết tool đó tồn tại → không thể suggest workaround
- Permanent block (BLOCKED list trong code) thì không thể enable qua UI: `['delete_site', 'wp_delete_user_self', 'update_core']`
- User-disabled tools có thể re-enable bất kỳ lúc nào qua Settings → Tools tab

### Save disabled tools

```php
// Settings page POST handler
$submitted_enabled = array_keys(array_filter(
    $_POST, fn($k) => str_starts_with($k, 'waa_tool_'), ARRAY_FILTER_USE_KEY
));
$enabled_names = array_map(fn($k) => substr($k, strlen('waa_tool_')), $submitted_enabled);
$all_tools     = array_column(WAA_REST_API::build_registry()->get_schemas(), 'name');
$disabled      = array_values(array_diff($all_tools, $enabled_names));
$settings->set_disabled_tools($disabled);
```

Logic: form gửi chỉ các tool đang **enabled** (checkboxes). PHP tính disabled = all_tools - enabled_tools.

### Ví dụ use case

Admin chỉ muốn AI đọc thông tin, không cho phép thay đổi:

```
Disable: install_plugin, activate_plugin, deactivate_plugin,
         switch_theme, update_user_role, create_post, update_post,
         set_post_image, update_site_settings, set_site_icon, search_images

Keep enabled: list_plugins, list_themes, list_users, list_posts,
              get_site_settings, search_icon
```

AI sẽ chỉ có thể list và report — không thể thực hiện bất kỳ write action nào.
