# Thêm Tool mới — WP Admin AI Assistant

## 1. Standard Flow

```
┌──────────────────────────────────────────────────────────────────┐
│  1. CHECK — WordPress/WP REST API có sẵn chưa?                  │
│     Nếu có → dùng thẳng trong execute()                         │
│     Nếu không → viết custom logic                               │
├──────────────────────────────────────────────────────────────────┤
│  2. WRITE — Tạo file tools/class-tool-{name}.php                │
│     extend WAA_Tool_Base                                        │
│     implement 4 methods: get_name, get_description,             │
│                           get_input_schema, execute             │
├──────────────────────────────────────────────────────────────────┤
│  3. WRAP — Đăng ký trong class-rest-api.php::build_registry()   │
│     $registry->register(new WAA_Tool_My_Tool());                │
├──────────────────────────────────────────────────────────────────┤
│  4. MCP — Tool tự động exposed qua /mcp endpoint (không cần     │
│     làm gì thêm — WAA_MCP_Server dùng registry)                │
└──────────────────────────────────────────────────────────────────┘
```

### Check trước khi viết

| Nhu cầu | WordPress có sẵn? |
|---------|-------------------|
| Đọc/ghi site options | `get_option()` / `update_option()` ✅ |
| Upload media | `wp_upload_bits()` + `wp_insert_attachment()` ✅ |
| Cài plugin | `Plugin_Upgrader` class ✅ |
| Gửi email | `wp_mail()` ✅ |
| Download file từ URL | `wp_remote_get()` ✅ |
| Tìm kiếm web | ❌ cần external API |
| WooCommerce data | WC REST API ✅ (nếu cài WC) |

---

## 2. Template đầy đủ

```php
<?php
// File: tools/class-tool-{name}.php
// Naming: WAA_Tool_{Name} → class-tool-{name}.php
//         (class name dùng PascalCase, filename dùng kebab-case)

defined('ABSPATH') || exit;

class WAA_Tool_My_Tool extends WAA_Tool_Base {

    /**
     * Tên tool — dùng snake_case, phải unique trong toàn bộ registry.
     * AI sẽ dùng tên này để gọi tool.
     */
    public function get_name(): string {
        return 'my_tool_name';
    }

    /**
     * Mô tả ngắn gọn, rõ ràng.
     * AI đọc description để biết khi nào nên gọi tool này.
     * Viết bằng tiếng Anh (tốt hơn cho AI reasoning).
     * Nên bao gồm: điều kiện, output, và side effects nếu có.
     */
    public function get_description(): string {
        return 'Brief description of what this tool does, when to call it, and what it returns. '
             . 'Mention any side effects (e.g., "creates a new record", "⚠ irreversible").';
    }

    /**
     * Input schema theo Anthropic format (JSON Schema subset).
     * AI dùng schema này để biết cần truyền parameters gì.
     *
     * QUAN TRỌNG: Tool không có params → 'properties' => (object)[]
     * KHÔNG được dùng [] (PHP array) — phải là (object) để serialize thành {} JSON.
     */
    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'required_param' => [
                    'type'        => 'string',
                    'description' => 'What this parameter means. Be specific. AI reads this.',
                ],
                'optional_param' => [
                    'type'        => 'integer',
                    'description' => 'Optional parameter. Default: 10.',
                    'minimum'     => 1,
                    'maximum'     => 100,
                ],
                'enum_param' => [
                    'type'        => 'string',
                    'enum'        => ['option_a', 'option_b', 'option_c'],
                    'description' => 'Choose one of the allowed values.',
                ],
            ],
            'required' => ['required_param'],  // chỉ list những param thực sự required
        ];
    }

    /**
     * Thực thi tool logic.
     *
     * @param array $input Validated input (đã qua validate_input()).
     *                     Keys match 'properties' trong get_input_schema().
     * @return array Luôn trả về array. Convention:
     *               ['success' => true, 'data' => ...] khi thành công
     *               throw RuntimeException khi lỗi (KHÔNG return error array)
     */
    public function execute(array $input): array {
        // 1. Extract và sanitize input
        $param  = sanitize_text_field($input['required_param'] ?? '');
        $limit  = max(1, min(100, (int) ($input['optional_param'] ?? 10)));

        // 2. Validate business logic
        if (empty($param)) {
            throw new RuntimeException('required_param cannot be empty.');
        }

        // 3. Execute WordPress operation
        $results = [];
        // ... your logic here ...

        // 4. Return structured result
        return [
            'success' => true,
            'data'    => $results,
            'count'   => count($results),
            'message' => "Found {$count} items matching '{$param}'.",
        ];
    }
}
```

---

## 3. Giải thích từng method

### get_name()

- Dùng `snake_case`
- Phải unique — không trùng với tool nào khác trong registry
- Được dùng trong NAVIGATE_MAP và disabled_tools list
- Không đổi sau khi deploy (saved conversations có thể reference tên này)

### get_description()

AI đọc description để quyết định khi nào gọi tool. Viết như hướng dẫn:

```php
// Tốt
'Search the WordPress.org plugin repository for plugins matching a keyword. '
. 'Returns plugin name, description, rating, and active installs. '
. 'Use before calling install_plugin to verify the slug exists.'

// Xấu (quá ngắn, không đủ context)
'Search plugins'
```

Nếu tool có side effects:
```php
// Ghi rõ side effect
'Permanently delete a post and all its metadata. ⚠ This action is irreversible.'
```

### get_input_schema()

**JSON Schema subset** được Anthropic, Gemini, Ollama support:

```php
// String
'param' => ['type' => 'string', 'description' => '...']

// Integer với range
'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => '...']

// Enum
'status' => ['type' => 'string', 'enum' => ['active', 'inactive', 'all'], 'description' => '...']

// Boolean
'include_drafts' => ['type' => 'boolean', 'description' => '...']

// Array of strings
'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '...']

// Tool không có params — PHẢI dùng (object)
'properties' => (object)[]
```

> **Lưu ý Gemini:** Provider Gemini tự động strip `additionalProperties` khỏi schema (kể cả trong nested properties). Không cần lo — `WAA_Provider_Gemini::to_gemini_tools()` xử lý điều này.

### execute()

**Convention quan trọng:**

```php
// ĐÚNG: throw RuntimeException khi lỗi
public function execute(array $input): array {
    $result = some_wp_operation();
    if (!$result) {
        throw new RuntimeException('Operation failed: ' . get_last_error());
    }
    return ['success' => true, 'data' => $result];
}

// SAI: return error array (agent sẽ bắt được nhưng error message kém hơn)
public function execute(array $input): array {
    $result = some_wp_operation();
    if (!$result) {
        return ['error' => 'Operation failed'];  // ← tránh pattern này
    }
    return ['success' => true, 'data' => $result];
}
```

Khi tool throw RuntimeException, WAA_Tool_Registry::execute() không catch — exception propagate lên WAA_Agent::run() và được yield ra dưới dạng SSE error event.

---

## 4. Đăng ký vào build_registry()

Mở `includes/class-rest-api.php`, tìm method `build_registry()`:

```php
public static function build_registry(array $disabled = []): WAA_Tool_Registry {
    $registry = new WAA_Tool_Registry($disabled);
    $registry->register(new WAA_Tool_Get_Settings());
    // ... existing tools ...
    $registry->register(new WAA_Tool_Search_Images());
    $registry->register(new WAA_Tool_Set_Post_Image());

    // Thêm tool mới ở đây:
    $registry->register(new WAA_Tool_My_Tool());

    return $registry;
}
```

Thứ tự đăng ký không ảnh hưởng đến behavior — registry là một map `name → tool`.

---

## 5. Thêm NAVIGATE_MAP (tùy chọn)

Nếu tool của bạn thực hiện write action và muốn redirect user sau khi xong, thêm vào `NAVIGATE_MAP` trong `class-agent.php`:

```php
private const NAVIGATE_MAP = [
    'update_site_settings'  => 'options-general.php',
    'set_site_icon'         => 'options-general.php',
    // ... existing entries ...

    // Tool mới của bạn:
    'my_tool_name'          => 'admin.php?page=my-plugin-page',
    // Hoặc standard WP pages:
    // 'my_tool_name'       => 'edit.php',
    // 'my_tool_name'       => 'plugins.php',
    // 'my_tool_name'       => 'options-general.php',
];
```

`admin_url()` được gọi trên value → relative path tự động thành absolute URL.

---

## 6. Infrastructure sẵn có

### WAA_Resource_Fetcher — Download file từ URL

```php
$fetcher = new WAA_Resource_Fetcher();

try {
    $resource = $fetcher->fetch_image('https://example.com/logo.png');
    // $resource = [
    //     'path'     => '/tmp/wp_xyz.png',  // temp file path
    //     'mime'     => 'image/png',
    //     'filename' => 'logo.png',
    //     'size'     => 24576,              // bytes
    // ]

    // Sử dụng file...
    $content = file_get_contents($resource['path']);

    // Luôn cleanup temp file
    unlink($resource['path']);

} catch (RuntimeException $e) {
    throw new RuntimeException('Could not download image: ' . $e->getMessage());
}
```

**Validation tự động:**
- URL scheme: chỉ `http://` và `https://`
- HEAD request trước → check Content-Type và Content-Length
- Size limit: 5MB max
- MIME whitelist: `image/png`, `image/jpeg`, `image/gif`, `image/webp`, `image/x-icon`, `image/vnd.microsoft.icon`, `image/svg+xml`

### WAA_Media_Importer — URL → WP Media Library

```php
$importer = new WAA_Media_Importer(new WAA_Resource_Fetcher());

try {
    $attachment_id = $importer->import_from_url(
        'https://example.com/logo.png',
        'My Logo'   // optional title
    );
    // Attachment được tạo trong WP Media Library
    // Thumbnails được generate tự động
    // Temp file được cleanup tự động (trong finally block)

    $url = wp_get_attachment_url($attachment_id);

} catch (RuntimeException $e) {
    throw new RuntimeException('Import failed: ' . $e->getMessage());
}
```

**Quá trình bên trong:**
1. `WAA_Resource_Fetcher::fetch_image()` → download + validate → temp file
2. `wp_upload_bits()` → copy vào `/wp-content/uploads/YYYY/MM/`
3. `wp_insert_attachment()` → tạo attachment post
4. `wp_generate_attachment_metadata()` → generate thumbnails
5. `wp_update_attachment_metadata()` → lưu metadata
6. `unlink(temp_file)` — cleanup trong `finally`

### WAA_Settings — Truy cập settings

```php
$settings = new WAA_Settings();

// Đọc
$settings->get_provider();          // 'anthropic' | 'gemini' | 'ollama'
$settings->get_model();             // 'claude-haiku-4-5', 'gemini-2.5-flash', ...
$settings->get_api_key();           // decrypted Anthropic key
$settings->get_gemini_api_key();    // decrypted Gemini key
$settings->get_ollama_url();        // Ollama base URL
$settings->get_pexels_api_key();    // decrypted Pexels key
$settings->get_custom_rules();      // plain text custom rules
$settings->get_disabled_tools();    // array of disabled tool names
$settings->get_max_tokens();        // int, default 4096

// Ghi (thường không cần trong tool execute)
$settings->set_provider('gemini');
$settings->set_api_key('sk-ant-...');  // auto-encrypts

// Check
$settings->has_active_credential(); // bool: provider có credentials không
```

### WAA_Audit_Log — Logging (không cần dùng trực tiếp)

Tool không cần tự log — WAA_Agent::run() tự động log mỗi tool execution vào `waa_logs`.

Nhưng nếu muốn đọc stats:
```php
$recent = WAA_Audit_Log::get_recent(10);    // 10 entries gần nhất
$stats  = WAA_Audit_Log::get_stats('30');   // stats 30 ngày
```

---

## 7. Ví dụ thực tế — search_images tool

`search_images` là tool phức tạp nhất hiện tại — tốt để làm reference.

### Flow:
1. Đọc Pexels API key từ settings
2. Gọi Pexels search API
3. Với mỗi kết quả: dùng `WAA_Media_Importer` để import vào WP
4. Lưu attribution metadata (`_pexels_photographer`, `_pexels_photo_url`)
5. Set alt text cho accessibility
6. Return attachment IDs cho subsequent `set_post_image` calls

```php
public function execute(array $input): array {
    // 1. Get API key
    $api_key = (new WAA_Settings())->get_pexels_api_key();
    if (!$api_key) {
        return ['success' => false, 'error' => 'Pexels API key not configured...'];
    }

    // 2. Sanitize và validate input
    $query       = sanitize_text_field($input['query'] ?? '');
    $limit       = max(1, min(5, (int) ($input['limit'] ?? 1)));
    $orientation = in_array($input['orientation'] ?? '', ['landscape', 'portrait', 'square'], true)
                       ? $input['orientation'] : 'landscape';

    // 3. Gọi Pexels API
    $response = wp_remote_get(add_query_arg([
        'query'       => $query,
        'per_page'    => $limit,
        'orientation' => $orientation,
    ], 'https://api.pexels.com/v1/search'), [
        'timeout' => 20,
        'headers' => ['Authorization' => $api_key],
    ]);

    // 4. Xử lý response
    if (is_wp_error($response)) {
        return ['success' => false, 'error' => $response->get_error_message()];
    }
    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($code !== 200) {
        return ['success' => false, 'error' => "Pexels API error (HTTP $code)"];
    }

    // 5. Import từng ảnh
    $importer = new WAA_Media_Importer(new WAA_Resource_Fetcher());
    $imported = [];

    foreach ($body['photos'] ?? [] as $photo) {
        $url   = $photo['src']['large2x'] ?? $photo['src']['large'] ?? $photo['src']['original'];
        $title = sanitize_text_field($photo['alt'] ?: $query);

        try {
            $attachment_id = $importer->import_from_url($url, $title);

            // 6. Lưu attribution metadata
            update_post_meta($attachment_id, '_pexels_photographer', sanitize_text_field($photo['photographer']));
            update_post_meta($attachment_id, '_pexels_photo_url',   esc_url_raw($photo['url']));
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $title);

            $imported[] = [
                'attachment_id' => $attachment_id,
                'title'         => $title,
                'photographer'  => $photo['photographer'],
                'source_url'    => $photo['url'],
                'media_url'     => wp_get_attachment_url($attachment_id),
            ];
        } catch (Throwable $e) {
            $imported[] = ['error' => $e->getMessage(), 'photo_id' => $photo['id']];
        }
    }

    // 7. Return kết quả với hướng dẫn cho AI
    $successful = array_filter($imported, fn($i) => isset($i['attachment_id']));
    return [
        'success'  => !empty($successful),
        'imported' => $imported,
        'message'  => sprintf(
            '%d image(s) imported. Use set_post_image with the attachment_id to attach to a post.',
            count($successful)
        ),
    ];
}
```

**Điểm đáng chú ý:**
- Loop qua nhiều photos, mỗi cái wrap trong try/catch riêng → một ảnh fail không dừng cả quá trình
- Return `message` field với hướng dẫn cụ thể → AI biết step tiếp theo là gì
- Attribution metadata để comply với Pexels license

---

## 8. Checklist trước khi deploy

```
[ ] Tên tool (get_name()) là snake_case và unique
[ ] Description đủ rõ để AI biết khi nào gọi
[ ] input_schema mô tả đầy đủ từng parameter
[ ] Tool không có params: 'properties' => (object)[] (không phải [])
[ ] execute() throw RuntimeException khi lỗi (không return error array)
[ ] Sanitize tất cả input (sanitize_text_field, esc_url_raw, intval, v.v.)
[ ] Đăng ký trong build_registry() (class-rest-api.php)
[ ] Thêm vào NAVIGATE_MAP nếu là write tool (class-agent.php)
[ ] Test với Anthropic: tool calls dùng 'input' field
[ ] Test với Gemini: tool calls dùng 'args' field (provider xử lý auto)
[ ] Test với Ollama: tool calls dùng 'arguments' field (provider xử lý auto)
[ ] Không để credentials hard-code trong execute() — dùng WAA_Settings
[ ] Return array hợp lý, AI sẽ đọc và summarize cho user
```

---

## 9. Ví dụ thêm tool đơn giản — list_categories

Tool đọc danh sách categories — không cần external API, chỉ dùng WP functions:

```php
<?php
// File: tools/class-tool-list-categories.php

defined('ABSPATH') || exit;

class WAA_Tool_List_Categories extends WAA_Tool_Base {

    public function get_name(): string {
        return 'list_categories';
    }

    public function get_description(): string {
        return 'List all post categories with their post count and slug. '
             . 'Use this to verify a category exists before assigning it to a post.';
    }

    public function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => (object)[],  // Không có params → (object)[]
        ];
    }

    public function execute(array $input): array {
        $terms = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            throw new RuntimeException('Could not fetch categories: ' . $terms->get_error_message());
        }

        $categories = array_map(fn($term) => [
            'id'         => $term->term_id,
            'name'       => $term->name,
            'slug'       => $term->slug,
            'post_count' => (int) $term->count,
            'parent_id'  => (int) $term->parent,
        ], $terms);

        return [
            'success'    => true,
            'categories' => $categories,
            'total'      => count($categories),
        ];
    }
}
```

Sau đó thêm vào `build_registry()`:
```php
$registry->register(new WAA_Tool_List_Categories());
```

Tool sẽ ngay lập tức available cả trong chat interface và MCP endpoint.

---

## 10. Tool với validate_input() tùy chỉnh

Base class `validate_input()` chỉ return input as-is. Override nếu cần validation phức tạp:

```php
public function validate_input(array $input): array|WP_Error {
    // Validate post ID tồn tại
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) {
        return new WP_Error('invalid_post_id', 'post_id must be a positive integer.');
    }

    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('post_not_found', "Post ID $post_id does not exist.");
    }

    return array_merge($input, ['_post' => $post]);  // có thể inject resolved objects
}

public function execute(array $input): array {
    $post = $input['_post'];  // đã validated ở trên
    // ...
}
```

Khi `validate_input()` trả về `WP_Error`, registry trả về `['error' => 'message']` và tool không execute.
