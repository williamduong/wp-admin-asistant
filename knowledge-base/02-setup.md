# Cài đặt & Cấu hình — WP Admin AI Assistant

## 1. Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.2+ (cần `str_starts_with`, `match`, constructor property promotion, `readonly`) |
| WordPress | 6.5+ |
| MySQL | 5.7+ hoặc MariaDB 10.3+ |
| Node.js | 18+ (chỉ cần cho development) |
| npm | 9+ (chỉ cần cho development) |
| Docker | Required cho `wp-env` dev environment |
| OpenSSL | Required cho AES-256 encryption (mặc định có trong PHP) |

**AI Provider** (cần ít nhất 1):
- Anthropic API key — từ [console.anthropic.com](https://console.anthropic.com)
- Google Gemini API key — từ [aistudio.google.com](https://aistudio.google.com/app/apikey) (có free tier)
- Ollama server — local hoặc remote (không cần API key)

---

## 2. Installation — Development (wp-env)

### 2.1 Clone và cài dependencies

```bash
git clone https://github.com/goright-ai/wp-admin-agent
cd wp-admin-agent
npm install
```

### 2.2 Build frontend

```bash
# Production build (một lần)
npm run build

# Hoặc watch mode khi đang phát triển
npm run dev
```

Build output:
- `assets/js/admin-agent.js` — React IIFE bundle
- `assets/css/admin-agent.css` — CSS (nếu tách riêng)

### 2.3 Khởi động WordPress environment

```bash
wp-env start
```

WordPress sẽ chạy tại `http://localhost:8888` (admin: `http://localhost:8888/wp-admin`).

Default credentials:
- Username: `admin`
- Password: `password`

### 2.4 Activate plugin

```bash
wp-env run cli wp plugin activate wp-admin-agent
```

Hoặc vào `wp-admin → Plugins → Activate`.

### 2.5 Stop environment

```bash
wp-env stop
```

---

## 3. Installation — Production

### 3.1 Upload plugin

1. Build frontend trước: `npm run build`
2. Upload toàn bộ thư mục plugin lên `wp-content/plugins/wp-admin-agent/`
3. Đảm bảo `assets/js/admin-agent.js` tồn tại (nếu không có, build chưa chạy)

### 3.2 Activate

Vào `wp-admin → Plugins → Installed Plugins → Activate "WP Admin Agent"`.

Khi activate, plugin tự động tạo 2 DB tables:
- `{prefix}waa_logs`
- `{prefix}waa_conversations`

### 3.3 Kiểm tra activation

```bash
# Via WP-CLI
wp option get waa_db_version
# Nên trả về: 0.2.0
```

---

## 4. Cấu hình Provider & API Keys

Vào `wp-admin → Settings → Admin Agent`.

### Tab "Provider & Keys"

**Bước 1: Chọn AI Provider**

```
AI Provider: [Anthropic (Claude) ▾]
             [Google Gemini      ]
             [Ollama (Local)     ]
```

**Bước 2: Chọn Model**

Model dropdown tự động cập nhật theo provider. Thông tin pricing hiển thị real-time ở panel bên phải.

**Bước 3: Nhập API credentials**

**Anthropic:**
```
Anthropic API Key: [your-anthropic-api-key]
```
- Lấy key tại [console.anthropic.com](https://console.anthropic.com)
- Key được mã hóa AES-256 trước khi lưu vào DB
- Nếu key đã set, hiển thị `••••••••` — để trống để giữ nguyên

**Gemini:**
```
Gemini API Key: [your-gemini-api-key]
```
- Lấy key tại [aistudio.google.com](https://aistudio.google.com/app/apikey)
- Free tier: có giới hạn request/minute

**Ollama:**
```
Ollama URL: [http://localhost:11434]
```

Các URL mẫu:
| Môi trường | URL |
|-----------|-----|
| Private network / another host | `http://your-ollama-host:11434` |
| Docker internal network | `http://ollama:11434` |
| Docker / wp-env local | `http://host.docker.internal:11434` |
| Localhost (non-Docker) | `http://localhost:11434` |

> **Quan trọng:** Khi chạy trong wp-env (Docker), WordPress container không thể reach `localhost` của máy host. Dùng `host.docker.internal:11434` thay thế.

**Bước 4: Test Connection**

Click `Test Connection` để verify. Plugin sẽ gửi một request nhỏ `"Reply with the single word OK"` và hiển thị response.

**Bước 5: Save Settings**

Click `Save Settings`. Settings được lưu qua POST form (có nonce verify) hoặc có thể lưu qua REST API.

---

## 5. Cấu hình Media & Images

Tab `Media & Images`:

```
Pexels API Key: [your-pexels-api-key]
```

- Lấy key miễn phí tại [pexels.com/api](https://www.pexels.com/api/)
- Free tier: 200 requests/hour
- Enables tool `search_images` — AI có thể tìm và import ảnh royalty-free
- Mỗi ảnh import được lưu attribution metadata (`_pexels_photographer`, `_pexels_photo_url`)

---

## 6. Cấu hình System Prompt

Tab `System Prompt`:

### Base prompt (read-only)

Hiển thị preview của base system prompt gồm:
- Site context (URL, title, WP version, timezone, current user, provider)
- 7 rules cơ bản
- Model-specific guidance (auto từ provider class)

### Custom Rules (editable)

Textarea để thêm rules bổ sung, được append vào cuối system prompt:

```
- Always respond in formal Vietnamese.
- Never activate or deactivate plugins without listing what will change.
- When creating posts, default category is 'Uncategorized'.
- Do not use tools without explaining what you are about to do.
```

Custom rules được lưu qua `waa_custom_rules` option và inject vào mỗi request.

---

## 7. Cấu hình Tools

Tab `Tools (17)`:

Hiển thị table tất cả tools với:
- Checkbox enable/disable
- Tool name
- Description
- JSON schema viewer

**Disable tool:** Bỏ tick checkbox → Save. Tool đó sẽ bị loại ra khỏi `WAA_Tool_Registry` và AI sẽ không biết tool đó tồn tại.

**Enable all / Disable all:** Buttons tiện lợi để toggle tất cả.

**Xem schema:** Click `JSON ▾` để xem input schema của tool.

> Tool bị disable vẫn tồn tại trong code — chỉ không được pass vào AI. Registry kiểm tra `disabled_tools` option khi đăng ký.

---

## 8. Test Cases — Kiểm thử từng nhóm chức năng

### 8.1 Plugin Management

```
User: "List all installed plugins and tell me which ones are inactive"
Expected: AI calls list_plugins, returns plugin list with status

User: "Install the Yoast SEO plugin"
Expected: AI confirms action → calls install_plugin{slug: "wordpress-seo"} → navigate to plugins.php

User: "Activate the Hello Dolly plugin"
Expected: AI calls activate_plugin{slug: "hello-dolly"} → navigate to plugins.php

User: "Deactivate Akismet"
Expected: AI asks for confirmation → calls deactivate_plugin → navigate to plugins.php
```

### 8.2 Theme Management

```
User: "What themes do I have installed?"
Expected: AI calls list_themes, returns themes with active indicator

User: "Switch to the Twenty Twenty-Three theme"
Expected: AI confirms → calls switch_theme{slug: "twentytwentythree"} → navigate to themes.php
```

### 8.3 Post Management

```
User: "Show me the last 5 published posts"
Expected: AI calls list_posts with appropriate filters

User: "Create a new post titled 'Welcome to our blog' with a brief introduction"
Expected: AI calls create_post{title, content} → navigate to edit.php

User: "Update post ID 5, add the tag 'featured' to it"
Expected: AI calls update_post{id: 5, tags: [...]}
```

### 8.4 User Management

```
User: "List all editors and administrators"
Expected: AI calls list_users, filters by role

User: "Change john@example.com's role to editor"
Expected: AI calls list_users first → confirms → calls update_user_role → navigate to users.php
```

### 8.5 Site Settings

```
User: "What is the current site title and tagline?"
Expected: AI calls get_site_settings → returns title + tagline

User: "Change the site tagline to 'Powered by AI'"
Expected: AI calls update_site_settings{tagline: "Powered by AI"} → navigate to options-general.php
```

### 8.6 Site Icon

```
User: "Set the site icon to a robot icon"
Expected: AI calls search_icon{query: "robot"} → calls set_site_icon{image_url: "..."} → navigate to options-general.php

User: "Set the site icon to https://example.com/logo.png"
Expected: AI calls set_site_icon{image_url: "https://example.com/logo.png"} directly
```

### 8.7 Image Search (cần Pexels key)

```
User: "Find 3 landscape photos of mountains and import them"
Expected: AI calls search_images{query: "mountains", limit: 3, orientation: "landscape"} → returns attachment IDs

User: "Find a coffee shop photo and set it as the featured image for post 10"
Expected: AI calls search_images → gets attachment_id → calls set_post_image{post_id: 10, attachment_id: ...}
```

---

## 9. Troubleshooting thường gặp

### AI không respond gì cả

**Check:** Settings → Provider & Keys → Test Connection.

Nguyên nhân phổ biến:
- API key chưa set hoặc sai
- Gemini: key chưa enable Generative Language API trong Google Cloud
- Ollama: URL sai hoặc Ollama server không chạy

### Ollama không connect được

```bash
# Kiểm tra Ollama đang chạy
curl http://localhost:11434/api/tags

# Trong wp-env Docker, dùng:
curl http://host.docker.internal:11434/api/tags
```

Nếu dùng wp-env, URL phải là `http://host.docker.internal:11434`, không phải `localhost`.

### Rate limit: "Too many requests"

Plugin giới hạn 30 requests/minute per user. Đây là hardcode (`WAA_RATE_LIMIT = 30`).

Để clear rate limit tạm thời (admin only):
```bash
wp transient delete --search="waa_rate_" --allow-root
```

Hoặc deactivate → reactivate plugin (deactivation hook tự xóa rate transients).

### "AI provider not configured"

REST API trả về error này khi `has_active_credential()` trả về false.

- Anthropic: `get_api_key()` trả về empty string
- Gemini: `get_gemini_api_key()` trả về empty string
- Ollama: luôn configured (không cần key)

Fix: vào Settings → lưu lại API key.

### Chat widget không hiện

1. Kiểm tra `assets/js/admin-agent.js` có tồn tại không (cần build)
2. Kiểm tra user có `manage_options` capability không (chỉ admin thấy widget)
3. Kiểm tra browser console cho JS errors

```bash
# Build lại frontend
npm run build
```

### Tool execute lỗi

Xem log tại `waa_logs` table:
```sql
SELECT tool_name, params, result, status, created_at
FROM {prefix}waa_logs
WHERE status = 'error'
ORDER BY created_at DESC
LIMIT 20;
```

### Settings không lưu được

Form settings dùng WP nonce. Kiểm tra:
1. User có `manage_options` không
2. Nonce chưa expire (mặc định 12h)
3. POST action đúng page `wp-admin-agent`

### `search_images` tool lỗi

```
Error: Pexels API key not configured
```

→ Vào Settings → Media & Images → nhập Pexels API key → Save.

Nếu key đúng nhưng vẫn lỗi:
```javascript
// Test trực tiếp
fetch('https://api.pexels.com/v1/search?query=test&per_page=1', {
    headers: { Authorization: 'YOUR_KEY' }
}).then(r => r.json()).then(console.log)
```

### DB tables không tồn tại

Nếu bảng `waa_logs` hoặc `waa_conversations` chưa tạo:
```bash
# Deactivate rồi activate lại
wp plugin deactivate wp-admin-agent
wp plugin activate wp-admin-agent
```

Hoặc chạy activation hook thủ công:
```php
WAA_Plugin::activate();
```
