# Luồng dữ liệu đầy đủ — WP Admin AI Assistant

Tài liệu này mô tả toàn bộ lifecycle của một request, từ khi trang load cho đến khi response hiển thị trên UI, bao gồm cả path MCP thay thế.

---

## Bước 1: Page Load → React Mount

Khi admin mở bất kỳ trang `wp-admin` nào:

```
WordPress renders page
    → WAA_Plugin::enqueue_assets() (hook: admin_enqueue_scripts)
        → wp_enqueue_script('waa-admin-agent', assets/js/admin-agent.js)
        → wp_localize_script('waa-admin-agent', 'waaData', [...])
    → WAA_Plugin::inject_mount_point() (hook: admin_footer)
        → echo '<div id="waa-root"></div>'
```

### waaData object (injected vào window)

```javascript
window.waaData = {
    nonce:       "abc123def456",          // wp_create_nonce('wp_rest')
    restUrl:     "https://site.com/wp-json/wp-admin-agent/v1/",
    currentUser: { id: 1, name: "Admin" },
    siteUrl:     "https://site.com",
    version:     "0.2.0",
    provider:    "anthropic",             // provider đang active
    model:       "claude-haiku-4-5",      // model đang active
    pricing:     { anthropic: {...}, gemini: {...}, ollama: {...} }
}
```

### React mount

```javascript
// src/index.jsx
ReactDOM.createRoot(document.getElementById('waa-root')).render(<App />)
```

React đọc `waaData` từ `window` qua `src/lib/api.js`:
```javascript
const { restUrl, nonce } = window.waaData ?? {};
```

State khởi tạo từ `localStorage`:
```javascript
// useChat.js: loadFromStorage()
const stored = localStorage.getItem('waa_chat_v1');
// → {
//   messages: [],
//   history: [],
//   usage: { input_tokens:0, output_tokens:0, cost_usd:0, elapsed_ms:0 },
//   conversationId: null,
//   pendingConfirmation: null
// }
```

---

## Bước 2: User nhập text → sendMessage()

User gõ tin nhắn vào `InputBar` → submit → `sendMessage(text)` trong `useChat.js`:

```javascript
const sendMessage = useCallback(async (text) => {
    // 1. Tạo UUID cho user message và assistant placeholder
    const userId   = crypto.randomUUID();
    const assistId = crypto.randomUUID();

    // 2. Append messages vào UI ngay lập tức (optimistic update)
    setMessages(prev => [
        ...prev,
        { role: 'user',      content: text, id: userId },
        { role: 'assistant', content: '',   toolCalls: [], id: assistId },
    ]);
    setIsLoading(true);

    // 3. Snapshot current API history để gửi lên server
    const historyToSend = apiHistory;

    // 4. Gửi request
    const response = await chatStream(
        text,
        historyToSend,
        resolvedConversationId,
        abortRef.current.signal,
        confirmation
    );

    // 5. Parse SSE stream
    for await (const event of parseSSE(response.body)) {
        // ... handle events
    }
}, [apiHistory]);
```

### chatStream() — src/lib/api.js

```javascript
export async function chatStream(message, history, conversationId, signal, confirmation = null) {
    return fetch(`${restUrl}chat`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({
            message,
            stream: true,
            history: history ?? [],
            conversation_id: conversationId ?? null,
            confirmation,
        }),
        signal,
    });
}
```

Request body ví dụ:
```json
{
    "message": "List all installed plugins",
    "stream": true,
    "history": [
        { "role": "user", "content": "What is the site title?" },
        { "role": "assistant", "content": "The site title is 'My WordPress Site'.", "tool_calls": [] }
    ]
}
```

---

## Bước 3: PHP handle_chat() → Setup

`WAA_REST_API::handle_chat()` nhận request:

```php
public function handle_chat(WP_REST_Request $request): void {
    $body    = $request->get_json_params();
    $message = $request->get_param('message');

    $settings = new WAA_Settings();

    // Check credentials
    if (!$settings->has_active_credential()) {
        $this->sse_error('AI provider not configured...');
        return;
    }

    // Prefer inline history từ frontend (không phải DB)
    $inline_history = is_array($body['history'] ?? null) ? $body['history'] : [];
    $history = !empty($inline_history)
        ? $inline_history
        : ($conversation_id ? $this->load_conversation_messages($conversation_id) : []);
    $confirmation = is_array($body['confirmation'] ?? null) ? $body['confirmation'] : null;

    // Set SSE headers
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) ob_end_flush();

    // Tạo Agent với provider + registry + audit log
    $agent = new WAA_Agent(
        WAA_Provider_Factory::make($settings),    // Provider object
        self::build_registry($settings->get_disabled_tools()),  // Tool registry
        new WAA_Audit_Log()
    );

    // Chạy agent loop và stream kết quả
    foreach ($agent->run($message, $history, $confirmation) as $event) {
        $this->sse_emit($event);
    }

    $this->sse_emit(['type' => 'done']);
    echo "data: [DONE]\n\n";
    flush();
    exit;
}
```

**Permission check** (chạy trước callback):
```php
public function check_permission(): bool|WP_Error {
    if (!current_user_can('manage_options')) {
        return new WP_Error('rest_forbidden', 'Insufficient permissions.', ['status' => 403]);
    }
    if (!(new WAA_Rate_Limiter())->check()) {
        return new WP_Error('rate_limited', 'Too many requests.', ['status' => 429]);
    }
    return true;
}
```

---

## Bước 4: Agent Loop — WAA_Agent::run()

```
┌─────────────────────────────────────────────────────────────┐
│  Agent::run($user_message, $history)                        │
│                                                             │
│  $messages = [...$history, {role:'user', content:$msg}]     │
│  $iteration = 0                                             │
│                                                             │
│  while ($iteration++ < 10) {                                │
│      ┌──────────────────────────────────────────────────┐   │
│      │  1. $provider->complete(system, messages, tools)  │   │
│      │     → $response = {stop_reason, text, tool_calls, │   │
│      │                    usage:{in_tokens, out_tokens}}  │   │
│      └───────────────────┬──────────────────────────────┘   │
│                           │                                  │
│      ┌────────────────────▼─────────────────────────────┐   │
│      │  2. yield 'usage' event                           │   │
│      │     (cumulative tokens + cost USD)                │   │
│      └────────────────────┬─────────────────────────────┘   │
│                           │                                  │
│      ┌────────────────────▼─────────────────────────────┐   │
│      │  3. if $response['text'] != ''                    │   │
│      │     yield 'text_delta' {content: string}          │   │
│      └────────────────────┬─────────────────────────────┘   │
│                           │                                  │
│      ┌────────────────────▼─────────────────────────────┐   │
│      │  4. if stop_reason == 'end_turn'                  │   │
│      │     OR no tool_calls → break                      │   │
│      └────────────────────┬─────────────────────────────┘   │
│                           │ (has tool_calls)                 │
│      ┌────────────────────▼─────────────────────────────┐   │
│      │  5. Append assistant message to $messages         │   │
│      │     foreach tool_call:                            │   │
│      │       yield 'tool_start' {name, id, input}        │   │
│      │       $result = registry->execute(name, input)    │   │
│      │       audit_log->write(tool, input, result, meta) │   │
│      │       yield 'tool_end' {name, result, id}         │   │
│      │       if NAVIGATE_MAP[name]:                      │   │
│      │         yield 'navigate' {url}                    │   │
│      │       Append tool message to $messages            │   │
│      └────────────────────┬─────────────────────────────┘   │
│                           │                                  │
│      Loop lại → iteration 2, 3, ... max 10                  │
│  }                                                           │
│                                                             │
│  if (iteration > 10):                                       │
│    yield 'text_delta' {"_(Maximum tool iterations reached)_"}│
└─────────────────────────────────────────────────────────────┘
```

### System prompt được build:

```php
private function system_prompt(): string {
    // Base: site context + 7 rules
    $base = "You are a WordPress admin assistant...
Site context:
- URL: {site_url}
- Title: {site_title}
- WordPress: {wp_version}
- Timezone: {timezone}
- Current user: {display_name}
Rules:
1. Read current state before modifying...
2. For destructive actions, ask for confirmation...
3. Never expose API keys...
4. If a tool returns an error, explain it...
5. Be concise...
6. Respond in the same language...
7. When asked to change site icon, call search_icon first...";

    // Append model-specific guidance
    $base .= "\nModel-specific guidance:\n" . $provider->get_model_instructions();

    // Append custom rules từ settings
    $base .= "\nCustom rules:\n" . $settings->get_custom_rules();

    return $base;
}
```

---

## Bước 5: Provider-specific Message Translation

Mỗi provider có method `to_X_messages()` để convert internal format → API format.

### Internal format (shared across all providers)

```php
// User message
['role' => 'user', 'content' => 'string']

// Assistant message với tool calls
['role' => 'assistant', 'content' => 'string', 'tool_calls' => [
    ['id' => 'tc_123', 'name' => 'list_plugins', 'input' => ['status' => 'active']],
]]

// Tool result
['role' => 'tool', 'tool_call_id' => 'tc_123', 'tool_name' => 'list_plugins', 'result' => [...]]
```

### Anthropic format (to_anthropic_messages)

```php
// user → giữ nguyên
['role' => 'user', 'content' => 'string']

// assistant → content là array of blocks
['role' => 'assistant', 'content' => [
    ['type' => 'text', 'text' => 'string'],
    ['type' => 'tool_use', 'id' => 'tc_123', 'name' => 'list_plugins', 'input' => [...]],
]]

// tool → grouped vào user message với tool_result blocks
['role' => 'user', 'content' => [
    ['type' => 'tool_result', 'tool_use_id' => 'tc_123', 'content' => '{json_encoded_result}'],
]]
// Nhiều tool results liên tiếp → gom vào 1 user message
```

### Gemini format (to_gemini_contents)

```php
// user → parts array
['role' => 'user', 'parts' => [['text' => 'string']]]

// assistant → role: 'model', functionCall dùng 'args' (KHÔNG phải 'input')
['role' => 'model', 'parts' => [
    ['text' => 'string'],
    ['functionCall' => ['name' => 'list_plugins', 'args' => (object)['status' => 'active']]],
    // Note: (object) cast cho empty args {} thay vì [] (PHP array)
]]

// tool → functionResponse (grouped vào user turn)
['role' => 'user', 'parts' => [
    ['functionResponse' => ['name' => 'list_plugins', 'response' => ['result' => [...]]]]
]]

// Tools schema: additionalProperties bị strip (Gemini không support)
// Wrapped vào: [['functionDeclarations' => [...]]]
```

### Ollama format (to_ollama_messages)

```php
// System prompt inject như message đầu tiên (khác Anthropic/Gemini)
['role' => 'system', 'content' => $system]

// user → đơn giản
['role' => 'user', 'content' => 'string']

// assistant → tool_calls dùng 'arguments' (KHÔNG phải 'input')
['role' => 'assistant', 'content' => '', 'tool_calls' => [
    ['function' => ['name' => 'list_plugins', 'arguments' => ['status' => 'active']]],
]]

// tool → standalone message (không group)
['role' => 'tool', 'content' => '{json_encoded_result}']

// Tools wrapped vào: [['type' => 'function', 'function' => [...]]]
```

### Provider Key Mapping — bảng so sánh

| Internal field | Anthropic | Gemini | Ollama |
|----------------|-----------|--------|--------|
| Tool args (trong call) | `input` | `args` | `arguments` |
| Tool block type | `tool_use` | `functionCall` | `tool_calls[].function` |
| Tool result type | `tool_result` | `functionResponse` | role `tool` (plain) |
| Tool result grouped | Yes (vào user) | Yes (vào user) | No (standalone) |
| System prompt | Top-level `system` field | `systemInstruction.parts` | First message role `system` |
| Assistant role | `assistant` | `model` | `assistant` |
| Usage field | `usage.input_tokens` | `usageMetadata.promptTokenCount` | `prompt_eval_count` |

---

## Bước 6: Tool Execution Flow

```
registry->execute($name, $input)
    │
    ├── Kiểm tra tool có tồn tại không
    ├── tool->check_permission() → current_user_can('manage_options')
    ├── tool->validate_input($input) → array|WP_Error
    └── tool->execute($validated_input) → array

// Kết quả được log vào DB
audit_log->write($tool_name, $input, $result, [
    'provider'      => 'anthropic',
    'model'         => 'claude-haiku-4-5',
    'input_tokens'  => $call_in,
    'output_tokens' => $call_out,
])
// Ghi vào waa_logs: user_id, tool_name, params(JSON), result(JSON), status, provider, model, tokens
```

### Tool result format

Tool luôn trả về `array`. Convention:
```php
// Success
['success' => true, 'data' => [...]]

// Error (nên throw RuntimeException thay vì return error)
['error' => 'Error message here']

// Hoặc với success flag
['success' => false, 'error' => 'message']
```

### Navigate event

Sau mỗi write tool trong NAVIGATE_MAP:
```php
$nav_path = self::NAVIGATE_MAP[$tc['name']] ?? '';
if ($nav_path) {
    yield ['type' => 'navigate', 'url' => admin_url($nav_path)];
}
```

`admin_url()` tự xử lý prefix (e.g., `/wp-admin/plugins.php`).

---

## Bước 7: SSE Stream → Frontend Event Handlers

### SSE wire format

Mỗi event PHP emit ra là:
```
data: {"type":"usage","input_tokens":150,"output_tokens":45,"cost_usd":0.000375}\n\n
data: {"type":"text_delta","content":"Here are your plugins:"}\n\n
data: {"type":"tool_start","tool_name":"list_plugins","tool_use_id":"tc_1","tool_input":{}}\n\n
data: {"type":"tool_end","tool_name":"list_plugins","result":{...},"tool_use_id":"tc_1"}\n\n
data: {"type":"navigate","url":"https://site.com/wp-admin/plugins.php"}\n\n
data: {"type":"text_delta","content":"I found 12 plugins..."}\n\n
data: {"type":"done"}\n\n
data: [DONE]\n\n
```

### parseSSE() — src/lib/sse.js

```javascript
export async function* parseSSE(body) {
    const reader  = body.getReader();
    const decoder = new TextDecoder();
    let buffer    = '';

    while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() ?? '';  // giữ lại dòng chưa hoàn chỉnh

        for (const line of lines) {
            if (!line.startsWith('data: ')) continue;
            const raw = line.slice(6).trim();
            if (raw === '[DONE]') return;
            yield JSON.parse(raw);  // parse JSON và yield event object
        }
    }
}
```

### Event handlers trong useChat.js

| Event type | Action |
|-----------|--------|
| `text_delta` | Append `event.content` vào assistant message hiện tại |
| `tool_start` | Set `activeToolName`, thêm tool vào `toolCalls` với `status: 'running'` |
| `tool_end` | Clear `activeToolName`, update tool với `status: 'done'` + result |
| `navigate` | Lưu URL vào `collectedNavUrl` (sẽ set vào `pendingNavUrl` sau khi stream xong) |
| `usage` | Update `sessionUsage` (tokens + cost). **Quan trọng:** trigger history chunking nếu có tools |
| `error` | Mark assistant message là `isError: true` với error text |
| `done` | Không handle (stream kết thúc) |

---

## Bước 8: History Building sau khi Stream kết thúc

Đây là phần phức tạp nhất của frontend. Logic này đảm bảo `apiHistory` luôn đúng format cho backend.

### Tại sao cần chunking?

Một request có thể có nhiều LLM iterations. Mỗi iteration tương ứng với một lần gọi provider, có thể gồm:
- Iteration 1: AI gọi `list_plugins` → nhận kết quả → tiếp tục
- Iteration 2: AI gọi `deactivate_plugin` → nhận kết quả → tiếp tục
- Iteration 3: AI trả lời text → end_turn

SSE stream gửi `usage` event đầu mỗi iteration → frontend dùng đây làm boundary.

### History chunk logic

```javascript
// Tracking state
let currentText        = '';         // text từ LLM iteration hiện tại
let currentToolCalls   = [];         // tool calls iteration hiện tại: [{id, name, input}]
let currentToolResults = [];         // tool results iteration hiện tại
const historyChunks    = [];         // completed iterations
let iterationHasTools  = false;
let firstUsageSeen     = false;

// Khi nhận 'usage' event:
case 'usage':
    if (firstUsageSeen && iterationHasTools) {
        // Flush completed iteration vào historyChunks
        historyChunks.push(
            { role: 'assistant', content: currentText, tool_calls: currentToolCalls },
            ...currentToolResults.map(tr => ({
                role: 'tool', tool_call_id: tr.tool_call_id,
                tool_name: tr.tool_name, result: tr.result,
            }))
        );
        // Reset cho iteration mới
        currentText = ''; currentToolCalls = []; currentToolResults = [];
        iterationHasTools = false;
    }
    firstUsageSeen = true;
    // Update session usage display
    setSessionUsage({...});
    break;
```

### Final history assembly (trong finally block)

```javascript
// Sau khi stream kết thúc:
const toolResultMessages = currentToolResults.map(tr => ({
    role: 'tool', tool_call_id: tr.tool_call_id,
    tool_name: tr.tool_name, result: tr.result,
}));

setApiHistory([
    ...historyToSend,          // lịch sử cũ (snapshot trước khi gửi)
    { role: 'user', content: text },  // user message vừa gửi
    ...historyChunks,          // các iterations đã hoàn thành (assistant + tools)
    { role: 'assistant', content: currentText, tool_calls: currentToolCalls },  // iteration cuối
    ...toolResultMessages,     // tool results của iteration cuối
]);
```

### Ví dụ đầy đủ — 2 tool iterations

```
User: "Cài plugin Yoast SEO và activate nó"

SSE stream:
  usage(iter 1)    → firstUsageSeen=true
  text_delta       → currentText = "I'll install Yoast SEO now."
  tool_start       → currentToolCalls = [{id:'tc1', name:'install_plugin', input:{slug:'wordpress-seo'}}]
  tool_end         → currentToolResults = [{tool_call_id:'tc1', result:{success:true}}]
  navigate         → collectedNavUrl = plugins.php
  usage(iter 2)    → FLUSH iter 1 vào historyChunks:
                     [{role:'assistant', content:'...', tool_calls:[tc1]},
                      {role:'tool', tool_call_id:'tc1', result:{...}}]
                     reset state
  text_delta       → currentText = "Installed! Now activating..."
  tool_start       → currentToolCalls = [{id:'tc2', name:'activate_plugin', ...}]
  tool_end         → currentToolResults = [{tool_call_id:'tc2', result:{...}}]
  navigate         → collectedNavUrl = plugins.php (overwrite)
  text_delta       → currentText = "Installed and activated successfully!"
  done             → stream ends

Final apiHistory = [
  ...oldHistory,
  {role:'user', content:'Cài plugin Yoast SEO...'},
  {role:'assistant', content:'I\'ll install...', tool_calls:[{id:'tc1', name:'install_plugin',...}]},
  {role:'tool', tool_call_id:'tc1', tool_name:'install_plugin', result:{success:true}},
  {role:'assistant', content:'Installed! Now activating...', tool_calls:[{id:'tc2',...}]},
  {role:'tool', tool_call_id:'tc2', tool_name:'activate_plugin', result:{success:true}},
  {role:'assistant', content:'Installed and activated successfully!', tool_calls:[]},
]
```

### localStorage persistence

Mỗi khi `messages`, `apiHistory`, `sessionUsage`, `conversationId`, hoặc `pendingConfirmation` thay đổi, useEffect lưu vào localStorage:

```javascript
useEffect(() => {
    localStorage.setItem('waa_chat_v1', JSON.stringify({
        messages,
        history: apiHistory,
        usage: sessionUsage,
        conversationId,
        pendingConfirmation,
    }));
}, [messages, apiHistory, sessionUsage, conversationId, pendingConfirmation]);
```

**Clear/New session:** `clearMessages()` xóa localStorage key `waa_chat_v1`, reset transcript, `apiHistory`, `conversationId`, `pendingConfirmation`, và session usage.

**Load saved conversation:** `loadMessages(savedMessages, savedUsage, savedHistory, savedConversationId)` restore luôn `apiHistory` và `conversationId`, nên user có thể chat tiếp trên đúng session cũ thay vì rebuild từ đầu.

---

## Bước 9: Task-state UI layer

Runtime hiện không còn dồn toàn bộ state vào transcript.

`ChatWidget` render thêm `TaskRail` để hiển thị các state ngắn hạn:

- `Awaiting approval`
- `Working`
- `Background task queued`

Điểm khác với phase đầu:

- confirmation controls không còn nằm trong `InputBar`
- queued async work không còn nhìn như đã hoàn tất
- transcript vẫn giữ audit trail, nhưng task rail mới là nơi hiển thị trạng thái workflow hiện tại

---

## Path MCP — Alternative Flow

Thay vì qua chat interface, external tools có thể gọi trực tiếp qua MCP endpoint:

```
Claude Desktop / Claude Code / Any MCP client
    │
    │  POST /wp-json/wp-admin-agent/v1/mcp
    │  Headers: X-WP-Nonce: {nonce}  (hoặc basic auth nếu configured)
    │  Body: JSON-RPC 2.0
    ▼
WAA_REST_API::handle_mcp()
    │
    ▼
WAA_MCP_Server::handle($request)
    │
    ├── method: 'initialize'
    │   └── return {protocolVersion, serverInfo, capabilities, instructions}
    │
    ├── method: 'tools/list'
    │   └── registry->get_schemas() → format to MCP inputSchema format
    │       return {tools: [{name, description, inputSchema}, ...]}
    │
    └── method: 'tools/call'
        └── registry->execute($name, $arguments)
            → return {content:[{type:'text', text:JSON}], isError:false}
            (on error: isError:true, không throw JSON-RPC error)
```

### MCP Request/Response examples

**Initialize:**
```json
// Request
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}

// Response
{"jsonrpc":"2.0","id":1,"result":{
    "protocolVersion":"2024-11-05",
    "serverInfo":{"name":"wp-admin-agent","version":"1.0.0"},
    "capabilities":{"tools":{"listChanged":false}},
    "instructions":"WordPress Admin Agent — manage plugins, themes, posts..."
}}
```

**tools/call:**
```json
// Request
{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{
    "name":"list_plugins",
    "arguments":{"status":"active"}
}}

// Response
{"jsonrpc":"2.0","id":2,"result":{
    "content":[{"type":"text","text":"{\"plugins\":[...]}"}],
    "isError":false
}}
```

**Lưu ý quan trọng:** Tool errors KHÔNG phải JSON-RPC errors. Khi tool fail, response vẫn là HTTP 200 với `isError: true` trong content, không phải JSON-RPC error object. Đây đúng theo MCP spec.
