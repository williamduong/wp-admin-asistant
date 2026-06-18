# Expanding Bot Capabilities — Standard Flow

## Nguyên tắc

```
1. Check — WordPress / WP REST API có sẵn chưa?
2. Write  — Chưa có thì viết custom endpoint hoặc plugin
3. Wrap   — Bọc thành WAA Tool (JSON schema + execute)
4. MCP    — Tool tự động expose qua MCP endpoint cho mọi AI client
```

Mọi capability mới đều đi qua flow này, không phân biệt loại (media, external API, async, v.v.).

---

## 1. Check trước khi viết

WordPress core và plugin phổ biến đã có sẵn rất nhiều:

| Nhu cầu | WordPress có sẵn? |
|---|---|
| Lấy/sửa settings | `get_option` / `update_option` ✅ |
| Upload media | `wp_upload_bits` + `wp_insert_attachment` ✅ |
| Cài plugin | `install_plugin_install_status` + Plugin_Upgrader ✅ |
| Download file từ URL | `wp_remote_get` ✅ |
| Gửi email | `wp_mail` ✅ |
| WooCommerce orders | WC REST API `/wp-json/wc/v3/orders` ✅ (nếu cài WC) |
| Tìm kiếm web | ❌ cần viết (DuckDuckGo, SerpAPI, v.v.) |
| Đổi site icon | Cần 2 bước: upload → `update_option('site_icon', $id)` |

**Rule:** Nếu WordPress function / WP REST route đã có → dùng thẳng trong `execute()`. Chỉ viết class mới khi cần logic tái sử dụng hoặc external API.

---

## 2. Viết — Các tầng infrastructure

### Tầng Resource (binary / file / URL)

Khi tool cần xử lý file hoặc URL, dùng `WAA_Resource_Fetcher` + `WAA_Media_Importer`:

```php
// Download ảnh từ URL → WP Media Library → trả về attachment_id
$importer = new WAA_Media_Importer(new WAA_Resource_Fetcher());
$attachment_id = $importer->import_from_url($url, 'Site Icon');
```

`WAA_Resource_Fetcher` lo:
- Validate URL scheme (https only)
- HEAD request trước → check Content-Type và Content-Length
- Download với timeout + size limit (5MB default)
- Trả về `['path', 'mime', 'filename', 'size']`

`WAA_Media_Importer` lo:
- Nhận kết quả từ Fetcher
- `wp_upload_bits()` → lưu vào `/uploads/`
- `wp_insert_attachment()` + `wp_generate_attachment_metadata()` → tạo record
- Trả về `int $attachment_id`

### Tầng External API

Khi tool cần gọi external service (Stripe, Mailchimp, Google, v.v.):

```php
// 1. Credential lưu qua WAA_Settings (đã có AES-256 encryption)
$settings->get_custom_key('mailchimp');

// 2. Viết class riêng cho service
class WAA_Mailchimp_Client {
    public function __construct(private string $api_key) {}
    public function list_subscribers(string $list_id): array { ... }
}

// 3. Tool gọi client
class WAA_Tool_List_Subscribers extends WAA_Tool_Base {
    public function execute(array $input): array {
        $client = new WAA_Mailchimp_Client(
            (new WAA_Settings())->get_custom_key('mailchimp')
        );
        return $client->list_subscribers($input['list_id']);
    }
}
```

### Tầng Async (long-running)

Khi tool cần thời gian dài (backup, bulk ops, export):

```php
// Tool kick off job → trả về job_id ngay lập tức
class WAA_Tool_Backup_Site extends WAA_Tool_Base {
    public function execute(array $input): array {
        $job_id = wp_schedule_single_event(time(), 'waa_run_backup', [$input]);
        return ['job_id' => $job_id, 'status' => 'queued'];
    }
}

// Tool kiểm tra trạng thái
class WAA_Tool_Check_Job extends WAA_Tool_Base {
    public function execute(array $input): array {
        return WAA_Job_Queue::get_status($input['job_id']);
    }
}
```

AI sẽ tự gọi `check_job` sau khi khởi động job.

---

## 3. Wrap — Viết WAA Tool

### Template chuẩn

```php
<?php
defined('ABSPATH') || exit;

class WAA_Tool_{Name} extends WAA_Tool_Base {
    public function get_name(): string        { return '{snake_name}'; }
    public function get_description(): string { return '{Mô tả rõ ràng, 1-2 câu}'; }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'param_name' => [
                    'type'        => 'string',
                    'description' => 'Mô tả param, AI đọc cái này để biết truyền gì',
                ],
            ],
            'required' => ['param_name'],
        ];
    }

    public function execute(array $input): array {
        // Luôn return array với ít nhất 'success' key
        // Khi lỗi: throw RuntimeException — agent sẽ bắt và báo AI
        return ['success' => true, 'data' => $result];
    }
}
```

### Quy ước quan trọng

| Vấn đề | Cách xử lý |
|---|---|
| Tool không có param | `'properties' => (object)[]` — phải là object, không phải array |
| Tool destructive | Mô tả rõ trong description: "⚠ irreversible" |
| Tool cần confirm | Ghi vào `system_prompt` rule, không phải trong tool |
| Tool lỗi | `throw new RuntimeException('message')` — không return error array |

### Đăng ký tool

```php
// includes/class-rest-api.php → build_registry()
$registry->register(new WAA_Tool_{Name}());
```

---

## 4. MCP — Tự động exposed

Sau khi đăng ký vào `WAA_Tool_Registry`, tool **tự động** xuất hiện trong MCP endpoint:

```
GET  /wp-json/wp-admin-agent/v1/mcp/tools   → list tất cả tools
POST /wp-json/wp-admin-agent/v1/mcp          → JSON-RPC 2.0 call
```

Bất kỳ MCP-compatible client nào (Claude Desktop, Claude Code, IDE extensions) đều có thể kết nối và dùng các WordPress tools này — không cần sửa gì thêm.

```jsonc
// MCP tools/call request
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "set_site_icon",
    "arguments": { "image_url": "https://example.com/logo.png" }
  }
}
```

---

## 5. Ví dụ đầy đủ — Đổi Site Icon

```
User: "đổi icon site thành logo con chó corgi cute"

AI (Gemini / Claude):
  1. [Tự biết URL từ training hoặc search] → "https://cdn.freepik.com/corgi-icon.png"
  2. calls: set_site_icon({ "image_url": "..." })

Plugin:
  3. WAA_Resource_Fetcher: HEAD check → mime=image/png, size=24KB ✅
  4. wp_remote_get() → download
  5. WAA_Media_Importer: wp_upload_bits() → wp_insert_attachment() → id=42
  6. update_option('site_icon', 42)
  7. return { success: true, attachment_id: 42, site_icon_url: "..." }

AI: "Đã đổi icon site thành corgi rồi nhé 🐕"
```

---

## 6. Capability Map — Khi nào dùng gì

```
Yêu cầu mới
│
├── Chỉ cần WordPress data (text/number)
│   └── Tool đơn giản → extend WAA_Tool_Base → xong
│
├── Cần file / binary / media
│   └── WAA_Resource_Fetcher + WAA_Media_Importer → Tool → xong
│
├── Cần external service (Stripe, Google, v.v.)
│   ├── Check WAA_Settings có chỗ lưu key chưa → thêm nếu thiếu
│   └── Viết client class riêng → Tool dùng client → xong
│
├── Long-running (> 30s)
│   ├── Tool 1: kick off (WP Cron / Action Scheduler) → return job_id
│   └── Tool 2: check_job(job_id) → AI tự poll
│
└── Cần AI search / generate nội dung
    └── Tool gọi external AI API (Unsplash, DALL-E, SerpAPI)
        → kết quả đưa vào các tool trên
```

---

## 7. Checklist khi thêm capability mới

- [ ] Check WordPress/plugin có API sẵn chưa
- [ ] Tool description đủ rõ để AI biết khi nào gọi
- [ ] `input_schema` đủ mô tả để AI điền đúng tham số
- [ ] `(object)[]` cho tool không có params (không phải `[]`)
- [ ] `throw RuntimeException` khi lỗi (không return error array)
- [ ] Đăng ký trong `build_registry()`
- [ ] Thêm vào `get_model_instructions()` của provider nếu tool có quirk đặc biệt
- [ ] Test với cả 3 providers: Anthropic, Gemini, Ollama
