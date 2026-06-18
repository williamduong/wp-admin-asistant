# Thêm Provider mới — WP Admin AI Assistant

## 1. WAA_Provider_Base — Abstract Class

Tất cả providers phải extend `WAA_Provider_Base` và implement các methods sau:

```php
abstract class WAA_Provider_Base {
    // REQUIRED
    abstract public function complete(string $system, array $messages, array $tools): array;
    abstract public function get_id(): string;
    abstract public function get_label(): string;

    // OPTIONAL (default trả về '')
    public function get_model_instructions(): string { return ''; }
}
```

### complete() — Contract đầy đủ

```php
/**
 * @param string $system   System prompt (base + model instructions + custom rules)
 * @param array  $messages Internal message history (xem format bên dưới)
 * @param array  $tools    Tool schemas trong Anthropic format
 * @return array {
 *     'stop_reason'  => 'end_turn' | 'tool_use',
 *     'text'         => string,            // text response từ AI
 *     'tool_calls'   => array,             // [{id, name, input}, ...]
 *     'usage'        => array,             // {input_tokens, output_tokens}
 * }
 */
abstract public function complete(string $system, array $messages, array $tools): array;
```

---

## 2. Internal Message Contract — PHẦN QUAN TRỌNG NHẤT

Đây là format được dùng **bên trong plugin** — tất cả providers nhận format này qua `$messages` và phải convert sang API-native format trong `to_X_messages()`.

```php
// Loại 1: User message
[
    'role'    => 'user',
    'content' => 'string — nội dung từ user',
]

// Loại 2: Assistant message (có thể có tool_calls)
[
    'role'       => 'assistant',
    'content'    => 'string — text response (có thể empty nếu chỉ có tool calls)',
    'tool_calls' => [
        [
            'id'    => 'string — unique ID cho tool call này',
            'name'  => 'string — tên tool (snake_case)',
            'input' => ['key' => 'value', ...],  // tool arguments
        ],
        // có thể có nhiều tool calls
    ],
]

// Loại 3: Tool result
[
    'role'         => 'tool',
    'tool_call_id' => 'string — ID phải match với id trong tool_call trên',
    'tool_name'    => 'string — tên tool',
    'result'       => ['key' => 'value', ...],  // return value từ tool->execute()
]
```

**Internal format này là Anthropic-style.** Provider khác phải convert từ format này sang format của API họ.

---

## 3. Provider Key Mapping — Common Bug Source

Bảng này là nguồn bug phổ biến nhất khi implement provider mới:

| Concept | Internal (WAA) | Anthropic API | Gemini API | Ollama API |
|---------|---------------|--------------|------------|------------|
| Tool arguments field | `input` | `input` | `args` | `arguments` |
| Tool call block type | — | `tool_use` | `functionCall` | `tool_calls[].function` |
| Tool result block | `role: 'tool'` | `type: 'tool_result'` | `functionResponse` | `role: 'tool'` (plain) |
| Tool results grouping | Separate messages | Grouped into 1 user msg | Grouped into 1 user turn | Each as separate message |
| System prompt location | Passed separately | Top-level `system` field | `systemInstruction.parts` | First message with `role: 'system'` |
| Assistant role name | `assistant` | `assistant` | `model` | `assistant` |
| Input tokens field | `usage.input_tokens` | `usage.input_tokens` | `usageMetadata.promptTokenCount` | `prompt_eval_count` |
| Output tokens field | `usage.output_tokens` | `usage.output_tokens` | `usageMetadata.candidatesTokenCount` | `eval_count` |
| Tools array format | flat array | flat array | `[{functionDeclarations:[...]}]` | `[{type:'function', function:{...}}]` |

---

## 4. Template Provider Class đầy đủ

```php
<?php
// File: includes/class-provider-{name}.php
// Example: includes/class-provider-myai.php → class WAA_Provider_Myai

defined('ABSPATH') || exit;

class WAA_Provider_Myai extends WAA_Provider_Base {

    // API endpoint của provider
    private const BASE_URL = 'https://api.my-ai-provider.com/v1/chat';

    public function __construct(
        private readonly string $api_key,
        private readonly string $model = 'myai-fast-v1'
    ) {}

    public function get_id(): string    { return 'myai'; }
    public function get_label(): string { return 'My AI Provider'; }

    /**
     * Hướng dẫn đặc thù cho model này.
     * Sẽ được append vào system prompt.
     * Dùng để compensate cho quirks của provider.
     */
    public function get_model_instructions(): string {
        return <<<INST
You are running on My AI Provider. Follow these constraints:
- Call tools one at a time (provider does not support parallel tool calls).
- Always confirm destructive actions before executing them.
INST;
    }

    /**
     * Main method: gọi API và trả về normalized response.
     */
    public function complete(string $system, array $messages, array $tools): array {
        $payload = [
            'model'       => $this->model,
            'max_tokens'  => 4096,
            // System prompt — format tùy provider:
            'system'      => $system,
            // Messages — phải convert từ internal format
            'messages'    => $this->to_myai_messages($messages),
            // Tools — phải convert từ Anthropic format
            'tools'       => $this->to_myai_tools($tools),
        ];

        $response = wp_remote_post(self::BASE_URL, [
            'method'  => 'POST',
            'timeout' => 90,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('MyAI connection failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['error']['message'] ?? "HTTP $code";
            throw new RuntimeException("MyAI error: $msg");
        }

        return $this->normalize($body);
    }

    // ─────────────────────────────────────────────────────────────────
    // Convert: Internal format → MyAI API format
    // ─────────────────────────────────────────────────────────────────

    private function to_myai_messages(array $messages): array {
        $out = [];

        foreach ($messages as $msg) {
            switch ($msg['role']) {
                case 'user':
                    // User messages: thường đơn giản
                    $out[] = [
                        'role'    => 'user',
                        'content' => $msg['content'],
                    ];
                    break;

                case 'assistant':
                    // Assistant với tool_calls: format tùy API
                    // Ví dụ này giống Anthropic (content là array of blocks)
                    $parts = [];
                    if (!empty($msg['content'])) {
                        $parts[] = ['type' => 'text', 'text' => $msg['content']];
                    }
                    foreach ($msg['tool_calls'] ?? [] as $tc) {
                        $parts[] = [
                            'type'   => 'tool_call',
                            'id'     => $tc['id'],
                            'name'   => $tc['name'],
                            // ← ĐỔI FIELD NAME NẾU API DÙNG TÊN KHÁC:
                            // Anthropic: 'input'     => $tc['input']
                            // Gemini:    'args'      => (object)($tc['input'] ?? [])
                            // Ollama:    'arguments' => $tc['input']
                            'params' => $tc['input'],  // ← 'params' cho MyAI
                        ];
                    }
                    $out[] = ['role' => 'assistant', 'content' => $parts];
                    break;

                case 'tool':
                    // Tool results: nhiều patterns khác nhau tùy API
                    // Pattern A (Anthropic-style): group vào user message
                    $last = end($out);
                    $tool_result = [
                        'type'   => 'tool_result',
                        'ref_id' => $msg['tool_call_id'],  // ← check tên field trong API docs
                        'output' => wp_json_encode($msg['result']),
                    ];
                    if ($last && $last['role'] === 'user' && is_array($last['content'])) {
                        $out[array_key_last($out)]['content'][] = $tool_result;
                    } else {
                        $out[] = ['role' => 'user', 'content' => [$tool_result]];
                    }
                    // Pattern B (Ollama-style): standalone message
                    // $out[] = ['role' => 'tool', 'content' => wp_json_encode($msg['result'])];
                    break;
            }
        }

        return $out;
    }

    private function to_myai_tools(array $tools): array {
        if (empty($tools)) return [];

        // Convert từ Anthropic format sang MyAI format
        return array_map(fn($tool) => [
            'type'     => 'function',
            'function' => [
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'parameters'  => $tool['input_schema'],
                // Nếu API không support 'additionalProperties': unset($schema['additionalProperties'])
            ],
        ], $tools);
    }

    // ─────────────────────────────────────────────────────────────────
    // Convert: MyAI API response → Internal format
    // ─────────────────────────────────────────────────────────────────

    private function normalize(array $body): array {
        $text       = '';
        $tool_calls = [];

        // Đọc từ API response structure — thay đổi tùy API
        $content = $body['choices'][0]['message']['content'] ?? [];  // ví dụ OpenAI-style

        foreach ((array) $content as $block) {
            // Text block
            if (isset($block['text'])) {
                $text .= $block['text'];
            }
            // Tool call block — field name và structure tùy API
            if (isset($block['type']) && $block['type'] === 'tool_call') {
                $tool_calls[] = [
                    'id'    => $block['id'],
                    'name'  => $block['name'],
                    // ← QUAN TRỌNG: normalize về 'input' (không phải 'args' hay 'arguments')
                    'input' => $block['params'] ?? [],  // MyAI dùng 'params'
                ];
            }
        }

        // stop_reason: normalize về 'end_turn' hoặc 'tool_use'
        $stop = $body['choices'][0]['finish_reason'] ?? 'stop';
        $stop_reason = !empty($tool_calls) ? 'tool_use' : 'end_turn';

        // Usage: normalize về input_tokens và output_tokens
        $usage = $body['usage'] ?? [];

        return [
            'stop_reason' => $stop_reason,
            'text'        => $text,
            'tool_calls'  => $tool_calls,
            'usage'       => [
                'input_tokens'  => $usage['prompt_tokens']     ?? 0,  // field name tùy API
                'output_tokens' => $usage['completion_tokens'] ?? 0,
            ],
        ];
    }
}
```

---

## 5. Implement complete() — to_X_messages() và normalize()

### to_X_messages() — Những điểm cần đặc biệt chú ý

**1. Empty tool input — Gemini cần (object) cast:**
```php
// Gemini: PHP [] serialize thành JSON [] (array), nhưng API cần {} (object)
'args' => (object)($tc['input'] ?? [])

// Anthropic và Ollama: không cần cast
'input'     => $tc['input']      // Anthropic
'arguments' => $tc['input']      // Ollama
```

**2. Tool results grouping:**
```php
// Pattern: group consecutive tool results vào 1 message (Anthropic, Gemini style)
$last = end($out);
if ($last && $last['role'] === 'user' && is_array($last['content'])) {
    // Thêm vào message user cuối
    $out[array_key_last($out)]['content'][] = $tool_result;
} else {
    // Tạo message user mới
    $out[] = ['role' => 'user', 'content' => [$tool_result]];
}
```

**3. Gemini role naming:**
```php
// Gemini dùng 'model' thay vì 'assistant'
$contents[] = ['role' => 'model', 'parts' => $parts];
```

**4. Ollama system prompt:**
```php
// Ollama: inject system prompt như message đầu tiên
private function to_ollama_messages(string $system, array $messages): array {
    $out = [['role' => 'system', 'content' => $system]];
    // ... process $messages
    return $out;
}
// Chú ý: signature khác — nhận thêm $system param
```

### normalize() — Những điểm cần chú ý

**1. Luôn normalize tool_calls về internal format:**
```php
$tool_calls[] = [
    'id'    => $block['id'],
    'name'  => $block['name'],
    'input' => $block['args'] ?? [],  // ← normalize 'args'/'arguments'/'params' → 'input'
];
```

**2. stop_reason: dùng presence of tool_calls làm indicator:**
```php
$stop_reason = !empty($tool_calls) ? 'tool_use' : 'end_turn';
// Đừng tin hoàn toàn vào API's finish_reason — một số provider không reliable
```

**3. Ollama arguments có thể là JSON string:**
```php
// Một số Ollama models trả về arguments là JSON string thay vì object
'input' => is_string($fn['arguments'])
    ? (json_decode($fn['arguments'], true) ?? [])
    : ($fn['arguments'] ?? []),
```

**4. Gemini generate tool_call ID (không có trong response):**
```php
// Gemini không trả về tool call ID → tự generate
'id' => uniqid('gemini_tc_'),
```

---

## 6. Register trong WAA_Provider_Factory

Mở `includes/class-provider-factory.php`:

```php
class WAA_Provider_Factory {
    public static function make(WAA_Settings $settings): WAA_Provider_Base {
        $provider = $settings->get_provider();

        return match ($provider) {
            'gemini' => new WAA_Provider_Gemini(
                $settings->get_gemini_api_key(),
                $settings->get_model()
            ),
            'ollama' => new WAA_Provider_Ollama(
                $settings->get_ollama_url(),
                $settings->get_model()
            ),
            // Thêm provider mới:
            'myai'   => new WAA_Provider_Myai(
                $settings->get_myai_api_key(),  // thêm method này vào WAA_Settings
                $settings->get_model()
            ),
            default  => new WAA_Provider_Anthropic(
                $settings->get_api_key(),
                $settings->get_model()
            ),
        };
    }
}
```

---

## 7. Thêm credentials vào WAA_Settings

Nếu provider cần API key riêng, thêm vào `includes/class-settings.php`:

```php
// API key cho MyAI — encrypted AES-256
public function get_myai_api_key(): string {
    $encrypted = get_option('waa_myai_key_enc', '');
    return $encrypted ? $this->enc->decrypt($encrypted) : '';
}

public function set_myai_api_key(string $key): void {
    update_option('waa_myai_key_enc', $this->enc->encrypt($key), false);
}
```

Và update `has_active_credential()`:
```php
public function has_active_credential(): bool {
    return match ($this->get_provider()) {
        'gemini' => !empty($this->get_gemini_api_key()),
        'ollama' => true,
        'myai'   => !empty($this->get_myai_api_key()),  // thêm dòng này
        default  => !empty($this->get_api_key()),
    };
}

// Và set_provider() — thêm 'myai' vào allowed list
public function set_provider(string $provider): void {
    $allowed = ['anthropic', 'gemini', 'ollama', 'myai'];
    if (in_array($provider, $allowed, true)) {
        update_option('waa_provider', $provider);
    }
}
```

---

## 8. Thêm models vào WAA_Pricing

Mở `includes/class-pricing.php`, thêm provider vào `DATA` array:

```php
private const DATA = [
    'anthropic' => [...],
    'gemini'    => [...],
    'ollama'    => [...],
    // Thêm provider mới:
    'myai' => [
        'myai-fast-v1' => [
            'label' => 'MyAI Fast v1',
            'ctx'   => 128000,    // context window size (tokens)
            'in'    => 0.50,      // USD per 1M input tokens
            'out'   => 1.50,      // USD per 1M output tokens
        ],
        'myai-pro-v2' => [
            'label' => 'MyAI Pro v2',
            'ctx'   => 256000,
            'in'    => 2.00,
            'out'   => 6.00,
        ],
    ],
];
```

Pricing data được dùng:
1. `WAA_Pricing::calculate()` — tính cost sau mỗi LLM call
2. `WAA_Pricing::all_for_js()` — gửi xuống frontend qua `waaData.pricing`
3. Settings page: model dropdown và pricing table

---

## 9. Thêm vào Settings Page dropdown

Mở `admin/settings-page.php`, tìm `<select id="waa_provider"`:

```php
<select id="waa_provider" name="waa_provider" onchange="waaOnProviderChange(this.value)">
    <option value="anthropic" <?php selected($provider,'anthropic'); ?>>Anthropic (Claude)</option>
    <option value="gemini"    <?php selected($provider,'gemini'); ?>>Google Gemini</option>
    <option value="ollama"    <?php selected($provider,'ollama'); ?>>Ollama (Local)</option>
    <!-- Thêm provider mới: -->
    <option value="myai"      <?php selected($provider,'myai'); ?>>My AI Provider</option>
</select>
```

Thêm row cho API key (ẩn/hiện theo provider selection):
```php
<tr id="row-myai" <?php echo $provider !== 'myai' ? 'style="display:none"' : ''; ?>>
    <th><label for="waa_myai_key">My AI API Key</label></th>
    <td>
        <input type="password" id="waa_myai_key" name="waa_myai_key" class="regular-text"
               value="<?php echo $settings->get_myai_api_key() ? '••••••••' : ''; ?>"
               placeholder="myai-..." autocomplete="off">
        <p class="description">
            Get your key at <a href="https://my-ai.com/api" target="_blank">my-ai.com/api</a>
        </p>
    </td>
</tr>
```

Update JavaScript `waaOnProviderChange()` để show/hide row:
```javascript
function waaOnProviderChange(p) {
    ['anthropic', 'gemini', 'ollama', 'myai'].forEach(id =>
        document.getElementById('row-' + id).style.display = (p === id) ? '' : 'none'
    );
    // ...
}
```

Và thêm save logic vào `WAA_Plugin::maybe_handle_settings_save()`:
```php
if (!empty($_POST['waa_myai_key']) && $_POST['waa_myai_key'] !== '••••••••') {
    $settings->set_myai_api_key(sanitize_text_field($_POST['waa_myai_key']));
}
```

---

## 10. Common Bugs và Cách Debug

### Bug 1: Tool input empty hoặc wrong keys

**Symptom:** Tool được gọi với `$input = []` hoặc key names sai.

**Nguyên nhân:** `normalize()` không map đúng tool arguments field.

```php
// Kiểm tra API response raw
$raw_body = wp_remote_retrieve_body($response);
error_log('Provider response: ' . $raw_body);  // tạm thời để debug
```

Check xem API trả về `input`, `args`, `arguments`, hay field name khác.

### Bug 2: "JSON-RPC parse error" hoặc API 400

**Symptom:** API trả về 400 với message về invalid format.

**Nguyên nhân thường gặp:**
1. Empty tool args serialize thành `[]` (array) thay vì `{}` (object)
   ```php
   // Fix: dùng (object) cast
   'args' => (object)($tc['input'] ?? [])
   ```
2. `additionalProperties` trong schema (Gemini không support)
   ```php
   unset($schema['additionalProperties']);
   ```
3. Tool result không đúng format cho API

### Bug 3: Infinite loop (tool_calls không dừng)

**Symptom:** Agent đạt max 10 iterations, yield `"Maximum tool iterations reached"`.

**Nguyên nhân:** `stop_reason` never returns `end_turn` vì `normalize()` luôn detect tool_calls.

**Debug:**
```php
// Trong normalize(), log raw response
error_log('stop_reason raw: ' . ($body['choices'][0]['finish_reason'] ?? 'null'));
error_log('tool_calls count: ' . count($tool_calls));
```

Check xem API có trả về empty tool_calls không, hay text response không được parse đúng.

### Bug 4: Provider không thấy tool results

**Symptom:** AI gọi tool, nhận kết quả, nhưng tiếp tục gọi cùng tool đó lần nữa.

**Nguyên nhân:** `to_X_messages()` không convert tool role messages đúng cách — backend không thấy tool results trong conversation.

**Debug:** Log `$messages` array trước khi gửi lên API:
```php
error_log('Messages to send: ' . wp_json_encode($this->to_myai_messages($messages)));
```

Kiểm tra xem tool result messages có được include không, và có đúng format không.

### Bug 5: Usage tokens là 0

**Symptom:** `GET /stats` trả về 0 tokens mặc dù AI đã response.

**Nguyên nhân:** `normalize()` dùng sai field names cho usage.

```php
// Kiểm tra API response để tìm đúng fields
'usage' => [
    'input_tokens'  => $body['usage']['prompt_tokens'] ?? 0,      // OpenAI-style
    'output_tokens' => $body['usage']['completion_tokens'] ?? 0,
    // Hoặc:
    'input_tokens'  => $body['meta']['tokens']['input'] ?? 0,     // tùy API
    'output_tokens' => $body['meta']['tokens']['output'] ?? 0,
],
```

### Checklist implement provider mới

```
[ ] get_id() trả về lowercase unique ID ('myai', 'deepseek', v.v.)
[ ] get_label() trả về human-readable name
[ ] complete() throw RuntimeException (không return WP_Error) khi lỗi
[ ] to_X_messages():
    [ ] user messages OK
    [ ] assistant messages với tool_calls: field name đúng cho provider
    [ ] tool results: grouping đúng (grouped vs standalone)
    [ ] Gemini: (object) cast cho empty args
    [ ] Gemini: role 'model' thay vì 'assistant'
    [ ] Ollama: system prompt là first message
[ ] to_X_tools(): format đúng cho API
[ ] normalize():
    [ ] Tool calls: field name normalized về 'input'
    [ ] stop_reason: dùng tool_calls presence để detect
    [ ] Usage tokens: đúng field names
    [ ] Ollama: arguments có thể là JSON string → parse
[ ] Đăng ký trong WAA_Provider_Factory::make()
[ ] Thêm models vào WAA_Pricing::DATA
[ ] WAA_Settings: thêm get/set cho API key nếu cần
[ ] Settings page: thêm dropdown option và key input row
[ ] Test với simple "Reply with OK" call
[ ] Test tool call với list_plugins (read tool)
[ ] Test multi-tool flow (install + activate)
[ ] Test empty tool input (no required params)
```
