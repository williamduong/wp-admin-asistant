# Nhúng Bot & Tích hợp — WP Admin AI Assistant

## 1. Cách Bot được nhúng vào wp-admin

### Injection flow

Bot được nhúng vào **mọi trang wp-admin** thông qua 2 hooks:

```php
// WAA_Plugin::register_admin_hooks()
add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
add_action('admin_footer',          [$this, 'inject_mount_point']);
```

**Hook 1: `admin_enqueue_scripts`** — load JavaScript và CSS:

```php
public function enqueue_assets(): void {
    if (!current_user_can('manage_options')) return;  // ← chỉ admin thấy

    // Load React IIFE bundle
    wp_enqueue_script(
        'waa-admin-agent',
        WAA_PLUGIN_URL . 'assets/js/admin-agent.js',
        [],           // no deps (React/ReactDOM bundled or loaded by WP)
        $version,
        true          // in footer
    );

    // Inject data object vào window.waaData
    wp_localize_script('waa-admin-agent', 'waaData', [
        'nonce'       => wp_create_nonce('wp_rest'),
        'restUrl'     => rest_url('wp-admin-agent/v1/'),
        'currentUser' => ['id' => get_current_user_id(), 'name' => wp_get_current_user()->display_name],
        'siteUrl'     => get_site_url(),
        'version'     => WAA_VERSION,
        'provider'    => $settings->get_provider(),
        'model'       => $settings->get_model(),
        'pricing'     => WAA_Pricing::all_for_js(),
    ]);
}
```

**Hook 2: `admin_footer`** — inject mount point:

```php
public function inject_mount_point(): void {
    if (!current_user_can('manage_options')) return;
    echo '<div id="waa-root"></div>';
}
```

### React mount

```javascript
// src/index.jsx
import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';

ReactDOM.createRoot(document.getElementById('waa-root')).render(
    <React.StrictMode><App /></React.StrictMode>
);
```

React tìm `#waa-root` và mount `ChatWidget` bên trong. Widget xuất hiện như floating panel ở góc phải màn hình.

### Build output format

```javascript
// vite.config.js
output: {
    format: 'iife',               // Immediately Invoked Function Expression
    globals: {
        react:       'React',
        'react-dom': 'ReactDOM',
    },
    entryFileNames: 'js/admin-agent.js',
    assetFileNames: 'css/admin-agent.css',
}
```

**IIFE format** đảm bảo không conflict với global scope của wp-admin. React và ReactDOM là `external` — WordPress core cung cấp chúng qua `wp_enqueue_script('react', ...)`.

---

## 2. Cách dùng MCP Endpoint từ Claude Desktop

### Cấu hình Claude Desktop

Thêm vào `~/.config/claude/claude_desktop_config.json` (macOS/Linux) hoặc `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
    "mcpServers": {
        "wordpress-admin": {
            "command": "npx",
            "args": [
                "-y",
                "mcp-client-http",
                "--url", "https://your-site.com/wp-json/wp-admin-agent/v1/mcp",
                "--header", "X-WP-Nonce: YOUR_NONCE_HERE"
            ]
        }
    }
}
```

> **Lưu ý về authentication:** MCP endpoint yêu cầu `manage_options` capability. Cần có nonce hợp lệ hoặc một cách auth khác. Xem phần Authentication bên dưới.

### Alternative: dùng với Application Passwords

Kích hoạt Application Passwords trong WP (yêu cầu HTTPS):

```json
{
    "mcpServers": {
        "wordpress-admin": {
            "command": "npx",
            "args": [
                "-y",
                "mcp-client-http",
                "--url", "https://your-site.com/wp-json/wp-admin-agent/v1/mcp",
                "--header", "Authorization: Basic BASE64(username:app_password)"
            ]
        }
    }
}
```

Tạo Application Password: `wp-admin → Users → Your Profile → Application Passwords → Add New`.

### MCP Protocol flow

```
Claude Desktop                    WP MCP Server
      │                                │
      │──── initialize ────────────────►│
      │◄─── {protocolVersion, info} ───│
      │                                │
      │──── tools/list ─────────────── ►│
      │◄─── {tools: [...17 tools...]} ─│
      │                                │
      │──── tools/call(list_plugins) ──►│
      │◄─── {content:[{text:JSON}]} ───│
```

### Ví dụ JSON-RPC requests

**Handshake:**
```bash
curl -X POST https://your-site.com/wp-json/wp-admin-agent/v1/mcp \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
```

Response:
```json
{
    "jsonrpc": "2.0",
    "id": 1,
    "result": {
        "protocolVersion": "2024-11-05",
        "serverInfo": { "name": "wp-admin-agent", "version": "1.0.0" },
        "capabilities": { "tools": { "listChanged": false } },
        "instructions": "WordPress Admin Agent — manage plugins, themes, posts, users, and settings via natural language."
    }
}
```

**List tools:**
```bash
curl -X POST https://your-site.com/wp-json/wp-admin-agent/v1/mcp \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```

Response:
```json
{
    "jsonrpc": "2.0",
    "id": 2,
    "result": {
        "tools": [
            {
                "name": "list_plugins",
                "description": "List all installed plugins...",
                "inputSchema": { "type": "object", "properties": {...} }
            },
            // ... 16 more tools
        ]
    }
}
```

**Call a tool:**
```bash
curl -X POST https://your-site.com/wp-json/wp-admin-agent/v1/mcp \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "jsonrpc": "2.0",
    "id": 3,
    "method": "tools/call",
    "params": {
        "name": "get_site_settings",
        "arguments": {}
    }
  }'
```

Response:
```json
{
    "jsonrpc": "2.0",
    "id": 3,
    "result": {
        "content": [
            {
                "type": "text",
                "text": "{\n  \"blogname\": \"My WordPress Site\",\n  \"blogdescription\": \"Just another WordPress site\"...}"
            }
        ],
        "isError": false
    }
}
```

**Tool error response (isError: true, vẫn HTTP 200):**
```json
{
    "jsonrpc": "2.0",
    "id": 4,
    "result": {
        "content": [
            { "type": "text", "text": "Plugin 'invalid-slug' not found in WordPress.org repository." }
        ],
        "isError": true
    }
}
```

---

## 3. Cách gọi REST API từ External Apps

### Tất cả routes

Base URL: `https://your-site.com/wp-json/wp-admin-agent/v1/`

| Method | Endpoint | Mô tả |
|--------|----------|-------|
| `POST` | `/chat` | Stream AI chat (SSE) |
| `GET` | `/conversations` | List saved conversations |
| `POST` | `/conversations` | Create new conversation |
| `GET` | `/conversations/{id}` | Get conversation |
| `DELETE` | `/conversations/{id}` | Delete conversation |
| `POST` | `/test-connection` | Test provider connection |
| `GET` | `/settings` | Get plugin settings |
| `POST` | `/settings` | Update plugin settings |
| `GET` | `/stats` | Get usage stats |
| `GET` | `/pricing` | Get model pricing data |
| `GET` | `/ollama-models` | List Ollama models |
| `POST` | `/mcp` | MCP JSON-RPC endpoint |

### Authentication

Tất cả routes yêu cầu:
1. User phải có `manage_options` capability (WordPress Administrator)
2. Valid `X-WP-Nonce` header (hoặc Application Password via Basic Auth)

**Lấy nonce từ browser:**
```javascript
// Trong context wp-admin (đã có waaData)
const nonce = window.waaData.nonce;

// Hoặc từ bất kỳ WP page có wp_rest nonce
const nonce = document.querySelector('meta[name="nonce"]')?.content;
```

**Lấy nonce qua WP-CLI:**
```bash
wp eval "echo wp_create_nonce('wp_rest');"
# Output: abc123def456  (valid 12 giờ)
```

**Application Password (persistent, không expire):**
```bash
# Tạo application password
wp user application-password create 1 "My App" --field=password
# Output: abc1 2345 6789 XYZ...

# Encode cho Basic Auth
echo -n "admin:abc1 2345 6789 XYZ..." | base64
# Output: YWRtaW46YWJjMSAyMzQ1IDY3ODkgWFlaLi4u
```

### Chat endpoint (SSE Stream)

```javascript
// Gọi chat endpoint, parse SSE stream
async function sendToBot(message, history = []) {
    const response = await fetch('https://your-site.com/wp-json/wp-admin-agent/v1/chat', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': 'YOUR_NONCE',
        },
        body: JSON.stringify({
            message: message,
            stream: true,
            history: history,
        }),
    });

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() ?? '';

        for (const line of lines) {
            if (!line.startsWith('data: ')) continue;
            const raw = line.slice(6).trim();
            if (raw === '[DONE]') return;

            const event = JSON.parse(raw);
            console.log('Event:', event.type, event);

            if (event.type === 'text_delta') {
                process.stdout.write(event.content);
            }
        }
    }
}

// Usage
await sendToBot('List all plugins');
```

### Python example

```python
import requests
import json

BASE_URL = "https://your-site.com/wp-json/wp-admin-agent/v1"
NONCE = "YOUR_NONCE"

def chat_with_bot(message, history=None):
    response = requests.post(
        f"{BASE_URL}/chat",
        headers={
            "Content-Type": "application/json",
            "X-WP-Nonce": NONCE,
        },
        json={
            "message": message,
            "stream": True,
            "history": history or [],
        },
        stream=True,
    )

    full_text = ""
    for line in response.iter_lines():
        if not line:
            continue
        line = line.decode("utf-8")
        if not line.startswith("data: "):
            continue
        raw = line[6:].strip()
        if raw == "[DONE]":
            break
        event = json.loads(raw)
        if event.get("type") == "text_delta":
            full_text += event["content"]
            print(event["content"], end="", flush=True)
    
    print()  # newline
    return full_text

# Usage
result = chat_with_bot("What plugins are installed?")
```

### Lấy settings

```bash
curl https://your-site.com/wp-json/wp-admin-agent/v1/settings \
  -H "X-WP-Nonce: YOUR_NONCE"
```

Response:
```json
{
    "provider": "anthropic",
    "model": "claude-haiku-4-5",
    "has_api_key": true,
    "has_gemini_key": false,
    "ollama_url": "http://localhost:11434"
}
```

### Lấy stats

```bash
curl "https://your-site.com/wp-json/wp-admin-agent/v1/stats?period=30" \
  -H "X-WP-Nonce: YOUR_NONCE"
```

Response:
```json
{
    "period_days": 30,
    "totals": {
        "total_calls": 143,
        "total_input": 450000,
        "total_output": 28000,
        "total_errors": 2
    },
    "total_cost": 0.59,
    "by_model": [
        { "provider": "anthropic", "model": "claude-haiku-4-5", "calls": 143, "cost_usd": 0.59 }
    ],
    "daily": [...],
    "top_tools": [
        { "tool_name": "list_plugins", "calls": 45 },
        { "tool_name": "get_site_settings", "calls": 38 }
    ]
}
```

---

## 4. Rate Limiting và Auth

### Rate limit

```
30 requests / minute / user (WAA_RATE_LIMIT constant)
```

Implement via WordPress transients:
```php
// Key: waa_rate_{user_id}
// Value: request count trong window 1 minute
$count = (int) get_transient("waa_rate_{$user_id}");
if ($count >= 30) return false;  // 429 Too Many Requests
set_transient("waa_rate_{$user_id}", $count + 1, MINUTE_IN_SECONDS);
```

Khi bị rate limit, API trả về:
```json
HTTP 429
{"code":"rate_limited","message":"Too many requests. Try again in a minute.","data":{"status":429}}
```

**Clear rate limit** (admin only, qua WP-CLI):
```bash
wp transient delete --search="waa_rate_" --allow-root
```

### Auth errors

```json
HTTP 403
{"code":"rest_forbidden","message":"Insufficient permissions.","data":{"status":403}}
```

Nguyên nhân:
1. User không có `manage_options` (không phải admin)
2. Nonce invalid hoặc expired
3. Cookie/session đã expire

### X-WP-Nonce vs Application Password

| Method | Lifetime | Use case |
|--------|----------|---------|
| X-WP-Nonce | 12 giờ | In-browser requests |
| Application Password | Permanent | Server-to-server, CLI scripts |
| Basic Auth (username:password) | — | Dev only (không nên dùng production) |

---

## 5. Dashboard Widget

Bot tự động thêm widget vào WordPress Dashboard:

```php
// WAA_Plugin::add_dashboard_widget()
wp_add_dashboard_widget(
    'waa_recent_actions',
    'Recent Agent Actions',
    function () {
        require_once WAA_PLUGIN_DIR . 'admin/dashboard-widget.php';
        waa_render_dashboard_widget();
    }
);
```

Widget hiển thị:
- 10 tool executions gần nhất từ `waa_logs`
- Tool name, status (success/error), timestamp
- Provider + model được dùng

Widget chỉ visible cho users có `manage_options`.

---

## 6. Customization

### Custom Rules — Thay đổi behavior

Vào `Settings → Admin Agent → System Prompt tab → Custom Rules`:

**Ví dụ: Force Vietnamese responses:**
```
- Always respond in Vietnamese, regardless of what language the user writes in.
- Use formal Vietnamese (Anh/Chị - tôi form).
```

**Ví dụ: Restrict capabilities:**
```
- Do not install or activate plugins unless the user explicitly mentions the plugin name.
- Always list the current plugins before suggesting any changes.
- Never deactivate security-related plugins (e.g., Wordfence, iThemes Security).
```

**Ví dụ: Custom workflow:**
```
- When creating posts, always ask for: title, category, and whether to publish or save as draft.
- After creating a post, always set a featured image using search_images.
- Default post status is 'draft' unless user says 'publish'.
```

Custom rules có tác dụng ngay lập tức sau khi save — không cần restart hay rebuild.

### Disable Tools — Thu hẹp quyền hạn

Vào `Settings → Admin Agent → Tools tab`:

**Read-only mode** (disable tất cả write tools):
```
Disable: install_plugin, activate_plugin, deactivate_plugin
         switch_theme, update_user_role
         create_post, update_post, set_post_image
         update_site_settings, set_site_icon, search_images
```

AI chỉ có thể list và report, không thể thay đổi gì.

**Media-only mode** (chỉ cho phép media operations):
```
Disable: install_plugin, activate_plugin, deactivate_plugin
         switch_theme, update_user_role
         update_site_settings
Keep: search_images, set_post_image, set_site_icon, search_icon, list_posts
```

### Model Selection

Chọn model dựa trên trade-off:

| Model | Speed | Cost | Capability | Best for |
|-------|-------|------|-----------|---------|
| claude-haiku-4-5 | Fast | $1/$5 per 1M | Good | Routine tasks, quick queries |
| claude-sonnet-4-6 | Medium | $3/$15 per 1M | Excellent | Complex multi-step tasks |
| gemini-2.5-flash | Fast | $0.30/$2.50 per 1M | Very good | Cost-conscious deployments |
| qwen2.5:3b (Ollama) | Fast | Free | Basic | Vietnamese content, offline |

### Provider-specific Tips

**Anthropic:**
- Nhất quán với tool use, ít bị "invent" parameters
- Tốt cho complex multi-step tool chains
- Haiku phù hợp cho hầu hết tasks

**Gemini:**
- Free tier hữu ích cho testing
- Đôi khi thêm extra fields không có trong schema → model instructions đã address điều này
- `gemini-2.5-flash` là balance tốt giữa speed và capability

**Ollama:**
- `qwen2.5:3b` recommend cho Vietnamese content (training data tốt)
- Tool support kém consistent hơn cloud providers
- Sequential tool calls only (Ollama instructions đã guide AI)
- Dùng `host.docker.internal:11434` trong wp-env Docker environment

---

## 7. Integrating với Claude Code (MCP)

Dùng plugin như một MCP server trong Claude Code sessions:

### Setup trong Claude Code

```bash
# Thêm MCP server
claude mcp add wordpress-admin \
  --transport http \
  --url "https://your-site.com/wp-json/wp-admin-agent/v1/mcp" \
  --header "X-WP-Nonce: YOUR_NONCE"
```

Hoặc edit `~/.claude/settings.json`:
```json
{
    "mcpServers": {
        "wordpress-admin": {
            "type": "http",
            "url": "https://your-site.com/wp-json/wp-admin-agent/v1/mcp",
            "headers": {
                "X-WP-Nonce": "YOUR_NONCE"
            }
        }
    }
}
```

### Verify connection

```bash
claude mcp list
# Output: wordpress-admin: connected (17 tools)
```

### Sử dụng trong Claude Code

Sau khi configure, Claude Code có thể dùng tất cả 17 WP tools trong session:

```
User: Use the wordpress-admin MCP to list all plugins

Claude: I'll use the wordpress-admin MCP to check the plugins.
[Calls: list_plugins()]
Here are the installed plugins...
```

---

## 8. Security Considerations

### API key storage

- Tất cả API keys (Anthropic, Gemini, Pexels) được encrypt với AES-256-CBC trước khi lưu vào DB
- Encryption key là WP's `AUTH_KEY` (từ `wp-config.php`)
- Keys chỉ decrypt khi cần dùng, không bao giờ expose trong responses
- Rule 3 trong system prompt: "Never expose or repeat API keys, passwords, or credentials"

### Capability check

```php
// Mọi REST route đều check
if (!current_user_can('manage_options')) {
    return new WP_Error('rest_forbidden', 'Insufficient permissions.', ['status' => 403]);
}
```

Chỉ WordPress Administrators (role `administrator`) mặc định có `manage_options`.

### Tool permission check

```php
// WAA_Tool_Base
public function check_permission(): bool {
    return current_user_can('manage_options');
}
```

Double-check — kể cả nếu REST auth pass, tool cũng check lại.

### Audit logging

Mọi tool execution đều được log:
```sql
SELECT tool_name, params, result, status, created_at
FROM waa_logs
ORDER BY created_at DESC;
```

Đây là trail để review AI actions nếu có vấn đề.

### Rate limiting

30 req/min prevent abuse và bảo vệ AI provider quota. Khi bị rate limit, user thấy error message và có thể thử lại sau 1 phút.

### Blocked tools

Các tools sau bị permanently block trong code (không thể enable qua UI):
- `delete_site` — xóa toàn bộ site
- `wp_delete_user_self` — tự xóa account hiện tại
- `update_core` — update WordPress core (quá nguy hiểm để AI tự làm)

### HTTPS requirement

- Nonce-based auth chỉ an toàn trên HTTPS (prevent man-in-the-middle)
- API keys trong transit được protect bởi TLS
- Production deployment nên bắt buộc HTTPS

### Data không rời site

- Conversation history lưu trong localStorage (browser) và DB (nếu save)
- Plugin không gửi history đến bất kỳ server bên ngoài nào ngoài AI provider
- Tool results không được cache hay share
- Chỉ AI provider (Anthropic/Gemini/Ollama) nhận nội dung conversation
