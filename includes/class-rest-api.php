<?php

defined('ABSPATH') || exit;

class WAA_REST_API {
    private const NS = 'wp-admin-agent/v1';
    private const DEFAULT_CONVERSATION_TITLE = 'New conversation';
    private const PROVISIONAL_TITLE_LIMIT = 60;
    private const DEBUG_LOG_LIMIT = 80;

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
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

        register_rest_route(self::NS, '/test-connection', [
            'methods'             => 'POST',
            'callback'            => [$this, 'test_connection'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NS, '/conversations', [
            ['methods' => 'GET',  'callback' => [$this, 'list_conversations'],  'permission_callback' => [$this, 'check_permission']],
            ['methods' => 'POST', 'callback' => [$this, 'create_conversation'], 'permission_callback' => [$this, 'check_permission']],
        ]);
        register_rest_route(self::NS, '/conversations/(?P<id>\d+)', [
            ['methods' => 'GET',    'callback' => [$this, 'get_conversation'],    'permission_callback' => [$this, 'check_permission']],
            ['methods' => 'POST',   'callback' => [$this, 'update_conversation'], 'permission_callback' => [$this, 'check_permission']],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete_conversation'], 'permission_callback' => [$this, 'check_permission']],
        ]);

        register_rest_route(self::NS, '/settings', [
            ['methods' => 'GET',  'callback' => [$this, 'get_plugin_settings'],  'permission_callback' => [$this, 'check_permission']],
            ['methods' => 'POST', 'callback' => [$this, 'save_plugin_settings'], 'permission_callback' => [$this, 'check_permission']],
        ]);

        register_rest_route(self::NS, '/stats', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'period' => ['required' => false, 'type' => 'integer', 'default' => 30],
            ],
        ]);

        register_rest_route(self::NS, '/pricing', [
            'methods'             => 'GET',
            'callback'            => fn() => new WP_REST_Response(WAA_Pricing::all_for_js()),
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NS, '/ollama-models', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_ollama_models'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NS, '/mcp', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_mcp'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function check_permission(WP_REST_Request $request): bool|WP_Error {
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', 'Insufficient permissions.', ['status' => 403]);
        }
        if ($this->should_rate_limit($request) && !(new WAA_Rate_Limiter())->check()) {
            return new WP_Error('rate_limited', 'Too many requests. Try again in a minute.', ['status' => 429]);
        }
        return true;
    }

    private function should_rate_limit(WP_REST_Request $request): bool {
        $route = $request->get_route();

        return $route === '/' . self::NS . '/chat'
            || $route === '/' . self::NS . '/mcp'
            || $route === '/' . self::NS . '/test-connection';
    }

    public function handle_chat(WP_REST_Request $request): void {
        $body            = $request->get_json_params();
        $message         = $request->get_param('message');
        $conversation_id = $request->get_param('conversation_id');

        $settings = new WAA_Settings();
        $confirmation = isset($body['confirmation']) && is_array($body['confirmation']) ? $body['confirmation'] : null;
        $workflow = $this->resolve_active_workflow($body ?? [], (int) $conversation_id);

        if (!$settings->has_active_credential()) {
            $this->append_conversation_debug_log((int) $conversation_id, [
                'turn_id' => $this->make_debug_turn_id(),
                'started_at' => current_time('mysql'),
                'completed_at' => current_time('mysql'),
                'status' => 'error',
                'provider' => $settings->get_provider(),
                'model' => $settings->get_model(),
                'request' => [
                    'message' => (string) $message,
                    'history_count' => 0,
                    'confirmation' => is_array($confirmation) ? $confirmation : null,
                    'workflow' => is_array($workflow) ? $this->sanitize_debug_value($workflow) : null,
                ],
                'warnings' => [],
                'errors' => ['AI provider not configured. Please go to Settings → Admin Agent.'],
                'events' => [
                    ['type' => 'error', 'message' => 'AI provider not configured. Please go to Settings → Admin Agent.'],
                ],
            ]);
            $this->sse_error('AI provider not configured. Please go to Settings → Admin Agent.');
            return;
        }

        $history = $this->resolve_history($body ?? [], (int) $conversation_id);
        $debug_turn = [
            'turn_id' => $this->make_debug_turn_id(),
            'started_at' => current_time('mysql'),
            'completed_at' => null,
            'status' => 'running',
            'provider' => $settings->get_provider(),
            'model' => $settings->get_model(),
            'request' => [
                'message' => (string) $message,
                'history_count' => count($history),
                'conversation_id' => (int) $conversation_id,
                'confirmation' => is_array($confirmation) ? $this->sanitize_debug_value($confirmation) : null,
                'workflow' => is_array($workflow) ? $this->sanitize_debug_value($workflow) : null,
            ],
            'assistant_text' => '',
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'warnings' => [],
            'errors' => [],
            'events' => [],
        ];

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) ob_end_flush();

        $agent = new WAA_Agent(
            WAA_Provider_Factory::make($settings),
            self::build_registry($settings->get_disabled_tools()),
            new WAA_Audit_Log()
        );

        try {
            foreach ($agent->run($message, $history, $confirmation, $workflow) as $event) {
                $this->record_debug_event($debug_turn, $event);
                $this->sse_emit($event);
            }
        } catch (Throwable $e) {
            $debug_turn['status'] = 'error';
            $debug_turn['errors'][] = $e->getMessage();
            $debug_turn['events'][] = [
                'type' => 'exception',
                'message' => $e->getMessage(),
            ];
            $this->sse_error($e->getMessage());
        } finally {
            $debug_turn['completed_at'] = current_time('mysql');
            if ($debug_turn['status'] === 'running') {
                $debug_turn['status'] = !empty($debug_turn['errors']) ? 'error' : 'success';
            }
            $this->append_conversation_debug_log((int) $conversation_id, $debug_turn);
        }

        $this->sse_emit(['type' => 'done']);
        echo "data: [DONE]\n\n";
        flush();
        exit;
    }

    public function resolve_history(array $body, int $conversation_id = 0): array {
        $inline_history = is_array($body['history'] ?? null) ? $body['history'] : [];

        if (!empty($inline_history)) {
            return $inline_history;
        }

        return $conversation_id > 0
            ? $this->load_conversation_messages($conversation_id)
            : [];
    }

    public function test_connection(WP_REST_Request $request): WP_REST_Response {
        $settings = new WAA_Settings();
        $body     = $request->get_json_params() ?? [];

        // Prefer live form values from request body (not yet saved to DB)
        $provider_id = !empty($body['provider'])  ? $body['provider']  : $settings->get_provider();
        $model       = !empty($body['model'])      ? $body['model']     : $settings->get_model();
        $api_key     = (!empty($body['api_key'])    && $body['api_key']    !== '••••••••') ? $body['api_key']    : $settings->get_api_key();
        $gemini_key  = (!empty($body['gemini_key']) && $body['gemini_key'] !== '••••••••') ? $body['gemini_key'] : $settings->get_gemini_api_key();
        $ollama_url  = !empty($body['ollama_url']) ? $body['ollama_url'] : $settings->get_ollama_url();

        $has_credential = match ($provider_id) {
            'gemini' => !empty($gemini_key),
            'ollama' => true,
            'fake'   => true,
            default  => !empty($api_key),
        };
        if (!$has_credential) {
            return new WP_REST_Response(['success' => false, 'error' => 'No credentials configured.'], 400);
        }

        try {
            $provider = match ($provider_id) {
                'gemini' => new WAA_Provider_Gemini($gemini_key, $model),
                'fake'   => new WAA_Provider_Fake($model),
                'ollama' => new WAA_Provider_Ollama($ollama_url, $model),
                default  => new WAA_Provider_Anthropic($api_key, $model),
            };
            $response = $provider->complete(
                'You are a test assistant.',
                [['role' => 'user', 'content' => 'Reply with the single word OK.']],
                []
            );
            return new WP_REST_Response([
                'success'  => true,
                'provider' => $provider->get_label(),
                'model'    => $model,
                'reply'    => trim($response['text']),
            ], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function get_plugin_settings(WP_REST_Request $request): WP_REST_Response {
        $settings = new WAA_Settings();
        return new WP_REST_Response([
            'provider'       => $settings->get_provider(),
            'model'          => $settings->get_model(),
            'has_api_key'    => !empty($settings->get_api_key()),
            'has_gemini_key' => !empty($settings->get_gemini_api_key()),
            'ollama_url'     => $settings->get_ollama_url(),
        ]);
    }

    public function save_plugin_settings(WP_REST_Request $request): WP_REST_Response {
        $settings = new WAA_Settings();
        $body     = $request->get_json_params();

        if (!empty($body['provider']))    $settings->set_provider($body['provider']);
        if (!empty($body['model']))       $settings->set_model($body['model']);
        if (!empty($body['api_key']))     $settings->set_api_key($body['api_key']);
        if (!empty($body['gemini_key']))  $settings->set_gemini_api_key($body['gemini_key']);
        if (!empty($body['ollama_url']))  $settings->set_ollama_url($body['ollama_url']);

        return new WP_REST_Response(['success' => true]);
    }

    public function list_conversations(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $user_id = get_current_user_id();
        $include_archived = (bool) $request->get_param('include_archived');
        $rows    = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, messages, created_at, updated_at FROM " . WAA_TABLE_CONVERSATIONS .
            " WHERE user_id = %d ORDER BY updated_at DESC LIMIT 20",
            $user_id
        ));
        $visible = [];
        foreach ($rows as $row) {
            $decoded = $this->decode_conversation_payload($row->messages);
            if (($decoded['meta']['archived'] ?? false) && !$include_archived) {
                continue;
            }
            unset($row->messages);
            $row->meta = $decoded['meta'];
            $visible[] = $row;
        }
        return new WP_REST_Response($visible);
    }

    public function create_conversation(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $body     = $request->get_json_params() ?? [];
        $messages = isset($body['messages']) && is_array($body['messages']) ? $body['messages'] : [];
        $history  = isset($body['history']) && is_array($body['history']) ? $body['history'] : [];
        $usage    = isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : [];
        $meta     = isset($body['meta']) && is_array($body['meta']) ? $body['meta'] : [];

        $payload = [
            'messages' => $messages,
            'history'  => $history,
            'usage'    => $usage,
            'meta'     => array_merge([
                'archived' => false,
            ], $meta),
        ];

        $title = $this->resolve_conversation_title(
            isset($body['title']) ? (string) $body['title'] : '',
            $messages,
            $history,
            self::DEFAULT_CONVERSATION_TITLE
        );

        $wpdb->insert(WAA_TABLE_CONVERSATIONS, [
            'user_id'  => get_current_user_id(),
            'title'    => $title,
            'messages' => wp_json_encode($payload),
        ]);
        return new WP_REST_Response(['id' => $wpdb->insert_id], 201);
    }

    public function get_conversation(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . WAA_TABLE_CONVERSATIONS . " WHERE id = %d AND user_id = %d",
            $request->get_param('id'),
            get_current_user_id()
        ));
        if (!$row) return new WP_REST_Response(['error' => 'Not found'], 404);
        $decoded = $this->decode_conversation_payload($row->messages);
        $row->messages = $decoded['messages'];
        $row->history  = $decoded['history'];
        $row->usage    = $decoded['usage'];
        $row->meta     = $decoded['meta'];
        return new WP_REST_Response($row);
    }

    public function update_conversation(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $body     = $request->get_json_params() ?? [];
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT title, messages FROM " . WAA_TABLE_CONVERSATIONS . " WHERE id = %d AND user_id = %d",
            $request->get_param('id'),
            get_current_user_id()
        ), ARRAY_A);

        if (!$existing) {
            return new WP_REST_Response(['error' => 'Not found'], 404);
        }

        $decoded = $this->decode_conversation_payload($existing['messages']);
        $payload = [
            'messages' => isset($body['messages']) && is_array($body['messages']) ? $body['messages'] : $decoded['messages'],
            'history'  => isset($body['history']) && is_array($body['history']) ? $body['history'] : $decoded['history'],
            'usage'    => isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : $decoded['usage'],
            'meta'     => isset($body['meta']) && is_array($body['meta'])
                ? array_merge($decoded['meta'], $body['meta'])
                : $decoded['meta'],
        ];

        $update_data = [
            'messages' => wp_json_encode($payload),
        ];

        $resolved_title = $this->resolve_conversation_title(
            isset($body['title']) ? (string) $body['title'] : '',
            $payload['messages'],
            $payload['history'],
            (string) ($existing['title'] ?? self::DEFAULT_CONVERSATION_TITLE)
        );

        if ($resolved_title !== (string) ($existing['title'] ?? '')) {
            $update_data['title'] = $resolved_title;
        }

        $updated = $wpdb->update(
            WAA_TABLE_CONVERSATIONS,
            $update_data,
            [
                'id'      => $request->get_param('id'),
                'user_id' => get_current_user_id(),
            ]
        );

        if ($updated === false) {
            return new WP_REST_Response(['error' => 'Could not update conversation'], 500);
        }

        return new WP_REST_Response(['success' => true]);
    }

    public function delete_conversation(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $deleted = $wpdb->delete(WAA_TABLE_CONVERSATIONS, [
            'id'      => $request->get_param('id'),
            'user_id' => get_current_user_id(),
        ]);
        return new WP_REST_Response(['success' => (bool) $deleted]);
    }

    private function load_conversation_messages(int $id): array {
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT messages FROM " . WAA_TABLE_CONVERSATIONS . " WHERE id = %d AND user_id = %d",
            $id, get_current_user_id()
        ));
        if (!$row) {
            return [];
        }

        $decoded = $this->decode_conversation_payload($row);
        return $decoded['history'] !== [] ? $decoded['history'] : $decoded['messages'];
    }

    private function load_conversation_meta(int $id): array {
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT messages FROM " . WAA_TABLE_CONVERSATIONS . " WHERE id = %d AND user_id = %d",
            $id,
            get_current_user_id()
        ));

        if (!$row) {
            return [];
        }

        $decoded = $this->decode_conversation_payload($row);
        return is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];
    }

    private function resolve_active_workflow(array $body, int $conversation_id = 0): ?array {
        if (isset($body['workflow']) && is_array($body['workflow'])) {
            return $body['workflow'];
        }

        if ($conversation_id <= 0) {
            return null;
        }

        $meta = $this->load_conversation_meta($conversation_id);

        return isset($meta['active_workflow']) && is_array($meta['active_workflow'])
            ? $meta['active_workflow']
            : null;
    }

    public function decode_conversation_payload(string $payload): array {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return [
                'messages' => [],
                'history'  => [],
                'usage'    => [],
                'meta'     => ['archived' => false],
            ];
        }

        if (array_key_exists('messages', $decoded) || array_key_exists('history', $decoded) || array_key_exists('usage', $decoded) || array_key_exists('meta', $decoded)) {
            return [
                'messages' => is_array($decoded['messages'] ?? null) ? $decoded['messages'] : [],
                'history'  => is_array($decoded['history'] ?? null) ? $decoded['history'] : [],
                'usage'    => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
                'meta'     => is_array($decoded['meta'] ?? null)
                    ? array_merge(['archived' => false], $decoded['meta'])
                    : ['archived' => false],
            ];
        }

        return [
            'messages' => $decoded,
            'history'  => [],
            'usage'    => [],
            'meta'     => ['archived' => false],
        ];
    }

    private function record_debug_event(array &$debug_turn, array $event): void {
        $type = (string) ($event['type'] ?? '');

        switch ($type) {
            case 'text_delta':
                $debug_turn['assistant_text'] .= (string) ($event['content'] ?? '');
                $debug_turn['events'][] = [
                    'type' => 'text_delta',
                    'chars' => strlen((string) ($event['content'] ?? '')),
                ];
                break;

            case 'tool_start':
                $tool_call = [
                    'tool_use_id' => (string) ($event['tool_use_id'] ?? ''),
                    'tool_name' => (string) ($event['tool_name'] ?? ''),
                    'input' => $this->sanitize_debug_value($event['tool_input'] ?? []),
                ];
                $debug_turn['tool_calls'][] = $tool_call;
                $debug_turn['events'][] = array_merge(['type' => 'tool_start'], $tool_call);
                break;

            case 'tool_end':
                $tool_result = [
                    'tool_use_id' => (string) ($event['tool_use_id'] ?? ''),
                    'tool_name' => (string) ($event['tool_name'] ?? ''),
                    'result' => $this->sanitize_debug_value($event['result'] ?? []),
                    'status' => !empty($event['result']['error']) || (($event['result']['success'] ?? true) === false) ? 'error' : 'success',
                ];
                $debug_turn['tool_results'][] = $tool_result;
                $debug_turn['events'][] = array_merge(['type' => 'tool_end'], $tool_result);
                if ($tool_result['status'] === 'error') {
                    $debug_turn['warnings'][] = sprintf('Tool `%s` finished with an error result.', $tool_result['tool_name']);
                }
                break;

            case 'usage':
                $debug_turn['usage'] = [
                    'input_tokens' => (int) ($event['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($event['output_tokens'] ?? 0),
                    'cost_usd' => (float) ($event['cost_usd'] ?? 0),
                    'elapsed_ms' => (int) ($event['elapsed_ms'] ?? 0),
                ];
                $debug_turn['events'][] = [
                    'type' => 'usage',
                    'input_tokens' => $debug_turn['usage']['input_tokens'],
                    'output_tokens' => $debug_turn['usage']['output_tokens'],
                    'cost_usd' => $debug_turn['usage']['cost_usd'],
                    'elapsed_ms' => $debug_turn['usage']['elapsed_ms'],
                ];
                break;

            case 'trace':
                $debug_turn['events'][] = [
                    'type' => 'trace',
                    'phase' => (string) ($event['phase'] ?? ''),
                    'iteration' => isset($event['iteration']) ? (int) $event['iteration'] : null,
                    'duration_ms' => isset($event['duration_ms']) ? (int) $event['duration_ms'] : null,
                    'status' => isset($event['status']) ? (string) $event['status'] : null,
                    'tool_name' => isset($event['tool_name']) ? (string) $event['tool_name'] : null,
                    'tool_use_id' => isset($event['tool_use_id']) ? (string) $event['tool_use_id'] : null,
                ];
                break;

            case 'confirmation_required':
                $debug_turn['warnings'][] = 'Assistant paused for confirmation before a sensitive action.';
                $debug_turn['events'][] = [
                    'type' => 'confirmation_required',
                    'tool_name' => (string) ($event['tool_name'] ?? ''),
                    'tool_use_id' => (string) ($event['tool_use_id'] ?? ''),
                    'message' => (string) ($event['message'] ?? ''),
                    'confirmation' => $this->sanitize_debug_value($event['confirmation'] ?? []),
                ];
                break;

            case 'navigate':
                $debug_turn['events'][] = [
                    'type' => 'navigate',
                    'url' => (string) ($event['url'] ?? ''),
                ];
                break;

            case 'error':
                $message = (string) ($event['message'] ?? 'Unknown error');
                $debug_turn['status'] = 'error';
                $debug_turn['errors'][] = $message;
                $debug_turn['events'][] = [
                    'type' => 'error',
                    'message' => $message,
                ];
                break;
        }
    }

    private function append_conversation_debug_log(int $conversation_id, array $entry): void {
        if ($conversation_id <= 0) {
            return;
        }

        global $wpdb;
        $existing_payload = $wpdb->get_var($wpdb->prepare(
            "SELECT messages FROM " . WAA_TABLE_CONVERSATIONS . " WHERE id = %d AND user_id = %d",
            $conversation_id,
            get_current_user_id()
        ));

        if (!$existing_payload) {
            return;
        }

        $decoded = $this->decode_conversation_payload($existing_payload);
        $meta = is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];
        $debug_log = is_array($meta['debug_log'] ?? null) ? $meta['debug_log'] : [];
        $debug_log[] = $this->sanitize_debug_value($entry);
        if (count($debug_log) > self::DEBUG_LOG_LIMIT) {
            $debug_log = array_slice($debug_log, -self::DEBUG_LOG_LIMIT);
        }
        $meta['debug_log'] = $debug_log;
        $meta['last_debug_turn_id'] = (string) ($entry['turn_id'] ?? '');
        $meta['last_debug_status'] = (string) ($entry['status'] ?? '');
        $meta['last_debug_at'] = current_time('mysql');

        $payload = [
            'messages' => $decoded['messages'],
            'history' => $decoded['history'],
            'usage' => $decoded['usage'],
            'meta' => $meta,
        ];

        $wpdb->update(
            WAA_TABLE_CONVERSATIONS,
            ['messages' => wp_json_encode($payload)],
            [
                'id' => $conversation_id,
                'user_id' => get_current_user_id(),
            ]
        );
    }

    private function make_debug_turn_id(): string {
        return 'turn_' . wp_generate_uuid4();
    }

    private function sanitize_debug_value(mixed $value): mixed {
        if (is_scalar($value) || $value === null) {
            if (is_string($value)) {
                return $this->truncate_debug_string($value);
            }
            return $value;
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitize_debug_value($item);
            }
            return $sanitized;
        }

        if (is_object($value)) {
            return $this->sanitize_debug_value((array) $value);
        }

        return $this->truncate_debug_string((string) $value);
    }

    private function truncate_debug_string(string $value, int $limit = 4000): string {
        $value = wp_check_invalid_utf8($value);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) > $limit) {
                return mb_substr($value, 0, $limit) . '…';
            }
            return $value;
        }

        if (strlen($value) > $limit) {
            return substr($value, 0, $limit) . '…';
        }

        return $value;
    }

    public function derive_conversation_title(array $messages, array $history = []): string {
        $first_user = $this->find_first_user_content($messages);
        if ($first_user === '') {
            $first_user = $this->find_first_user_content($history);
        }

        if ($first_user === '') {
            return self::DEFAULT_CONVERSATION_TITLE;
        }

        $task_title = $this->derive_task_title($messages, $history);
        if ($task_title !== '') {
            return sanitize_text_field($task_title);
        }

        $title = function_exists('mb_substr')
            ? mb_substr($first_user, 0, self::PROVISIONAL_TITLE_LIMIT)
            : substr($first_user, 0, self::PROVISIONAL_TITLE_LIMIT);
        $length = function_exists('mb_strlen')
            ? mb_strlen($first_user)
            : strlen($first_user);

        if ($length > self::PROVISIONAL_TITLE_LIMIT) {
            $title .= '…';
        }

        return sanitize_text_field($title);
    }

    private function resolve_conversation_title(string $requested_title, array $messages, array $history, string $existing_title): string {
        $requested_title = sanitize_text_field($requested_title);
        if ($requested_title !== '') {
            return $requested_title;
        }

        $derived_input_title = $this->derive_first_user_title($messages, $history);
        $derived_task_title = $this->derive_task_title($messages, $history);

        if (
            $derived_task_title !== ''
            && sanitize_text_field($existing_title) === $derived_input_title
            && $derived_task_title !== $derived_input_title
        ) {
            return $derived_task_title;
        }

        if (!$this->is_generic_conversation_title($existing_title)) {
            return sanitize_text_field($existing_title);
        }

        if ($derived_task_title !== '') {
            return $derived_task_title;
        }

        return $this->derive_conversation_title($messages, $history);
    }

    private function is_generic_conversation_title(string $title): bool {
        $title = sanitize_text_field($title);

        return $title === ''
            || $title === self::DEFAULT_CONVERSATION_TITLE
            || preg_match('/^Conversation\b/i', $title) === 1;
    }

    private function find_first_user_content(array $messages): string {
        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }

            $content = sanitize_text_field((string) ($message['content'] ?? ''));
            if ($content !== '') {
                return $content;
            }
        }

        return '';
    }

    private function derive_first_user_title(array $messages, array $history = []): string {
        $first_user = $this->find_first_user_content($messages);
        if ($first_user === '') {
            $first_user = $this->find_first_user_content($history);
        }

        if ($first_user === '') {
            return self::DEFAULT_CONVERSATION_TITLE;
        }

        $title = function_exists('mb_substr')
            ? mb_substr($first_user, 0, self::PROVISIONAL_TITLE_LIMIT)
            : substr($first_user, 0, self::PROVISIONAL_TITLE_LIMIT);
        $length = function_exists('mb_strlen')
            ? mb_strlen($first_user)
            : strlen($first_user);

        if ($length > self::PROVISIONAL_TITLE_LIMIT) {
            $title .= '…';
        }

        return sanitize_text_field($title);
    }

    private function derive_task_title(array $messages, array $history = []): string {
        $entries = array_merge($history, $messages);

        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $entry = $entries[$i];
            if (!is_array($entry)) {
                continue;
            }

            if (($entry['role'] ?? '') === 'tool') {
                $title = $this->title_from_tool_result((string) ($entry['tool_name'] ?? ''), $entry['result'] ?? []);
                if ($title !== '') {
                    return $title;
                }
            }

            if (($entry['role'] ?? '') === 'assistant' && is_array($entry['tool_calls'] ?? null)) {
                for ($j = count($entry['tool_calls']) - 1; $j >= 0; $j--) {
                    $tool_call = $entry['tool_calls'][$j];
                    if (!is_array($tool_call)) {
                        continue;
                    }
                    $title = $this->title_from_tool_input((string) ($tool_call['name'] ?? ''), $tool_call['input'] ?? []);
                    if ($title !== '') {
                        return $title;
                    }
                }
            }
        }

        return '';
    }

    private function title_from_tool_input(string $tool_name, array $input): string {
        return match ($tool_name) {
            'install_plugin' => 'Install ' . $this->humanize_slug((string) ($input['slug'] ?? 'Plugin')),
            'install_theme' => 'Install ' . $this->humanize_slug((string) ($input['slug'] ?? 'Theme')) . ' theme',
            'activate_plugin' => 'Activate ' . $this->humanize_plugin((string) ($input['plugin_file'] ?? 'plugin')),
            'deactivate_plugin' => 'Deactivate ' . $this->humanize_plugin((string) ($input['plugin_file'] ?? 'plugin')),
            'switch_theme' => 'Switch to ' . $this->humanize_slug((string) ($input['theme_slug'] ?? 'theme')),
            'get_woocommerce_status' => 'Review WooCommerce setup',
            'update_woocommerce_settings' => 'Configure WooCommerce store settings',
            'create_woocommerce_product' => sanitize_text_field((string) ($input['name'] ?? 'New WooCommerce product')),
            'update_woocommerce_product' => 'Update WooCommerce product ' . (string) ($input['product_id'] ?? 'product'),
            'list_woocommerce_orders' => 'Review WooCommerce orders',
            'update_woocommerce_order_status' => 'Update WooCommerce order ' . (string) ($input['order_id'] ?? 'order'),
            'create_woocommerce_coupon' => 'Create WooCommerce coupon ' . sanitize_text_field((string) ($input['code'] ?? 'coupon')),
            'create_post', 'create_simple_post', 'create_rich_post' => sanitize_text_field((string) ($input['title'] ?? 'New post')),
            'wordfence_run_scan' => 'Run Wordfence scan',
            default => '',
        };
    }

    private function title_from_tool_result(string $tool_name, array $result): string {
        return match ($tool_name) {
            'install_plugin' => 'Install ' . sanitize_text_field((string) ($result['name'] ?? $this->humanize_plugin((string) ($result['plugin_file'] ?? 'plugin')))),
            'install_theme' => 'Install ' . sanitize_text_field((string) ($result['name'] ?? $this->humanize_slug((string) ($result['theme_slug'] ?? 'theme')))) . ' theme',
            'create_woocommerce_product', 'update_woocommerce_product' => !empty($result['product']['name'])
                ? sanitize_text_field((string) $result['product']['name'])
                : '',
            'update_woocommerce_order_status' => !empty($result['order_id'])
                ? 'Update WooCommerce order ' . (string) $result['order_id']
                : '',
            'create_woocommerce_coupon' => !empty($result['code'])
                ? 'Create WooCommerce coupon ' . sanitize_text_field((string) $result['code'])
                : '',
            'create_post', 'create_simple_post', 'create_rich_post' => !empty($result['post_id'])
                ? 'Post ' . (string) $result['post_id']
                : '',
            'wordfence_run_scan' => 'Run Wordfence scan',
            default => '',
        };
    }

    private function humanize_slug(string $slug): string {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        $slug = preg_replace('#/.*$#', '', $slug);
        $slug = str_replace(['-', '_'], ' ', $slug);
        return ucwords(sanitize_text_field($slug));
    }

    private function humanize_plugin(string $plugin_file): string {
        $plugin_file = trim($plugin_file);
        if ($plugin_file === '') {
            return '';
        }

        $base = explode('/', $plugin_file)[0] ?? $plugin_file;
        return $this->humanize_slug($base);
    }

    public static function build_registry(array $disabled = []): WAA_Tool_Registry {
        $registry = new WAA_Tool_Registry($disabled);
        $registry->register(new WAA_Tool_Get_Settings());
        $registry->register(new WAA_Tool_Update_Settings());
        $registry->register(new WAA_Tool_List_Plugins());
        $registry->register(new WAA_Tool_Install_Plugin());
        $registry->register(new WAA_Tool_Activate_Plugin());
        $registry->register(new WAA_Tool_Deactivate_Plugin());
        $registry->register(new WAA_Tool_List_Themes());
        $registry->register(new WAA_Tool_Search_Themes());
        $registry->register(new WAA_Tool_Install_Theme());
        $registry->register(new WAA_Tool_Switch_Theme());
        $registry->register(new WAA_Tool_List_Users());
        $registry->register(new WAA_Tool_Update_User_Role());
        $registry->register(new WAA_Tool_List_Posts());
        $registry->register(new WAA_Tool_Create_Post());
        $registry->register(new WAA_Tool_Create_Simple_Post());
        $registry->register(new WAA_Tool_Create_Rich_Post());
        $registry->register(new WAA_Tool_Update_Post());
        $registry->register(new WAA_Tool_Navigate());
        $registry->register(new WAA_Tool_Search_Icon());
        $registry->register(new WAA_Tool_Set_Site_Icon());
        $registry->register(new WAA_Tool_Search_Images());
        $registry->register(new WAA_Tool_Resolve_Image());
        $registry->register(new WAA_Tool_Generate_Image());
        $registry->register(new WAA_Tool_Set_Post_Image());
        $registry->register(new WAA_Tool_Get_WooCommerce_Status());
        $registry->register(new WAA_Tool_Update_WooCommerce_Settings());
        $registry->register(new WAA_Tool_List_WooCommerce_Products());
        $registry->register(new WAA_Tool_Create_WooCommerce_Product());
        $registry->register(new WAA_Tool_Update_WooCommerce_Product());
        $registry->register(new WAA_Tool_List_WooCommerce_Orders());
        $registry->register(new WAA_Tool_Update_WooCommerce_Order_Status());
        $registry->register(new WAA_Tool_Create_WooCommerce_Coupon());
        $registry->register(new WAA_Tool_Security_Harden());
        $registry->register(new WAA_Tool_Fetch_Rss());
        $registry->register(new WAA_Tool_Wordfence_Get_Settings());
        $registry->register(new WAA_Tool_Wordfence_Update_Settings());
        $registry->register(new WAA_Tool_Wordfence_Run_Scan());
        $registry->register(new WAA_Tool_Wordfence_Get_Scan_Results());
        $registry->register(new WAA_Tool_Wordfence_Disconnect_Central());
        return $registry;
    }

    public function get_stats(WP_REST_Request $request): WP_REST_Response {
        $period = (int) $request->get_param('period');
        return new WP_REST_Response(WAA_Audit_Log::get_stats($period));
    }

    public function handle_mcp(WP_REST_Request $request): WP_REST_Response {
        $settings = new WAA_Settings();
        $server   = new WAA_MCP_Server(self::build_registry($settings->get_disabled_tools()));
        return $server->handle($request);
    }

    public function get_ollama_models(WP_REST_Request $request): WP_REST_Response {
        $settings = new WAA_Settings();
        $base_url = rtrim($settings->get_ollama_url(), '/');

        $response = wp_remote_get("$base_url/api/tags", ['timeout' => 5]);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['error' => $response->get_error_message()], 503);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || !isset($body['models'])) {
            return new WP_REST_Response(['error' => "Ollama returned HTTP $code"], 503);
        }

        $models = [];
        foreach ($body['models'] as $m) {
            $name = $m['name'];
            $size = isset($m['size']) ? round($m['size'] / 1024 / 1024 / 1024, 1) . ' GB' : '';
            $models[$name] = $name . ($size ? " ($size)" : '');
        }

        return new WP_REST_Response(['models' => $models]);
    }

    private function sse_emit(array $event): void {
        echo 'data: ' . wp_json_encode($event) . "\n\n";
        flush();
    }

    private function sse_error(string $message): void {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-store');
        while (ob_get_level() > 0) ob_end_flush();
        $this->sse_emit(['type' => 'error', 'message' => $message]);
        $this->sse_emit(['type' => 'done']);
        echo "data: [DONE]\n\n";
        flush();
        exit;
    }
}
