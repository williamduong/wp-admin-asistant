# Tổng quan kiến trúc — WP Admin AI Assistant

## 1. Plugin là gì?

**WP Admin Agent** là một WordPress plugin nhúng một AI chat assistant trực tiếp vào `wp-admin`. Admin có thể dùng ngôn ngữ tự nhiên (tiếng Anh, tiếng Việt, hoặc bất kỳ ngôn ngữ nào) để quản lý plugin, theme, posts, users, settings, media và một số security flows thay vì phải click qua nhiều menu.

- **Plugin Name:** WP Admin Agent
- **Version:** 0.2.0
- **Author:** William GoRight AI
- **License:** GPL-2.0-or-later
- **Requires:** WordPress 6.5+, PHP 8.2+

Bot hỗ trợ 3 AI provider:
- **Anthropic Claude** — Claude Haiku, Sonnet, Opus (cloud, có phí)
- **Google Gemini** — Gemini 2.5 Flash, Pro (cloud, có free tier)
- **Ollama** — Qwen, Gemma, Llama chạy local hoặc trên server riêng (miễn phí)

---

## 2. Kiến trúc 3 layer

```
┌─────────────────────────────────────────────────────────────┐
│  BROWSER (React 18 + SSE)                                   │
│  ChatWidget → TaskRail → useChat() → chatStream() → parseSSE() │
│  localStorage: waa_chat_v1                                   │
│  (messages + history + usage + conversationId + confirmation)│
└──────────────────┬──────────────────────────────────────────┘
                   │  POST /wp-json/wp-admin-agent/v1/chat
                   │  Body: { message, stream:true, history:[] }
                   │  Response: text/event-stream (SSE)
┌──────────────────▼──────────────────────────────────────────┐
│  REST API  (WAA_REST_API)                                   │
│  Namespace: wp-admin-agent/v1                               │
│  Auth: manage_options + X-WP-Nonce                          │
│  Rate limit: 30 req/min (WP transients)                     │
│  Routes: /chat, /conversations, /settings, /stats, /mcp     │
│  Conversation payloads: messages + history + usage + meta   │
└──────────────────┬──────────────────────────────────────────┘
                   │
┌──────────────────▼──────────────────────────────────────────┐
│  AGENT LOOP  (WAA_Agent::run())                             │
│  Max 10 iterations (WAA_MAX_TOOL_ITERATIONS)                │
│  Runtime policy: confirmation + async classification         │
│  Yields SSE events: usage, text_delta, tool_start,          │
│                     tool_end, navigate, confirmation_required│
│         ┌──────────────────┬──────────────────┐            │
│         │                  │                  │            │
│  WAA_Provider_*     WAA_Tool_Registry    WAA_Audit_Log      │
│  (Anthropic/Gemini  (~30 tools,          (waa_logs table)   │
│   /Ollama)                                                  │
│   incl. fake provider)                                      │
└─────────────────────────────────────────────────────────────┘
```

**Luồng thay thế — MCP:**
```
Claude Desktop / Claude Code
    │  POST /wp-json/wp-admin-agent/v1/mcp (JSON-RPC 2.0)
    ▼
WAA_MCP_Server → WAA_Tool_Registry → Tool execute()
```

---

## 3. Danh sách đầy đủ files và classes

### Entry point

| File | Class | Vai trò |
|------|-------|---------|
| `wp-admin-agent.php` | — | Plugin entry: defines constants, autoloader, activation hooks, calls `WAA_Plugin::get_instance()->init()` |

**Constants được định nghĩa:**
- `WAA_VERSION` = `'0.2.0'`
- `WAA_PLUGIN_DIR`, `WAA_PLUGIN_URL`
- `WAA_TABLE_LOGS` = `{prefix}waa_logs`
- `WAA_TABLE_CONVERSATIONS` = `{prefix}waa_conversations`
- `WAA_MAX_TOOL_ITERATIONS` = `10`
- `WAA_RATE_LIMIT` = `30`

**Autoloader rules:**
- `WAA_Tool_*` → `tools/class-tool-{suffix}.php`
- `WAA_*` → `includes/class-{suffix}.php`

### Core classes (`includes/`)

| File | Class | Vai trò |
|------|-------|---------|
| `class-plugin.php` | `WAA_Plugin` | Singleton. Registers admin hooks: `admin_init`, `admin_enqueue_scripts`, `admin_footer`, `admin_menu`, `wp_dashboard_setup`. Handles settings save via POST. Activation/deactivation hooks (creates DB tables). |
| `class-rest-api.php` | `WAA_REST_API` | Registers all REST routes. `handle_chat()` starts SSE stream. `build_registry()` static method tạo tool registry. `handle_mcp()` delegates to MCP server. |
| `class-agent.php` | `WAA_Agent` | Agent loop `run()`: gọi provider, yield SSE events, execute tools, log, lặp tối đa 10 lần. Chứa `NAVIGATE_MAP`, context window normalization, confirmation policy, async classification metadata. |
| `class-provider-base.php` | `WAA_Provider_Base` | Abstract base. Defines contract: `complete()`, `get_id()`, `get_label()`, `get_model_instructions()`. Documents internal message format. |
| `class-provider-anthropic.php` | `WAA_Provider_Anthropic` | Anthropic Messages API. `to_anthropic_messages()` converts internal format → Anthropic format. `normalize()` converts response → internal format. |
| `class-provider-gemini.php` | `WAA_Provider_Gemini` | Google Gemini generateContent API. Uses `functionCall`/`functionResponse`. Key difference: tool args field is `args` (not `input`). Strips `additionalProperties`. |
| `class-provider-ollama.php` | `WAA_Provider_Ollama` | Ollama `/api/chat` endpoint. System prompt injected as first message. Tool args field: `arguments`. Handles JSON string args from some models. |
| `class-provider-factory.php` | `WAA_Provider_Factory` | `make(WAA_Settings)` creates correct provider instance. Hỗ trợ cả fake provider deterministic cho testing/runtime safety cases. |
| `class-tool-registry.php` | `WAA_Tool_Registry` | Holds registered tools. Skips BLOCKED tools and user-disabled tools. `get_schemas()` returns Anthropic-format schemas. `execute()` validates permission + input before calling tool. |
| `class-settings.php` | `WAA_Settings` | Read/write all settings from WP options. API keys encrypted via `WAA_Encryptor`. `has_active_credential()` checks if provider is configured. |
| `class-encryptor.php` | `WAA_Encryptor` | AES-256-CBC encryption/decryption using WP's `AUTH_KEY`. IV prepended to ciphertext, stored as base64. |
| `class-audit-log.php` | `WAA_Audit_Log` | `write()` logs every tool execution to `waa_logs`. `get_stats()` aggregates data by period, model, tool. |
| `class-rate-limiter.php` | `WAA_Rate_Limiter` | 30 requests/minute per user using WP transients (`waa_rate_{user_id}`). |
| `class-pricing.php` | `WAA_Pricing` | Static model metadata: label, context size, input/output price per 1M tokens. `calculate()` returns USD cost. `all_for_js()` formats for frontend. |
| `class-mcp-server.php` | `WAA_MCP_Server` | JSON-RPC 2.0 server. Methods: `initialize`, `tools/list`, `tools/call`. Protocol version 2024-11-05. Tool errors returned as `isError:true` content. |
| `class-resource-fetcher.php` | `WAA_Resource_Fetcher` | Safe URL download: validate scheme → HEAD check → GET with 5MB limit → MIME validation. Returns `{path, mime, filename, size}`. |
| `class-media-importer.php` | `WAA_Media_Importer` | URL → WP Media Library: uses Fetcher, then `wp_upload_bits()` + `wp_insert_attachment()` + `wp_generate_attachment_metadata()`. Returns `attachment_id`. |
| `class-anthropic.php` | `WAA_Anthropic` | (Legacy / utility class — separate from provider) |

### Tool classes (`tools/`)

| File | Class | Tool name | Chức năng |
|------|-------|-----------|-----------|
| `class-tool-base.php` | `WAA_Tool_Base` | — | Abstract base: `get_name()`, `get_description()`, `get_input_schema()`, `execute()`, `check_permission()`, `validate_input()`, `get_schema()` |
| `class-tool-list-plugins.php` | `WAA_Tool_List_Plugins` | `list_plugins` | Liệt kê tất cả plugins với trạng thái active |
| `class-tool-install-plugin.php` | `WAA_Tool_Install_Plugin` | `install_plugin` | Cài plugin từ WordPress.org theo slug |
| `class-tool-activate-plugin.php` | `WAA_Tool_Activate_Plugin` | `activate_plugin` | Kích hoạt plugin đã cài |
| `class-tool-deactivate-plugin.php` | `WAA_Tool_Deactivate_Plugin` | `deactivate_plugin` | Tắt plugin đang active |
| `class-tool-list-themes.php` | `WAA_Tool_List_Themes` | `list_themes` | Liệt kê themes với active status |
| `class-tool-switch-theme.php` | `WAA_Tool_Switch_Theme` | `switch_theme` | Chuyển sang theme khác |
| `class-tool-list-users.php` | `WAA_Tool_List_Users` | `list_users` | Liệt kê users với role |
| `class-tool-update-user-role.php` | `WAA_Tool_Update_User_Role` | `update_user_role` | Đổi role của user |
| `class-tool-list-posts.php` | `WAA_Tool_List_Posts` | `list_posts` | Liệt kê posts/pages với status |
| `class-tool-create-post.php` | `WAA_Tool_Create_Post` | `create_post` | Tạo post/page mới |
| `class-tool-update-post.php` | `WAA_Tool_Update_Post` | `update_post` | Cập nhật nội dung post |
| `class-tool-set-post-image.php` | `WAA_Tool_Set_Post_Image` | `set_post_image` | Đặt featured image cho post |
| `class-tool-get-settings.php` | `WAA_Tool_Get_Settings` | `get_site_settings` | Đọc site settings (title, tagline, timezone, v.v.) |
| `class-tool-update-settings.php` | `WAA_Tool_Update_Settings` | `update_site_settings` | Cập nhật site settings |
| `class-tool-search-icon.php` | `WAA_Tool_Search_Icon` | `search_icon` | Tìm icon theo keyword (trả về URL) |
| `class-tool-set-site-icon.php` | `WAA_Tool_Set_Site_Icon` | `set_site_icon` | Download icon URL → Media Library → set làm site icon |
| `class-tool-search-images.php` | `WAA_Tool_Search_Images` | `search_images` | Tìm ảnh Pexels → import vào Media Library → trả về attachment IDs |

### Admin templates

| File | Vai trò |
|------|---------|
| `admin/settings-page.php` | Settings page với 4 tabs: Provider & Keys, Media & Images, System Prompt, Tools |
| `admin/dashboard-widget.php` | Dashboard widget hiển thị recent agent actions |

### Frontend (`src/`)

| File/Component | Vai trò |
|----------------|---------|
| `src/index.jsx` | Entry point: mount React vào `#waa-root` |
| `src/App.jsx` | Root component, toggle button state |
| `src/components/ChatWidget.jsx` | Main chat panel: header, views (chat/conversations), task-state rail |
| `src/components/MessageList.jsx` | Render list tin nhắn |
| `src/components/Message.jsx` | Render một tin nhắn (text + tool calls) |
| `src/components/InputBar.jsx` | Textarea + Send button |
| `src/components/TaskRail.jsx` | Hiển thị `awaiting approval`, `working`, `background task queued` |
| `src/components/TypingIndicator.jsx` | Loading indicator khi AI đang xử lý |
| `src/components/QuickPrompts.jsx` | Gợi ý prompt khi chat rỗng |
| `src/components/SessionStats.jsx` | Hiển thị token usage + cost |
| `src/components/ConversationManager.jsx` | Session history: load, search, sort, rename, archive |
| `src/components/NavToast.jsx` | Toast thông báo khi navigate sau tool |
| `src/hooks/useChat.js` | State management, SSE handling, history building |
| `src/lib/api.js` | `chatStream()`, `apiFetch()`, conversation API functions |
| `src/lib/sse.js` | `parseSSE()` async generator: đọc ReadableStream → yield events |
| `src/lib/quickPrompts.js` | Danh sách quick prompt suggestions |

---

## 4. Danh sách tools đã đăng ký

Tools đã tăng khá nhiều so với phase đầu. Hiện codebase có khoảng 30 tool classes, bao gồm content, media, theme/plugin, navigation, RSS/news và Wordfence flows.

| # | Tool Name | Nhóm | Mô tả ngắn |
|---|-----------|-------|------------|
| 1 | `get_site_settings` | Settings | Đọc site title, tagline, timezone, URL |
| 2 | `update_site_settings` | Settings | Cập nhật site settings |
| 3 | `list_plugins` | Plugins | Liệt kê plugins + active status |
| 4 | `install_plugin` | Plugins | Cài từ WordPress.org |
| 5 | `activate_plugin` | Plugins | Kích hoạt plugin |
| 6 | `deactivate_plugin` | Plugins | Tắt plugin |
| 7 | `list_themes` | Themes | Liệt kê themes + active |
| 8 | `switch_theme` | Themes | Đổi active theme |
| 9 | `list_users` | Users | Liệt kê users + roles |
| 10 | `update_user_role` | Users | Đổi role của user |
| 11 | `list_posts` | Posts | Liệt kê posts/pages |
| 12 | `create_post` | Posts | Tạo post mới |
| 13 | `update_post` | Posts | Sửa post |
| 14 | `search_icon` | Media | Tìm icon theo keyword |
| 15 | `set_site_icon` | Media | Đặt site icon từ URL |
| 16 | `search_images` | Media | Tìm Pexels → import Media Library |
| 17 | `set_post_image` | Media | Gán featured image cho post |
| 18 | `create_simple_post` | Posts | Tạo post ngắn 1–3 đoạn |
| 19 | `create_rich_post` | Posts | Tạo post dài có ảnh tự động |
| 20 | `search_themes` | Themes | Tìm theme trên WordPress.org |
| 21 | `navigate` | Admin UX | Điều hướng tới trang wp-admin cụ thể |
| 22 | `fetch_rss` | News | Lấy bài mới từ curated RSS feeds |
| 23 | `security_harden` | Security | Áp dụng hardening settings |
| 24 | `wordfence_get_settings` | Security | Đọc Wordfence settings |
| 25 | `wordfence_update_settings` | Security | Cập nhật Wordfence settings |
| 26 | `wordfence_run_scan` | Security | Queue background scan |
| 27 | `wordfence_get_scan_results` | Security | Đọc scan results |
| 28 | `wordfence_disconnect_central` | Security | Disconnect Central |

> **Lưu ý:** số lượng chính xác có thể thay đổi theo registry/build path, nhưng docs tab nên được hiểu theo trạng thái runtime hiện tại hơn là phase đầu. BLOCKED list vẫn giữ các tên nguy hiểm như `delete_site`, `wp_delete_user_self`, `update_core`.

---

## 5. NAVIGATE_MAP — Auto-redirect sau write tools

Sau khi một write tool hoàn thành thành công, agent yields `navigate` SSE event và frontend hiển thị `NavToast` toast với link redirect:

| Tool | Redirect đến |
|------|-------------|
| `update_site_settings` | `options-general.php` |
| `set_site_icon` | `options-general.php` |
| `install_plugin` | `plugins.php` |
| `activate_plugin` | `plugins.php` |
| `deactivate_plugin` | `plugins.php` |
| `switch_theme` | `themes.php` |
| `update_user_role` | `users.php` |
| `create_post` | `edit.php` |
| `update_post` | `edit.php` |
| `set_post_image` | `edit.php` |

Ngoài navigate map, runtime hiện còn có:

- `confirmation_required` event cho sensitive/destructive actions
- auto-persistence cho `conversation_id`
- task-state rail cho `awaiting approval`, `working`, `queued`
- archive metadata cho session history

---

## 6. Database Schema

Hai bảng được tạo khi activate plugin (`WAA_Plugin::activate()`):

### `{prefix}waa_logs`

```sql
CREATE TABLE {prefix}waa_logs (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    tool_name      VARCHAR(100)    NOT NULL,
    params         LONGTEXT,           -- JSON: tool input
    result         LONGTEXT,           -- JSON: tool output
    status         VARCHAR(20)     DEFAULT 'success',  -- 'success' | 'error'
    provider       VARCHAR(50)     DEFAULT '',          -- 'anthropic' | 'gemini' | 'ollama'
    model          VARCHAR(100)    DEFAULT '',          -- e.g. 'claude-haiku-4-5'
    input_tokens   INT UNSIGNED    DEFAULT 0,
    output_tokens  INT UNSIGNED    DEFAULT 0,
    created_at     DATETIME        DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_id  (user_id),
    KEY idx_created  (created_at),
    KEY idx_model    (provider, model)
);
```

### `{prefix}waa_conversations`

```sql
CREATE TABLE {prefix}waa_conversations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    title       VARCHAR(255),
    messages    LONGTEXT,           -- JSON: display messages array
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_id (user_id)
);
```

**Lưu ý migration:** `WAA_Plugin::maybe_migrate_logs_table()` chạy khi activate để add các columns mới (`provider`, `model`, `input_tokens`, `output_tokens`) nếu bảng cũ chưa có.

**Deactivation cleanup:** `WAA_Plugin::deactivate()` xóa tất cả rate limit transients (`waa_rate_%`).

---

## 7. Tech Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Backend | PHP | 8.2+ |
| CMS | WordPress | 6.5+ |
| Frontend framework | React | 18 |
| Build tool | Vite | — |
| Frontend output | IIFE bundle | `assets/js/admin-agent.js` |
| CSS | CSS Modules (inlined) | `assets/css/admin-agent.css` |
| Real-time | Server-Sent Events (SSE) | — |
| Database | MySQL (via wpdb) | — |
| Encryption | AES-256-CBC (OpenSSL) | — |
| Rate limiting | WP Transients | — |
| AI Protocol | MCP (JSON-RPC 2.0) | 2024-11-05 |
| Testing | Vitest (JS) | — |
| Dev env | wp-env (Docker) | — |

### Build output

- `assets/js/admin-agent.js` — React app compiled as IIFE (inline format), CSS inlined
- `assets/css/admin-agent.css` — CSS file (may be separate depending on Vite config)
- React và ReactDOM được load từ WordPress core (`external: ['react', 'react-dom']`), không bundle vào app

### Pricing model (WAA_Pricing)

Tất cả pricing được hardcode trong `class-pricing.php` với đơn vị **USD per 1,000,000 tokens**:

| Provider | Model | Input | Output | Context |
|----------|-------|-------|--------|---------|
| Anthropic | claude-haiku-4-5 | $1.00/M | $5.00/M | 200K |
| Anthropic | claude-sonnet-4-6 | $3.00/M | $15.00/M | 1M |
| Anthropic | claude-opus-4-7 | $5.00/M | $25.00/M | 1M |
| Gemini | gemini-2.5-flash-lite | $0.10/M | $0.40/M | 1M |
| Gemini | gemini-2.5-flash | $0.30/M | $2.50/M | 1M |
| Gemini | gemini-2.5-pro | $1.25/M | $10.00/M | 1M |
| Ollama | qwen2.5:3b, gemma:2b, v.v. | $0 | $0 | varies |

Formula: `cost = (input_tokens * in_price + output_tokens * out_price) / 1_000_000`
