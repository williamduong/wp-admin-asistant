# WP Admin AI Assistant — Kế Hoạch Triển Khai Chi Tiết

> Từ zero → production · Natural language → WordPress admin settings  
> Version: 2.0 · Ngày tạo: 2026-05-14 · Tác giả: William / GoRight AI

---

## Mục lục

1. [Tổng quan dự án](#1-tổng-quan-dự-án)
2. [Kiến trúc hệ thống](#2-kiến-trúc-hệ-thống)
3. [Tech Stack](#3-tech-stack)
4. [Cấu trúc thư mục](#4-cấu-trúc-thư-mục)
5. [Phase 0 — Cài đặt môi trường Dev](#phase-0--cài-đặt-môi-trường-dev)
6. [Phase 1 — Plugin Core (PHP)](#phase-1--plugin-core-php)
7. [Phase 2 — Chat UI (React)](#phase-2--chat-ui-react)
8. [Phase 3 — AI Agent Backend](#phase-3--ai-agent-backend)
9. [Phase 4 — WordPress Tool Definitions](#phase-4--wordpress-tool-definitions)
10. [Phase 5 — Security & Permissions](#phase-5--security--permissions)
11. [Phase 6 — Testing (Unit + Integration + E2E)](#phase-6--testing-unit--integration--e2e)
12. [Phase 7 — CI/CD Pipeline](#phase-7--cicd-pipeline)
13. [Phase 8 — Demo Polish & Packaging](#phase-8--demo-polish--packaging)
14. [Phase 9 — Deployment & Vận hành](#phase-9--deployment--vận-hành)
15. [Risk Register](#15-risk-register)
16. [Reference Links](#16-reference-links)

---

## 1. Tổng quan dự án

**Tên:** WP Admin Agent  
**Loại:** WordPress Plugin (self-contained)  
**Mục tiêu:** Nhúng AI chat assistant vào `wp-admin`, cho phép administrator điều khiển WordPress settings, plugins, themes, content bằng ngôn ngữ tự nhiên.

**Ví dụ tương tác:**
- _"Change the site title to GoRight and update the tagline to 'AI for Everyone'"_
- _"Deactivate the Jetpack plugin"_
- _"Switch the theme to Twenty Twenty-Four"_
- _"Show me all users with the Editor role"_
- _"Set the default comment status to closed"_

**Timeline tổng:** ~30 ngày làm việc

| Phase | Thời gian | Output |
|---|---|---|
| Phase 0 | Day 1–2 | Dev environment + plugin skeleton |
| Phase 1 | Day 3–5 | PHP core + REST endpoints |
| Phase 2 | Day 6–9 | React chat UI (mock) |
| Phase 3 | Day 10–14 | AI agent loop + SSE |
| Phase 4 | Day 15–21 | WordPress tools thực tế |
| Phase 5 | Day 22–25 | Security + permissions |
| Phase 6 | Day 26–28 | Testing đầy đủ |
| Phase 7 | Day 28–29 | CI/CD pipeline |
| Phase 8 | Day 29–30 | Demo + packaging |
| Phase 9 | Day 30+ | Deploy staging → production |

---

## 2. Kiến trúc hệ thống

### Sơ đồ luồng dữ liệu

```
┌─────────────────────────────────────────────────────────┐
│  wp-admin (Browser)                                      │
│  ┌──────────────────────────────────────────────────┐    │
│  │  React Chat Widget                               │    │
│  │  - Message list (streaming tokens via SSE)       │    │
│  │  - Input bar + quick prompt chips                │    │
│  │  - TypingIndicator / ToolStatus display          │    │
│  │  - ConfirmDialog for destructive actions         │    │
│  └────────────────┬─────────────────────────────────┘    │
└───────────────────│──────────────────────────────────────┘
                    │  POST + SSE stream
                    ▼
┌─────────────────────────────────────────────────────────┐
│  WordPress Plugin PHP Backend                            │
│                                                          │
│  REST endpoint: /wp-json/wp-admin-agent/v1/chat         │
│         │                                                │
│         ▼                                                │
│  ┌─────────────────────────────────────────┐             │
│  │  Agentic Loop (WAA_Agent)               │             │
│  │  1. Build messages + tool schemas       │             │
│  │  2. Call Anthropic API                  │             │
│  │  3. Receive tool_use block              │             │
│  │  4. Execute WP tool (WAA_Tool_Registry) │             │
│  │  5. Log to audit table                  │             │
│  │  6. Feed result back → repeat           │             │
│  │  7. Stream SSE events to browser        │             │
│  └───────────────┬─────────────────────────┘             │
│                  │                                        │
│         ┌────────┴───────────────────┐                    │
│         ▼                            ▼                    │
│  WordPress REST API           WordPress Functions         │
│  /wp/v2/settings              update_option()            │
│  /wp/v2/plugins               activate_plugins()         │
│  /wp/v2/themes                switch_theme()             │
│  /wp-abilities/v1/*           wp_update_user()           │
│                               wp_update_post()           │
│                                                           │
│  [Optional] MCP Adapter bridge                           │
│  WordPress/mcp-adapter → Abilities API                   │
└─────────────────────────────────────────────────────────┘
                    │
                    ▼
         Anthropic API (claude-haiku-4-5)
         Tool use / streaming messages
```

### Quyết định kiến trúc: Self-Contained PHP Plugin

| Tiêu chí | Self-Contained PHP | Separate Node Server |
|---|---|---|
| Cài đặt | Upload zip, activate | Deploy server riêng |
| Auth | WP Nonce native | Custom auth layer |
| WP API access | Direct (in-process) | REST roundtrip |
| Demo-ability | Instant | Cần infra |
| **Kết luận** | **✅ Chọn** | ❌ Phức tạp hơn |

### MCP Bridge (Optional — upgrade path)

- Phase 4 có thêm MCP bridge qua `WordPress/mcp-adapter`
- Khi bật: tools có thể dùng từ Claude Desktop, external clients
- Không cần cho demo — architecture hooks sẵn để thêm sau

---

## 3. Tech Stack

| Layer | Technology | Version | Ghi chú |
|---|---|---|---|
| WordPress | WP Core | **6.9+** | Bắt buộc — Abilities API |
| PHP | PHP | **8.2+** | Readonly props, fibers cho SSE |
| Frontend | React | 18 | Qua `@wordpress/element` |
| Build tool | Vite | 5.x | HMR nhanh khi dev |
| CSS | CSS Modules | — | Scoped `#waa-root`, không conflict wp-admin |
| AI Model | claude-haiku-4-5 | latest | Nhanh, rẻ, tool-use tốt |
| AI SDK | Anthropic HTTP API | — | Raw curl/wp_remote_post |
| Streaming | Server-Sent Events | — | Polling fallback cho restricted hosts |
| Auth | WP Nonce + `manage_options` | — | Native WP |
| Storage | `wp_options` + custom tables | — | Không cần external DB |
| Dev env | wp-env (Docker) | — | Reproducible |
| Testing PHP | PHPUnit | 10.x | Unit + integration tests |
| Testing JS | Vitest + RTL | 1.x | Component + hook tests |
| E2E | Playwright | 1.x | Full browser automation |
| CI | GitHub Actions | — | Lint + test + build |
| MCP (opt.) | WordPress/mcp-adapter | 0.x | Official WP MCP bridge |

---

## 4. Cấu trúc thư mục

```
wp-admin-agent/
│
├── wp-admin-agent.php              # Plugin header + bootstrap
├── readme.txt                      # WP.org standard readme
├── CHANGELOG.md
├── package.json                    # JS dependencies + scripts
├── composer.json                   # PHP dependencies (nếu cần)
├── vite.config.js
├── phpunit.xml                     # PHPUnit config
├── playwright.config.ts            # E2E config
├── .github/
│   └── workflows/
│       ├── ci.yml                  # Lint + test + build on PR
│       └── release.yml             # Build + zip + tag on merge to main
│
├── includes/                       # PHP classes (autoloaded)
│   ├── class-plugin.php            # Init, activation, deactivation hooks
│   ├── class-rest-api.php          # REST routes + handlers
│   ├── class-agent.php             # Agentic loop (LLM calls + tool execution)
│   ├── class-anthropic.php         # HTTP client cho Anthropic API
│   ├── class-tool-registry.php     # Đăng ký + routing tool execution
│   ├── class-audit-log.php         # Ghi DB log mỗi tool call
│   ├── class-settings.php          # Plugin settings (key, model, limits)
│   ├── class-encryptor.php         # AES-256 encrypt/decrypt API key
│   ├── class-capabilities.php      # Permission map per tool
│   └── class-rate-limiter.php      # Transient-based rate limiting
│
├── tools/                          # Mỗi file = 1 WordPress tool
│   ├── class-tool-base.php         # Abstract: schema + execute + permission
│   ├── class-tool-get-settings.php
│   ├── class-tool-update-settings.php
│   ├── class-tool-list-plugins.php
│   ├── class-tool-activate-plugin.php
│   ├── class-tool-deactivate-plugin.php
│   ├── class-tool-list-themes.php
│   ├── class-tool-switch-theme.php
│   ├── class-tool-list-users.php
│   ├── class-tool-update-user-role.php
│   ├── class-tool-list-posts.php
│   ├── class-tool-update-post.php
│   └── class-tool-mcp-bridge.php   # Optional: delegate đến mcp-adapter
│
├── admin/
│   ├── settings-page.php           # UI nhập API key, chọn model
│   └── dashboard-widget.php        # "Recent Agent Actions" widget
│
├── src/                            # React source
│   ├── index.jsx                   # Mount vào #waa-root
│   ├── App.jsx                     # Root state
│   ├── components/
│   │   ├── ChatWidget.jsx          # Toggle button + slide panel
│   │   ├── MessageList.jsx         # Thread renderer
│   │   ├── Message.jsx             # Bubble (user/assistant)
│   │   ├── InputBar.jsx            # Textarea + send
│   │   ├── TypingIndicator.jsx     # Animated dots
│   │   ├── QuickPrompts.jsx        # Preset chips
│   │   ├── ToolStatus.jsx          # "Calling get_site_settings..."
│   │   ├── ConfirmDialog.jsx       # Destructive action modal
│   │   └── HistoryPanel.jsx        # Past conversations
│   ├── hooks/
│   │   ├── useChat.js              # SSE + message state
│   │   ├── useHistory.js           # Load/save conversations
│   │   └── useConfirm.js           # Confirm dialog state machine
│   ├── lib/
│   │   ├── api.js                  # fetch wrapper với nonce headers
│   │   ├── sse.js                  # SSE stream parser (async generator)
│   │   └── markdown.js             # Lightweight MD renderer
│   └── styles/
│       ├── widget.module.css
│       ├── messages.module.css
│       └── input.module.css
│
├── assets/                         # Compiled output (gitignored)
│   ├── js/admin-agent.js
│   └── css/admin-agent.css
│
└── tests/
    ├── php/
    │   ├── bootstrap.php           # PHPUnit bootstrap (load WP)
    │   ├── unit/
    │   │   ├── AgentTest.php
    │   │   ├── EncryptorTest.php
    │   │   ├── RateLimiterTest.php
    │   │   ├── ToolRegistryTest.php
    │   │   └── tools/
    │   │       ├── GetSettingsToolTest.php
    │   │       ├── UpdateSettingsToolTest.php
    │   │       ├── ListPluginsToolTest.php
    │   │       └── ListUsersToolTest.php
    │   └── integration/
    │       ├── RestApiTest.php      # Test REST endpoints vs WP test db
    │       └── AgentIntegrationTest.php
    ├── js/
    │   ├── setup.js                # Vitest setup (jsdom)
    │   ├── components/
    │   │   ├── ChatWidget.test.jsx
    │   │   ├── MessageList.test.jsx
    │   │   ├── ConfirmDialog.test.jsx
    │   │   └── InputBar.test.jsx
    │   └── hooks/
    │       ├── useChat.test.js
    │       └── useConfirm.test.js
    └── e2e/
        ├── fixtures/
        │   └── wp-seed.sql         # Demo DB snapshot
        ├── smoke.spec.ts           # 5 critical flows
        └── security.spec.ts        # Auth bypass attempts
```

---

## Phase 0 — Cài đặt môi trường Dev

**Duration:** Day 1–2  
**Output:** WordPress chạy local, plugin skeleton activatable, JS build pipeline hoạt động.

### 0.1 Cài wp-env (Docker)

```bash
# Yêu cầu: Docker Desktop đang chạy
npm install -g @wordpress/env

# Tạo thư mục plugin
mkdir wp-admin-agent && cd wp-admin-agent

# Tạo .wp-env.json
cat > .wp-env.json << 'EOF'
{
  "core": "WordPress/WordPress#6.9",
  "phpVersion": "8.2",
  "plugins": ["."],
  "config": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true,
    "SCRIPT_DEBUG": true
  }
}
EOF

# Khởi động
npx @wordpress/env start

# Kiểm tra phiên bản
npx @wordpress/env run cli wp core version
# → 6.9.x

# Admin credentials mặc định: admin / password
# URL: http://localhost:8888/wp-admin
```

> **Thay thế nếu không có Docker:** Dùng [LocalWP](https://localwp.com), PHP 8.2+, WordPress 6.9+

### 0.2 Plugin Main File

Tạo `wp-admin-agent.php`:

```php
<?php
/**
 * Plugin Name:       WP Admin Agent
 * Plugin URI:        https://github.com/yourname/wp-admin-agent
 * Description:       Natural language AI assistant for WordPress admin settings.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            GoRight AI
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-admin-agent
 */

defined('ABSPATH') || exit;

define('WAA_VERSION',            '0.1.0');
define('WAA_PLUGIN_DIR',         plugin_dir_path(__FILE__));
define('WAA_PLUGIN_URL',         plugin_dir_url(__FILE__));
define('WAA_TABLE_LOGS',         $GLOBALS['wpdb']->prefix . 'waa_logs');
define('WAA_TABLE_CONVERSATIONS',$GLOBALS['wpdb']->prefix . 'waa_conversations');
define('WAA_MAX_TOOL_ITERATIONS', 10);  // Ngăn infinite loop
define('WAA_RATE_LIMIT',          30);  // Requests/phút/user

// Autoload
spl_autoload_register(function (string $class): void {
    $prefixes = [
        'WAA_Tool_' => WAA_PLUGIN_DIR . 'tools/class-',
        'WAA_'      => WAA_PLUGIN_DIR . 'includes/class-',
    ];
    foreach ($prefixes as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) continue;
        $suffix = strtolower(str_replace('_', '-', substr($class, strlen($prefix))));
        $file   = $base . $suffix . '.php';
        if (file_exists($file)) require_once $file;
    }
});

require_once WAA_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook(__FILE__,   ['WAA_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WAA_Plugin', 'deactivate']);

add_action('plugins_loaded', fn() => WAA_Plugin::get_instance()->init());
```

### 0.3 JavaScript Build Setup

```bash
npm init -y
npm install --save-dev vite @vitejs/plugin-react
npm install --save-dev react react-dom           # sẽ externalize với wp-element
npm install --save-dev vitest @vitest/coverage-v8 \
    @testing-library/react @testing-library/user-event \
    @testing-library/jest-dom jsdom
npm install --save-dev playwright @playwright/test
```

**vite.config.js:**
```js
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'assets',
    emptyOutDir: true,
    rollupOptions: {
      input: 'src/index.jsx',
      external: ['react', 'react-dom'],          // Dùng wp-element thay thế
      output: {
        globals: { react: 'React', 'react-dom': 'ReactDOM' },
        entryFileNames: 'js/admin-agent.js',
        assetFileNames: 'css/admin-agent.css',
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['tests/js/setup.js'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'lcov'],
      thresholds: { lines: 70 },
    },
  },
});
```

**package.json scripts:**
```json
{
  "scripts": {
    "dev":      "vite build --watch",
    "build":    "vite build",
    "test:js":  "vitest run --coverage",
    "test:e2e": "playwright test",
    "lint":     "eslint src --ext .js,.jsx"
  }
}
```

### 0.4 Database Tables (Activation Hook)

```php
// includes/class-plugin.php
class WAA_Plugin {
    private static ?self $instance = null;

    public static function get_instance(): static {
        return static::$instance ??= new static();
    }

    public function init(): void {
        new WAA_REST_API();
        $this->register_admin_hooks();
    }

    public static function activate(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS " . WAA_TABLE_LOGS . " (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL,
            tool_name   VARCHAR(100)    NOT NULL,
            params      LONGTEXT,
            result      LONGTEXT,
            status      VARCHAR(20)     DEFAULT 'success',
            created_at  DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id  (user_id),
            KEY created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE IF NOT EXISTS " . WAA_TABLE_CONVERSATIONS . " (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL,
            title       VARCHAR(255),
            messages    LONGTEXT,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $charset;");

        update_option('waa_db_version', WAA_VERSION);
    }

    public static function deactivate(): void {
        // Giữ data — chỉ xóa transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'waa_rate_%'");
    }

    private function register_admin_hooks(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_footer',          [$this, 'inject_mount_point']);
        add_action('admin_menu',            [$this, 'add_settings_page']);
        add_action('wp_dashboard_setup',    [$this, 'add_dashboard_widget']);
    }

    public function enqueue_assets(string $hook): void {
        if (!current_user_can('manage_options')) return;

        wp_enqueue_script(
            'waa-admin-agent',
            WAA_PLUGIN_URL . 'assets/js/admin-agent.js',
            ['wp-element'],
            WAA_VERSION,
            true
        );
        wp_enqueue_style(
            'waa-admin-agent',
            WAA_PLUGIN_URL . 'assets/css/admin-agent.css',
            [],
            WAA_VERSION
        );

        wp_localize_script('waa-admin-agent', 'waaData', [
            'nonce'       => wp_create_nonce('waa_rest_nonce'),
            'restUrl'     => rest_url('wp-admin-agent/v1/'),
            'currentUser' => [
                'id'   => get_current_user_id(),
                'name' => wp_get_current_user()->display_name,
            ],
            'siteUrl'     => get_site_url(),
            'version'     => WAA_VERSION,
        ]);
    }

    public function inject_mount_point(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div id="waa-root"></div>';
    }
}
```

### 0.5 Kiểm tra Exit Criterion Phase 0

```bash
# Activate plugin
npx @wordpress/env run cli wp plugin activate wp-admin-agent

# Kiểm tra bảng DB
npx @wordpress/env run cli wp db query "SHOW TABLES LIKE 'wp_waa_%'"
# → wp_waa_logs, wp_waa_conversations

# Kiểm tra không có PHP error
npx @wordpress/env run cli wp eval "do_action('init'); echo 'OK';"
# → OK

# Build JS
npm run build
ls assets/js/admin-agent.js   # phải tồn tại
```

**Exit Criterion:** Plugin activatable không lỗi, DB tables tồn tại, JS build thành công.

---

## Phase 1 — Plugin Core (PHP)

**Duration:** Day 3–5  
**Output:** REST endpoints hoạt động, nonce auth, verify bằng curl.

### 1.1 REST API Registration

```php
// includes/class-rest-api.php
class WAA_REST_API {
    private const NS = 'wp-admin-agent/v1';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        // Chat endpoint — main entry point
        register_rest_route(self::NS, '/chat', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_chat'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'message'         => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
                'conversation_id' => ['required' => false, 'type' => 'integer'],
                'stream'          => ['required' => false, 'type' => 'boolean', 'default' => true],
            ],
        ]);

        // Test connection (validate API key)
        register_rest_route(self::NS, '/test-connection', [
            'methods'             => 'POST',
            'callback'            => [$this, 'test_connection'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Conversations CRUD
        register_rest_route(self::NS, '/conversations', [
            ['methods' => 'GET',  'callback' => [$this, 'list_conversations'],  'permission_callback' => [$this, 'check_permission']],
            ['methods' => 'POST', 'callback' => [$this, 'create_conversation'], 'permission_callback' => [$this, 'check_permission']],
        ]);
        register_rest_route(self::NS, '/conversations/(?P<id>\d+)', [
            ['methods' => 'GET',    'callback' => [$this, 'get_conversation'],    'permission_callback' => [$this, 'check_permission']],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete_conversation'], 'permission_callback' => [$this, 'check_permission']],
        ]);

        // Plugin settings (API key, model)
        register_rest_route(self::NS, '/settings', [
            ['methods' => 'GET',  'callback' => [$this, 'get_plugin_settings'],  'permission_callback' => [$this, 'check_permission']],
            ['methods' => 'POST', 'callback' => [$this, 'save_plugin_settings'], 'permission_callback' => [$this, 'check_permission']],
        ]);
    }

    public function check_permission(WP_REST_Request $request): bool|WP_Error {
        // 1. Verify nonce
        $nonce = $request->get_header('X-WP-Nonce') ?? '';
        if (!wp_verify_nonce($nonce, 'waa_rest_nonce')) {
            return new WP_Error('rest_forbidden', 'Invalid nonce.', ['status' => 403]);
        }

        // 2. Verify capability
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', 'Insufficient permissions.', ['status' => 403]);
        }

        // 3. Rate limiting
        if (!(new WAA_Rate_Limiter())->check()) {
            return new WP_Error('rate_limited', 'Too many requests. Try again in a minute.', ['status' => 429]);
        }

        return true;
    }

    public function test_connection(WP_REST_Request $request): WP_REST_Response {
        $settings = new WAA_Settings();
        $api_key  = $settings->get_api_key();

        if (empty($api_key)) {
            return new WP_REST_Response(['success' => false, 'error' => 'No API key configured.'], 400);
        }

        try {
            $client = new WAA_Anthropic($api_key);
            $result = $client->messages([
                'messages'   => [['role' => 'user', 'content' => 'Say OK']],
                'max_tokens' => 5,
            ], stream: false);

            return new WP_REST_Response(['success' => true, 'model' => $result['model'] ?? 'unknown'], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
```

### 1.2 Settings Class

```php
// includes/class-settings.php
class WAA_Settings {
    private WAA_Encryptor $enc;

    public function __construct() {
        $this->enc = new WAA_Encryptor();
    }

    public function get_api_key(): string {
        $encrypted = get_option('waa_api_key_enc', '');
        return $encrypted ? $this->enc->decrypt($encrypted) : '';
    }

    public function set_api_key(string $key): void {
        update_option('waa_api_key_enc', $this->enc->encrypt($key), false);
    }

    public function get_model(): string {
        return get_option('waa_model', 'claude-haiku-4-5');
    }

    public function set_model(string $model): void {
        $allowed = ['claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-opus-4-7'];
        if (in_array($model, $allowed, true)) {
            update_option('waa_model', $model);
        }
    }

    public function get_max_tokens(): int {
        return (int) get_option('waa_max_tokens', 4096);
    }
}
```

### 1.3 Encryptor Class

```php
// includes/class-encryptor.php
class WAA_Encryptor {
    private const CIPHER = 'AES-256-CBC';

    public function encrypt(string $value): string {
        $iv        = random_bytes(16);
        $encrypted = openssl_encrypt($value, self::CIPHER, AUTH_KEY, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $encoded): string {
        $raw = base64_decode($encoded, strict: true);
        if ($raw === false || strlen($raw) < 16) return '';
        $iv        = substr($raw, 0, 16);
        $encrypted = substr($raw, 16);
        $result    = openssl_decrypt($encrypted, self::CIPHER, AUTH_KEY, OPENSSL_RAW_DATA, $iv);
        return $result !== false ? $result : '';
    }
}
```

### 1.4 Rate Limiter

```php
// includes/class-rate-limiter.php
class WAA_Rate_Limiter {
    public function check(): bool {
        $user_id = get_current_user_id();
        $key     = "waa_rate_{$user_id}";
        $count   = (int) get_transient($key);

        if ($count >= WAA_RATE_LIMIT) return false;

        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }
}
```

### 1.5 Verify với curl

```bash
SITE=http://localhost:8888

# 1. Không có nonce → phải trả 403
curl -s -o /dev/null -w "%{http_code}" \
  -X POST "$SITE/wp-json/wp-admin-agent/v1/chat" \
  -H "Content-Type: application/json" \
  -d '{"message":"hello"}'
# → 403

# 2. Lấy nonce (login trước)
NONCE=$(curl -s -c /tmp/wp-cookies.txt \
  -X POST "$SITE/wp-login.php" \
  -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F" \
  | grep -o 'nonce":"[^"]*' | cut -d'"' -f3)

# 3. Gọi với nonce hợp lệ → 200
curl -s -b /tmp/wp-cookies.txt \
  -X POST "$SITE/wp-json/wp-admin-agent/v1/chat" \
  -H "X-WP-Nonce: $NONCE" \
  -H "Content-Type: application/json" \
  -d '{"message":"hello","stream":false}'
# → {"id":...}
```

**Exit Criterion:** 403 không auth, 200 với nonce hợp lệ. Không có PHP notices trong debug.log.

---

## Phase 2 — Chat UI (React)

**Duration:** Day 6–9  
**Output:** Chat widget hoàn chỉnh về UX, hardcode mock response. AI chưa cần thiết.

### 2.1 SSE Parser

```js
// src/lib/sse.js
export async function* parseSSE(body) {
    const reader  = body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() ?? '';  // giữ dòng incomplete

        for (const line of lines) {
            if (!line.startsWith('data: ')) continue;
            const json = line.slice(6).trim();
            if (json === '[DONE]') return;
            try {
                yield JSON.parse(json);
            } catch {
                // skip malformed lines
            }
        }
    }
}
```

### 2.2 API Wrapper

```js
// src/lib/api.js
const { restUrl, nonce } = window.waaData ?? {};

export async function chatStream(message, conversationId) {
    return fetch(`${restUrl}chat`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({ message, conversation_id: conversationId, stream: true }),
    });
}

export async function apiFetch(path, options = {}) {
    const res = await fetch(`${restUrl}${path}`, {
        ...options,
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': nonce,
            ...(options.headers ?? {}),
        },
    });
    if (!res.ok) throw new Error(await res.text());
    return res.json();
}
```

### 2.3 useChat Hook

```js
// src/hooks/useChat.js
import { useState, useCallback, useRef } from 'react';
import { chatStream } from '../lib/api';
import { parseSSE } from '../lib/sse';

export function useChat(conversationId) {
    const [messages,       setMessages]       = useState([]);
    const [isLoading,      setIsLoading]      = useState(false);
    const [activeToolName, setActiveToolName] = useState(null);
    const abortRef = useRef(null);

    const sendMessage = useCallback(async (text) => {
        const userMsg = { role: 'user', content: text, id: crypto.randomUUID() };
        const assistMsg = { role: 'assistant', content: '', toolCalls: [], id: crypto.randomUUID() };

        setMessages(prev => [...prev, userMsg, assistMsg]);
        setIsLoading(true);

        abortRef.current?.abort();
        abortRef.current = new AbortController();

        try {
            const response = await chatStream(text, conversationId);

            for await (const event of parseSSE(response.body)) {
                switch (event.type) {
                    case 'text_delta':
                        setMessages(prev => prev.map(m =>
                            m.id === assistMsg.id
                                ? { ...m, content: m.content + event.content }
                                : m
                        ));
                        break;

                    case 'tool_start':
                        setActiveToolName(event.tool_name);
                        setMessages(prev => prev.map(m =>
                            m.id === assistMsg.id
                                ? { ...m, toolCalls: [...m.toolCalls, { name: event.tool_name, status: 'running' }] }
                                : m
                        ));
                        break;

                    case 'tool_end':
                        setActiveToolName(null);
                        setMessages(prev => prev.map(m =>
                            m.id === assistMsg.id
                                ? { ...m, toolCalls: m.toolCalls.map(tc =>
                                    tc.name === event.tool_name
                                        ? { ...tc, status: 'done', result: event.result }
                                        : tc
                                )}
                                : m
                        ));
                        break;

                    case 'error':
                        setMessages(prev => prev.map(m =>
                            m.id === assistMsg.id
                                ? { ...m, content: `Error: ${event.message}`, isError: true }
                                : m
                        ));
                        break;
                }
            }
        } catch (err) {
            if (err.name !== 'AbortError') {
                setMessages(prev => prev.map(m =>
                    m.id === assistMsg.id
                        ? { ...m, content: 'Connection error. Please try again.', isError: true }
                        : m
                ));
            }
        } finally {
            setIsLoading(false);
            setActiveToolName(null);
        }
    }, [conversationId]);

    const clearMessages = useCallback(() => setMessages([]), []);

    return { messages, isLoading, activeToolName, sendMessage, clearMessages };
}
```

### 2.4 Quick Prompts

```js
// src/lib/quickPrompts.js
export const QUICK_PROMPTS = [
    { label: 'Site settings',  text: 'Show me the current site title, tagline, and timezone' },
    { label: 'Active plugins', text: 'List all active plugins' },
    { label: 'Active theme',   text: 'What theme is currently active?' },
    { label: 'Admin users',    text: 'Show all users with Administrator role' },
    { label: 'Reading settings', text: 'What is the homepage set to display?' },
];
```

### 2.5 CSS Scoping

```css
/* src/styles/widget.module.css */
/* Tất cả styles phải nằm trong #waa-root để tránh conflict */
.panel {
    position: fixed;
    bottom: 80px;
    right: 20px;
    width: 400px;
    height: 600px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.2);
    z-index: 99999;
    display: flex;
    flex-direction: column;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

.toggle-btn {
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: #2271b1;  /* WP admin blue */
    color: #fff;
    border: none;
    cursor: pointer;
    z-index: 99999;
    font-size: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(34, 113, 177, 0.4);
    transition: transform 0.2s;
}

.toggle-btn:hover { transform: scale(1.08); }
```

**Exit Criterion:** Widget mở/đóng, messages render đúng, mock echo response hiển thị, quick prompts điền vào input, typing indicator animate.

---

## Phase 3 — AI Agent Backend

**Duration:** Day 10–14  
**Output:** Agent loop hoàn chỉnh với echo_tool, SSE streaming tới React, tool badges hiển thị.

### 3.1 Anthropic HTTP Client

```php
// includes/class-anthropic.php
class WAA_Anthropic {
    private string $base = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private readonly string $api_key,
        private readonly string $model = 'claude-haiku-4-5'
    ) {}

    /**
     * @return array Non-streaming response
     * @throws RuntimeException on HTTP error
     */
    public function messages(array $payload, bool $stream = false): array {
        $payload['model']      = $this->model;
        $payload['stream']     = $stream;
        $payload['max_tokens'] = $payload['max_tokens'] ?? 4096;

        if ($stream) {
            // Streaming handled by caller via handle_chat SSE output
            throw new LogicException('Use stream_to_callback() for streaming.');
        }

        $response = wp_remote_post($this->base, [
            'method'  => 'POST',
            'timeout' => 90,
            'headers' => $this->headers(),
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['error']['message'] ?? "HTTP $code";
            throw new RuntimeException("Anthropic API error: $msg");
        }

        return $body;
    }

    /**
     * Streams SSE events, calling $callback for each parsed event.
     * Uses curl because wp_remote_post doesn't support streaming.
     */
    public function stream_to_callback(array $payload, callable $callback): void {
        $payload['model']      = $this->model;
        $payload['stream']     = true;
        $payload['max_tokens'] = $payload['max_tokens'] ?? 4096;

        $buffer = '';
        $ch     = curl_init($this->base);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => wp_json_encode($payload),
            CURLOPT_HTTPHEADER     => $this->headers_array(),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$buffer, $callback) {
                $buffer .= $data;
                // Parse complete SSE lines
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    if (str_starts_with($line, 'data: ')) {
                        $json = json_decode(substr($line, 6), true);
                        if ($json) $callback($json);
                    }
                }
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException("curl error: " . curl_strerror($errno));
        }
    }

    private function headers(): array {
        return [
            'x-api-key'         => $this->api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ];
    }

    private function headers_array(): array {
        return array_map(
            fn($k, $v) => "$k: $v",
            array_keys($this->headers()),
            array_values($this->headers())
        );
    }
}
```

### 3.2 Agentic Loop

```php
// includes/class-agent.php
class WAA_Agent {
    public function __construct(
        private readonly WAA_Anthropic     $client,
        private readonly WAA_Tool_Registry $registry,
        private readonly WAA_Audit_Log     $log
    ) {}

    /**
     * Runs the agent loop. Yields SSE event arrays.
     * Caller is responsible for outputting them as SSE.
     */
    public function run(string $user_message, array $history = []): Generator {
        $messages  = array_merge($history, [['role' => 'user', 'content' => $user_message]]);
        $iteration = 0;

        while ($iteration++ < WAA_MAX_TOOL_ITERATIONS) {
            $response    = $this->client->messages([
                'system'   => $this->system_prompt(),
                'tools'    => $this->registry->get_schemas(),
                'messages' => $messages,
            ]);

            $stop_reason = $response['stop_reason'];
            $content     = $response['content'];

            // Emit any text blocks
            foreach ($content as $block) {
                if ($block['type'] === 'text') {
                    yield ['type' => 'text_delta', 'content' => $block['text']];
                }
            }

            if ($stop_reason === 'end_turn') break;

            if ($stop_reason === 'tool_use') {
                $tool_results = [];

                foreach ($content as $block) {
                    if ($block['type'] !== 'tool_use') continue;

                    yield ['type' => 'tool_start', 'tool_name' => $block['name'], 'tool_use_id' => $block['id']];

                    $result = $this->registry->execute($block['name'], $block['input'] ?? []);
                    $this->log->write($block['name'], $block['input'] ?? [], $result);

                    yield ['type' => 'tool_end', 'tool_name' => $block['name'], 'result' => $result, 'tool_use_id' => $block['id']];

                    $tool_results[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content'     => wp_json_encode($result),
                    ];
                }

                $messages[] = ['role' => 'assistant', 'content' => $content];
                $messages[] = ['role' => 'user',      'content' => $tool_results];
                continue;
            }

            break;
        }

        if ($iteration >= WAA_MAX_TOOL_ITERATIONS) {
            yield ['type' => 'text_delta', 'content' => "\n\n_(Maximum tool iterations reached.)_"];
        }
    }

    private function system_prompt(): string {
        $site = [
            'url'      => get_site_url(),
            'title'    => get_bloginfo('name'),
            'wp_ver'   => get_bloginfo('version'),
            'timezone' => get_option('timezone_string') ?: 'UTC',
            'user'     => wp_get_current_user()->display_name,
        ];

        return <<<PROMPT
You are a WordPress admin assistant embedded in the wp-admin panel.

Site context:
- URL: {$site['url']}
- Title: {$site['title']}
- WordPress: {$site['wp_ver']}
- Timezone: {$site['timezone']}
- Current user: {$site['user']}

Your job: help administrators configure WordPress through natural language.

Rules:
1. Read current state before modifying (use get_ tools first).
2. For destructive actions (deactivate plugin, switch theme, change user roles), state clearly what you will do and ask for confirmation before using the write tool.
3. Never expose or repeat API keys, passwords, or credentials.
4. If a tool returns an error, explain it and suggest a fix.
5. Be concise. Confirm changes after every successful write.
6. Respond in the same language the user writes in.
PROMPT;
    }
}
```

### 3.3 REST Handler với SSE Output

```php
// Trong class WAA_REST_API
public function handle_chat(WP_REST_Request $request): void {
    $message         = $request->get_param('message');
    $conversation_id = $request->get_param('conversation_id');

    $settings = new WAA_Settings();
    $api_key  = $settings->get_api_key();

    if (empty($api_key)) {
        $this->sse_error('API key not configured. Please go to Settings → Admin Agent.');
        return;
    }

    // Load conversation history
    $history = [];
    if ($conversation_id) {
        $row = $this->get_conversation_row($conversation_id);
        if ($row) {
            $history = json_decode($row->messages, true) ?? [];
        }
    }

    // SSE headers
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');  // Disable nginx output buffering

    // Turn off PHP output buffering layers
    while (ob_get_level() > 0) ob_end_flush();

    $client   = new WAA_Anthropic($api_key, $settings->get_model());
    $registry = $this->build_registry();
    $log      = new WAA_Audit_Log();
    $agent    = new WAA_Agent($client, $registry, $log);

    try {
        foreach ($agent->run($message, $history) as $event) {
            $this->sse_emit($event);
        }
    } catch (Throwable $e) {
        $this->sse_error($e->getMessage());
    }

    $this->sse_emit(['type' => 'done']);
    exit;
}

private function sse_emit(array $event): void {
    echo 'data: ' . wp_json_encode($event) . "\n\n";
    flush();
}

private function sse_error(string $message): void {
    header('Content-Type: text/event-stream');
    $this->sse_emit(['type' => 'error', 'message' => $message]);
    $this->sse_emit(['type' => 'done']);
    exit;
}

private function build_registry(): WAA_Tool_Registry {
    $registry = new WAA_Tool_Registry();
    $registry->register(new WAA_Tool_Get_Settings());
    $registry->register(new WAA_Tool_Update_Settings());
    $registry->register(new WAA_Tool_List_Plugins());
    $registry->register(new WAA_Tool_Activate_Plugin());
    $registry->register(new WAA_Tool_Deactivate_Plugin());
    $registry->register(new WAA_Tool_List_Themes());
    $registry->register(new WAA_Tool_Switch_Theme());
    $registry->register(new WAA_Tool_List_Users());
    $registry->register(new WAA_Tool_Update_User_Role());
    $registry->register(new WAA_Tool_List_Posts());
    $registry->register(new WAA_Tool_Update_Post());
    return $registry;
}
```

**Exit Criterion:** Gõ "echo hello" → agent gọi `echo_tool` → SSE stream về React → badges tool_start/tool_end hiển thị → text response render.

---

## Phase 4 — WordPress Tool Definitions

**Duration:** Day 15–21  
**Output:** Agent có thể đọc/ghi WordPress settings thực sự bằng ngôn ngữ tự nhiên.

### Tool Inventory

| Tool | API | R/W | Cần confirm? |
|---|---|---|---|
| `get_site_settings` | `get_option()` | Read | No |
| `update_site_settings` | `update_option()` | Write | No (low risk) |
| `list_plugins` | `get_plugins()` | Read | No |
| `activate_plugin` | `activate_plugin()` | Write | **Yes** |
| `deactivate_plugin` | `deactivate_plugins()` | Write | **Yes** |
| `list_themes` | `wp_get_themes()` | Read | No |
| `switch_theme` | `switch_theme()` | Write | **Yes** |
| `list_users` | `WP_User_Query` | Read | No |
| `update_user_role` | `wp_update_user()` | Write | **Yes** |
| `list_posts` | `WP_Query` | Read | No |
| `update_post` | `wp_update_post()` | Write | No |

### Tool Base Class

```php
// tools/class-tool-base.php
abstract class WAA_Tool_Base {
    abstract public function get_name(): string;
    abstract public function get_description(): string;
    abstract public function get_input_schema(): array;
    abstract public function execute(array $input): array;

    public function check_permission(): bool {
        return current_user_can('manage_options');
    }

    public function validate_input(array $input): array|WP_Error {
        return $input;
    }

    final public function get_schema(): array {
        return [
            'name'         => $this->get_name(),
            'description'  => $this->get_description(),
            'input_schema' => $this->get_input_schema(),
        ];
    }
}
```

### get_site_settings

```php
// tools/class-tool-get-settings.php
class WAA_Tool_Get_Settings extends WAA_Tool_Base {
    private const KEYS = [
        'blogname', 'blogdescription', 'siteurl', 'admin_email',
        'timezone_string', 'date_format', 'time_format', 'posts_per_page',
        'default_comment_status', 'default_ping_status',
        'show_on_front', 'page_on_front', 'page_for_posts',
    ];

    public function get_name(): string        { return 'get_site_settings'; }
    public function get_description(): string {
        return 'Read WordPress site settings: title, tagline, URL, admin email, timezone, date/time formats, posts per page, comment/ping status, homepage settings.';
    }
    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'keys' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Specific keys to retrieve. Omit for all. Valid keys: ' . implode(', ', self::KEYS),
                ],
            ],
        ];
    }

    public function execute(array $input): array {
        $settings = array_combine(self::KEYS, array_map('get_option', self::KEYS));

        if (!empty($input['keys'])) {
            $requested = array_intersect(array_map('strval', $input['keys']), self::KEYS);
            $settings  = array_intersect_key($settings, array_flip($requested));
        }

        return ['settings' => $settings];
    }
}
```

### update_site_settings

```php
// tools/class-tool-update-settings.php
class WAA_Tool_Update_Settings extends WAA_Tool_Base {
    private const ALLOWED = [
        'blogname', 'blogdescription', 'admin_email',
        'timezone_string', 'date_format', 'time_format',
        'posts_per_page', 'default_comment_status', 'default_ping_status',
        'show_on_front',
    ];

    public function get_name(): string        { return 'update_site_settings'; }
    public function get_description(): string {
        return 'Update WordPress site settings. Allowed keys: ' . implode(', ', self::ALLOWED);
    }
    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'updates' => [
                    'type'                 => 'object',
                    'description'          => 'Key-value pairs of settings to update.',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
            'required'   => ['updates'],
        ];
    }

    public function execute(array $input): array {
        $results = [];
        foreach ($input['updates'] as $key => $value) {
            if (!in_array($key, self::ALLOWED, true)) {
                $results[$key] = ['status' => 'rejected', 'reason' => 'Key not in allowlist'];
                continue;
            }
            $old            = get_option($key);
            $sanitized      = $key === 'admin_email' ? sanitize_email($value) : sanitize_text_field($value);
            $updated        = update_option($key, $sanitized);
            $results[$key]  = [
                'status'    => $updated ? 'updated' : 'unchanged',
                'old_value' => $old,
                'new_value' => $sanitized,
            ];
        }
        return ['results' => $results];
    }
}
```

### list_plugins + activate/deactivate

```php
// tools/class-tool-list-plugins.php
class WAA_Tool_List_Plugins extends WAA_Tool_Base {
    public function get_name(): string        { return 'list_plugins'; }
    public function get_description(): string { return 'List all installed WordPress plugins with their name, version, status (active/inactive), and description.'; }
    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['all', 'active', 'inactive'], 'default' => 'all'],
        ]];
    }

    public function execute(array $input): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins    = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $status_filter  = $input['status'] ?? 'all';

        $list = [];
        foreach ($all_plugins as $file => $data) {
            $is_active = in_array($file, $active_plugins, true);
            if ($status_filter === 'active'   && !$is_active) continue;
            if ($status_filter === 'inactive' &&  $is_active) continue;

            $list[] = [
                'file'        => $file,
                'name'        => $data['Name'],
                'version'     => $data['Version'],
                'description' => wp_trim_words($data['Description'], 20),
                'status'      => $is_active ? 'active' : 'inactive',
                'author'      => $data['Author'],
            ];
        }

        return ['plugins' => $list, 'total' => count($list)];
    }
}

class WAA_Tool_Activate_Plugin extends WAA_Tool_Base {
    public function get_name(): string        { return 'activate_plugin'; }
    public function get_description(): string { return 'Activate an installed WordPress plugin. Requires exact plugin file path (e.g., "jetpack/jetpack.php").'; }
    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => [
            'plugin_file' => ['type' => 'string', 'description' => 'Plugin file path relative to plugins directory'],
        ], 'required' => ['plugin_file']];
    }

    public function execute(array $input): array {
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $result = activate_plugin(sanitize_text_field($input['plugin_file']));
        if (is_wp_error($result)) {
            return ['status' => 'error', 'message' => $result->get_error_message()];
        }
        return ['status' => 'activated', 'plugin' => $input['plugin_file']];
    }
}

class WAA_Tool_Deactivate_Plugin extends WAA_Tool_Base {
    public function get_name(): string        { return 'deactivate_plugin'; }
    public function get_description(): string { return 'Deactivate an active WordPress plugin. This is reversible — the plugin remains installed.'; }
    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => [
            'plugin_file' => ['type' => 'string'],
        ], 'required' => ['plugin_file']];
    }

    public function execute(array $input): array {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins([sanitize_text_field($input['plugin_file'])]);
        return ['status' => 'deactivated', 'plugin' => $input['plugin_file']];
    }
}
```

### list_users

```php
// tools/class-tool-list-users.php
class WAA_Tool_List_Users extends WAA_Tool_Base {
    public function get_name(): string        { return 'list_users'; }
    public function get_description(): string { return 'List WordPress users, optionally filtered by role.'; }
    public function get_input_schema(): array {
        return ['type' => 'object', 'properties' => [
            'role'   => ['type' => 'string', 'description' => 'Filter by role: administrator, editor, author, contributor, subscriber'],
            'number' => ['type' => 'integer', 'default' => 20, 'description' => 'Max users to return (1-100)'],
        ]];
    }

    public function execute(array $input): array {
        $args = [
            'number' => min((int) ($input['number'] ?? 20), 100),
            'fields' => ['ID', 'user_login', 'user_email', 'display_name'],
        ];
        if (!empty($input['role'])) {
            $args['role'] = sanitize_text_field($input['role']);
        }

        $users = get_users($args);
        return [
            'users' => array_map(function ($u) {
                $roles = get_userdata($u->ID)->roles;
                return [
                    'id'           => $u->ID,
                    'login'        => $u->user_login,
                    'email'        => $u->user_email,
                    'display_name' => $u->display_name,
                    'roles'        => $roles,
                ];
            }, $users),
            'total' => count($users),
        ];
    }
}
```

**Exit Criterion:** "What is the site title?" → agent gọi `get_site_settings` → trả về đúng `blogname`. "Change site tagline to 'AI-powered'" → `blogdescription` cập nhật trong DB.

---

## Phase 5 — Security & Permissions

**Duration:** Day 22–25  
**Output:** Plugin an toàn để chạy trên production site.

### 5.1 Per-Tool Permission Checks

```php
// Mỗi tool ghi đè check_permission() nếu cần capability riêng
class WAA_Tool_Switch_Theme extends WAA_Tool_Base {
    public function check_permission(): bool { return current_user_can('switch_themes'); }
}

class WAA_Tool_Update_User_Role extends WAA_Tool_Base {
    public function check_permission(): bool { return current_user_can('promote_users'); }
    public function execute(array $input): array {
        $user_id = (int) $input['user_id'];
        $role    = sanitize_text_field($input['role']);

        // Không cho phép hạ quyền admin khác
        $target = get_userdata($user_id);
        if (!$target) return ['error' => 'User not found'];
        if (in_array('administrator', $target->roles, true) && get_current_user_id() !== $user_id) {
            return ['error' => 'Cannot change role of another administrator.'];
        }

        wp_update_user(['ID' => $user_id, 'role' => $role]);
        return ['status' => 'updated', 'user_id' => $user_id, 'new_role' => $role];
    }
}
```

### 5.2 Input Sanitization trong Tool Registry

```php
// includes/class-tool-registry.php
class WAA_Tool_Registry {
    private array $tools          = [];
    private const BLOCKED_TOOLS   = ['delete_site', 'wp_delete_user_self', 'update_core'];

    public function register(WAA_Tool_Base $tool): void {
        $name = $tool->get_name();
        if (!in_array($name, self::BLOCKED_TOOLS, true)) {
            $this->tools[$name] = $tool;
        }
    }

    public function get_schemas(): array {
        return array_values(array_map(fn($t) => $t->get_schema(), $this->tools));
    }

    public function execute(string $name, array $input): array {
        if (!isset($this->tools[$name])) {
            return ['error' => "Unknown tool: $name"];
        }

        $tool = $this->tools[$name];

        if (!$tool->check_permission()) {
            return ['error' => 'Insufficient permissions for this operation.'];
        }

        $validated = $tool->validate_input($input);
        if (is_wp_error($validated)) {
            return ['error' => $validated->get_error_message()];
        }

        return $tool->execute($validated);
    }
}
```

### 5.3 Audit Log

```php
// includes/class-audit-log.php
class WAA_Audit_Log {
    public function write(string $tool, array $params, array $result): void {
        global $wpdb;
        $wpdb->insert(WAA_TABLE_LOGS, [
            'user_id'    => get_current_user_id(),
            'tool_name'  => $tool,
            'params'     => wp_json_encode($params),
            'result'     => wp_json_encode($result),
            'status'     => isset($result['error']) ? 'error' : 'success',
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%s', '%s']);
    }

    public static function get_recent(int $limit = 10): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . WAA_TABLE_LOGS . " ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }
}
```

### 5.4 Security Checklist

- [x] Nonce verification trước mọi REST request
- [x] `current_user_can()` check tại mỗi tool
- [x] Input sanitization (`sanitize_text_field`, `sanitize_email`, `intval`)
- [x] API key encrypted (AES-256-CBC) trong `wp_options`, `autoload=false`
- [x] API key không bao giờ trả về qua REST response
- [x] Rate limiting 30 req/phút/user
- [x] Max 10 tool iterations để tránh infinite loop
- [x] Blocklist tools nguy hiểm (delete_site, update_core)
- [x] Audit log mọi tool execution
- [x] Output escaping khi render trong admin (`esc_html`, `esc_attr`)
- [x] No SQL queries không dùng `$wpdb->prepare()`

---

## Phase 6 — Testing (Unit + Integration + E2E)

**Duration:** Day 26–28  
**Output:** Test suite đầy đủ, coverage ≥70%, CI xanh.

### 6.1 PHPUnit Setup

**phpunit.xml:**
```xml
<?xml version="1.0"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.0/phpunit.xsd"
    bootstrap="tests/php/bootstrap.php"
    colors="true"
>
    <testsuites>
        <testsuite name="unit">
            <directory>tests/php/unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/php/integration</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">includes</directory>
            <directory suffix=".php">tools</directory>
        </include>
        <report>
            <clover outputFile="coverage.xml"/>
            <text outputFile="php://stdout"/>
        </report>
    </coverage>
</phpunit>
```

**tests/php/bootstrap.php:**
```php
<?php
// Load WordPress test environment
$_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';
require_once $_tests_dir . '/includes/functions.php';

function _manually_load_plugin(): void {
    require dirname(__DIR__, 2) . '/wp-admin-agent.php';
}
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

require $_tests_dir . '/includes/bootstrap.php';
```

### 6.2 Unit Tests

```php
// tests/php/unit/tools/GetSettingsToolTest.php
class GetSettingsToolTest extends WP_UnitTestCase {
    private WAA_Tool_Get_Settings $tool;

    public function setUp(): void {
        parent::setUp();
        $this->tool = new WAA_Tool_Get_Settings();
        update_option('blogname', 'Test Site');
        update_option('blogdescription', 'Test Tagline');
    }

    public function test_returns_all_settings_when_no_keys_given(): void {
        $result = $this->tool->execute([]);
        $this->assertArrayHasKey('settings', $result);
        $this->assertArrayHasKey('blogname', $result['settings']);
        $this->assertEquals('Test Site', $result['settings']['blogname']);
    }

    public function test_filters_by_requested_keys(): void {
        $result = $this->tool->execute(['keys' => ['blogname']]);
        $this->assertArrayHasKey('blogname', $result['settings']);
        $this->assertArrayNotHasKey('blogdescription', $result['settings']);
    }

    public function test_schema_is_valid_anthropic_format(): void {
        $schema = $this->tool->get_schema();
        $this->assertArrayHasKey('name', $schema);
        $this->assertArrayHasKey('description', $schema);
        $this->assertArrayHasKey('input_schema', $schema);
        $this->assertEquals('object', $schema['input_schema']['type']);
    }
}
```

```php
// tests/php/unit/tools/UpdateSettingsToolTest.php
class UpdateSettingsToolTest extends WP_UnitTestCase {
    private WAA_Tool_Update_Settings $tool;

    public function setUp(): void {
        parent::setUp();
        $this->tool = new WAA_Tool_Update_Settings();
        update_option('blogname', 'Original Title');
    }

    public function test_updates_allowed_key(): void {
        $result = $this->tool->execute(['updates' => ['blogname' => 'New Title']]);
        $this->assertEquals('updated', $result['results']['blogname']['status']);
        $this->assertEquals('New Title', get_option('blogname'));
    }

    public function test_rejects_non_allowlisted_key(): void {
        $result = $this->tool->execute(['updates' => ['siteurl' => 'https://evil.com']]);
        $this->assertEquals('rejected', $result['results']['siteurl']['status']);
        // siteurl không thay đổi
        $this->assertNotEquals('https://evil.com', get_option('siteurl'));
    }

    public function test_sanitizes_input(): void {
        $result = $this->tool->execute(['updates' => ['blogname' => '<script>alert(1)</script>']]);
        $saved  = get_option('blogname');
        $this->assertStringNotContainsString('<script>', $saved);
    }

    public function test_returns_unchanged_when_value_same(): void {
        $result = $this->tool->execute(['updates' => ['blogname' => 'Original Title']]);
        $this->assertEquals('unchanged', $result['results']['blogname']['status']);
    }
}
```

```php
// tests/php/unit/EncryptorTest.php
class EncryptorTest extends WP_UnitTestCase {
    private WAA_Encryptor $enc;

    public function setUp(): void {
        parent::setUp();
        $this->enc = new WAA_Encryptor();
    }

    public function test_roundtrip(): void {
        $original  = 'example-anthropic-api-key';
        $encrypted = $this->enc->encrypt($original);
        $this->assertNotEquals($original, $encrypted);
        $this->assertEquals($original, $this->enc->decrypt($encrypted));
    }

    public function test_decrypt_invalid_returns_empty(): void {
        $this->assertEquals('', $this->enc->decrypt('not-valid-base64!!!'));
    }

    public function test_each_encryption_is_unique(): void {
        $key = 'same-key';
        $this->assertNotEquals($this->enc->encrypt($key), $this->enc->encrypt($key));
    }
}
```

```php
// tests/php/unit/ToolRegistryTest.php
class ToolRegistryTest extends WP_UnitTestCase {
    public function test_execute_unknown_tool_returns_error(): void {
        $registry = new WAA_Tool_Registry();
        $result   = $registry->execute('nonexistent_tool', []);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_blocked_tools_are_not_registered(): void {
        $registry = new WAA_Tool_Registry();
        // Attempt to register a tool named like a blocked tool
        $mock = $this->createMock(WAA_Tool_Base::class);
        $mock->method('get_name')->willReturn('delete_site');
        $registry->register($mock);
        // Should silently not register it
        $result = $registry->execute('delete_site', []);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_permission_denied_returns_error(): void {
        // Tạo user không có manage_options
        $user_id = $this->factory->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $registry = new WAA_Tool_Registry();
        $registry->register(new WAA_Tool_Get_Settings());
        $result = $registry->execute('get_site_settings', []);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('permissions', $result['error']);
    }
}
```

### 6.3 Integration Tests

```php
// tests/php/integration/RestApiTest.php
class RestApiTest extends WP_Test_REST_TestCase {
    private string $nonce;
    private int    $admin_id;

    public function setUp(): void {
        parent::setUp();
        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        $this->nonce = wp_create_nonce('waa_rest_nonce');
    }

    public function test_chat_endpoint_requires_nonce(): void {
        $request  = new WP_REST_Request('POST', '/wp-admin-agent/v1/chat');
        $request->set_body_params(['message' => 'hello']);
        // Không set nonce
        $response = rest_do_request($request);
        $this->assertEquals(403, $response->get_status());
    }

    public function test_chat_endpoint_requires_manage_options(): void {
        $editor_id = $this->factory->user->create(['role' => 'editor']);
        wp_set_current_user($editor_id);

        $request = new WP_REST_Request('POST', '/wp-admin-agent/v1/chat');
        $request->set_header('X-WP-Nonce', wp_create_nonce('waa_rest_nonce'));
        $request->set_body_params(['message' => 'hello']);
        $response = rest_do_request($request);
        $this->assertEquals(403, $response->get_status());
    }

    public function test_settings_endpoint_saves_model(): void {
        $request = new WP_REST_Request('POST', '/wp-admin-agent/v1/settings');
        $request->set_header('X-WP-Nonce', $this->nonce);
        $request->set_body_params(['model' => 'claude-sonnet-4-6']);
        $response = rest_do_request($request);
        $this->assertEquals(200, $response->get_status());
        $this->assertEquals('claude-sonnet-4-6', get_option('waa_model'));
    }
}
```

### 6.4 JavaScript Tests

```js
// tests/js/setup.js
import '@testing-library/jest-dom';

// Mock waaData global (WordPress localized script)
global.waaData = {
    nonce:   'test-nonce-123',
    restUrl: 'http://localhost/wp-json/wp-admin-agent/v1/',
    currentUser: { id: 1, name: 'Admin' },
    siteUrl: 'http://localhost',
    version: '0.1.0',
};
```

```jsx
// tests/js/components/ChatWidget.test.jsx
import { render, screen, fireEvent } from '@testing-library/react';
import { vi }                         from 'vitest';
import ChatWidget                      from '../../../src/components/ChatWidget';

describe('ChatWidget', () => {
    it('renders toggle button', () => {
        render(<ChatWidget isOpen={false} onToggle={vi.fn()} />);
        expect(screen.getByRole('button', { name: /open/i })).toBeInTheDocument();
    });

    it('shows panel when isOpen=true', () => {
        render(<ChatWidget isOpen={true} onToggle={vi.fn()} />);
        expect(screen.getByRole('textbox')).toBeInTheDocument();
    });

    it('calls onToggle when button clicked', () => {
        const onToggle = vi.fn();
        render(<ChatWidget isOpen={false} onToggle={onToggle} />);
        fireEvent.click(screen.getByRole('button', { name: /open/i }));
        expect(onToggle).toHaveBeenCalledOnce();
    });
});
```

```js
// tests/js/hooks/useChat.test.js
import { renderHook, act } from '@testing-library/react';
import { vi }              from 'vitest';
import { useChat }         from '../../../src/hooks/useChat';

vi.mock('../../../src/lib/api', () => ({
    chatStream: vi.fn(),
}));
vi.mock('../../../src/lib/sse', () => ({
    parseSSE: async function* () {
        yield { type: 'text_delta', content: 'Hello from agent' };
        yield { type: 'done' };
    },
}));

import { chatStream } from '../../../src/lib/api';

describe('useChat', () => {
    beforeEach(() => {
        chatStream.mockResolvedValue({ body: null });
    });

    it('adds user message immediately on sendMessage', async () => {
        const { result } = renderHook(() => useChat(null));
        await act(async () => {
            await result.current.sendMessage('What is the site title?');
        });
        expect(result.current.messages[0]).toMatchObject({
            role: 'user',
            content: 'What is the site title?',
        });
    });

    it('appends assistant response from SSE stream', async () => {
        const { result } = renderHook(() => useChat(null));
        await act(async () => {
            await result.current.sendMessage('Hello');
        });
        const assistantMsg = result.current.messages.find(m => m.role === 'assistant');
        expect(assistantMsg?.content).toBe('Hello from agent');
    });

    it('sets isLoading=false after completion', async () => {
        const { result } = renderHook(() => useChat(null));
        await act(async () => {
            await result.current.sendMessage('Hello');
        });
        expect(result.current.isLoading).toBe(false);
    });
});
```

### 6.5 E2E Tests với Playwright

**playwright.config.ts:**
```ts
import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 30_000,
    use: {
        baseURL:         'http://localhost:8888',
        storageState:    'tests/e2e/auth.json',  // Lưu WP login session
        screenshot:      'only-on-failure',
        trace:           'on-first-retry',
    },
    projects: [
        { name: 'chromium', use: { browserName: 'chromium' } },
    ],
});
```

```ts
// tests/e2e/smoke.spec.ts
import { test, expect } from '@playwright/test';

test.beforeAll(async ({ browser }) => {
    // Login một lần, lưu session
    const page = await browser.newPage();
    await page.goto('/wp-login.php');
    await page.fill('#user_login', '<local-admin-user>');
    await page.fill('#user_pass', '<local-admin-password>');
    await page.click('#wp-submit');
    await page.waitForURL(/wp-admin/);
    await page.context().storageState({ path: 'tests/e2e/auth.json' });
    await page.close();
});

test('chat widget appears on wp-admin', async ({ page }) => {
    await page.goto('/wp-admin/');
    const toggleBtn = page.locator('#waa-root button');
    await expect(toggleBtn).toBeVisible();
});

test('can open and close widget', async ({ page }) => {
    await page.goto('/wp-admin/');
    await page.locator('#waa-root button').click();
    await expect(page.locator('textarea')).toBeVisible();
    await page.locator('#waa-root button').click();
    await expect(page.locator('textarea')).not.toBeVisible();
});

test('quick prompt fills input', async ({ page }) => {
    await page.goto('/wp-admin/');
    await page.locator('#waa-root button').click();
    await page.locator('.quick-prompt-chip').first().click();
    const textarea = page.locator('textarea');
    await expect(textarea).not.toBeEmpty();
});

test('site settings query returns data', async ({ page }) => {
    await page.goto('/wp-admin/');
    await page.locator('#waa-root button').click();
    await page.fill('textarea', 'What is the site title?');
    await page.keyboard.press('Enter');
    // Chờ response xuất hiện
    await expect(page.locator('.message.assistant')).toBeVisible({ timeout: 15_000 });
    const text = await page.locator('.message.assistant').last().textContent();
    expect(text).toBeTruthy();
    expect(text!.length).toBeGreaterThan(10);
});

test('unauthorized editor cannot see widget', async ({ browser }) => {
    // Login với editor
    const page = await browser.newPage();
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'editor');
    await page.fill('#user_pass', '<local-editor-password>');
    await page.click('#wp-submit');
    await page.waitForURL(/wp-admin/);
    // Widget phải không hiển thị
    await expect(page.locator('#waa-root')).not.toBeVisible();
});
```

```ts
// tests/e2e/security.spec.ts
import { test, expect } from '@playwright/test';

test('REST endpoint rejects missing nonce', async ({ request }) => {
    const res = await request.post('/wp-json/wp-admin-agent/v1/chat', {
        data: { message: 'hello' },
        headers: { 'Content-Type': 'application/json' },
    });
    expect(res.status()).toBe(403);
});

test('REST endpoint rejects invalid nonce', async ({ request }) => {
    const res = await request.post('/wp-json/wp-admin-agent/v1/chat', {
        data: { message: 'hello' },
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': 'invalid-nonce-xyz',
        },
    });
    expect(res.status()).toBe(403);
});

test('settings endpoint does not leak API key', async ({ request, storageState }) => {
    // Gọi settings GET
    const res  = await request.get('/wp-json/wp-admin-agent/v1/settings', {
        headers: { 'X-WP-Nonce': 'valid-admin-nonce' },
    });
    const body = await res.json();
    // API key không được xuất hiện trong response
    expect(JSON.stringify(body)).not.toContain('sk-ant');
});
```

### 6.6 Chạy Tests

```bash
# PHP unit tests
composer require --dev phpunit/phpunit
npx @wordpress/env run tests-cli vendor/bin/phpunit --testsuite unit

# PHP integration tests (cần WP test DB)
npx @wordpress/env run tests-cli vendor/bin/phpunit --testsuite integration

# JS unit + component tests
npm run test:js

# E2E (cần WP đang chạy)
npx @wordpress/env start
npm run test:e2e

# Coverage report
npx @wordpress/env run tests-cli vendor/bin/phpunit --coverage-text
npm run test:js -- --coverage
```

**Exit Criterion:** PHPUnit ≥70% coverage, Vitest pass hết, 5 E2E smoke flows xanh.

---

## Phase 7 — CI/CD Pipeline

**Duration:** Day 28–29  
**Output:** GitHub Actions tự động lint + test + build + release.

### 7.1 CI Workflow (on PR)

**.github/workflows/ci.yml:**
```yaml
name: CI

on:
  pull_request:
    branches: [main, develop]
  push:
    branches: [main]

jobs:
  php-tests:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wordpress_test
        ports: ['3306:3306']
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP 8.2
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, curl, openssl
          coverage: xdebug

      - name: Install Composer deps
        run: composer install --no-interaction

      - name: Setup WordPress test suite
        run: |
          bash tests/bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
        env:
          WP_TESTS_DIR: /tmp/wordpress-tests-lib

      - name: Run PHPUnit
        run: vendor/bin/phpunit --coverage-clover coverage.xml
        env:
          WP_TESTS_DIR: /tmp/wordpress-tests-lib

      - name: Upload coverage
        uses: codecov/codecov-action@v4
        with:
          files: coverage.xml

  js-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Install deps
        run: npm ci

      - name: Lint
        run: npm run lint

      - name: Test + coverage
        run: npm run test:js -- --coverage

      - name: Build
        run: npm run build

      - name: Verify build output
        run: |
          test -f assets/js/admin-agent.js  || exit 1
          test -f assets/css/admin-agent.css || exit 1

  e2e:
    runs-on: ubuntu-latest
    needs: [php-tests, js-tests]
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Install deps
        run: npm ci

      - name: Build
        run: npm run build

      - name: Start wp-env
        run: npx @wordpress/env start
        env:
          WP_ENV_HOME: /tmp/wp-env

      - name: Install Playwright
        run: npx playwright install --with-deps chromium

      - name: Seed demo data
        run: |
          npx @wordpress/env run cli wp option update waa_api_key_enc "$(openssl enc -base64 -e <<< "${{ secrets.ANTHROPIC_API_KEY }}")"
          bash tests/e2e/seed.sh

      - name: Run E2E tests
        run: npm run test:e2e
        env:
          ANTHROPIC_API_KEY: ${{ secrets.ANTHROPIC_API_KEY }}

      - name: Upload artifacts on failure
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report
          path: playwright-report/
```

### 7.2 Release Workflow (on tag)

**.github/workflows/release.yml:**
```yaml
name: Release

on:
  push:
    tags: ['v*.*.*']

jobs:
  build-and-release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Install & build
        run: |
          npm ci
          npm run build

      - name: Create release zip
        run: |
          VERSION=${GITHUB_REF_NAME#v}
          mkdir -p dist/wp-admin-agent

          # Sao chép production files
          rsync -a \
            --exclude='src' \
            --exclude='tests' \
            --exclude='node_modules' \
            --exclude='.github' \
            --exclude='.wp-env.json' \
            --exclude='vite.config.js' \
            --exclude='phpunit.xml' \
            --exclude='package*.json' \
            --exclude='composer*.json' \
            --exclude='playwright.config.ts' \
            . dist/wp-admin-agent/

          cd dist && zip -r wp-admin-agent-${VERSION}.zip wp-admin-agent/

      - name: Create GitHub Release
        uses: softprops/action-gh-release@v2
        with:
          files: dist/wp-admin-agent-*.zip
          generate_release_notes: true
```

### 7.3 Branch Strategy

```
main          ← Production releases (tagged)
  └── develop ← Integration branch
       ├── feature/phase-1-rest-api
       ├── feature/phase-2-react-ui
       ├── feature/phase-3-agent-loop
       ├── feature/phase-4-tools
       └── feature/phase-5-security
```

- PRs vào `develop` phải pass CI
- Merge develop → main khi phase hoàn thành
- Tag `v0.x.0` trigger release workflow

---

## Phase 8 — Demo Polish & Packaging

**Duration:** Day 29–30  
**Output:** Plugin demo-ready, zip có thể upload ngay vào WP.

### 8.1 Demo Seed Script

```bash
#!/bin/bash
# tests/e2e/seed.sh — Thiết lập demo site chuẩn
WP="npx @wordpress/env run cli wp"

$WP option update blogname "Demo Company"
$WP option update blogdescription "Built with WordPress + AI"
$WP plugin install woocommerce contact-form-7 akismet --activate
$WP theme install twentytwentyfour --activate
$WP user create editor editor@example.test --role=editor --user_pass='<local-editor-password>'
$WP post create --post_title="Sample Post" --post_status=publish --post_content="Hello World"
$WP post create --post_title="About Us"   --post_status=publish --post_type=page

echo "✅ Demo site seeded"
```

### 8.2 Onboarding Wizard (First Run)

```php
// Hiển thị wizard lần đầu activate (nếu chưa có API key)
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (get_option('waa_api_key_enc')) return;
    if (get_option('waa_onboarding_dismissed')) return;

    $settings_url = admin_url('options-general.php?page=wp-admin-agent');
    printf(
        '<div class="notice notice-info"><p><strong>WP Admin Agent</strong> — %s <a href="%s">%s</a></p></div>',
        esc_html__('Enter your Anthropic API key to get started.', 'wp-admin-agent'),
        esc_url($settings_url),
        esc_html__('Configure now →', 'wp-admin-agent')
    );
});
```

### 8.3 Dashboard Widget

```php
add_action('wp_dashboard_setup', function () {
    if (!current_user_can('manage_options')) return;
    wp_add_dashboard_widget(
        'waa_recent_actions',
        'Recent Agent Actions',
        function () {
            $logs = WAA_Audit_Log::get_recent(5);
            if (empty($logs)) {
                echo '<p>No agent actions yet. <a href="#">Open chat →</a></p>';
                return;
            }
            echo '<ul>';
            foreach ($logs as $log) {
                printf(
                    '<li><code>%s</code> — <span class="waa-status-%s">%s</span> <small>%s ago</small></li>',
                    esc_html($log->tool_name),
                    esc_attr($log->status),
                    esc_html($log->status),
                    esc_html(human_time_diff(strtotime($log->created_at)))
                );
            }
            echo '</ul>';
        }
    );
});
```

### 8.4 E2E Demo Flows (Acceptance Criteria)

| # | User gõ | Tool được gọi | Kết quả kiểm tra |
|---|---|---|---|
| 1 | "What's the site title?" | `get_site_settings` | Response chứa "Demo Company" |
| 2 | "Change site tagline to 'AI-powered'" | `update_site_settings` | `blogdescription` = 'AI-powered' trong DB |
| 3 | "List all active plugins" | `list_plugins` | Response liệt kê woocommerce, contact-form-7 |
| 4 | "What theme is active?" | `list_themes` | Response chứa "Twenty Twenty-Four" |
| 5 | "Show all admin users" | `list_users` | Response chứa user "admin" |

### 8.5 Plugin Packaging

```
wp-admin-agent.zip
├── wp-admin-agent.php    # Plugin header
├── readme.txt            # WP.org format
├── CHANGELOG.md
├── includes/             # PHP classes
├── tools/                # Tool definitions
├── admin/                # Settings page PHP
├── assets/               # Compiled JS + CSS (Vite output)
│   ├── js/admin-agent.js
│   └── css/admin-agent.css
└── languages/
    └── wp-admin-agent.pot
```

**readme.txt (WP.org format):**
```
=== WP Admin Agent ===
Contributors: yourname
Tags: ai, assistant, admin, automation, chatgpt
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPL-2.0-or-later

Natural language AI assistant for WordPress admin — control your site by chatting.

== Description ==
WP Admin Agent embeds a floating AI chat widget in wp-admin powered by Claude (Anthropic).
Administrators can read and update site settings, manage plugins, themes, and users through
natural conversation — no clicking through settings screens required.

== Installation ==
1. Upload the plugin files to /wp-content/plugins/wp-admin-agent/
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings → Admin Agent and enter your Anthropic API key
4. Click the chat bubble icon in the bottom-right corner of any wp-admin page

== Frequently Asked Questions ==
= Which Anthropic models are supported? =
claude-haiku-4-5 (fast), claude-sonnet-4-6 (balanced), claude-opus-4-7 (powerful)

= Is my API key secure? =
Yes — encrypted with AES-256 using WordPress's AUTH_KEY, never sent to the browser.

== Screenshots ==
1. Chat widget open with site settings query
2. Plugin settings page
3. Dashboard widget showing recent actions

== Changelog ==
= 0.1.0 =
* Initial release
```

---

## Phase 9 — Deployment & Vận hành

**Duration:** Day 30+  
**Output:** Plugin chạy trên staging → production, monitoring cơ bản.

### 9.1 Deployment Checklist

#### Staging Deploy
```bash
# 1. Pull latest release zip từ GitHub
curl -L https://github.com/yourname/wp-admin-agent/releases/latest/download/wp-admin-agent-0.1.0.zip \
  -o wp-admin-agent.zip

# 2. Upload via WP-CLI
wp plugin install wp-admin-agent.zip --activate

# 3. Verify DB tables
wp db query "SHOW TABLES LIKE 'wp_waa_%'"

# 4. Set API key
wp eval "
  \$s = new WAA_Settings();
  \$s->set_api_key('sk-ant-...');
  echo 'API key saved.';
"

# 5. Test connection
curl -X POST https://staging.yoursite.com/wp-json/wp-admin-agent/v1/test-connection \
  -H "X-WP-Nonce: YOUR_NONCE"
```

#### Production Checklist
- [ ] PHP 8.2+, WordPress 6.9+ xác nhận
- [ ] HTTPS bật (SSE yêu cầu secure context)
- [ ] Nginx không block SSE (thêm `proxy_buffering off` nếu cần)
- [ ] PHP `max_execution_time` ≥ 90 giây
- [ ] Anthropic API key có sufficient credits
- [ ] Rate limit phù hợp với team size (mặc định 30/phút)
- [ ] Backup DB trước khi activate

### 9.2 Server Configuration

**Nginx config cho SSE:**
```nginx
location /wp-json/wp-admin-agent/ {
    proxy_pass         http://wordpress_backend;
    proxy_buffering    off;
    proxy_cache        off;
    proxy_read_timeout 90s;
    add_header         X-Accel-Buffering no;
    gzip               off;  # SSE không compatible với gzip
}
```

**Apache .htaccess (nếu dùng Apache):**
```apache
# SSE cần deflate tắt cho endpoint này
<Location "/wp-json/wp-admin-agent/v1/chat">
    SetEnv no-gzip 1
    SetEnv dont-vary 1
</Location>
```

**PHP ini (thêm vào wp-config.php hoặc .user.ini):**
```php
@ini_set('max_execution_time', '90');
@ini_set('output_buffering',   'off');
```

### 9.3 Monitoring & Observability

#### Audit Log Queries hữu ích
```sql
-- Top tools được dùng nhiều nhất
SELECT tool_name, COUNT(*) as calls, 
       SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) as errors
FROM wp_waa_logs
WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY tool_name
ORDER BY calls DESC;

-- Error rate theo ngày
SELECT DATE(created_at) as day, 
       COUNT(*) as total,
       SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) as errors
FROM wp_waa_logs
GROUP BY DATE(created_at)
ORDER BY day DESC
LIMIT 30;

-- User activity
SELECT u.display_name, COUNT(*) as actions
FROM wp_waa_logs l
JOIN wp_users u ON l.user_id = u.ID
WHERE l.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY l.user_id
ORDER BY actions DESC;
```

#### WP-CLI Commands (tự thêm)
```php
// Thêm vào plugin nếu muốn
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('waa logs', function ($args, $assoc_args) {
        $limit = $assoc_args['limit'] ?? 10;
        $logs  = WAA_Audit_Log::get_recent((int) $limit);
        WP_CLI\Utils\format_items('table', $logs, ['id', 'tool_name', 'status', 'created_at']);
    });

    WP_CLI::add_command('waa clear-logs', function () {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE " . WAA_TABLE_LOGS);
        WP_CLI::success('Logs cleared.');
    });
}
```

### 9.4 Update Strategy

```bash
# Upgrade plugin (giữ settings và data)
wp plugin update wp-admin-agent

# Nếu DB schema thay đổi → activation hook chạy lại dbDelta()
wp plugin deactivate wp-admin-agent && wp plugin activate wp-admin-agent

# Rollback nếu có vấn đề
wp plugin install wp-admin-agent-0.1.0.zip --force
```

### 9.5 Fallback khi SSE không hoạt động

Một số hosting shared block SSE. Plugin tự detect và fallback sang polling:

```js
// src/hooks/useChat.js — polling fallback
async function pollFallback(jobId, assistantMsgId, setMessages, setIsLoading) {
    const maxAttempts = 60;
    let attempts = 0;

    while (attempts++ < maxAttempts) {
        await new Promise(r => setTimeout(r, 1000));
        const result = await apiFetch(`chat-status/${jobId}`);

        if (result.status === 'done') {
            setMessages(prev => prev.map(m =>
                m.id === assistantMsgId ? { ...m, content: result.content } : m
            ));
            break;
        }
    }
    setIsLoading(false);
}
```

---

## 15. Risk Register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| PHP SSE blocked by nginx / shared host | Medium | High | Polling fallback, document server requirements |
| LLM hallucinates wrong option keys | Medium | Medium | Tool validates inputs against allowlist trước khi write DB |
| React bundle conflict với wp-admin JS | Low | Medium | Scope CSS `#waa-root`, external React = `@wordpress/element` |
| `manage_options` quá broad | Low | Medium | Per-tool capability check, admin restrict theo user ID |
| API key exposed | Low | Critical | AES-256 encrypted, không trả về qua REST, nonce-gated |
| Agent infinite tool loop | Low | Medium | Max 10 iterations hard cap |
| Plugin conflict với AI Engine, Jetpack AI | Medium | Low | Namespace `waa_` cho tất cả options/hooks/tables |
| WP 6.9 Abilities API không có trên older installs | Medium | Low | Optional enhancement, plugin degrads gracefully |
| Anthropic API down | Low | High | Error handling rõ ràng, suggest retry, không crash WP |
| DB migration fail khi upgrade | Low | High | `dbDelta()` idempotent, version check trước migrate |

---

## 16. Reference Links

| Resource | URL |
|---|---|
| WordPress REST API — Settings | https://developer.wordpress.org/rest-api/reference/settings/ |
| WordPress Abilities API | https://developer.wordpress.org/apis/abilities-api/ |
| Abilities API in WP 6.9 | https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/ |
| WordPress/mcp-adapter | https://github.com/WordPress/mcp-adapter |
| MCP Adapter Blog Post | https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/ |
| AI Engine Plugin (reference) | https://github.com/jordymeow/ai-engine |
| RaheesAhmed wordpress-mcp-server | https://github.com/RaheesAhmed/wordpress-mcp-server |
| Anthropic Tool Use Docs | https://docs.anthropic.com/en/docs/tool-use |
| Anthropic Streaming Docs | https://docs.anthropic.com/en/docs/streaming |
| Model Context Protocol Spec | https://modelcontextprotocol.io |
| WooCommerce MCP Docs | https://developer.woocommerce.com/docs/features/mcp/ |
| wp-env (Docker WP dev) | https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/ |
| PHPUnit 10 Docs | https://docs.phpunit.de/en/10.0/ |
| Playwright Docs | https://playwright.dev/docs/intro |
| Vitest Docs | https://vitest.dev/guide/ |

---

*Document version: 2.0 · Cập nhật: 2026-05-14 · Tác giả: William / GoRight AI*  
*Plugin: WP Admin Agent · Model: claude-haiku-4-5 · License: GPL-2.0-or-later*
